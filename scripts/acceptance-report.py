#!/usr/bin/env python3
"""Collect executed package qualification; never runs tests or fabricates results."""
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
MAP = 'tests/Architecture/qualification-map.json'
PACKAGE_ACCEPTANCE = 'tests/Contract/PackageAcceptanceTest.php'
PACKAGE_DATASETS = {
    'it public send использует safe extension, новый permit, trusted TLS и один fixture receipt': ('(false)', '(true)'),
    'it public send не обходит owner, tenant и исчерпанную quota': ("('owner')", "('tenant')", "('quota')"),
    'it public send сохраняет TLS verification без origin bytes и hidden retry': ("('wrong')", "('expired')", "('untrusted')"),
}
REQUIRED_INVARIANTS = {
    'P1.boundaries', 'P1.transactions', 'P2.catalog', 'P2.tenant', 'P2.policy', 'P2.routing',
    'audit.selection', 'audit.provisioning', 'audit.cooldown', 'audit.metadata', 'audit.transport',
    'P3.fifo', 'P3.gcra', 'P3.quota', 'P4.fencing', 'P4.locks', 'P4.recovery',
    'P5.destination', 'P5.credentials', 'P5.seal', 'P5.response', 'P5.executor',
    'P6.health', 'P6.probes', 'P6.operations', 'P7.extensions', 'P7.panels', 'P7.ui',
    'P8.install', 'P8.public',
}


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def finite_number(value, positive=False):
    number = float(value)
    if not math.isfinite(number) or number < 0 or (positive and number == 0):
        raise ValueError('invalid evidence number')
    return number


