# Квалификация пакета 0.1

Все команды выполняются из корня Proxyma. Единственная authority-среда —
Compose project `proxyma_test`, PostgreSQL 17, marker `proxyma_test_v1`,
database/user `proxyma_test`, internal fixture network без published ports.
`make test-env` проверяет identity, TLS/CONNECT fixture и запрет public egress до
записи данных. Тесты используют случайные отдельные SQL schemas; concurrency
использует независимые PHP workers/PG sessions и barriers, а не одну rollback
transaction. PHP 8.4/Laravel 13/Filament 5 — заявленная первая комбинация;
точные установленные версии подтверждает installation suite. Тестовый PHP image
задаёт `memory_limit=512M`, в том числе для PHPStan parallel workers.

## Выполнение и доказательства

```bash
make test-up                       # build/setup, отдельно от qualification
make test-env
make test-file TEST_FILE=tests/Contract/PackageAcceptanceTest.php
make quality
make test-all                     # один полный прогон финального дерева
make test-report                  # только сбор доказательств, тесты не запускает
```

Исполнитель P8.2 запускает только целевой файл один раз и Pint изменённых PHP
файлов. `quality`, `test-all`, `test-report` исполняет кондуктор в checks.1.
После исправления затронутые evidence надо обновить; наличие теста или старого
зелёного отчёта не является выполненной проверкой. Нельзя переносить зелёный
результат на другой HEAD или fingerprint.

`scripts/test-rig.py` сохраняет каждый действительный запуск в
`build/junit/<HEAD>/<source fingerprint>/<test-path SHA256>.{xml,json,log}`.
Receipt содержит command (`vendor/bin/pest … --log-junit …`), container cwd `/work`,
host cwd, exit, UTC start, monotonic elapsed time, JUnit digest и runtime:
PHP/cURL/TLS/extensions, PostgreSQL version, immutable Docker image IDs и
composer.lock digest. Неуспешный запуск сохраняет output, но не успешный receipt.
Исходники проверяются до/после запуска; build artifacts не входят в fingerprint.
Запуски и collector удерживают общий host `flock` в `build/.rig.lock` до записи
receipts. Параллельный collector ждёт завершения suite и не читает XML между
его созданием и публикацией metadata; одновременно запущенные file/suite команды
не смешивают wire receipts. Неполный или упавший suite по-прежнему закрывает gate.

`scripts/acceptance-report.py` (также `make test-report`) собирает
`build/test-report.json`. Он требует весь текущий inventory PHP test files всех
трёх roots (`tests`, `packages/core/tests`, `packages/filament/tests`), исключая
dependency trees `vendor`/`node_modules` и `__pycache__`, на одном
HEAD/fingerprint/runtime. Пропущенные файлы и объявленные Pest cases, неизвестные
имена, duplicate class/name, mismatched source file, failure/error/skip/warning,
нулевые assertions, нечисловое время и stale JUnit digest закрывают gate.
Dataset labels остаются частью executed identity. Python collector regressions
исполняются внутри `PackageAcceptanceTest`, а не выдаются за PHP сценарии.

Матрица содержит invariant ID, смысл, provenance, exact source/test SHA256,
HEAD, actual JUnit class/name IDs, assertion counts, timing и пути raw reports.
`dirty_worktree=true` явно отмечает незакоммиченный candidate; fingerprint
идентифицирует проверенное дерево. После commit нужен новый прогон на новом SHA:
перепривязывать старый JUnit изменением metadata запрещено.

## Invariant → test → actual evidence

Нормативная машинная карта —
[`qualification-map.json`](../tests/Architecture/qualification-map.json).
В ней `sources` задаёт owning implementation, `tests` — behavioral cases.
Directories sources разворачиваются в точные digests при сборе. Actual executed
IDs находятся в `matrix[].executed_ids`, результаты — `cases[]`, окружение и
команды — `runs[]`. Карта сама по себе не утверждает green и не содержит
заготовленных «actual» чисел.

`inherited` означает сохранённый контракт Agelto; `strengthened` — сохранённый
контракт с package-specific fencing/расширенным negative proof; `added` — новая
package guarantee. Это происхождение поведения, не утверждение побайтового копирования.
Baseline: `axiomasoft/agelto@f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`.
Donor hashes берутся из неизменного `docs/reference/source-manifest.json`;
полный manifest заново не пересчитывается. Три прочитанных donor файла
(`process-test-rig.md`, `private-vendors.lock.json`, `vendor-sync.sh`) сверены
с ним при P8.2. Historical Agelto scenario manifests и SHA256 остаются исходными;
package JUnit не заменяет consumer replay или scenario-transfers.

