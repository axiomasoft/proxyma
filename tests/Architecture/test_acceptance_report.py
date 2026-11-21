import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

SPEC = importlib.util.spec_from_file_location('acceptance', Path(__file__).resolve().parents[2] / 'scripts/acceptance-report.py')
REPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(REPORT)
RIG_SPEC = importlib.util.spec_from_file_location('rig', Path(__file__).resolve().parents[2] / 'scripts/test-rig.py')
RIG = importlib.util.module_from_spec(RIG_SPEC)
RIG_SPEC.loader.exec_module(RIG)


class AcceptanceReportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        inventory = patch.object(REPORT, 'REQUIRED_INVARIANTS', {'authority'})
        inventory.start()
        self.addCleanup(inventory.stop)
        self.root = Path(self.temp.name)
        self.sha = 'a' * 40
        self.fingerprint = 'b' * 64
        self.test = 'tests/Contract/ExampleTest.php'
        self.runtime = {'php': '8.4', 'postgres': '17', 'curl': '8', 'ssl': 'OpenSSL', 'images': {'runner': 'sha256:fixture'}}
        self.xml = '<testsuites><testsuite><testcase classname="Example" name="it denies" file="tests/Contract/ExampleTest.php::it denies" assertions="2" time="0.2"/></testsuite></testsuites>'
        self.path = self.root / 'build/junit' / self.sha / self.fingerprint / 'example.xml'
        self.path.parent.mkdir(parents=True)
        self.metadata = {'git_sha': self.sha, 'source_sha256': self.fingerprint, 'junit_sha256': '',
                         'test_file': self.test, 'command': ['vendor/bin/pest', self.test, '--log-junit', '/work/' + str(self.path.relative_to(self.root))],
                         'cwd': '/work', 'host_cwd': '/fixture', 'exit': 0, 'started_at': '2026-11-10T00:00:00+00:00',
                         'elapsed_seconds': 0.3, 'runtime': self.runtime, 'fixture_receipts': None}
        self.mapping = {'schema': 'proxyma-qualification-map/v1',
                        'invariants': [{'id': 'authority', 'evolution': 'added', 'tests': [self.test], 'sources': ['packages/core/src/Example.php']}]}
        for name, content in [(self.test, "<?php\nit('denies', function () {});"), ('packages/core/src/Example.php', '<?php')]:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        (self.root / REPORT.MAP).parent.mkdir(parents=True, exist_ok=True)

    def collect(self, expected=None):
        self.path.write_text(self.xml)
        self.metadata['junit_sha256'] = hashlib.sha256(self.path.read_bytes()).hexdigest()
        self.path.with_suffix('.json').write_text(json.dumps(self.metadata))
        self.path.with_suffix('.log').write_text('actual runner output')
        (self.root / REPORT.MAP).write_text(json.dumps(self.mapping))
        return REPORT.collect(self.root, self.sha, self.fingerprint, expected or {self.test}, self.runtime)

    def test_declares_scenarios_contributed_by_a_used_trait(self):
        (self.root / self.test).write_text("<?php\nnamespace Tests;\nuse Fixture\\Holds;\nclass ExampleTest {\n    use Holds;\n}\n")
        trait = self.root / 'packages/fixture/Holds.php'
        trait.parent.mkdir(parents=True)
        trait.write_text("<?php\nnamespace Fixture;\ntrait Holds { public function test_example_holds(): void {} }\n")
        self.xml = self.xml.replace('name="it denies"', 'name="Example holds" class="Tests\\ExampleTest"')
        self.xml = self.xml.replace(
            'file="tests/Contract/ExampleTest.php::it denies"',
            'file="Holds (Tests\\Example)::Example holds"',
        )
        result = self.collect()
        self.assertEqual(['Example::Example holds'], result['matrix'][0]['executed_ids'])

    def test_accepts_phpunit_description_when_the_class_lives_in_the_executed_file(self):
        (self.root / self.test).write_text("<?php\nnamespace Tests;\nclass ExampleTest {}\nit('denies', function () {});\n")
        self.xml = self.xml.replace(
            'file="tests/Contract/ExampleTest.php::it denies"',
            'file="Example conformance (Tests\\ExampleTest)::it denies" class="Tests\\ExampleTest"',
        )
        result = self.collect()
        self.assertEqual('it denies', result['cases'][0]['name'])
        self.xml = self.xml.replace(
            'file="Example conformance (Tests\\ExampleTest)::it denies"',
            'file="tests/Contract/OtherTest.php::it denies"',
        )
        with self.assertRaisesRegex(ValueError, 'JUnit source file mismatch'):
            self.collect()

    def test_rejects_a_phpunit_class_from_a_different_namespace(self):
        (self.root / self.test).write_text("<?php\nnamespace Other;\nclass ExampleTest {}\nit('denies', function () {});\n")
        self.xml = self.xml.replace('name="it denies"', 'name="it denies" class="Tests\\ExampleTest"')
        self.xml = self.xml.replace('file="tests/Contract/ExampleTest.php::it denies"',
                                    'file="Example (Tests\\Example)::it denies"')
        with self.assertRaisesRegex(ValueError, 'JUnit source file mismatch'):
            self.collect()

    def test_records_exact_executed_identity_assertions_digests_and_context(self):
        result = self.collect()
        self.assertEqual(['Example::it denies'], result['matrix'][0]['executed_ids'])
        self.assertEqual(2, result['cases'][0]['assertions'])
        self.assertEqual(self.sha, result['matrix'][0]['git_sha'])
        self.assertEqual(self.metadata, {k: v for k, v in result['runs'][0].items() if k not in ('log', 'log_sha256')})
        self.assertEqual(hashlib.sha256(b'<?php').hexdigest(), result['matrix'][0]['source_sha256']['packages/core/src/Example.php'])

    def test_rejects_missing_full_suite_file(self):
        with self.assertRaisesRegex(ValueError, 'missing package test files'):
            self.collect({self.test, 'tests/MissingTest.php'})
        vendor = self.root / 'tests/Fixtures/Consumers/custom/vendor/dependency/VendorTest.php'
        vendor.parent.mkdir(parents=True)
        vendor.write_text('<?php')
        with patch.object(RIG, 'ROOT', self.root), patch.object(RIG, 'preflight') as preflight:
            self.assertEqual([self.root / self.test], RIG.suite_files('.'))
            self.assertFalse(RIG.owned_source(vendor))
            self.assertTrue(RIG.owned_source(self.root / self.test))
            with self.assertRaises(ValueError):
                RIG.test(str(vendor.relative_to(self.root)))
            preflight.assert_not_called()

    def test_rejects_stale_metadata_command_exit_cwd_runtime_and_timing(self):
        original = copy.deepcopy(self.metadata)
        for field, value in [('git_sha', 'd' * 40), ('source_sha256', 'e' * 64), ('command', ['true']),
                             ('exit', 1), ('cwd', '/tmp'), ('runtime', {}), ('elapsed_seconds', float('nan')),
                             ('elapsed_seconds', -1), ('started_at', '')]:
            with self.subTest(field=field, value=value):
                self.metadata = {**original, field: value}
                with self.assertRaises(ValueError):
                    self.collect()

    def test_rejects_bad_junit_outcomes_identity_assertions_and_duration(self):
        original = self.xml
        variants = [original.replace('/>', f'><{tag}/></testcase>') for tag in ('failure', 'error', 'skipped', 'warning')]
        variants += [original.replace('name="it denies"', 'name="it invented"'), original.replace('assertions="2"', 'assertions="0"'), original.replace('time="0.2"', 'time="NaN"'),
                     original.replace('classname="Example"', 'classname=""'), original.replace(self.test + '::', 'tests/FakeTest.php::'),
                     '<testsuites/>', original.replace('</testsuite>', original.split('<testsuite>')[1].split('</testsuite>')[0] + '</testsuite>')]
        for xml in variants:
            with self.subTest(xml=xml), self.assertRaises(ValueError):
                self.xml = xml
                self.collect()

        self.test = 'tests/Contract/PackageAcceptanceTest.php'
        source = Path(__file__).resolve().parents[2] / self.test
        target = self.root / self.test
        target.write_text(source.read_text())
        self.metadata['test_file'] = self.test
        self.metadata['command'][1] = self.test
        self.mapping['invariants'][0]['tests'] = [self.test]
        names = [
            'it public send использует safe extension, новый permit, trusted TLS и один fixture receipt with data set "(false)"',
            'it public send использует safe extension, новый permit, trusted TLS и один fixture receipt with data set "(true)"',
            'it public send не обходит owner, tenant и исчерпанную quota with data set "(\'owner\')"',
            'it public send не обходит owner, tenant и исчерпанную quota with data set "(\'tenant\')"',
            'it public send не обходит owner, tenant и исчерпанную quota with data set "(\'quota\')"',
            'it public send сохраняет TLS verification без origin bytes и hidden retry with data set "(\'wrong\')"',
            'it public send сохраняет TLS verification без origin bytes и hidden retry with data set "(\'expired\')"',
            'it public send сохраняет TLS verification без origin bytes и hidden retry with data set "(\'untrusted\')"',
            'it public send удерживает unknown и consumed quota после transport exception и prune',
            'it qualification collector отвергает неполные, stale, duplicate и failed evidence',
        ]

        def junit(executed):
            tree = ET.Element('testsuites')
            suite = ET.SubElement(tree, 'testsuite')
            for name in executed:
                ET.SubElement(suite, 'testcase', classname='PackageAcceptance', name=name,
                              file=self.test + '::' + name, assertions='2', time='0.2')
            return ET.tostring(tree, encoding='unicode')

        self.xml = junit(names)
        self.assertEqual(10, len(self.collect()['cases']))
        for index, name in enumerate(names):
            with self.subTest(missing=name), self.assertRaisesRegex(ValueError, 'missing .*scenarios'):
                self.xml = junit(names[:index] + names[index + 1:])
                self.collect()
            with self.subTest(invented_dataset=name), self.assertRaisesRegex(ValueError, 'unexpected .*identity'):
                invented = name.split(' with data set ')[0] + ' with data set "invented"'
                self.xml = junit(names[:index] + [invented] + names[index + 1:])
                self.collect()
        with self.subTest(owner_only=True), self.assertRaisesRegex(ValueError, 'missing .*scenarios'):
            self.xml = junit([name for name in names if "('tenant')" not in name and "('quota')" not in name])
            self.collect()

    def test_rejects_duplicate_reports_and_changed_junit_digest(self):
        self.collect()
        other = self.path.with_name('duplicate.xml')
        other.write_text(self.xml)
        other.with_suffix('.json').write_text(json.dumps(self.metadata))
        with self.assertRaises(ValueError):
            REPORT.collect(self.root, self.sha, self.fingerprint, {self.test}, self.runtime)
        other.unlink()
        self.path.write_text(self.xml + ' ')
        with self.assertRaisesRegex(ValueError, 'stale or modified'):
            REPORT.collect(self.root, self.sha, self.fingerprint, {self.test}, self.runtime)

    def test_rejects_unexecuted_mapping_missing_provenance_and_duplicate_invariant(self):
        original = copy.deepcopy(self.mapping['invariants'][0])
        for field, value in [('tests', ['tests/AbsentTest.php']), ('sources', []), ('evolution', 'invented')]:
            with self.subTest(field=field):
                self.mapping['invariants'] = [{**original, field: value}]
                with self.assertRaises(ValueError):
                    self.collect()
        self.mapping['invariants'] = [original, original]
        with self.assertRaises(ValueError):
            self.collect()
        self.mapping['invariants'] = []
        with self.assertRaisesRegex(ValueError, 'incomplete invariant inventory'):
            self.collect()
        self.mapping['invariants'] = [original]
        self.mapping['schema'] = 'other'
        with self.assertRaisesRegex(ValueError, 'provenance mismatch'):
            self.collect()
        self.mapping['schema'] = 'proxyma-qualification-map/v1'
        self.metadata['fixture_receipts'] = {'path': str(self.path.with_suffix('.wire.jsonl').relative_to(self.root)), 'sha256': 'f' * 64}
        self.path.with_suffix('.wire.jsonl').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'modified fixture receipts'):
            self.collect()
        self.metadata['fixture_receipts'] = None
        with (self.root / self.test).open('a') as stream:
            stream.write("\nit('missing declaration', function () {});")
        with self.assertRaisesRegex(ValueError, 'missing declared scenarios'):
            self.collect()


if __name__ == '__main__':
    unittest.main()
