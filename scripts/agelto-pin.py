#!/usr/bin/env python3
"""Offline re-pin обоих пакетов; существующий dirty checkout никогда не перезаписывается."""
import argparse
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def module(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


def pin(root, commit):
    supply = module(ROOT / 'scripts/package-check.py', 'pin_supply')
    vendor = module(root / 'docker/scripts/vendor_manifest.py', 'pin_vendor')
    supply.sha(commit)
    origin = supply.git(root, 'config', '--get', 'remote.origin.url').decode().strip()
    supply.require(origin in ('git@github.com:axiomasoft/agelto.git',
                             'https://github.com/axiomasoft/agelto.git'), 'wrong consumer origin')
    # Source revision должна существовать локально; remote fetch запрещён.
    supply.git(ROOT, 'cat-file', '-e', commit + '^{commit}')
    manifest_path = root / 'docker/private-vendors.lock.json'
    rows = vendor.load(manifest_path)
    for row in rows:
        vendor.check_checkout(vendor.safe_checkout(root, row), row)
    selected = [r for r in rows if r['key'] == 'proxyma']
    supply.require(len(selected) == 1, 'missing Proxyma package map')
    row = {**selected[0], 'commit': commit}
    checkout = vendor.safe_checkout(root, row)
    supply.git(checkout, 'fetch', '--no-tags', '--no-write-fetch-head', str(ROOT), commit)
    # Никакого reset: checkout допускается только после dirty/origin preflight.
    supply.git(checkout, 'checkout', '--detach', commit)
    vendor.check_checkout(checkout, row)
    value = json.loads(manifest_path.read_text())
    value['repositories'] = [row if r['key'] == 'proxyma' else r for r in rows]
    manifest_path.write_text(json.dumps(value, indent=2) + '\n')
    command = vendor.composer_command()
    env = {**os.environ, 'COMPOSER_DISABLE_NETWORK': '1'}
    names = [p['name'] for p in row['packages']]
    for arguments in (['update', *names, '--no-install', '--no-scripts', '--no-interaction',
                       '--no-progress', '--no-audit'],
                      ['reinstall', *names, '--no-scripts', '--no-interaction', '--no-progress']):
        subprocess.run([*command, *arguments], cwd=root, env=env, check=True)
    vendor.composer_contract(root, value['repositories'])
    runtime = root / 'tests/Fixtures/ProcessCore/runtime-inputs.json'
    inputs = json.loads(runtime.read_text())
    inputs['repositories']['proxyma'] = {'commit': commit,
        'packages': vendor.package_hashes(vendor.package_files(checkout, row), row)}
    runtime.write_text(json.dumps(inputs, ensure_ascii=False, indent=2, sort_keys=True) + '\n')
    refresh_references(root, {'composer.lock', 'docker/private-vendors.lock.json',
                              'tests/Fixtures/ProcessCore/runtime-inputs.json'})
    print('Pinned both packages at ' + commit + '; copied vendor and runtime-inputs refreshed.\n'
          'Before acceptance: commit Agelto, refresh the stopped isolated rig snapshots via core-test-down/up.')


def refresh_references(root, changed):
    # Только затронутые inputs: original_* hashes и исторические scenarios неизменны.
    ai_path = root / 'tests/Fixtures/ProcessCore/Llm/acceptance-v1.json'
    ai = json.loads(ai_path.read_text())
    updated = False
    for entry in ai['inputs']:
        if entry['path'] in changed:
            current = hashlib.sha256((root / entry['path']).read_bytes()).hexdigest()
            if current != entry['sha256']:
                entry['sha256'] = current
                updated = True
    if updated:
        ai_path.write_text(json.dumps(ai, ensure_ascii=False, indent=2) + '\n')
        changed = changed | {'tests/Fixtures/ProcessCore/Llm/acceptance-v1.json'}
    path = root / 'tests/Fixtures/ProcessCore/manifest.json'
    manifest = json.loads(path.read_text())
    for group in ('source_classes', 'migrations', 'contract_fixtures', 'files'):
        for entry in manifest[group]:
            if entry['path'] in changed:
                entry['sha256'] = hashlib.sha256((root / entry['path']).read_bytes()).hexdigest()
    path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--consumer-root', type=Path, required=True)
    parser.add_argument('--source-commit', required=True)
    args = parser.parse_args()
    pin(args.consumer_root.resolve(), args.source_commit)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.CalledProcessError) as error:
        print('Agelto pin refused: ' + str(error), file=sys.stderr)
        sys.exit(1)
