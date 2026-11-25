import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location('proxyma_test_rig', ROOT / 'scripts/test-rig.py')
RIG = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RIG)


class TestRigDiscoveryTest(unittest.TestCase):
    def test_filament_dispatches_package_tests_without_running_them(self):
        with patch.object(sys, 'argv', ['test-rig.py', 'suite', 'filament']), patch.object(RIG, 'test') as run_test:
            RIG.main()

        paths = [ROOT / call.args[0] for call in run_test.call_args_list]
        self.assertEqual({
            ROOT / 'packages/filament/tests' / name for name in (
                'BindingEditorTest.php', 'CatalogAuthorizationTest.php', 'EmbeddedSettingsTest.php',
                'OperationsPagesTest.php', 'RouteProfilesTest.php',
            )
        }, set(paths))
        self.assertEqual(paths, sorted(set(paths)))
        self.assertTrue(all(path.is_file() for path in paths))

    def test_empty_filament_package_fails_without_dispatching_other_roots(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for other in ('tests/Filament', 'packages/core/tests/Filament'):
                decoy = root / other / 'OtherTest.php'
                decoy.parent.mkdir(parents=True)
                decoy.write_text('<?php')

            with patch.object(RIG, 'ROOT', root), patch.object(sys, 'argv', ['test-rig.py', 'suite', 'filament']), patch.object(RIG, 'test') as run_test:
                with self.assertRaisesRegex(ValueError, 'zero test files'):
                    RIG.main()
                run_test.assert_not_called()

    def test_transport_dispatches_existing_contract_files_without_running_them(self):
        with patch.object(sys, 'argv', ['test-rig.py', 'suite', 'transport']), patch.object(RIG, 'test') as run_test:
            RIG.main()

        paths = [ROOT / call.args[0] for call in run_test.call_args_list]
        self.assertTrue(paths)
        self.assertEqual(len(paths), len(set(paths)))
        self.assertTrue(all(path.is_file() for path in paths))
        self.assertTrue({
            'PhysicalTransportTest.php', 'DestinationPolicyTest.php', 'TlsPolicyTest.php',
            'SealedWireTest.php', 'DispatchDeadlineTest.php', 'BoundedResponseTest.php',
            'ArtifactOwnershipTest.php', 'SecretRotationTest.php',
            'ExecutorPolicyTest.php', 'RedirectPolicyTest.php',
        }.issubset({path.name for path in paths}))

    def test_unknown_suite_still_fails_without_dispatch(self):
        with patch.object(sys, 'argv', ['test-rig.py', 'suite', 'missing']), patch.object(RIG, 'test') as run_test:
            with self.assertRaisesRegex(ValueError, 'zero test files'):
                RIG.main()
            run_test.assert_not_called()

    def test_missing_required_transport_file_fails_before_dispatch(self):
        with patch.object(sys, 'argv', ['test-rig.py', 'suite', 'transport']), patch.object(RIG, 'test') as run_test:
            with patch.object(Path, 'is_file', return_value=False):
                with self.assertRaisesRegex(ValueError, 'missing required contract test files'):
                    RIG.main()
            run_test.assert_not_called()


if __name__ == '__main__':
    unittest.main()
