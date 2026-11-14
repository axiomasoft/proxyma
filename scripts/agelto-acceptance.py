#!/usr/bin/env python3
"""Проверка installed Agelto по исходным отчётам; тесты и сеть не запускаются."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def load(root, filename, name):
    spec = importlib.util.spec_from_file_location(name, root / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def need(condition, message):
    if not condition:
        raise ValueError(message)


def input_tree(root):
    names = subprocess.check_output(['git', '-C', str(root), 'ls-files', '--cached',
                                     '--others', '--exclude-standard']).decode().splitlines()
    result = {}
    for name in names:
        if name.startswith(('plans/', '.swissknifeman/')):
            continue
        path = root / name
        if path.is_symlink():
            import os
            result[name] = hashlib.sha256(os.readlink(path).encode()).hexdigest()
        elif path.is_file():
            result[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def check(root, consumer_root, proof, commit, source_commit, refs, installed_paths):
    supply = load(root, 'scripts/package-check.py', 'agelto_supply')
    collector = load(consumer_root, 'docker/tests/process-core/acceptance_report.py', 'agelto_report')
    load_report = load(consumer_root, 'docker/tests/process-core/load_report.py', 'agelto_load')
    need(proof['format'] == 'process-core-acceptance/v1' and
         proof['verdict'] == 'ready_for_owner_review' and not proof['errors'],
         'incomplete native consumer acceptance')
    need(proof['candidate']['head'] == commit, 'recollect consumer report after final commit')
    report_path = supply.local(consumer_root, refs['report']['path'])
    folder = report_path.parent
    inputs = json.loads(supply.local(consumer_root, str((folder / 'inputs.json').relative_to(consumer_root))).read_text())
    need(input_tree(consumer_root) == inputs, 'consumer report does not match current inputs')
    rebuilt = collector.build(consumer_root, folder.parent, folder, load_report.validate, proof['candidate'])
    need(rebuilt == proof, 'modified or stale native consumer report')
    contract = json.loads(supply.local(consumer_root, collector.CONTRACT).read_text())
    need(contract.get('installed_proxyma') is True and contract.get('integration_cases'),
         'consumer collector lacks installed integration requirements')
    runtime_inputs = json.loads(supply.local(consumer_root,
        'tests/Fixtures/ProcessCore/runtime-inputs.json').read_text())
    need(runtime_inputs['repositories']['proxyma']['commit'] == source_commit,
         'runtime source differs from installed source')
    runtime = None
    for gate in collector.GATES:
        record = proof['validations'][gate]
        for path, expected in record['artifacts'].items():
            need(supply.digest(supply.local(consumer_root, path).read_bytes()) == expected,
                 'modified gate artifact: ' + path)
        result = json.loads(supply.local(consumer_root, record['result']).read_text())
        need(result['runtime']['dependencies'] == runtime_inputs['repositories'], 'stale gate runtime')
        if runtime is None:
            runtime = result['runtime']
        need(runtime == result['runtime'], 'mixed consumer runtimes')
        recorded_head = result['candidate']['head']
        supply.sha(recorded_head)
        supply.git(consumer_root, 'merge-base', '--is-ancestor', recorded_head, commit)
    # Проверяем тот же payload, который вошёл в package qualification и vendor copy.
    source_files = supply.tree(root, source_commit)
    for name, prefix in supply.PACKAGES.items():
        need(installed_paths[name] == 'vendor/' + name, 'unexpected installed path')
        need(runtime_inputs['repositories']['proxyma']['packages'][prefix] ==
             supply.payload(source_files, prefix), 'runtime package payload mismatch')
        expected = {p[len(prefix) + 1:]: supply.digest(raw) for p, raw in source_files.items()
                    if p == prefix + '/composer.json' or
                    p.startswith(tuple(prefix + '/' + d + '/' for d in ('src', 'config', 'database', 'resources')))}
        need(runtime['installed_packages'][name] == expected, 'installed runtime files differ from qualified source')
    removal = json.loads(supply.local(root, 'integration/agelto/legacy-removal.json').read_text())
    need(removal['runtime_retirements'], 'empty legacy removal allowlist')
    for name in removal['runtime_retirements']:
        path = supply.local(consumer_root, name)
        need(not path.exists(), 'legacy runtime still present: ' + name)
    for entry in removal['test_retirements']:
        archive = supply.local(consumer_root, entry['archive'])
        need(supply.digest(archive.read_bytes()) == entry['source_sha256'], 'modified historical test archive')
    return {'scope': 'isolated fixtures; no production run or public release',
            'runtime_sha256': collector.digest(runtime),
            'inputs_sha256': proof['candidate']['input_tree_sha256'],
            'removal_allowlist': supply.reference(root, 'integration/agelto/legacy-removal.json')}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--descriptor', required=True, type=Path)
    parser.add_argument('--consumer-root', required=True, type=Path)
    parser.add_argument('--report', required=True)
    parser.add_argument('--output', type=Path, default=ROOT / 'integration/agelto/acceptance.json')
    args = parser.parse_args()
    supply = load(ROOT, 'scripts/package-check.py', 'final_supply')
    value = json.loads(args.descriptor.read_text())
    paths = {'vendor_lock': 'docker/private-vendors.lock.json', 'composer_json': 'composer.json',
             'composer_lock': 'composer.lock', 'installed': 'vendor/composer/installed.json', 'report': args.report}
    value['consumer'] = supply.consumer(ROOT, value, args.consumer_root.resolve(), paths)
    need('integration' in value['consumer'], 'native consumer acceptance required')
    value['status'] = 'accepted'
    # integration/ не входит в source fingerprint: commit результата не требует повторять тесты.
    need(args.output.resolve() != args.descriptor.resolve(), 'preserve original pinned descriptor')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
    print('internal 0.1.0 accepted by source SHA ' + value['source']['commit'] +
          '; Agelto ' + value['consumer']['commit'] + '; isolated fixtures only')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.CalledProcessError) as error:
        print('Agelto acceptance refused: ' + str(error), file=sys.stderr)
        sys.exit(1)