def collect(root, git_sha, fingerprint, expected, runtime):
    if not re.fullmatch('[a-f0-9]{40}', git_sha) or not re.fullmatch('[a-f0-9]{64}', fingerprint):
        raise ValueError('invalid source identity')
    directory = root / 'build/junit' / git_sha / fingerprint
    reports = sorted(directory.glob('*.xml'))
    if not reports:
        raise ValueError('no JUnit reports')
    cases, files, hashes, runs = [], set(), {}, []
    identities = set()
    for report in reports:
        metadata = json.loads(report.with_suffix('.json').read_text())
        test_file = metadata['test_file']
        digest = sha256(report)
        if (metadata['git_sha'], metadata['source_sha256'], metadata['junit_sha256']) != (git_sha, fingerprint, digest):
            raise ValueError('stale or modified JUnit evidence')
        if test_file not in expected or test_file in files:
            raise ValueError('unexpected or duplicate test file')
        relative_report = str(report.relative_to(root))
        command = ['vendor/bin/pest', test_file, '--log-junit', '/work/' + relative_report]
        if metadata['command'] != command or metadata['cwd'] != '/work' or metadata['exit'] != 0:
            raise ValueError('invalid execution receipt')
        if metadata['runtime'] != runtime:
            raise ValueError('runtime mismatch')
        if not metadata['started_at'] or not metadata['host_cwd'] or not report.with_suffix('.log').is_file():
            raise ValueError('missing execution context')
        finite_number(metadata['elapsed_seconds'], positive=True)
        receipt = metadata['fixture_receipts']
        if receipt is not None:
            receipt_path = root / receipt['path']
            if receipt_path != report.with_suffix('.wire.jsonl') or sha256(receipt_path) != receipt['sha256']:
                raise ValueError('modified fixture receipts')
            wire = [json.loads(line) for line in receipt_path.read_text().splitlines()]
            if not wire or any(entry['scope'] != 'isolated-loopback' or not isinstance(entry['events'], list) for entry in wire):
                raise ValueError('invalid fixture receipt scope')
        tree = ET.parse(report).getroot()
        if any(tree.findall('.//' + tag) for tag in ('failure', 'error', 'skipped', 'warning')):
            raise ValueError('failed or skipped JUnit scenario')
        actual = tree.findall('.//testcase')
        if not actual:
            raise ValueError('zero executed scenarios')
        declarations = re.findall(r"^\s*(it|test)\(\s*'((?:[^'\\]|\\.)*)'", (root / test_file).read_text(), re.MULTILINE)
        declared = {('it ' if kind == 'it' else '') + text.replace("\\'", "'").replace('\\\\', '\\') for kind, text in declarations}
        expected_names = None
        if test_file == PACKAGE_ACCEPTANCE:
            if not PACKAGE_DATASETS.keys() <= declared:
                raise ValueError('missing required dataset declarations')
            expected_names = set()
            for name in declared:
                if name in PACKAGE_DATASETS:
                    expected_names.update(name + ' with data set "' + variant + '"' for variant in PACKAGE_DATASETS[name])
                else:
                    expected_names.add(name)
        executed = set()
        executed_names = set()
        for case in actual:
            name, classname = case.get('name'), case.get('classname')
            identity = (classname, name)
            if not name or not classname or identity in identities:
                raise ValueError('missing or duplicate scenario identity')
            if case.get('file', '').split('::')[0] != test_file:
                raise ValueError('JUnit source file mismatch')
            base = name.split(' with data set ')[0]
            if base not in declared:
                raise ValueError('undeclared scenario identity')
            if expected_names is not None and (name not in expected_names or name in executed_names):
                raise ValueError('unexpected full scenario identity')
            executed.add(base)
            executed_names.add(name)
            assertions = int(case.get('assertions', '0'))
            if assertions <= 0:
                raise ValueError('zero assertions')
            duration = finite_number(case.get('time', ''))
            identities.add(identity)
            cases.append({'id': classname + '::' + name, 'name': name, 'class': classname,
                          'file': test_file, 'assertions': assertions, 'time': duration, 'report': relative_report})
        if executed != declared:
            raise ValueError('missing declared scenarios')
        if expected_names is not None and executed_names != expected_names:
            raise ValueError('missing required dataset scenarios')
        files.add(test_file)
        hashes[relative_report] = digest
        runs.append({**metadata, 'log': str(report.with_suffix('.log').relative_to(root)),
                     'log_sha256': sha256(report.with_suffix('.log'))})
    if files != set(expected):
        raise ValueError('missing package test files: ' + ', '.join(sorted(set(expected) - files)))
    mapping = json.loads((root / MAP).read_text())
    donor = json.loads((root / 'docs/reference/source-manifest.json').read_text())
    if mapping['schema'] != 'proxyma-qualification-map/v1' or mapping['baseline'] != donor['commit']:
        raise ValueError('qualification provenance mismatch')
    donor_files = {item['path']: item['sha256'] for item in donor['files']}
    matrix, invariant_ids = [], set()
    for item in mapping['invariants']:
        if item['id'] in invariant_ids or item['evolution'] not in ('inherited', 'added', 'strengthened'):
            raise ValueError('invalid invariant identity or provenance')
        invariant_ids.add(item['id'])
        selected = [case['id'] for case in cases if case['file'] in item['tests']]
        if not item['tests'] or not set(item['tests']) <= files or not selected:
            raise ValueError('unexecuted invariant: ' + item['id'])
        sources = {}
        for path in item['sources']:
            candidate = root / path
            if not candidate.resolve().is_relative_to(root.resolve()):
                raise ValueError('source path escape')
            paths = sorted(candidate.rglob('*.php')) if candidate.is_dir() else [candidate]
            if not paths:
                raise ValueError('missing invariant source')
            sources.update({str(source.relative_to(root)): sha256(source) for source in paths})
        if not sources:
            raise ValueError('empty invariant source set')
        baseline = {path: donor_files[path] for path in item.get('donor_paths', [])}
        if item['evolution'] != 'added' and not baseline:
            raise ValueError('missing inherited source provenance')
        matrix.append({**item, 'git_sha': git_sha, 'source_sha256': sources,
                       'test_sha256': {path: sha256(root / path) for path in item['tests']},
                       'executed_ids': selected, 'donor_commit': donor['commit'], 'donor_sha256': baseline})
    if invariant_ids != REQUIRED_INVARIANTS:
        raise ValueError('incomplete invariant inventory')
    return {'git_sha': git_sha, 'source_sha256': fingerprint, 'runtime': runtime,
            'fixture_scope': 'proxyma_test internal network; local fixtures only; no throughput claim',
            'junit_sha256': hashes, 'cases': cases, 'runs': runs, 'matrix': matrix,
            'mapping_sha256': sha256(root / MAP)}


def main():
    spec = importlib.util.spec_from_file_location('rig', ROOT / 'scripts/test-rig.py')
    rig = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(rig)
    with rig.exclusive_run():
        write_report(rig)


def write_report(rig):
    rig.preflight()
    git_sha = rig.run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip()
    fingerprint = rig.source_fingerprint()
    expected = {str(path.relative_to(ROOT)) for path in rig.suite_files('.')}
    result = collect(ROOT, git_sha, fingerprint, expected, rig.runtime_identity())
    result['dirty_worktree'] = bool(rig.run(['git', 'status', '--porcelain'], capture=True).stdout.strip())
    if fingerprint != rig.source_fingerprint() or git_sha != rig.run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip():
        raise ValueError('source changed during collection')
    output = ROOT / 'build/test-report.json'
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(f'{output.relative_to(ROOT)}: {len(result["cases"])} executed scenarios, {len(result["matrix"])} invariants')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, ET.ParseError) as error:
        print(f'acceptance report: {error}', file=sys.stderr)
        sys.exit(1)
