# Проверяемая совместимость 0.1

Квалификация ограничена PHP 8.4.27, PostgreSQL 17 и Linux image из
`docker/test/Dockerfile` / `docker/compose.test.yml`. В нём libcurl 7.88.1 с
OpenSSL и HTTPS proxy support. Наличие manifest не доказывает остальные minor,
PHP 8.5, другой libcurl/backend, Windows, MySQL или Redis authority.

| Installed fixture | Laravel | Filament | Dependency selection |
| --- | --- | --- | --- |
| core | 13.40.0 | отсутствует | закреплённый lock |
| filament | 13.40.0 | 5.11.2 | закреплённый lock |
| core-lowest | 13.0.0 | отсутствует | `--prefer-lowest --prefer-stable` |
| filament-lowest | 13.0.0 | 5.4.0 | `--prefer-lowest --prefer-stable` |

Четыре lock-файла находятся в `tests/Installation/Locks/`. Нижний набор —
результат Composer resolver для этих manifests, а не обещание совместимости с
любой комбинацией промежуточных версий. Runtime core требует PHP/extensions,
Illuminate Console, Contracts, Database, Encryption, Events, Support, PSR Log,
Symfony HttpFoundation и Process; `laravel/framework` требует consumer fixture,
а не core. Core устанавливается без любых `filament/*` и Testbench/Pest.
Filament добавляет UI над тем же обязательным admission/budget/health stack.
`ext-curl`, `ext-mbstring`, `ext-openssl`, `ext-pdo`, `ext-pdo_pgsql` объявлены
явно; остальные транзитивные extensions проверяет Composer на настоящем runtime.
При разрешении нижних наборов Composer сообщил security advisories в старых
транзитивных зависимостях. Эти locks нужны для проверки нижней границы API;
их нельзя трактовать как рекомендацию безопасного production dependency set.
Нижние зависимости также выдают PHP 8.4 deprecations; PHP направляет их в stderr,
который сохраняется вместе с каждым command/exit в `build/installation-*.log`.

Из repository root:

```bash
make test-up
python3 scripts/install-fixture.py prepare
make test-file TEST_FILE=tests/Installation/PackageInstallTest.php
```

Setup требует host Composer **2.11.2 PHAR** и запускает отдельный контейнер с
сетью для загрузки архивов в `build/installation/cache`. PHP, extensions и
Composer resolver работают в том же PHP image, что и rig. Setup также
устанавливает два extension consumers предыдущего этапа. Для осознанного
обновления закреплённых dependency sets используется
`python3 scripts/install-fixture.py prepare --refresh-locks`; обычный prepare
использует существующие locks. Tools workspace закреплены в root composer.json
и composer.lock: Pest 5.3.5, Testbench 11.3.0, Pint 1.32.1, PHPStan 2.3.5,
PAO 1.1.5. Эти dev tools не попадают в installed consumer.

Внутри internal network тест создаёт новый `/tmp/pxm_install_*`, выполняет
настоящий `composer install --no-dev` с `COMPOSER_DISABLE_NETWORK=1` и
`check-platform-reqs`. Path repositories используют **`symlink:false`**;
после установки `sources/` удаляется. Дочерний PHP процесс загружает только
свой vendor, проверяет все именованные package-классы, отсутствие workspace
Tests/App и Filament в core, discovery providers и девять plugin pages в UI.
Это отдельная проверка копируемой поставки; workspace symlinks для разработки
не служат её доказательством.

Перед SQL mutations проверяются exact DB/user `proxyma_test` и rig marker.
Каждый scenario работает в собственной случайной PostgreSQL schema, без
`public` в search_path. Проверяются cache/discovery с недоступным DB port до
миграций; два fresh install с полным сравнением columns, constraints, indexes
и triggers; повторный `migrate` без изменений; cache/discovery после миграций;
doctor failure на пустой schema и после удаления обязательного constraint.
После opt-out discovery отсутствие providers подтверждается отдельно; явная
регистрация providers восстанавливает cache, migrations и healthy doctor.
Doctor не выполняет network I/O; live crawling и внешние providers не нужны.

Итогом проверки служит настоящий Pest JUnit с ненулевым набором сценариев,
привязанный существующим rig к HEAD и fingerprint исходников. CI сохраняет
`build/junit/` и `build/test-report.json`. Статус конкретного прогона берётся
из его выполненных checks; эта таблица не подменяет результат прогона.
