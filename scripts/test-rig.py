#!/usr/bin/env python3
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
COMPOSE = ['docker', 'compose', '--project-name', 'proxyma_test', '--file', str(ROOT / 'docker/compose.test.yml')]


def source_fingerprint():
    digest = hashlib.sha256()
    for directory in ('packages', 'tests', 'scripts', 'docker'):
        for path in sorted((ROOT / directory).rglob('*')):
            if path.is_file() and '__pycache__' not in path.parts:
                digest.update(str(path.relative_to(ROOT)).encode() + b'\0' + path.read_bytes() + b'\0')
    for name in ('composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', 'pint.json', 'Makefile'):
        digest.update(name.encode() + b'\0' + (ROOT / name).read_bytes() + b'\0')
    return digest.hexdigest()


def run(args, timeout=900, capture=False):
    return subprocess.run(args, cwd=ROOT, check=True, timeout=timeout, text=True, capture_output=capture)


def compose(*args, capture=False):
    return run(COMPOSE + list(args), capture=capture)


def preflight():
    for key, expected in {'DB_CONNECTION': 'pgsql', 'DB_HOST': 'db', 'DB_DATABASE': 'proxyma_test', 'DB_USERNAME': 'proxyma_test', 'DB_PORT': '5432', 'DB_URL': '', 'DATABASE_URL': ''}.items():
        if key in os.environ and os.environ[key] != expected:
            raise ValueError(f'unsafe {key}')
    network = json.loads(run(['docker', 'network', 'inspect', 'proxyma_test_fixtures'], capture=True).stdout)[0]
    if not network['Internal'] or network['Labels'].get('com.docker.compose.project') != 'proxyma_test':
        raise ValueError('unsafe fixture network')
    allowed_containers = set()
    for service in ('db', 'fixture', 'runner'):
        container_id = compose('ps', '-q', service, capture=True).stdout.strip()
        if not container_id or container_id not in network['Containers']:
            raise ValueError(f'{service} is not on fixture network')
        allowed_containers.add(container_id)
        container = json.loads(run(['docker', 'inspect', container_id], capture=True).stdout)[0]
        if set(container['NetworkSettings']['Networks']) != {'proxyma_test_fixtures'} or container['HostConfig']['PortBindings']:
            raise ValueError(f'{service} has an unsafe network or published port')
    if set(network['Containers']) != allowed_containers:
        raise ValueError('unexpected container on fixture network')
    volume = json.loads(run(['docker', 'volume', 'inspect', 'proxyma_test_db'], capture=True).stdout)[0]
    if volume['Labels'].get('com.docker.compose.project') != 'proxyma_test':
        raise ValueError('unsafe database volume')
    compose('exec', '-T', 'db', 'sh', '-c', 'test "$(cat /var/lib/postgresql/data/.proxyma-test-volume)" = proxyma_test_v1')
    compose('exec', '-T', 'runner', 'php', 'scripts/test-preflight.php')


def test_roots():
    return (ROOT / 'tests', ROOT / 'packages/core/tests', ROOT / 'packages/filament/tests')


def test(path):
    target = (ROOT / path).resolve()
    if not target.is_file() or not any(target.is_relative_to(root) for root in test_roots()) or target.suffix != '.php':
        raise ValueError('test-file requires an existing repository test PHP file')
    preflight()
    fingerprint = source_fingerprint()
    head = run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip()
    report = ROOT / 'build/junit' / head / fingerprint / (hashlib.sha256(path.encode()).hexdigest() + '.xml')
    report.parent.mkdir(parents=True, exist_ok=True)
    report.unlink(missing_ok=True)
    metadata = report.with_suffix('.json')
    metadata.unlink(missing_ok=True)
    compose('exec', '-T', 'runner', 'vendor/bin/pest', path, '--log-junit', '/work/' + str(report.relative_to(ROOT)))
    root = ET.parse(report).getroot()
    if not any(case.find('skipped') is None for case in root.findall('.//testcase')):
        raise ValueError('zero tests executed')
    if fingerprint != source_fingerprint() or head != run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip():
        raise ValueError('source changed during test')
    metadata.write_text(json.dumps({'git_sha': head, 'source_sha256': fingerprint, 'junit_sha256': hashlib.sha256(report.read_bytes()).hexdigest()}))


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'up':
        (ROOT / 'build').mkdir(exist_ok=True)
        compose('build', 'runner')
        compose('up', '-d', '--wait')
    elif cmd == 'env':
        preflight()
    elif cmd == 'file' and len(sys.argv) == 3:
        test(sys.argv[2])
    elif cmd == 'file-env':
        test(os.environ.get('TEST_FILE', ''))
    elif cmd == 'suite' and len(sys.argv) == 3:
        roots = [root if sys.argv[2] == '.' else root / sys.argv[2].capitalize() for root in test_roots()]
        files = sorted(path for root in roots if root.is_dir() for path in root.rglob('*Test.php'))
        if not files:
            raise ValueError('suite contains zero test files')
        for path in files:
            test(str(path.relative_to(ROOT)))
    elif cmd == 'report':
        sha = run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip()
        fingerprint = source_fingerprint()
        reports = sorted((ROOT / 'build/junit' / sha / fingerprint).glob('*.xml'))
        if not reports:
            raise ValueError('no JUnit reports')
        cases = []
        for report in reports:
            metadata = json.loads(report.with_suffix('.json').read_text())
            if metadata != {'git_sha': sha, 'source_sha256': fingerprint, 'junit_sha256': hashlib.sha256(report.read_bytes()).hexdigest()}:
                raise ValueError('stale or modified JUnit evidence')
            for case in ET.parse(report).getroot().findall('.//testcase'):
                cases.append({'name': case.get('name'), 'class': case.get('classname'), 'time': case.get('time'), 'report': str(report.relative_to(ROOT))})
        if not cases:
            raise ValueError('no executed test cases')
        hashes = {str(report.relative_to(ROOT)): hashlib.sha256(report.read_bytes()).hexdigest() for report in reports}
        dirty = bool(run(['git', 'status', '--porcelain'], capture=True).stdout.strip())
        (ROOT / 'build/test-report.json').write_text(json.dumps({'git_sha': sha, 'source_sha256': fingerprint, 'dirty_worktree': dirty, 'fixture_scope': 'proxyma_test internal network', 'junit_sha256': hashes, 'cases': cases}, indent=2) + '\n')
    else:
        raise ValueError('usage: test-rig.py up|env|file PATH|suite DIR|report')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, subprocess.SubprocessError, OSError, ET.ParseError) as error:
        print(f'test rig: {error}', file=sys.stderr)
        sys.exit(1)
