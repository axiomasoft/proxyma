"""Узкие проверки private supply; локальные fixtures, без Agelto/DB/network."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('package_check', ROOT / 'scripts/package-check.py')
SUPPLY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SUPPLY)
QUALIFIED = json.loads((ROOT / 'integration/agelto/candidate.json').read_text())['qualification_source_commit']


class PrivateSupplyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.temp.cleanup)
        cls.root = Path(cls.temp.name)
        cls.files = SUPPLY.worktree(ROOT)
        cls.base = dict(cls.files)
        # Unit fixture only: never treat a historical local build report as current qualification.
        (cls.root / 'qualification.json').write_text(json.dumps({
            'source_sha256': SUPPLY.fingerprint(cls.base), 'synthetic_unit_fixture': True,
        }))
        with patch.object(SUPPLY, 'qualification', return_value=cls.base), \
                patch.object(SUPPLY, 'worktree', return_value=cls.files):
            cls.value = SUPPLY.candidate(cls.root, 'qualification.json', QUALIFIED)

    def verify(self, value, files=None, source=None):
        with patch.object(SUPPLY, 'qualification', return_value=self.base), \
                patch.object(SUPPLY, 'worktree', return_value=files or self.files), \
                patch.object(SUPPLY, 'tree', return_value=source or self.files):
            return SUPPLY.verify(self.root, value)

    def test_candidate_связан_с_qualification_reference_и_двумя_пакетами(self):
        self.assertEqual(self.value['qualification_source_commit'], QUALIFIED)
        self.assertEqual({p['name'] for p in self.value['packages']}, set(SUPPLY.PACKAGES))
        self.assertEqual({p['version'] for p in self.value['packages']}, {'0.1.0'})
        self.assertNotIn('source', self.value)
        self.assertEqual(self.verify(self.value)['status'], 'candidate')

    def test_candidate_не_обходит_проверку_qualification(self):
        with patch.object(SUPPLY, 'qualification', side_effect=ValueError('incomplete qualification')), \
                self.assertRaisesRegex(ValueError, 'incomplete qualification'):
            SUPPLY.candidate(self.root, 'qualification.json', QUALIFIED)

    def test_candidate_flag_выбирает_проверку_существующего_descriptor(self):
        args = SUPPLY.arguments(['--candidate', 'integration/agelto/candidate.json'])
        explicit = SUPPLY.arguments(['verify', '--descriptor', 'integration/agelto/candidate.json'])
        for key in ('command', 'descriptor', 'consumer_root'):
            self.assertEqual(getattr(args, key), getattr(explicit, key))
        self.assertEqual(args.command, 'verify')

    def test_candidate_flag_не_разрешает_смешать_проверку_и_создание(self):
        with patch('sys.stderr'):
            for argv in ([], ['--candidate'], ['--candidate', 'candidate.json', 'candidate',
                         '--qualification-source-commit', QUALIFIED, '--output', 'output.json']):
                with self.subTest(argv=argv), self.assertRaises(SystemExit) as error:
                    SUPPLY.arguments(argv)
                self.assertEqual(error.exception.code, 2)

    def test_отказ_для_floating_placeholder_и_сокращённого_SHA(self):
        for value in ('main', 'dev-main', 'HEAD', '5a046f6', '0' * 40, 'A' * 40):
            with self.subTest(value=value), self.assertRaises(ValueError):
                SUPPLY.sha(value)

    def test_отказ_для_path_traversal_и_symlink(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'original').write_text('fixture')
            (root / 'link').symlink_to(root / 'original')
            for value in ('../outside', '/outside', 'a/../original', './original', 'a\\b', 'link'):
                with self.subTest(value=value), self.assertRaises(ValueError):
                    SUPPLY.local(root, value)

    def test_отказ_для_подмены_имени_версии_пути_или_digest(self):
        for key, replacement in [('name', 'axiomasoft/proxyma-workspace'), ('version', 'dev-main'),
                                 ('path', '.'), ('payload_sha256', '0' * 64), ('manifest_sha256', '0' * 64)]:
            value = copy.deepcopy(self.value)
            value['packages'][0][key] = replacement
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.verify(value)

    def test_отказ_для_пропущенного_или_дублированного_пакета(self):
        for packages in (self.value['packages'][:1], self.value['packages'] * 2):
            value = {**self.value, 'packages': packages}
            with self.assertRaises(ValueError):
                self.verify(value)

    def test_UI_требует_ровно_свою_версию_core(self):
        for constraint in ('dev-main', '*', '^0.2.0'):
            files = dict(self.files)
            path = 'packages/filament/composer.json'
            manifest = json.loads(files[path])
            manifest['require']['axiomasoft/proxyma-core'] = constraint
            files[path] = json.dumps(manifest).encode()
            with self.subTest(constraint=constraint), self.assertRaises(ValueError):
                SUPPLY.manifests(files)

    def test_изменение_runtime_вне_qualification_отклоняется(self):
        for path in ('packages/core/src/Unqualified.php', 'tests/NewTest.php', 'docker/unqualified'):
            files = {**self.files, path: b'new untested behavior'}
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'unqualified source changes'):
                self.verify(self.value, files=files)

    def test_отказ_при_подмене_qualification_report(self):
        ref = {**self.value['qualification'], 'sha256': '0' * 64}
        with self.assertRaisesRegex(ValueError, 'stale or modified evidence'):
            SUPPLY.read_reference(self.root, ref)

    def test_отказ_для_qualification_с_другим_source_digest(self):
        report = json.loads((self.root / self.value['qualification']['path']).read_text())
        report['source_sha256'] = '0' * 64
        with patch.object(SUPPLY, 'read_reference', return_value=report), \
                patch.object(SUPPLY, 'tree', return_value=self.base), \
                self.assertRaisesRegex(ValueError, 'qualification source commit/digest mismatch'):
            SUPPLY.qualification(self.root, self.value['qualification'], QUALIFIED)

    def test_resolve_проверяет_исходники_commit_и_не_сам_дескриптор(self):
        value = {**self.value, 'source': {'commit': QUALIFIED, 'sha256': SUPPLY.fingerprint(self.files)}}
        self.assertEqual(self.verify(value)['source']['commit'], QUALIFIED)
        invalid_source = dict(self.files)
        path = 'packages/filament/composer.json'
        manifest = json.loads(invalid_source[path])
        manifest['require']['axiomasoft/proxyma-core'] = '*'
        invalid_source[path] = json.dumps(manifest).encode()
        with self.assertRaisesRegex(ValueError, 'same mapped version of core'):
            self.verify(value, source=invalid_source)
        mismatched = {**self.files, 'packages/core/src/Unqualified.php': b'changed source'}
        with self.assertRaisesRegex(ValueError, 'does not contain candidate packages'):
            self.verify(value, source=mismatched)
        value['source']['sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'resolved source digest mismatch'):
            self.verify(value)

    def test_accepted_невозможен_без_точного_source_pin(self):
        value = {**self.value, 'status': 'accepted'}
        with self.assertRaisesRegex(ValueError, 'resolve the source commit'):
            self.verify(value)

    def test_Composer_reference_использует_PHP_serialize_включая_relative(self):
        self.assertEqual(SUPPLY.php_serialize({'symlink': False, 'versions': {'a/b': '0.1.0'}, 'relative': True}),
                         b'a:3:{s:7:"symlink";b:0;s:8:"versions";a:1:{s:3:"a/b";s:5:"0.1.0";}s:8:"relative";b:1;}')
        self.assertEqual(SUPPLY.path_reference(b'{}', {'symlink': False}),
                         SUPPLY.path_reference(b'{}', {'symlink': False, 'relative': True}))
        self.assertNotEqual(SUPPLY.path_reference(b'{}', {'symlink': False}),
                            SUPPLY.path_reference(b'{}', {'symlink': True}))

    def test_Composer_устанавливает_две_стабильные_копии_через_self_version_без_сети(self):
        composer = shutil.which('composer')
        self.assertIsNotNone(composer, 'локальный Composer нужен для executable docs proof')
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            repos = []
            for name, path in SUPPLY.PACKAGES.items():
                directory = root / path
                directory.mkdir(parents=True)
                manifest = {'name': name, 'type': 'library'}
                if name.endswith('-filament'):
                    manifest['require'] = {'axiomasoft/proxyma-core': 'self.version'}
                (directory / 'composer.json').write_text(json.dumps(manifest))
                repos.append({'type': 'path', 'url': path, 'options': {
                    'symlink': False, 'reference': 'config', 'versions': {name: '0.1.0'}}})
            (root / 'composer.json').write_text(json.dumps({'name': 'fixture/consumer',
                'repositories': [*repos, {'packagist.org': False}],
                'require': {name: '0.1.0' for name in SUPPLY.PACKAGES}}))
            env = {**os.environ, 'COMPOSER_DISABLE_NETWORK': '1', 'COMPOSER_HOME': str(root / 'home')}
            result = subprocess.run([composer, 'update', '--no-dev', '--no-plugins', '--no-scripts',
                                     '--no-audit', '--no-interaction'], cwd=root, env=env,
                                    capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            lock = json.loads((root / 'composer.lock').read_text())
            self.assertEqual({p['version'] for p in lock['packages']}, {'0.1.0'})
            for package, repo in zip(sorted(lock['packages'], key=lambda p: p['name']), repos):
                manifest = (root / repo['url'] / 'composer.json').read_bytes()
                self.assertEqual(package['dist']['reference'], SUPPLY.path_reference(manifest, repo['options']))
                self.assertFalse((root / 'vendor' / package['name']).is_symlink())
                self.assertTrue((root / 'vendor' / package['name'] / 'composer.json').is_file())


class ConsumerProofTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.refs = {key: {'sha256': 'a' * 64} for key in ('composer_lock', 'vendor_lock', 'installed')}
        self.proof = {'schema': 'proxyma-consumer-proof/v1', 'consumer_commit': 'b' * 40,
                      'source_commit': QUALIFIED, 'packages': {'axiomasoft/proxyma-core': 'vendor/axiomasoft/proxyma-core'},
                      'composer_lock_sha256': 'a' * 64, 'vendor_lock_sha256': 'a' * 64,
                      'installed_sha256': 'a' * 64, 'inputs_sha256': 'c' * 64,
                      'runtime_sha256': 'd' * 64, 'gates': {}}
        commands = ['core-test-install', 'core-test-e2e', 'core-test-faults', 'core-test-load',
                    'core-test-load', 'core-test-regression', 'core-quality']
        evidence = self.root / 'fixture.log'
        evidence.write_text('isolated synthetic test proof; not an executed consumer gate')
        for i, name in enumerate(commands, 1):
            gate = 'V' + str(i)
            record = {key: self.proof[key] for key in ('source_commit', 'consumer_commit', 'inputs_sha256', 'runtime_sha256')}
            record.update(exit=0, cwd=str(self.root), command=['make', name])
            result = {**record, 'assertions': 1, 'scenarios': 1,
                      'evidence': [SUPPLY.reference(self.root, 'fixture.log')]}
            if i in (4, 5):
                record['command'].append('WIDTH=' + ('10' if i == 4 else '20'))
                result.update(width=10 if i == 4 else 20, duration_seconds=1800, fixture_documents=1000)
            path = gate + '.json'
            (self.root / path).write_text(json.dumps(result))
            self.proof['gates'][gate] = {**record, **SUPPLY.reference(self.root, path)}

    def check(self):
        SUPPLY.check_consumer_proof(self.root, self.proof, 'b' * 40, QUALIFIED, self.refs,
                                    {'axiomasoft/proxyma-core': 'vendor/axiomasoft/proxyma-core'})

    def test_полный_локальный_fixture_принимается(self):
        self.check()

    def test_отказ_для_пропущенного_failed_stale_или_другого_runtime_gate(self):
        original = copy.deepcopy(self.proof)
        for mutation in ('missing', 'failed', 'stale', 'runtime', 'command', 'digest'):
            self.proof = copy.deepcopy(original)
            record = self.proof['gates']['V4']
            if mutation == 'missing':
                del self.proof['gates']['V4']
            elif mutation == 'failed':
                record['exit'] = 1
            elif mutation == 'stale':
                record['consumer_commit'] = 'e' * 40
            elif mutation == 'runtime':
                record['runtime_sha256'] = 'e' * 64
            elif mutation == 'command':
                record['command'] = ['true']
            else:
                record['sha256'] = '0' * 64
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                self.check()

    def test_отказ_для_короткого_load_и_изменённого_артефакта(self):
        path = self.root / 'V4.json'
        result = json.loads(path.read_text())
        result['duration_seconds'] = 1
        path.write_text(json.dumps(result))
        self.proof['gates']['V4'].update(SUPPLY.reference(self.root, 'V4.json'))
        with self.assertRaisesRegex(ValueError, 'incomplete load profile'):
            self.check()
        result['duration_seconds'] = 1800
        path.write_text(json.dumps(result))
        self.proof['gates']['V4'].update(SUPPLY.reference(self.root, 'V4.json'))
        (self.root / 'fixture.log').write_text('changed')
        with self.assertRaisesRegex(ValueError, 'stale consumer result evidence'):
            self.check()


class ConsumerInstallTest(unittest.TestCase):
    def setUp(self):
        ConsumerProofTest.setUp(self)
        self.value = {'status': 'candidate', 'repository': 'axiomasoft/proxyma',
                      'source': {'commit': QUALIFIED}, 'packages': [
                          {'name': name, 'path': path, 'version': '0.1.0'} for name, path in SUPPLY.PACKAGES.items()]}
        self.files = {}
        repos, packages = [], []
        for name, path in SUPPLY.PACKAGES.items():
            manifest = json.dumps({'name': name}).encode()
            self.files[path + '/composer.json'] = manifest
            self.files[path + '/src/Fixture.php'] = b'<?php // synthetic isolated fixture'
            installed = self.root / 'vendor' / name
            (installed / 'src').mkdir(parents=True)
            (installed / 'composer.json').write_bytes(manifest)
            (installed / 'src/Fixture.php').write_bytes(self.files[path + '/src/Fixture.php'])
            repo = {'type': 'path', 'url': 'local/vendors/proxyma/' + path, 'options': {
                'symlink': False, 'reference': 'config', 'versions': {name: '0.1.0'}}}
            repos.append(repo)
            packages.append({'name': name, 'version': '0.1.0', 'dist': {'type': 'path',
                'url': repo['url'], 'reference': SUPPLY.path_reference(manifest, repo['options'])},
                'transport-options': {'symlink': False}})
        values = {'vendor_lock': {'version': 2, 'repositories': [{'key': 'proxyma',
                   'repository': {'host': 'github.com', 'path': 'axiomasoft/proxyma'},
                   'commit': QUALIFIED, 'packages': self.value['packages']}]},
                  'composer_json': {'require': {p: '0.1.0' for p in SUPPLY.PACKAGES}, 'repositories': repos},
                  'composer_lock': {'packages': packages}, 'installed': {'packages': packages}}
        self.paths = {}
        for key, data in values.items():
            self.paths[key] = key + '.json'
            (self.root / self.paths[key]).write_text(json.dumps(data))
        self.proof['packages'] = {p: 'vendor/' + p for p in SUPPLY.PACKAGES}
        for key in ('composer_lock', 'vendor_lock', 'installed'):
            self.proof[key + '_sha256'] = SUPPLY.reference(self.root, self.paths[key])['sha256']
        self.paths['report'] = 'proof.json'
        (self.root / 'proof.json').write_text(json.dumps(self.proof))

    def fake_git(self, root, *args):
        if args[0] == 'status':
            return b''
        checkout = root.name == 'proxyma'
        if args[0] == 'rev-parse':
            return (QUALIFIED if checkout else 'b' * 40).encode()
        return ('git@github.com:axiomasoft/proxyma.git' if checkout
                else 'git@github.com:axiomasoft/agelto.git').encode()

    def install(self, git=None):
        with patch.object(SUPPLY, 'verify'), patch.object(SUPPLY, 'tree', return_value=self.files), \
                patch.object(SUPPLY, 'git', side_effect=git or self.fake_git):
            return SUPPLY.consumer(ROOT, self.value, self.root, self.paths)

    def mutate(self, key, change):
        path = self.root / self.paths[key]
        data = json.loads(path.read_text())
        change(data)
        path.write_text(json.dumps(data))

    def test_полная_проверка_копий_lock_и_consumer_refs(self):
        self.assertEqual(self.install()['commit'], 'b' * 40)

    def test_отказ_для_wrong_SHA_dirty_origin_и_отсутствующего_package(self):
        for failure in ('sha', 'dirty', 'origin'):
            def git(root, *args):
                if root.name == 'proxyma':
                    if failure == 'sha' and args[0] == 'rev-parse':
                        return b'e' * 40
                    if failure == 'dirty' and args[0] == 'status':
                        return b' M src/Fixture.php'
                    if failure == 'origin' and args[0] == 'config':
                        return b'git@github.com:other/repo.git'
                return self.fake_git(root, *args)
            with self.subTest(failure=failure), self.assertRaises(ValueError):
                self.install(git)
        (self.root / 'vendor/axiomasoft/proxyma-core/src/Fixture.php').unlink()
        with self.assertRaises(OSError):
            self.install()

    def test_отказ_для_floating_consumer_requirement(self):
        self.mutate('composer_json', lambda d: d['require'].update({'axiomasoft/proxyma-core': 'dev-main'}))
        with self.assertRaisesRegex(ValueError, 'exactly 0.1.0'):
            self.install()

    def test_отказ_для_symlink_options_и_несогласованного_lock(self):
        self.mutate('composer_json', lambda d: d['repositories'][0]['options'].update(symlink=True))
        with self.assertRaisesRegex(ValueError, 'unsafe or floating'):
            self.install()
        self.mutate('composer_json', lambda d: d['repositories'][0]['options'].update(symlink=False))
        self.mutate('composer_lock', lambda d: d['packages'][0]['dist'].update(reference='e' * 40))
        with self.assertRaisesRegex(ValueError, 'does not match pinned source'):
            self.install()


if __name__ == '__main__':
    unittest.main(verbosity=2)
