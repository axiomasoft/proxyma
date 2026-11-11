"""Финальный acceptance guard на синтетических receipts, без БД, сети и gate-прогонов."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


NATIVE = load(ROOT / 'scripts/agelto-acceptance.py', 'native_acceptance')
SUPPLY = load(ROOT / 'scripts/package-check.py', 'native_supply')
LAUNCHER = load(ROOT / 'scripts/agelto.py', 'native_launcher')
CONSUMER = LAUNCHER.resolve_root()
sys.path.insert(0, str(CONSUMER / 'docker/tests/process-core'))
import acceptance_report as REPORT
from test_acceptance_report import EvidenceFixture


class InstalledAcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.source = root / 'source'
        self.f = EvidenceFixture(root / 'consumer')
        self.files = {}
        for prefix in SUPPLY.PACKAGES.values():
            self.files[prefix + '/composer.json'] = b'{}'
            self.files[prefix + '/src/Fixture.php'] = b'<?php // fixture'
        self.revision = 'c' * 40
        repositories = {'proxyma': {'commit': self.revision, 'packages': {
            prefix: SUPPLY.payload(self.files, prefix) for prefix in SUPPLY.PACKAGES.values()}}}
        self.f.write(self.f.root / 'tests/Fixtures/ProcessCore/runtime-inputs.json', {'repositories': repositories})
        self.runtime = {'dependencies': repositories, 'images': {'app': 'sha256:synthetic'},
                        'config_sha256': 'd' * 64, 'installed_packages': {
                            name: {p[len(prefix) + 1:]: SUPPLY.digest(raw) for p, raw in self.files.items()
                                   if p.startswith(prefix + '/')} for name, prefix in SUPPLY.PACKAGES.items()}}
        self.f.contract['installed_proxyma'] = True
        self.f.contract['integration_cases'] = {'tests/Feature/ProcessCore/T01Test.php':
            self.f.contract['test_cases']['tests/Feature/ProcessCore/T01Test.php']}
        self.f.save_contract()
        for gate in REPORT.GATES:
            self.f.mutate_result(gate, lambda record: record.update(runtime=copy.deepcopy(self.runtime)))
        self.f.write(self.source / 'integration/agelto/legacy-removal.json', {
            'runtime_retirements': ['app/Old.php'], 'test_retirements': []})
        self.refs = {'report': {'path': 'reports/current/summary.json'}}
        self.installed = {name: 'vendor/' + name for name in SUPPLY.PACKAGES}

    def collect(self):
        self.proof = self.f.build()
        self.f.write(self.f.folder / 'summary.json', self.proof)
        return self.proof

    def check(self):
        modules = {'scripts/package-check.py': SUPPLY,
                   'docker/tests/process-core/acceptance_report.py': REPORT,
                   'docker/tests/process-core/load_report.py': SimpleNamespace(
                       validate=lambda report, width: {'width': width, 'verdict': 'pass'})}
        with patch.object(NATIVE, 'load', side_effect=lambda root, filename, name: modules[filename]), \
                patch.object(NATIVE, 'input_tree', return_value=self.f.inputs), \
                patch.object(SUPPLY, 'tree', return_value=self.files), \
                patch.object(SUPPLY, 'git', return_value=b''):
            return NATIVE.check(self.source, self.f.root, self.proof, 'a' * 40,
                                self.revision, self.refs, self.installed)

    def test_связывает_семь_нативных_gates_с_копиями_и_финальным_SHA(self):
        self.assertEqual([], self.collect()['errors'])
        result = self.check()
        self.assertEqual(REPORT.digest(self.runtime), result['runtime_sha256'])
        self.assertIn('no production', result['scope'])

    def test_отклоняет_отсутствующий_и_иной_runtime_любого_gate(self):
        original = copy.deepcopy(self.runtime)
        for gate in REPORT.GATES:
            for replacement in ({}, {**original, 'images': {'app': 'other'}}):
                with self.subTest(gate=gate, replacement=replacement):
                    self.f.mutate_result(gate, lambda r: r.update(runtime=replacement))
                    self.assertTrue(self.f.build()['errors'])
                    self.f.mutate_result(gate, lambda r: r.update(runtime=copy.deepcopy(original)))

    def test_отклоняет_пропуск_нового_integration_case(self):
        self.f.contract['integration_cases']['tests/Feature/ProcessCore/MissingTest.php'] = {'count': 1}
        self.f.save_contract()
        self.assertTrue(any(e['id'] == 'V6' for e in self.f.build()['errors']))

    def test_отклоняет_непересобранный_после_commit_отчёт(self):
        self.collect()
        self.proof['candidate']['head'] = 'b' * 40
        with self.assertRaisesRegex(ValueError, 'after final commit'):
            self.check()

    def test_отклоняет_изменённый_нативный_summary(self):
        self.collect()
        self.proof['validations']['V1']['exit'] = 1
        with self.assertRaisesRegex(ValueError, 'modified or stale'):
            self.check()

    def test_отклоняет_старый_payload_и_чужие_файлы_в_runtime(self):
        self.runtime['installed_packages']['axiomasoft/proxyma']['src/Fixture.php'] = '0' * 64
        for gate in REPORT.GATES:
            self.f.mutate_result(gate, lambda r: r.update(runtime=self.runtime))
        self.collect()
        with self.assertRaisesRegex(ValueError, 'installed runtime files'):
            self.check()

    def test_отклоняет_оставшийся_legacy_runtime(self):
        self.f.write_text(self.f.root / 'app/Old.php', '<?php // legacy')
        self.collect()
        with self.assertRaisesRegex(ValueError, 'legacy runtime still present'):
            self.check()

    def test_проверяет_hash_и_группу_архивного_сценария_для_replay(self):
        archive = 'archive/old.php.txt'
        self.f.write_text(self.f.root / archive, "<?php test('old', fn () => null)->group('network');")
        binding = {'replaces': 'old.php', 'file': 'replay.php', 'group': 'network'}
        contract = {'replay_replacements': {'old.php': {'files': ['replay.php'], 'groups': ['network'],
            'archive': archive, 'source_sha256': REPORT.sha(self.f.root / archive)}}}
        REPORT.replay_binding(self.f.root, contract, binding)
        self.f.write_text(self.f.root / archive, 'changed historical source')
        with self.assertRaisesRegex(ValueError, 'modified historical'):
            REPORT.replay_binding(self.f.root, contract, binding)

    def test_accepted_flag_требует_проверку_итогового_descriptor(self):
        args = SUPPLY.arguments(['--accepted', 'acceptance.json', '--consumer-root', str(self.f.root)])
        self.assertEqual(('verify', 'acceptance.json', self.f.root),
                         (args.command, args.descriptor, args.consumer_root))

    def test_current_input_tree_не_читает_native_plan_и_выявляет_изменение(self):
        self.f.write_text(self.f.root / 'source.php', 'first')
        paths = b'source.php\nplans/plan.json\n.swissknifeman/context.json\n'
        with patch.object(NATIVE.subprocess, 'check_output', return_value=paths):
            first = NATIVE.input_tree(self.f.root)
            self.f.write_text(self.f.root / 'source.php', 'second')
            second = NATIVE.input_tree(self.f.root)
        self.assertEqual(['source.php'], list(first))
        self.assertNotEqual(first, second)


if __name__ == '__main__':
    unittest.main(verbosity=2)
