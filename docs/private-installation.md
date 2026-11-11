# Приватная установка 0.1.0

Это runbook **candidate**, не разрешение production rollout. PHP ^8.4, Laravel ^13.0,
PostgreSQL 17 и optional Filament ^5.0 проверяются в пределах
[qualification matrix](compatibility.md). Нужны curl, mbstring, openssl, PDO и
pdo_pgsql; `composer check-platform-reqs --no-dev` проверяет также транзитивные
requirements. Не используйте `--ignore-platform-reqs`.

## Qualification → точный install pin

Из Proxyma root, после package qualification:

```bash
python3 -B scripts/package-check.py candidate \
  --qualification build/test-report.json \
  --qualification-source-commit 5a046f699b32facfa7512e2f75c7079ef3a451e3 \
  --output integration/agelto/candidate.json
python3 -B scripts/package-check.py verify
```

Qualification commit — существующий commit **точного проверенного source tree**.
Report может иметь более ранний HEAD с dirty tree; checker требует точного
совпадения rig fingerprint с qualification commit, hashes source/tests, полного
inventory, JUnit/receipts/logs/runtime и всех invariant mappings. Исходные reports
и SHA-256 manifests сохраняются. При смене runtime кода прежнее proof отклоняется.
Доставочные изменения ограничены checker, glossary и Filament manifest;
`self.version` проверяется отдельно. После новой qualification обновляйте её commit
и ref осознанно, не копируйте старые отчёты под новым именем.

Coordinator коммитит исходники. Затем из **того же Proxyma root**:

```bash
source_sha=$(git rev-parse HEAD)
python3 -B scripts/package-check.py resolve \
  --source-commit "$source_sha" --output /tmp/proxyma-0.1.0-pinned.json
python3 -B scripts/package-check.py verify --descriptor /tmp/proxyma-0.1.0-pinned.json
```

`resolve` требует, чтобы commit содержал оба candidate payload и manifests.
Branch, short SHA, нули и commit с иным manifest отвергаются. Результат сохраняется
**вне source checkout**: `source.commit` и rig-compatible `source.sha256` вычисляются
после commit и не включают сам descriptor. Tracked candidate содержит реальный
qualification SHA и package payload digests, но ещё не является install pin.
Только resolved descriptor передаётся Agelto; оба пакета получают один
`source.commit`. Никаких tag/push/release/Packagist команд.

## Чистый pinned checkout

Следующие команды — из consumer root. `candidate_file` указывает на полученный
resolved descriptor. Credentials предоставляются SSH agent/read-only deploy key
или внешним Composer auth file; они не попадают в Git или Docker context.
Не вставляйте token в URL.

```bash
candidate_file=/tmp/proxyma-0.1.0-pinned.json
source_sha=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["status"]=="candidate"; print(d["source"]["commit"])' "$candidate_file")
test ! -e local/vendors/proxyma
git clone --no-checkout git@github.com:axiomasoft/proxyma.git local/vendors/proxyma
git -C local/vendors/proxyma checkout --detach "$source_sha"
test "$(git -C local/vendors/proxyma rev-parse HEAD)" = "$source_sha"
test -z "$(git -C local/vendors/proxyma status --porcelain --untracked-files=all)"
```

Для local-only qualification клонируйте доверенный локальный Proxyma checkout:
`git clone --no-hardlinks --no-checkout /absolute/path/to/proxyma local/vendors/proxyma`.
Задайте origin через `git -C local/vendors/proxyma remote set-url origin git@github.com:axiomasoft/proxyma.git`
и выполните те же detach/SHA/dirty checks. Существующий dirty checkout не
перезаписывайте и не reset-ите. Нет нужного объекта — остановитесь.

Agelto vendor-sync читает strict v2 `repositories[]`; v1 Azguard нормализуется без
перезаписи исходного manifest. Allowlist: checkout `azguard` →
`axiomasoft/azguard-private`, `proxyma` → `axiomasoft/proxyma`, только github.com,
полный SHA и два уникальных package name/path/version на repository. Текущий consumer
pin Proxyma: `cad9aa1f529f28c8e5bfeda4f05b1befbaa3ab03`, Azguard:
`ebd613cb99b1d72b7b303032e0d7ed0dd7774f4f`. Pin не меняется при последующих правках
integration scripts: оба package payload остаются byte-identical candidate.

Из Proxyma root задайте **явный** consumer checkout или используйте единственную
зарегистрированную maind binding:

```bash
export PROXYMA_AGELTO_ROOT=/absolute/path/to/agelto
python3 -B scripts/agelto.py run -- bash docker/scripts/vendor-sync.sh --check
python3 -B scripts/agelto.py run -- make core-test-up
```

Для запуска без переменной окружения зарегистрируйте checkout в maind. Файл
`integration/agelto/maind-binding.json` содержит явную привязку этой машины;
на другой машине сначала задайте в нём нужный абсолютный путь:

```bash
maind projects sync --from brain --path integration/agelto/maind-binding.json
python3 -B scripts/agelto.py run -- pwd
```