| IDs | Проверяемое поведение | Owning tests |
| --- | --- | --- |
| P1.boundaries, P1.transactions | Dependency direction, one SQL connection, запрет I/O в transaction | DependencyBoundaryTest, TransactionCompositionTest |
| P2.catalog, P2.tenant | Revision/audit/replay/retirement, default deny, shared grants | CatalogCommandTest, CatalogSchemaTest, AdminAuditTest, TenantAuthorizationTest |
| P2.policy, P2.routing | Typed inheritance/null/empty, cache isolation, exact approved membership | SettingPatchTest, PolicyResolutionTest, PolicyCacheIsolationTest, RouteSelectionTest |
| audit.selection | Одинаковые <=12 SQL при 1/10/100 candidates | SelectionQueryBudgetTest |
| audit.provisioning | Materialization, shared-egress provisioning race, no late UPSERT | GcraReservationTest, AuthorityInterleavingTest, QuotaWindowTest |
| audit.cooldown | Pinned origin/egress, stale 429, retirement, нет global alternative heuristic | CooldownSnapshotTest, HealthProjectionTest, HealthCompletionTest |
| audit.metadata | Scope metadata не даёт route authority | TenantAuthorizationTest, RouteSelectionTest |
| audit.transport | Реальные HTTP/proxy/CONNECT/TLS/SOCKS5 и bounded response | PhysicalTransportTest, BoundedResponseTest |
| P3.fifo, P3.gcra | FIFO/cancel/lock-wait expiry; multi-scope debts, no subset spend | BoundedDemandTest, DemandFifoTest, MultiScopeReservationTest, GcraReservationTest |
| P3.quota | Last unit across scopes, midnight, lower limit, reserved/spent | QuotaWindowTest, QuotaBoundaryTest |
| P4.fencing, P4.locks | Credential/profile/owner interleavings, fresh SQL CAS, duplicate claim, finance rollback | ClaimSendFenceTest, SealedWireTest, AuthorityInterleavingTest |
| P4.recovery | SIGKILL phases, completion/reconcile, unknown retention, once-only release | RecoveryLifecycleTest, CompletionReconcileTest |
| P5.destination, P5.credentials | DNS/SSRF/pinning, rotation, encrypted secrets, trust/redaction | DestinationPolicyTest, RedirectPolicyTest, SecretRotationTest, TlsPolicyTest |
| P5.seal, P5.response | Post-commit one-use sealed capability, monotonic latestStart, bounded artifacts | SealedWireTest, DispatchDeadlineTest, BoundedResponseTest, ArtifactOwnershipTest |
| P5.executor | Bounded retry/redirect and technical policy precedence | ExecutorPolicyTest, RedirectPolicyTest |
| P6.health, P6.probes | Immutable facts, stale epochs/407, compound owner and ordinary-send interference | HealthProjectionTest, HealthCompletionTest, ProbeOwnershipTest, AuthorityInterleavingTest |
| P6.operations | Bounded diagnostics/dry-run/prune, secret-safe telemetry/reset | OperationsCommandTest, TelemetryRedactionTest |
| P7.extensions, P7.panels, P7.ui | Finite registry/freeze, mandatory limits, fixed tiers, after-commit, tenant/permission UI | ExtensionConformanceTest, PanelBindingTest, Filament tests |
| P8.install, P8.public | Copied offline consumers, locked/lowest, fresh/repeat migrations, public no-bypass | Installation tests, PackageAcceptanceTest |

`SelectionQueryBudgetTest` выводит actual SQL counts 1/10/100 в raw `.log`;
JUnit хранит elapsed и assertions. Это fixture query bound, не latency benchmark
и не production throughput claim. Wire fixture пишет sanitized receipts в
`build/p53-wire.jsonl`; runner копирует только прирост данного запуска в
соседний `.wire.jsonl` и сохраняет его digest в `runs[].fixture_receipts`.
Collector проверяет digest и isolated-loopback scope. Assertions сопоставляют body digest/Host/SNI и отсутствие
origin bytes при отказах. Исторический файл может накапливаться, поэтому actual
verdict берётся из текущего JUnit, а не из числа строк этого журнала.

## Read-only checkpoint P1–P7

P8.2 сверяет публичную цепочку `OutboundClient → OutboundExecutor → reserve →
ClaimSend → post-commit capability → PhysicalHttpTransport → completion` с
owning behavioral tests карты. В просмотренных участках dependency direction
сохраняется: UI вызывает core commands; Domain/Contracts не импортируют SQL,
consumer, Process/LLM или Filament. Независимые copied consumers проверяются P8.1.

Safety services обязательны: selector/extension может сузить выбор, но не выдать
permit; final claim повторяет owner/tenant/policy/catalog/budget fencing.
DNS/secret/CA preparation находится вне SQL transaction. Durable deadlines
проверяются свежим `clock_timestamp()`, локальное ожидание — monotonic clock.
Physical transport фиксирует peer/host verification, address pinning, fresh
connection, запрет redirect/retry и environment proxy overrides.

При отказе claim нет bytes и partial finance/network spend; после authorization
неизвестность сохраняет consumed quota и durable attempt. Receipt/reconcile и
поздние health projections не требуют живого owner, не откатывают debt и не
стирают stale failure fact. Эти выводы требуют зелёного actual matrix; static
checkpoint не подменяет tests и отдельное cross-provider review кондуктора.
Новый unresolved data/fence/secret finding блокирует acceptance.

## Границы квалификации и передача P8.3

Нет coverage-percent gate, live crawling/load, paid API и утверждения exactly-once
HTTP delivery. P8.2 не доказывает Agelto integration/cutover, заполненный consumer
upgrade, private source lock agreement либо удаление старого runtime: это P9–P10.
Audit proposals об optional safety-off, arbitrary model/hooks, generic durable
outbox и внешнем event delivery отклонены D1/D10 для 0.1; обязательный adopted
event contract — durable audit плюс best-effort after-commit observers.

P8.3 использует actual `build/test-report.json` только для соответствующего
candidate SHA/fingerprint; наличие этого документа не является qualification.
Compatibility lowest lanes с advisories из P8.1 не превращаются в рекомендованный
production dependency lock. Release/install documentation должна различать
locked qualification и нижнюю совместимость, package acceptance и consumer rollout.
