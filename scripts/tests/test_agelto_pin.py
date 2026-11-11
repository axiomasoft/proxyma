"""Отказы re-pin до мутаций и сохранение исторических hashes, без Composer/network."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]


def load(filename, name):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


PIN = load('scripts/agelto-pin.py', 'target_pin')
SUPPLY = load('scripts/package-check.py', 'pin_test_supply')


class PinTest(unittest.TestCase):
    def test_не_начинает_git_для_floating_или_placeholder_SHA(self):
        with patch.object(PIN, 'module', return_value=SUPPLY), patch.object(SUPPLY, 'git') as git:
            for commit in ('HEAD', 'main', 'a' * 7, '0' * 40):
                with self.subTest(commit=commit), self.assertRaises(ValueError):
                    PIN.pin(Path('/unused'), commit)
            git.assert_not_called()

    def test_dirty_vendor_отклоняется_до_fetch_checkout_и_записи_lock(self):
        vendor = Mock()
        vendor.load.return_value = [{'key': 'proxyma'}]
        vendor.check_checkout.side_effect = ValueError('dirty checkout')
        with patch.object(PIN, 'module', side_effect=[SUPPLY, vendor]), \
                patch.object(SUPPLY, 'git', side_effect=[b'https://github.com/axiomasoft/agelto.git', b'']) as git:
            with self.assertRaisesRegex(ValueError, 'dirty checkout'):
                PIN.pin(Path('/unused'), 'a' * 40)
            self.assertEqual(2, git.call_count)

    def test_обновляет_только_затронутые_refs_и_сохраняет_original_hashes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            folder = root / 'tests/Fixtures/ProcessCore'
            (folder / 'Llm').mkdir(parents=True)
            (root / 'composer.lock').write_text('new locked input')
            entry = {'path': 'composer.lock', 'sha256': 'old', 'original_sha256': 'historical'}
            ai = folder / 'Llm/acceptance-v1.json'
            ai.write_text(json.dumps({'inputs': [dict(entry)]}))
            manifest = {group: [] for group in ('source_classes', 'migrations', 'contract_fixtures', 'files')}
            manifest['files'] = [dict(entry), {'path': 'untouched', 'sha256': 'unchanged'}]
            manifest['contract_fixtures'] = [{'path': str(ai.relative_to(root)), 'sha256': 'old'}]
            path = folder / 'manifest.json'
            path.write_text(json.dumps(manifest))
            PIN.refresh_references(root, {'composer.lock'})
            result = json.loads(path.read_text())
            self.assertEqual('historical', result['files'][0]['original_sha256'])
            self.assertEqual('unchanged', result['files'][1]['sha256'])
            self.assertNotEqual('old', result['files'][0]['sha256'])
            self.assertEqual(PIN.hashlib.sha256(ai.read_bytes()).hexdigest(),
                             result['contract_fixtures'][0]['sha256'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