Это только registry import, без onboarding skills/hooks/memory. В формате импорта
поле `repo` содержит **локальный путь**; maind превращает его в `local_path` и
оставляет GitHub slug пустым. Launcher принимает такую единственную active/enabled
запись с `id=agelto`, затем обязательно проверяет настоящий origin, baseline и diff.
Конфликтующий GitHub slug, duplicate/disabled/inactive bindings отвергаются;
папки не ищутся. Явный `PROXYMA_AGELTO_ROOT` сохраняет приоритет.

Launcher печатает фактические cwd и HEAD, проверяет canonical credential-free origin,
точный ожидаемый baseline и diff только в declared integration paths. Default baseline —
`f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`. После coordinator commit передайте его полный
SHA через `run --expected-head SHA -- command...` или `PROXYMA_AGELTO_BASELINE`;
launcher никогда не выбирает checkout по имени папки и не делает reset.

`vendor-sync.sh` сначала проверяет все существующие origins и dirty trees, затем
получает отсутствующие Git objects и detach на pin. При наличии локальных объектов
sync не делает fetch; `--check` никогда не клонирует и не fetch-ит. Dirty или symlink
checkout не перезаписывается. `--composer-check` дополнительно сверяет path repositories,
versions и lock references с raw manifest из закреплённого source commit, затем запускает
Composer validate/dry-run. При nested packages Composer `reference:auto` тоже использует
config hash, если `.git` отсутствует именно в package subdirectory.

Для Composer checks helper выбирает установленный `php8.5`, затем `php8.4`, затем
`php`, проверяя фактическую версию: текущему Agelto lock нужен PHP >=8.4.1 <9.
`AGELTO_PHP_BINARY` явно задаёт интерпретатор; несовместимое явное значение вызывает
отказ без fallback. Platform checks Composer сохраняются, `--ignore-platform-reqs`
не применяется. Системный PHP 8.3 не мешает проверке при установленном PHP 8.4/8.5.

CI готовит Proxyma через checkout exact SHA с repository-scoped read-only
`PROXYMA_READ_TOKEN`, `persist-credentials:false`. Нужный commit должен быть доступен
CI; local-only qualification не подразумевает push. На deploy host helper создаёт
раздельные `github-agelto`, `github-azguard`, `github-proxyma` identities, проверяет
host fingerprint, origin, dirty state и read-only права. Создание/зачисление реальных
production ключей в этот пункт не входит. Contract использует fake git/ssh.

Rig экспортирует новые Git metadata и только оба package subtrees каждого vendor,
сверяет source SHA/payload SHA-256 из `runtime-inputs.json` и монтирует snapshots read-only.
Build context исключает `.git`, `.env*`, `auth.json`, credentials/keys, secrets и лишние
vendor source paths; dependency stages копируют оба подпакета, application/doctor
получают собранный vendor. Stale/incomplete snapshots требуют остановки isolated rig
и refresh по его протоколу; живые shared data не удаляются.

## Composer root consumer

Добавьте в **consumer** composer.json эти отдельные repositories и requirements,
сохранив остальные зависимости. Core-only consumer опускает UI repository/requirement:

```json
{
  "repositories": [
    {"type": "path", "url": "local/vendors/proxyma/packages/core", "options": {"symlink": false, "reference": "config", "versions": {"axiomasoft/proxyma": "0.1.0"}}},
    {"type": "path", "url": "local/vendors/proxyma/packages/filament", "options": {"symlink": false, "reference": "config", "versions": {"axiomasoft/proxyma-filament": "0.1.0"}}}
  ],
  "require": {"axiomasoft/proxyma": "0.1.0", "axiomasoft/proxyma-filament": "0.1.0"}
}
```

Filament `self.version` становится точно 0.1.0. Не устанавливайте workspace root
через VCS repository: Composer не объявляет два nested packages автоматически.
Workspace dev-main/symlinks предназначены только для разработки.

```bash
composer update axiomasoft/proxyma axiomasoft/proxyma-filament --with-all-dependencies --no-dev
composer validate --strict
composer check-platform-reqs --no-dev
```

Сохраните composer.lock вместе с private-vendors v2 SHA и packages map.
Path `reference:config` — hash raw manifest + PHP serialized path options
(Composer добавляет `relative:true`); это **не Git SHA**. Checker вычисляет его
по закреплённому commit и проверяет lock, installed.json и копии runtime файлов.
Не полагайтесь только на dev symlink. Для повторной/offline установки нужны
сохранённый lock, dependency archive cache и локальный pinned checkout:

```bash
COMPOSER_DISABLE_NETWORK=1 composer install --no-dev --prefer-dist --no-interaction
composer check-platform-reqs --no-dev
test ! -L vendor/axiomasoft/proxyma
test ! -L vendor/axiomasoft/proxyma-filament
```

Отсутствующий cached dependency должен привести к отказу. Не подменяйте его
floating branch или обращением к сети. В production image копируются package
subtrees и собранный vendor, без `.git`, `.env`, `auth.json`, SSH keys и fixture
secrets. Проверяйте файлы **в image**. Docker/CI allowlist и runtime-input hashes
Agelto закреплены вместе с private-vendors v2 manifest.

## Конфигурация и начальная проверка

