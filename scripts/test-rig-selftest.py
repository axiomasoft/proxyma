import importlib.util
import json
from contextlib import contextmanager
from pathlib import Path
import select
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('rig', Path(__file__).with_name('test-rig.py'))
rig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rig)


class RigGuardTest(unittest.TestCase):
    def test_process_lock_blocks_collector_until_suite_releases_receipts(self):
        program = '''import importlib.util
from pathlib import Path
import sys
spec = importlib.util.spec_from_file_location('rig', sys.argv[1])
rig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rig)
rig.ROOT = Path(sys.argv[2])
print('waiting', flush=True)
with rig.exclusive_run():
    print('acquired', flush=True)
'''
        with tempfile.TemporaryDirectory() as directory, patch.object(rig, 'ROOT', Path(directory)):
            child = None
            try:
                with rig.exclusive_run():
                    child = subprocess.Popen([sys.executable, '-c', program, str(Path(__file__).with_name('test-rig.py')), directory],
                                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, bufsize=0)
                    self.assertEqual(b'waiting\n', child.stdout.readline())
                    self.assertEqual([], select.select([child.stdout], [], [], 0.2)[0])
                stdout, stderr = child.communicate(timeout=5)
                self.assertEqual(0, child.returncode, stderr.decode())
                self.assertEqual(b'acquired\n', stdout)
            finally:
                if child is not None and child.poll() is None:
                    child.kill()
                    child.communicate()

    def test_suite_dispatch_holds_lock_and_collector_acquires_it_without_parent_lock(self):
        events = []

        @contextmanager
        def locked():
            events.append('lock')
            try:
                yield
            finally:
                events.append('unlock')

        with patch.object(rig, 'exclusive_run', locked), patch.object(rig, 'dispatch', side_effect=lambda: events.append('dispatch')):
            with patch.object(sys, 'argv', ['test-rig.py', 'suite', '.']):
                rig.main()
            self.assertEqual(['lock', 'dispatch', 'unlock'], events)
            events.clear()
            with patch.object(sys, 'argv', ['test-rig.py', 'report']):
                rig.main()
            self.assertEqual(['dispatch'], events)

        spec = importlib.util.spec_from_file_location('acceptance', Path(__file__).with_name('acceptance-report.py'))
        acceptance = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(acceptance)
        loader = SimpleNamespace(exec_module=lambda module: None)
        fake_rig = SimpleNamespace(exclusive_run=locked)
        events.clear()
        with patch.object(acceptance.importlib.util, 'spec_from_file_location', return_value=SimpleNamespace(loader=loader)), \
             patch.object(acceptance.importlib.util, 'module_from_spec', return_value=fake_rig), \
             patch.object(acceptance, 'write_report', side_effect=lambda module: events.append('collect')):
            acceptance.main()
        self.assertEqual(['lock', 'collect', 'unlock'], events)

    def test_rejects_wrong_identity_before_docker_access(self):
        for key, value in [('DB_HOST', 'production'), ('DB_DATABASE', 'production'), ('DB_USERNAME', 'postgres'), ('DB_PORT', '1')]:
            with self.subTest(key=key), patch.dict(rig.os.environ, {key: value}), patch.object(rig, 'run') as run:
                with self.assertRaises(ValueError):
                    rig.preflight()
                run.assert_not_called()

    def test_rejects_missing_or_outside_test_file_before_preflight(self):
        with patch.object(rig, 'preflight') as preflight:
            for path in ['tests/no-such-test.php', 'composer.json', '../composer.json']:
                with self.assertRaises(ValueError):
                    rig.test(path)
            preflight.assert_not_called()

    def test_rejects_non_internal_network(self):
        network = [{'Internal': False, 'Labels': {'com.docker.compose.project': 'proxyma_test'}}]
        with patch.dict(rig.os.environ, {}, clear=True), patch.object(rig, 'run', return_value=SimpleNamespace(stdout=json.dumps(network))):
            with self.assertRaises(ValueError):
                rig.preflight()

    def test_rejects_empty_junit(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'tests').mkdir()
            (root / 'tests/EmptyTest.php').write_text('<?php')

            def fake_compose(*args, **kwargs):
                target = root / args[-1].removeprefix('/work/')
                target.write_text('<testsuites><testsuite tests="0"/></testsuites>')
                return SimpleNamespace(stdout='', stderr='')

            with patch.object(rig, 'ROOT', root), patch.object(rig, 'preflight'), patch.object(rig, 'compose', side_effect=fake_compose), patch.object(rig, 'source_fingerprint', return_value='test'), patch.object(rig, 'run', return_value=SimpleNamespace(stdout='sha')):
                with self.assertRaisesRegex(ValueError, 'zero tests'):
                    rig.test('tests/EmptyTest.php')

    def test_rejects_unmarked_volume(self):
        ids = {'db': 'a', 'fixture': 'b', 'runner': 'c'}
        network = {'Internal': True, 'Labels': {'com.docker.compose.project': 'proxyma_test'}, 'Containers': {key: {} for key in ids.values()}}
        container = {'NetworkSettings': {'Networks': {'proxyma_test_fixtures': {}}}, 'HostConfig': {'PortBindings': None}}
        volume = {'Labels': {'com.docker.compose.project': 'proxyma_test'}}

        def fake_run(args, **kwargs):
            data = network if args[1:3] == ['network', 'inspect'] else volume if args[1:3] == ['volume', 'inspect'] else container
            return SimpleNamespace(stdout=json.dumps([data]))

        def fake_compose(*args, **kwargs):
            if args[0] == 'ps':
                return SimpleNamespace(stdout=ids[args[-1]])
            raise subprocess.CalledProcessError(1, args)

        with patch.dict(rig.os.environ, {}, clear=True), patch.object(rig, 'run', side_effect=fake_run), patch.object(rig, 'compose', side_effect=fake_compose):
            with self.assertRaises(subprocess.CalledProcessError):
                rig.preflight()


if __name__ == '__main__':
    unittest.main()
