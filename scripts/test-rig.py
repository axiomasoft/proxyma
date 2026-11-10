#!/usr/bin/env python3
import hashlib
import fcntl
from contextlib import contextmanager
import json
import os
from pathlib import Path
import subprocess
import sys
import time
from datetime import datetime, timezone
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
COMPOSE = ['docker', 'compose', '--project-name', 'proxyma_test', '--file', str(ROOT / 'docker/compose.test.yml')]


@contextmanager
def exclusive_run():
    directory = ROOT / 'build'
    directory.mkdir(exist_ok=True)
    with (directory / '.rig.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(lock, fcntl.LOCK_UN)


def owned_source(path):
    return not {'vendor', 'node_modules', '__pycache__'}.intersection(path.relative_to(ROOT).parts)


def source_fingerprint():
    digest = hashlib.sha256()
    for directory in ('packages', 'tests', 'scripts', 'docker'):
        for path in sorted((ROOT / directory).rglob('*')):
            if owned_source(path) and path.is_file():
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


def suite_files(name):
    if name == 'filament':
        return sorted(path for path in (ROOT / 'packages/filament/tests').rglob('*Test.php') if owned_source(path))
    if name == 'transport':
        contract = ROOT / 'packages/core/tests/Contract'
        names = (
            'DestinationPolicyTest.php', 'TlsPolicyTest.php', 'SecretRotationTest.php',
            'SealedWireTest.php', 'PhysicalTransportTest.php', 'DispatchDeadlineTest.php',
            'BoundedResponseTest.php', 'ArtifactOwnershipTest.php',
            'ExecutorPolicyTest.php', 'RedirectPolicyTest.php',
        )
        files = [contract / filename for filename in names]
        if not all(path.is_file() for path in files):
            raise ValueError('transport suite is missing required contract test files')
        return files
    roots = [root if name == '.' else root / name.capitalize() for root in test_roots()]
    return sorted(path for root in roots if root.is_dir() for path in root.rglob('*Test.php') if owned_source(path))


def runtime_identity():
    php = json.loads(compose('exec', '-T', 'runner', 'php', '-r',
        'echo json_encode(["php"=>PHP_VERSION,"memory_limit"=>ini_get("memory_limit"),"curl"=>curl_version()["version"],"ssl"=>curl_version()["ssl_version"],"extensions"=>get_loaded_extensions()]);', capture=True).stdout)
    php['postgres'] = compose('exec', '-T', 'db', 'psql', '-U', 'proxyma_test', '-d', 'proxyma_test', '-Atqc', 'SHOW server_version', capture=True).stdout.strip()
    php['images'] = {}
    for service in ('runner', 'db', 'fixture'):
        container = compose('ps', '-q', service, capture=True).stdout.strip()
        php['images'][service] = run(['docker', 'inspect', '--format', '{{.Image}}', container], capture=True).stdout.strip()
    php['composer_lock_sha256'] = hashlib.sha256((ROOT / 'composer.lock').read_bytes()).hexdigest()
    return php


def test(path):
    target = (ROOT / path).resolve()
    if not target.is_file() or not any(target.is_relative_to(root) for root in test_roots()) or target.suffix != '.php' or not owned_source(target):
        raise ValueError('test-file requires an existing repository test PHP file')
    preflight()
    fingerprint = source_fingerprint()
    head = run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip()
    report = ROOT / 'build/junit' / head / fingerprint / (hashlib.sha256(path.encode()).hexdigest() + '.xml')
    report.parent.mkdir(parents=True, exist_ok=True)
    report.unlink(missing_ok=True)
    metadata = report.with_suffix('.json')
    metadata.unlink(missing_ok=True)
    command = ['vendor/bin/pest', path, '--log-junit', '/work/' + str(report.relative_to(ROOT))]
    wire_log = ROOT / 'build/p53-wire.jsonl'
    wire_offset = wire_log.stat().st_size if wire_log.exists() else 0
    started = datetime.now(timezone.utc).isoformat()
    clock = time.monotonic()
    try:
        result = compose('exec', '-T', 'runner', *command, capture=True)
    except subprocess.CalledProcessError as error:
        report.with_suffix('.log').write_text((error.stdout or '') + (error.stderr or ''))
        raise
    report.with_suffix('.log').write_text(result.stdout + result.stderr)
    print(result.stdout, end='')
    print(result.stderr, end='', file=sys.stderr)
    root = ET.parse(report).getroot()
    if not any(case.find('skipped') is None for case in root.findall('.//testcase')):
        raise ValueError('zero tests executed')
    if fingerprint != source_fingerprint() or head != run(['git', 'rev-parse', 'HEAD'], capture=True).stdout.strip():
        raise ValueError('source changed during test')
    receipts = None
    if wire_log.exists():
        with wire_log.open('rb') as stream:
            stream.seek(wire_offset)
            wire_bytes = stream.read()
        if wire_bytes:
            receipt_file = report.with_suffix('.wire.jsonl')
            receipt_file.write_bytes(wire_bytes)
            receipts = {'path': str(receipt_file.relative_to(ROOT)), 'sha256': hashlib.sha256(wire_bytes).hexdigest()}
    metadata.write_text(json.dumps({'git_sha': head, 'source_sha256': fingerprint,
        'junit_sha256': hashlib.sha256(report.read_bytes()).hexdigest(), 'test_file': path,
        'command': command, 'cwd': '/work', 'host_cwd': str(ROOT), 'exit': 0,
        'started_at': started, 'elapsed_seconds': time.monotonic() - clock,
        'runtime': runtime_identity(), 'fixture_receipts': receipts}, indent=2) + '\n')


def dispatch():
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
        files = suite_files(sys.argv[2])
        if not files:
            raise ValueError('suite contains zero test files')
        for path in files:
            test(str(path.relative_to(ROOT)))
    elif cmd == 'report':
        run([sys.executable, str(ROOT / 'scripts/acceptance-report.py')])
    else:
        raise ValueError('usage: test-rig.py up|env|file PATH|suite DIR|report')


def main():
    # Collector получает тот же lock сам, без вложенного flock через subprocess.
    if len(sys.argv) > 1 and sys.argv[1] == 'report':
        return dispatch()
    with exclusive_run():
        return dispatch()


if __name__ == '__main__':
    try:
        main()
    except (ValueError, subprocess.SubprocessError, OSError, ET.ParseError) as error:
        print(f'test rig: {error}', file=sys.stderr)
        sys.exit(1)