Discovery регистрирует `Proxyma\Laravel\ProxymaServiceProvider` и optional
`Proxyma\Filament\ProxymaFilamentServiceProvider`. При отключённом discovery
зарегистрируйте их явно в `bootstrap/providers.php`.

```bash
php artisan vendor:publish --tag=proxyma-config
php artisan package:discover
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Cache/discovery выполняются без DB/secret I/O. До runtime команд настройте
PostgreSQL connection, `APP_KEY`, отдельный секрет `PROXYMA_WIRE_DIGEST_KEY`
минимум 32 bytes и trusted `TenantContextResolver` / `Authorizer` bindings.
Provider по умолчанию deny; не подменяйте его allow-all ради doctor или cron.
Секреты поступают извне через окружение/runtime secret mounts, не включаются в
repository, image или evidence. Default `EncryptedSecretStore` хранит encrypted
credentials; consumer может привязать `SecretResolver`. Сохраните credential
versions/epochs при ротации. См. [security](security.md), [extensions](extensions.md)
и [Filament permissions](filament.md).

Следующие команды — для новой isolated installation fixture; production migration
и populated upgrade требуют отдельного согласованного cutover:

```bash
php artisan migrate --force
php artisan proxyma:doctor
php artisan proxyma:transport-doctor
```

Doctor exit 0 означает локальную готовность, не успешную сеть и не acceptance.
Catalog, targets, profiles, explicit direct membership, owner adapter и policy
задаются trusted application commands; неизвестный binding не разрешает fallback.
Shared financial/owner/network claim использует одну connection/transaction;
HTTP/DNS/secret-store/object I/O запрещены под SQL locks.

## Расписание и восстановление

В published config задайте `scheduler.enabled=true`, `maintenance.batch_size=32`,
`maintenance.retention_seconds=2592000` и снова выполните `config:cache`.
Владелец приложения обеспечивает `schedule:run` каждую минуту и trusted console
tenant/actor. Opt-in добавляет reconcile раз в минуту, prune ежедневно; probes
не включаются автоматически. Для нескольких tenants запускайте bounded задачи
в контексте каждого tenant. [Operations](operations.md) содержит permissions,
размеры batch и единицы retention: **секунды**, 2592000 = 30 дней.

После authorization timeout остаётся unknown, без автоматической повторной отправки.
Первый durable new claim — irreversible point; после него только roll-forward.
До него rollback требует drain и проверки неизменности старой authority.
Полные условия и сохраняемая история — [upgrading](upgrading.md).

## Accepted descriptor — только после consumer qualification

После P10.4 из Proxyma root, с чистым Agelto checkout:

```bash
python3 -B scripts/package-check.py accept \
  --descriptor /tmp/proxyma-0.1.0-pinned.json \
  --consumer-root "$PROXYMA_AGELTO_ROOT" \
  --report storage/app/testing/process-core/proxyma-consumer-proof.json \
  --output /tmp/proxyma-0.1.0-accepted.json
python3 -B scripts/package-check.py verify \
  --descriptor /tmp/proxyma-0.1.0-accepted.json --consumer-root "$PROXYMA_AGELTO_ROOT"
```

Accepted mode требует v2 vendor lock (`key`, `repository`, `commit`, packages с
`name/path/version`), clean pinned checkout, composer.json/lock, installed.json
и copied runtime файлы. Consumer collector предоставляет envelope
`proxyma-consumer-proof/v1`: `consumer_commit`, `source_commit`, `packages`
(name → vendor path), `composer_lock_sha256`, `vendor_lock_sha256`,
`installed_sha256`, `inputs_sha256`, `runtime_sha256`, `gates` с точными V1..V7.

Каждый gate содержит `command` (argv `make core-test-*`, для load — `WIDTH=10` / 20),
`cwd`, `exit`, обе revisions, одинаковые input/runtime hashes, `path/sha256`
JSON result. Result повторяет identity/command/exit, содержит положительные
`assertions`, `scenarios` и непустые `evidence` refs (`path/sha256`) реальных
JUnit/logs/reports. Load result также содержит `width`, `duration_seconds >= 1800`,
`fixture_documents >= 1000`. Gates: V1 install, V2 e2e, V3 faults, V4/V5 load,
V6 regression, V7 quality. Envelope связывает исходные consumer reports;
checker не запускает gates и не создаёт результаты вместо collector.
До consumer-фазы envelope отсутствует: **accepted сейчас получить нельзя**.

Cheap regression (из Proxyma root):

```bash
python3 -B docs/tests/test_package_check.py
```

Synthetic fixtures этого теста не являются consumer evidence. Сохраняйте package
report, JUnit/log/receipt refs и consumer originals вместе с resolved/accepted
descriptors: возраст файла и имя версии не заменяют hashes.

Целевой integration self-check (без БД, live providers и enrollment):

```bash
PROXYMA_AGELTO_ROOT=/absolute/path/to/agelto python3 -B scripts/tests/test_agelto.py
```

Он включает `test_rig.py`, offline Git/Composer fixtures, fake deploy git/ssh и
минимальный Docker scratch context export. Полные consumer gates выполняет coordinator.
