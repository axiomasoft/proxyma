#!/usr/bin/env python3
"""Приватная поставка: qualification → candidate → pinned candidate → accepted."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
SCHEMA = 'proxyma-private-supply/v1'
PACKAGES = {'axiomasoft/proxyma': 'packages/core',
            'axiomasoft/proxyma-filament': 'packages/filament'}
VERSION = '0.1.0'
ROOT_INPUTS = ('composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', 'pint.json', 'Makefile')
DELIVERY_INPUTS = {'packages/filament/composer.json', 'packages/core/CONTEXT.md',
                   'scripts/package-check.py'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def php_serialize(value):
    if isinstance(value, bool):
        return b'b:1;' if value else b'b:0;'
    if isinstance(value, str):
        raw = value.encode()
        return b's:' + str(len(raw)).encode() + b':"' + raw + b'";'
    require(isinstance(value, dict), 'unsupported Composer path option')
    return b'a:' + str(len(value)).encode() + b':{' + b''.join(
        php_serialize(k) + php_serialize(v) for k, v in value.items()) + b'}'


def path_reference(manifest, options):
    # Composer 2.8 PathRepository добавляет relative до hash(json + serialize(options)).
    options = dict(options)
    options.setdefault('relative', True)
    return hashlib.sha1(manifest + php_serialize(options)).hexdigest()


def sha(value):
    require(isinstance(value, str) and re.fullmatch(r'[0-9a-f]{40}', value)
            and value != '0' * 40, 'expected a real full commit SHA')
    return value


def relative(value):
    require(isinstance(value, str) and value != '' and '\\' not in value, 'invalid relative path')
    path = PurePosixPath(value)
    require(not path.is_absolute() and '..' not in path.parts and str(path) == value,
            'path traversal or noncanonical path')
    return value


def local(root, value):
    path = root / relative(value)
    require(path.resolve().is_relative_to(root.resolve()), 'path escapes evidence root')
    require(not any(p.is_symlink() for p in [path, *path.parents] if p != root.parent),
            'symlink is not copy proof')
    return path


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args], stderr=subprocess.PIPE)


def tree(root, commit):
    sha(commit)
    entries = git(root, 'ls-tree', '-rz', commit).split(b'\0')
    result = {}
    for entry in filter(None, entries):
        meta, name = entry.split(b'\t', 1)
        mode, kind, oid = meta.split()
        path = name.decode()
        if path.startswith(('packages/', 'tests/', 'scripts/', 'docker/')) or path in ROOT_INPUTS:
            require(mode in (b'100644', b'100755') and kind == b'blob', 'non-file source: ' + path)
            if not {'vendor', 'node_modules', '__pycache__'}.intersection(PurePosixPath(path).parts):
                result[path] = git(root, 'cat-file', 'blob', oid.decode())
    return result


def fingerprint(files):
    ordered = []
    for directory in ('packages', 'tests', 'scripts', 'docker'):
        ordered.extend(sorted((p for p in files if p.startswith(directory + '/')), key=PurePosixPath))
    ordered.extend(ROOT_INPUTS)
    return digest(b''.join(p.encode() + b'\0' + files[p] + b'\0' for p in ordered))


def worktree(root):
    files = {}
    for directory in ('packages', 'tests', 'scripts', 'docker'):
        for path in (root / directory).rglob('*'):
            if not {'vendor', 'node_modules', '__pycache__'}.intersection(path.relative_to(root).parts) and path.is_file():
                name = str(path.relative_to(root))
                files[name] = local(root, name).read_bytes()
    files.update({p: local(root, p).read_bytes() for p in ROOT_INPUTS})
    return files


def payload(files, prefix):
    selected = sorted(p for p in files if p.startswith(prefix + '/'))
    require(bool(selected), 'empty package payload')
    return digest(b''.join(p.encode() + b'\0' + files[p] + b'\0' for p in selected))


def manifests(files):
    values = []
    for name, path in PACKAGES.items():
        raw = files[path + '/composer.json']
        manifest = json.loads(raw)
        require(manifest['name'] == name and manifest['type'] == 'library', 'wrong package identity')
        require('version' not in manifest, 'use the explicit path version map')
        require(manifest['require']['php'] == '^8.4', 'unqualified PHP constraint')
        if name.endswith('-filament'):
            require(manifest['require']['axiomasoft/proxyma'] == 'self.version',
                    'UI must require the same mapped version of core')
            require(manifest['require']['filament/filament'] == '^5.0', 'unqualified Filament constraint')
        else:
            require(not any(p.startswith('filament/') for p in manifest['require']), 'core depends on UI')
            for extension in ('curl', 'mbstring', 'openssl', 'pdo', 'pdo_pgsql'):
                require('ext-' + extension in manifest['require'], 'missing runtime extension')
        values.append({'name': name, 'path': path, 'version': VERSION,
                       'manifest_sha256': digest(raw), 'payload_sha256': payload(files, path)})
    return values


def reference(root, path):
    return {'path': relative(path), 'sha256': digest(local(root, path).read_bytes())}


def read_reference(root, ref):
    require(set(ref) == {'path', 'sha256'}, 'invalid evidence reference')
    raw = local(root, ref['path']).read_bytes()
    require(digest(raw) == ref['sha256'], 'stale or modified evidence: ' + ref['path'])
    return json.loads(raw)


def module(root, name, filename):
    spec = importlib.util.spec_from_file_location(name, root / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def qualification(root, ref, source_commit):
    report = read_reference(root, ref)
    base = tree(root, source_commit)
    require(fingerprint(base) == report['source_sha256'], 'qualification source commit/digest mismatch')
    sha(report['git_sha'])
    # Report HEAD может предшествовать commit проверенного dirty tree: fingerprint обязан совпасть.
    require(git(root, 'merge-base', '--is-ancestor', report['git_sha'], source_commit) == b'',
            'qualification HEAD is not an ancestor')
    for matrix in report['matrix']:
        for key in ('source_sha256', 'test_sha256'):
            require(bool(matrix[key]), 'empty qualification source proof')
            for name, expected in matrix[key].items():
                require(digest(base[name]) == expected, 'qualification source digest mismatch: ' + name)
    collector = module(root, 'private_supply_collector', 'scripts/acceptance-report.py')
    rig = module(root, 'private_supply_rig', 'scripts/test-rig.py')
    expected = {str(p.relative_to(root)) for p in rig.suite_files('.')}
    actual = collector.collect(root, report['git_sha'], report['source_sha256'], expected, report['runtime'])
    require(all(report[key] == value for key, value in actual.items()), 'incomplete or stale qualification report')
    return base


def candidate(root, report_path, source_commit):
    ref = reference(root, report_path)
    base = qualification(root, ref, source_commit)
    files = worktree(root)
    check_delivery_delta(base, files)
    return {'schema': SCHEMA, 'status': 'candidate', 'repository': 'axiomasoft/proxyma',
            'qualification_source_commit': sha(source_commit), 'qualification': ref,
            'packages': manifests(files)}


def check_delivery_delta(base, files):
    changed = {p for p in base.keys() | files.keys() if base.get(p) != files.get(p)}
    require(changed <= DELIVERY_INPUTS, 'unqualified source changes: ' + ', '.join(sorted(changed - DELIVERY_INPUTS)))


def verify(root, value, require_source=False):
    require(value['schema'] == SCHEMA and value['status'] in ('candidate', 'accepted'), 'invalid descriptor phase')
    require(value['repository'] == 'axiomasoft/proxyma', 'wrong repository')
    allowed = {'schema', 'status', 'repository', 'qualification_source_commit', 'qualification', 'packages', 'source'}
    if value['status'] == 'accepted':
        allowed.add('consumer')
    require(set(value) <= allowed, 'unexpected descriptor fields')
    base = qualification(root, value['qualification'], value['qualification_source_commit'])
    files = worktree(root)
    check_delivery_delta(base, files)
    require(value['packages'] == manifests(files), 'package manifest/version/path/payload mismatch')
    if require_source or value['status'] == 'accepted':
        require('source' in value, 'resolve the source commit before installation/acceptance')
    if 'source' in value:
        source = value['source']
        require(set(source) == {'commit', 'sha256'}, 'invalid resolved source')
        committed = tree(root, source['commit'])
        require(manifests(committed) == value['packages'], 'source SHA does not contain candidate packages')
        check_delivery_delta(base, committed)
        require(source['sha256'] == fingerprint(committed), 'resolved source digest mismatch')
    return value


def consumer(root, value, consumer_root, paths):
    require(value['status'] == 'candidate', 'accept only a pinned candidate')
    verify(root, value, require_source=True)
    commit = sha(git(consumer_root, 'rev-parse', 'HEAD').decode().strip())
    require(not git(consumer_root, 'status', '--porcelain', '--untracked-files=all').strip(), 'dirty consumer checkout')
    origin = git(consumer_root, 'config', '--get', 'remote.origin.url').decode().strip()
    require(origin in ('git@github.com:axiomasoft/agelto.git', 'https://github.com/axiomasoft/agelto.git'),
            'wrong consumer repository')
    refs = {key: reference(consumer_root, path) for key, path in paths.items()}
    lock = read_reference(consumer_root, refs['vendor_lock'])
    require(lock['version'] == 2, 'consumer vendor lock must be v2')
    repositories = lock['repositories']
    keys = [entry['key'] for entry in repositories]
    require(len(keys) == len(set(keys)), 'duplicate checkout key')
    entries = [entry for entry in repositories if entry['repository'] == {'host': 'github.com', 'path': value['repository']}]
    require(len(entries) == 1 and entries[0]['commit'] == value['source']['commit'], 'consumer source SHA mismatch')
    expected = [{k: p[k] for k in ('name', 'path', 'version')} for p in value['packages']]
    require(entries[0]['packages'] == expected, 'consumer package map mismatch')
    checkout = relative(entries[0]['key'])
    require(re.fullmatch(r'[a-z0-9_-]+', checkout) is not None, 'invalid checkout key')
    checkout_root = local(consumer_root, 'local/vendors/' + checkout)
    require(git(checkout_root, 'rev-parse', 'HEAD').decode().strip() == value['source']['commit']
            and not git(checkout_root, 'status', '--porcelain', '--untracked-files=all').strip(),
            'missing, dirty or wrong pinned checkout')
    require(git(checkout_root, 'config', '--get', 'remote.origin.url').decode().strip() in (
        'git@github.com:axiomasoft/proxyma.git', 'https://github.com/axiomasoft/proxyma.git'),
        'wrong pinned checkout origin')
    composer = read_reference(consumer_root, refs['composer_json'])
    locked = read_reference(consumer_root, refs['composer_lock'])
    installed = read_reference(consumer_root, refs['installed'])
    installed_packages = installed['packages'] if isinstance(installed, dict) else installed
    source_files = tree(root, value['source']['commit'])
    installed_paths = {}
    for package in expected:
        name, path = package['name'], package['path']
        require(composer['require'].get(name) == VERSION, 'consumer requirement must be exactly 0.1.0')
        repos = [r for r in composer['repositories'] if r.get('type') == 'path'
                 and r.get('url') == 'local/vendors/' + checkout + '/' + path]
        require(len(repos) == 1 and repos[0]['options'].get('symlink') is False
                and repos[0]['options'].get('reference') == 'config'
                and repos[0]['options'].get('versions') == {name: VERSION}, 'unsafe or floating path repository')
        for inventory in (locked['packages'], installed_packages):
            matches = [p for p in inventory if p['name'] == name]
            require(len(matches) == 1, 'missing or duplicate installed/locked package')
            record = matches[0]
            require(record['version'] == VERSION and record['dist']['type'] == 'path'
                    and record['dist']['url'] == repos[0]['url']
                    and re.fullmatch(r'[a-f0-9]{40}', record['dist']['reference']) is not None,
                    'incorrect lock/install version or source')
            manifest_raw = source_files[path + '/composer.json']
            expected_ref = path_reference(manifest_raw, repos[0]['options'])
            require(record['dist']['reference'] == expected_ref, 'Composer path reference does not match pinned source')
            require(record.get('transport-options', {}).get('symlink') is False, 'lock/install is not copy mode')
        install_path = local(consumer_root, 'vendor/' + name)
        require(install_path.is_dir(), 'installed package is missing')
        for filename, content in source_files.items():
            if filename == path + '/composer.json' or filename.startswith(tuple(path + '/' + d + '/' for d in ('src', 'config', 'database', 'resources'))):
                relative_name = filename[len(path) + 1:]
                require(local(install_path, relative_name).read_bytes() == content, 'installed source differs: ' + filename)
        installed_paths[name] = 'vendor/' + name
    proof = read_reference(consumer_root, refs['report'])
    integration = {}
    if proof.get('format') == 'process-core-acceptance/v1':
        native = module(root, 'native_agelto_acceptance', 'scripts/agelto-acceptance.py')
        integration = {'integration': native.check(root, consumer_root, proof, commit,
                                                   value['source']['commit'], refs, installed_paths)}
    else:
        check_consumer_proof(consumer_root, proof, commit, value['source']['commit'], refs, installed_paths)
    return {'repository': 'axiomasoft/agelto', 'commit': commit, **refs, **integration}


def check_consumer_proof(consumer_root, proof, commit, source_commit, refs, installed_paths):
    require(proof['schema'] == 'proxyma-consumer-proof/v1' and proof['consumer_commit'] == commit
            and proof['source_commit'] == source_commit and proof['packages'] == installed_paths,
            'stale consumer proof')
    require(proof['composer_lock_sha256'] == refs['composer_lock']['sha256']
            and proof['vendor_lock_sha256'] == refs['vendor_lock']['sha256']
            and proof['installed_sha256'] == refs['installed']['sha256'], 'consumer proof input mismatch')
    required = {'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7'}
    require(set(proof['gates']) == required, 'incomplete consumer acceptance proof')
    commands = {'V1': ['make', 'core-test-install'], 'V2': ['make', 'core-test-e2e'],
                'V3': ['make', 'core-test-faults'], 'V4': ['make', 'core-test-load', 'WIDTH=10'],
                'V5': ['make', 'core-test-load', 'WIDTH=20'],
                'V6': ['make', 'core-test-regression'], 'V7': ['make', 'core-quality']}
    for key in ('inputs_sha256', 'runtime_sha256'):
        require(isinstance(proof[key], str) and re.fullmatch(r'[a-f0-9]{64}', proof[key]) is not None,
                'missing consumer input/runtime identity')
    for gate, record in proof['gates'].items():
        require(record['exit'] == 0 and record['cwd'] == str(consumer_root.resolve())
                and record['command'] == commands[gate] and record['consumer_commit'] == commit
                and record['source_commit'] == source_commit
                and all(record[key] == proof[key] for key in ('inputs_sha256', 'runtime_sha256')),
                'failed/stale consumer gate: ' + gate)
        raw = local(consumer_root, record['path']).read_bytes()
        require(digest(raw) == record['sha256'], 'modified consumer gate evidence')
        result = json.loads(raw)
        require(all(result[key] == record[key] for key in ('exit', 'cwd', 'command', 'consumer_commit',
                    'source_commit', 'inputs_sha256', 'runtime_sha256')), 'gate result identity mismatch')
        require(result['assertions'] > 0 and result['scenarios'] > 0, 'empty consumer gate')
        if gate in ('V4', 'V5'):
            require(result['width'] == (10 if gate == 'V4' else 20)
                    and result['duration_seconds'] >= 1800 and result['fixture_documents'] >= 1000,
                    'incomplete load profile')
        for ref in result['evidence']:
            evidence = local(consumer_root, ref['path']).read_bytes()
            require(digest(evidence) == ref['sha256'], 'stale consumer result evidence')
        require(bool(result['evidence']), 'missing consumer result evidence')


def arguments(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--candidate', metavar='PATH', help='verify an existing candidate descriptor')
    parser.add_argument('--accepted', metavar='PATH', help='verify final installed Agelto acceptance')
    parser.add_argument('--consumer-root', type=Path, dest='accepted_consumer_root')
    sub = parser.add_subparsers(dest='command')
    create = sub.add_parser('candidate')
    create.add_argument('--qualification', default='build/test-report.json')
    create.add_argument('--qualification-source-commit', required=True)
    create.add_argument('--output', required=True)
    for name in ('verify', 'resolve', 'accept'):
        command = sub.add_parser(name)
        command.add_argument('--descriptor', default='integration/agelto/candidate.json')
        if name == 'resolve':
            command.add_argument('--source-commit', required=True)
        if name in ('resolve', 'accept'):
            command.add_argument('--output', required=True)
        if name in ('verify', 'accept'):
            command.add_argument('--consumer-root', type=Path)
        if name == 'accept':
            command.add_argument('--vendor-lock', default='docker/private-vendors.lock.json')
            command.add_argument('--composer-json', default='composer.json')
            command.add_argument('--composer-lock', default='composer.lock')
            command.add_argument('--installed', default='vendor/composer/installed.json')
            command.add_argument('--report', required=True)
    args = parser.parse_args(argv)
    if args.accepted is not None:
        if args.command is not None or args.candidate is not None:
            parser.error('--accepted cannot be combined with another command')
        args.command = 'verify'
        args.descriptor = args.accepted
        args.consumer_root = args.accepted_consumer_root
        if args.consumer_root is None:
            launcher = module(ROOT, 'accepted_agelto_root', 'scripts/agelto.py')
            args.consumer_root = launcher.resolve_root()
    elif args.candidate is not None:
        if args.command is not None:
            parser.error('--candidate cannot be combined with a command')
        args.command = 'verify'
        args.descriptor = args.candidate
        args.consumer_root = None
    elif args.command is None:
        parser.error('a command or --candidate is required')
    return args


def main():
    args = arguments()
    if args.command == 'candidate':
        value = candidate(ROOT, args.qualification, args.qualification_source_commit)
    else:
        value = json.loads(Path(args.descriptor).read_text())
        if args.accepted is not None:
            require(value.get('status') == 'accepted', 'final acceptance has not been collected')
            proof = read_reference(args.consumer_root, value['consumer']['report'])
            require(proof.get('format') == 'process-core-acceptance/v1',
                    'final acceptance requires original native Agelto reports')
        verify(ROOT, value)
        if args.command == 'resolve':
            require(value['status'] == 'candidate', 'cannot repin accepted proof')
            value['source'] = {'commit': sha(args.source_commit), 'sha256': fingerprint(tree(ROOT, args.source_commit))}
            verify(ROOT, value, require_source=True)
        elif args.command == 'accept' or value['status'] == 'accepted':
            require(args.consumer_root is not None, 'accepted proof requires --consumer-root')
            if args.command == 'accept':
                paths = {key: getattr(args, key) for key in ('vendor_lock', 'composer_json', 'composer_lock', 'installed', 'report')}
                value['consumer'] = consumer(ROOT, value, args.consumer_root, paths)
                value['status'] = 'accepted'
            else:
                original = value['consumer']
                staged = {**value, 'status': 'candidate'}
                del staged['consumer']
                paths = {key: original[key]['path'] for key in ('vendor_lock', 'composer_json', 'composer_lock', 'installed', 'report')}
                require(consumer(ROOT, staged, args.consumer_root, paths) == original, 'stale accepted descriptor')
    if args.command != 'verify':
        output = Path(args.output)
        if args.command in ('resolve', 'accept'):
            require(not output.resolve().is_relative_to(ROOT.resolve()), 'write resolved proof outside its source checkout')
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
    print('private supply: ' + value['status'] + ('; pinned ' + value['source']['commit'] if 'source' in value else '; resolve after source commit'))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.CalledProcessError) as error:
        print('private supply: ' + str(error), file=sys.stderr)
        sys.exit(1)
