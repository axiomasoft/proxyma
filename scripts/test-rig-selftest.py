import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('rig', Path(__file__).with_name('test-rig.py'))
rig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rig)


class RigGuardTest(unittest.TestCase):
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
