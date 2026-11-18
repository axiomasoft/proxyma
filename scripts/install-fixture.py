#!/usr/bin/env python3
"""Загрузка зависимостей отдельно от offline install в изолированном rig."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / 'tests/Installation'
BUILD = ROOT / 'build/installation'
LANES = ('core', 'filament', 'core-lowest', 'filament-lowest')


def run(arguments, cwd=ROOT, offline=False):
    environment = {**os.environ, 'COMPOSER_HOME': str(BUILD / 'home'),
                   'COMPOSER_CACHE_DIR': str(BUILD / 'cache'), 'COMPOSER_ALLOW_SUPERUSER': '1',
                   'COMPOSER_NO_AUDIT': '1'}
    if offline:
        environment['COMPOSER_DISABLE_NETWORK'] = '1'
    subprocess.run(arguments, cwd=cwd, env=environment, check=True, timeout=900)


def php_command():
    php84 = ROOT / 'bin/php84'
    return [str(php84), 'php'] if php84.exists() else ['php']


def composer(*arguments, cwd=ROOT, offline=False):
    composer_bin = BUILD / 'composer.phar'
    if not composer_bin.exists():
        host_composer = shutil.which('composer')
        if not host_composer:
            raise ValueError('Composer 2.8.2 PHAR is required on host or in build/installation/composer.phar')
        BUILD.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(host_composer, composer_bin)
    run([*php_command(), str(composer_bin), *arguments, '--no-interaction', '--no-plugins', '--no-scripts'], cwd, offline)


def populate(lane, destination):
    shutil.copytree(FIXTURES / 'Fixture', destination, dirs_exist_ok=True)
    for name in ('composer.json', 'composer.lock'):
        source = FIXTURES / 'Locks' / lane / name
        if source.exists():
            shutil.copyfile(source, destination / name)
    for package in ('core', 'filament') if lane.startswith('filament') else ('core',):
        shutil.copytree(ROOT / 'packages' / package, destination / 'sources' / package,
                        ignore=shutil.ignore_patterns('vendor', '.git'))
    packman_root = ROOT.parent / 'packman/packages'
    packman_packages = ('core', 'panels', 'plugins', 'schema', 'filament') if lane.startswith('filament') else ('core', 'panels', 'plugins', 'schema')
    for package in packman_packages:
        shutil.copytree(packman_root / package, destination / 'sources/packman' / package,
                        ignore=shutil.ignore_patterns('vendor', '.git', 'tests'))
    for directory in ('bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'):
        (destination / directory).mkdir(parents=True, exist_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('prepare', 'prepare-runtime', 'install'))
    parser.add_argument('--refresh-locks', action='store_true')
    parser.add_argument('--native', action='store_true')
    parser.add_argument('--lane', choices=LANES)
    parser.add_argument('--destination', type=Path)
    args = parser.parse_args()
    if args.action == 'prepare':
        BUILD.mkdir(parents=True, exist_ok=True)
        composer_path = shutil.which('composer')
        if not composer_path:
            raise ValueError('Composer 2.8.2 PHAR is required on host')
        shutil.copyfile(composer_path, BUILD / 'composer.phar')
        if args.native:
            run(['python3', 'scripts/install-fixture.py', 'prepare-runtime',
                 *(['--refresh-locks'] if args.refresh_locks else [])])
            return
        security = subprocess.check_output(['docker', 'info', '--format', '{{json .SecurityOptions}}'], text=True)
        user = '0:0' if 'rootless' in security else f'{os.getuid()}:{os.getgid()}'
        run(['docker', 'run', '--rm', '--user', user,
             '--volume', f'{ROOT}:/work', '--workdir', '/work', 'proxyma_test_php:local',
             'python3', 'scripts/install-fixture.py', 'prepare-runtime',
             *(['--refresh-locks'] if args.refresh_locks else [])])
        return
    if not (BUILD / 'composer.phar').exists():
        composer('--version')
    version = subprocess.check_output([*php_command(), str(BUILD / 'composer.phar'), '--version'], text=True)
    if not version.startswith('Composer version 2.8.2 '):
        raise ValueError('Expected locked Composer 2.8.2')
    if args.action == 'prepare-runtime':
        if args.refresh_locks:
            composer('update', 'axiomasoft/proxyma-core', 'axiomasoft/proxyma-filament', '--no-install')
        for lane in LANES:
            # Каждый setup получает новый каталог; прежние артефакты не маскируют missing dependency.
            with tempfile.TemporaryDirectory(prefix='pxm_prepare_') as temp:
                destination = Path(temp)
                populate(lane, destination)
                if args.refresh_locks:
                    composer('update', '--prefer-dist', '--no-dev',
                             *(['--prefer-lowest', '--prefer-stable'] if lane.endswith('lowest') else []), cwd=destination)
                    shutil.copyfile(destination / 'composer.lock', FIXTURES / 'Locks' / lane / 'composer.lock')
                else:
                    composer('install', '--prefer-dist', '--no-dev', cwd=destination)
        for consumer in ('minimal', 'custom'):
            action = 'update' if args.refresh_locks else 'install'
            composer(action, '--prefer-dist', '--no-dev', cwd=ROOT / 'tests/Fixtures/Consumers' / consumer)
        return
    destination = args.destination
    if not args.lane or destination is None or destination.parent.resolve() != Path(tempfile.gettempdir()).resolve() or not destination.name.startswith('pxm_install_'):
        raise ValueError('install requires --lane and a new pxm_install_* directory under the system temporary directory')
    destination.mkdir()
    populate(args.lane, destination)
    composer('install', '--prefer-dist', '--no-dev', cwd=destination, offline=True)
    composer('check-platform-reqs', '--no-dev', cwd=destination, offline=True)
    # После Composer install остаётся только установленная поставка, без исходного checkout.
    shutil.rmtree(destination / 'sources')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        print(f'install fixture: {error}', file=sys.stderr)
        sys.exit(1)
