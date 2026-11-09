# Proxyma: PostgreSQL schema и catalog lifecycle

Core владеет всеми `pxm_*` таблицами. Единственный путь установки —
`loadMigrationsFrom` в `ProxymaServiceProvider`. `migrate` вызывается приложением;
повторный вызов не меняет установленную схему. Publish-копии миграций нет.
Register/boot не подключаются к БД, не создают catalog и не запускают миграции.
Поддерживается PostgreSQL; SQLite не доказывает ограничения authority.

## Сущности

У каждой строки есть UUID `id`, обязательный `tenant_key`, `created_at`, `updated_at`
и unique `(tenant_key, id)`. Tenant — lowercase ASCII, 1–128 байт, без wildcard.
UUID — глобальный PK; одинаковые логические ключи разрешены разным tenants.
Все FK принадлежат пакету и включают tenant. FK на `users`, process или finance нет.
Созданные package timestamps используют `clock_timestamp()`.

| Таблица | Данные и связи |
|:---|:---|
| `pxm_endpoints` | Protocol `http/https/socks5/socks5h`, canonical host до 253 символов, port 1–65535, opaque credential ref до 255 байт и version как nullable пара, revision ≥1, retired_at |
| `pxm_routes` | Tenant-unique route_key, kind `direct/proxy`, endpoint_id, enabled, egress_key, conservative_pool, independence_group, shared_grant, revision, retired_at. Proxy требует endpoint; direct запрещает его |
| `pxm_route_profiles` | Tenant-unique profile_key, strategy_key до 64 символов, revision, retired_at |
| `pxm_route_profile_members` | Exact profile → route membership, position ≥0, unique membership и position внутри profile |
| `pxm_targets` | Tenant-unique generic target_key, normalized origin с явным port, destination_policy_key, revision, retired_at |
| `pxm_policy_heads` | Tenant-unique canonical scope_key, revision, current_revision_id; deferred composite FK требует revision того же head/tenant |
| `pxm_policy_revisions` | Head, revision, JSON object definition; unique head+revision, immutable UPDATE/DELETE |
| `pxm_bindings` | Tenant+subject_type+opaque subject_id до 255 байт, head/profile, revision, retired_at |
| `pxm_operations` | Root mutex: profile/target, обязательные owner type/id/token/generation, revision, status `active/completed/cancelled/expired`, finite deadline, revoked_at, max_physical_attempts и authorized_attempts. Connect/request timeouts положительны, connect ≤ request |
| `pxm_demands` | Operation+profile+target composite FK, purpose `normal/probe`, state `active/admitted/completed/cancelled/expired`, monotonic sequence, positive cost, finite deadline |
| `pxm_permits` | Operation/demand/route/target, physical_attempt_id, SHA-256 wire fingerprint, positive cost, not_before/expires_at, state, claim_id, authorized_at/completed_at. Demand принадлежит тому же operation и target |
| `pxm_attempts` | Unique operation+physical_attempt_id; composite permit FK, unique receipt_id, status, wire fingerprint и JSON snapshot |
| `pxm_budgets` | Tenant-unique scope_key, positive emission_interval_us/burst/max_inflight, mandatory, revision; runtime tat/send_tat/inflight ≥0 |
| `pxm_quota_windows` | Budget, finite increasing starts_at/ends_at, positive quota_limit, reserved/spent ≥0 и сумма ≤ limit; unique budget+start. UTC window boundaries определяет quota application service |
| `pxm_reservations` | Permit/budget/optional quota window того же budget, positive cost, state `reserved/spent/released`; unique permit+budget |
| `pxm_profile_health` | Unique profile, circuit state, health_epoch/state_version, failures, openings, cooldown/probe. Материализуется вместе с profile |
| `pxm_route_health` | Unique route, те же circuit/probe поля |
| `pxm_route_target_health` | Unique route+target, те же circuit/probe поля |
| `pxm_target_health` | Unique target, circuit/probe и availability `unknown/available/suspect/unavailable` |
| `pxm_observations` | Attempt, unique receipt_id, classification, JSON evidence, observed_at |
| `pxm_observation_applications` | Observation, canonical application_key, health_epoch; unique observation+application_key |
| `pxm_audit_events` | Actor type/id, reason, ability, entity type/id, previous/current revision, tenant-unique idempotency_key, command hash и исходный результат; immutable UPDATE/DELETE |

Health state: `unknown/closed/open/half_open`; epochs и state versions ≥1,
failures/openings ≥0. Probe token/deadline задаются только парой.
Budget/application scope keys — 1–255 байт lowercase ASCII `[a-z0-9_.:-]`,
первый символ alphanumeric. Unicode, uppercase, whitespace и неоднозначная
нормализация отвергаются, а не исправляются. `COLLATE "C"` на ключе и lock index
совпадает с PHP `SORT_STRING`. Тест проверяет и collation из PostgreSQL catalog,
и фактический порядок. Индексы/constraints используют PostgreSQL identifier limit
63 байта; короткие имена значимых query indexes приведены ниже.

Permit `reserved` допускает только `authorized`, `cancelled_before_send`,
`expired_before_send`. `authorized` переходит в `completed` либо
`unknown_after_authorization`; последний допускает поздний `completed`.
CHECK связывает send states с claim_id/authorized_at, completed — с completed_at.
Trigger запрещает смену identity, повторный claim и откат state назад.
Проверка подлинности позднего receipt остаётся обязанностью completion service;
SQL transition сам по себе её не доказывает.

## Команды каталога

`CatalogCommands` связан с `DatabaseCatalogCommands`; `CatalogQueries` — с
`DatabaseCatalogQueries`. Приложение **обязано** внедрить `Authorizer`.
Production allow-all и fallback binding отсутствуют. Authorizer получает
`ActorRef(type,id)`, trusted `TenantKey`, ability и resource UUID; отказ оформляет
исключением. Все mutations, включая idempotent replay, проходят авторизацию.
Ability names: `catalog.endpoint.upsert/retire`, `catalog.route.upsert/set-enabled`,
`catalog.profile.upsert`, `catalog.target.upsert/retire`.

Команда содержит `CatalogMutationActor`: ActorRef, reason, expectedRevision,
idempotencyKey. `0` означает создание; update требует точной текущей revision.
Старый конструктор `(string actorId, string reason, ?int expectedRevision)`
сохранён для DTO compatibility P1.2 и отображает string в actor type `operator`.
Но исполнять mutation без явных revision и idempotency key запрещено.
Idempotency key — 1–128 видимых ASCII байт. Он уникален внутри tenant для всех
catalog abilities. Тот же key с другим payload конфликтует; точный replay
возвращает исходный snapshot, даже если текущая revision уже изменилась.
Reason, actor и expected revision входят в command hash.

Catalog commands владеют самостоятельной transaction и отвергают вызов внутри
уже открытой owning transaction. Это сохраняет authorizer перед SQL transaction
и не позволяет provisioning после execution locks. `ActorRef` не подтверждает
права сам по себе; доверие устанавливает Authorizer. Никаких HTTP, DNS, secret-store
или object I/O в retry closure нет. Deadlock retry ограничен тремя SQL попытками.

Revision, catalog row, exact membership, health/budget provisioning и audit
коммитятся атомарно. Audit сохраняет opaque credential reference/version,
но не разрешает/загружает secret. Direct ORM save/delete запрещены у внутренних
моделей; observers и public model substitution не являются путём изменения authority.
Внутренний query builder доступен только storage implementation.

Endpoint rotation повышает endpoint revision и credential version при смене
непустой credential пары. Disable повышает только route revision. Retirement
повышает только revision выводимой сущности и сохраняет ссылки/receipts; revive
запрещён. Hard delete catalog запрещён даже для неиспользованных строк.
Связанные operations/permits не блокируются и не переписываются; claim обязан
проверять актуальные revisions и retired/enabled state при send.
`Route.credentialVersion` при чтении берётся из текущего endpoint.

Чтение всегда ограничено явным tenant. UUID другого tenant даёт null/пустой
результат. Profile membership принимает только принадлежащие tenant маршруты;
`shared_grant=true` сохраняет intent, но не является wildcard доступом.
Отдельный scoped grant и permission integration относятся к P1.4; до этого
cross-tenant чтение и membership закрыты. Empty profile остаётся deny.

## Provisioning и lock protocol

Catalog transaction сначала берёт transaction advisory mutex по tenant для
сериализации создания отсутствующих строк. Этот mutex отсутствует в execution
path. Затем существующие строки блокируются по tier и stable UUID:
profile → profile health → endpoint → route → route health → route-target health
→ target → target health. Команда повышает только свою catalog revision.
Catalog intentionally блокирует весь network catalog tenant: это простой
provisioning path, без locks зависимых operations/demands/permits.

Create/update материализует profile/route/target health и все route-target пары
того же tenant до исполнения send. Порядок создания route и target не влияет
на полноту пар. ON CONFLICT допустим только здесь. Повторное provision не
сбрасывает health epoch, state, cooldown, probe, failures или timestamps.

`UpsertRouteCommand`, `UpsertRouteProfileCommand`, `UpsertTargetCommand` принимают
необязательный список typed `BudgetConstraint` в последнем аргументе `budgets`.
Команды создают настроенные budget rows в той же transaction; ограничения одного
scope внутри команды пересекаются. Existing scope обновляет только конфигурацию
и её revision; tat, send_tat, inflight, quota usage и reservations сохраняются.
Mandatory флаг сохраняется после включения; пустой список не удаляет budgets.
Budget rows блокируются последними в `scope_key COLLATE "C"` порядке.
В send path создание missing health/budget запрещено: admission должен вернуть
configuration hold, а не выполнить поздний UPSERT. Runtime admission/health
algorithms реализуются в следующих пунктах и не входят в эту миграцию.

`OperationMutex::lock` требует активную transaction на переданном connection,
берёт unique operation UUID по возрастанию и не завершает owning transaction.
Для reserve/claim/complete/reconcile он идёт первым package lock после
объявленного owner/finance prefix и перед policy heads/network/budgets.
Catalog не берёт этот mutex: изменение каталога не может замкнуть обратный lock
путь через pending permits. Interference checks выполнения остаются задачей P3/P4.

## Query indexes и source-to-test mapping

| Index | Predicate/path | Исполняемая проверка |
|:---|:---|:---|
| `pxm_demand_fifo_idx` | Tenant/profile/target/sequence, active demands | CatalogSchemaTest: index inventory, positive cost и operation ownership |
| `pxm_demand_deadline_idx` | Tenant/deadline/id, active/admitted | CatalogSchemaTest: inventory, finite deadline CHECK |
| `pxm_operation_deadline_idx` | Tenant/deadline/id, active roots | CatalogSchemaTest: root на двух независимых connections, timeout/owner CHECK |
| `pxm_permit_due_idx`, `pxm_permit_operation_idx` | Active permit reconciliation и operation lookup | CatalogSchemaTest: duplicate claim, lifecycle rollback и fingerprint identity |
| `pxm_budget_lock_idx` | Tenant/scope_key COLLATE C | CatalogSchemaTest: canonical negative inputs, catalog collation/order |
| `pxm_reservation_budget_idx` | Tenant/budget/state | Composite FK и positive cost; будущие reconcile queries используют этот путь |
| `pxm_*_health_probe_idx` | Tenant/open_until/id, open/half_open | CatalogSchemaTest: inventory/probe pair; CatalogCommandTest: provision без сброса health |
| `pxm_route_target_target_idx` | Tenant/target/route | CatalogCommandTest: создание пар в обоих порядках |
| `pxm_route_endpoint_idx`, `pxm_member_route_idx` | Tenant-scoped dependency lookup | CatalogSchemaTest: foreign tenant FK denial |
| `pxm_observation_attempt_idx` | Tenant/attempt | Receipt FK; completion behavior относится к следующей фазе |
| `pxm_audit_entity_idx` | Tenant/entity type/id/revision | CatalogCommandTest: atomic audit rollback, idempotency replay/conflict |

Основные файлы: `PackageSchema`, `CatalogProvisioner`, `DatabaseCatalogCommands`,
`DatabaseCatalogQueries`, `OperationMutex`. Тесты находятся в
`packages/core/tests/Integration/CatalogSchemaTest.php` и `CatalogCommandTest.php`.
Каждый сценарий создаёт собственную schema только после проверки marked
`proxyma_test` identity; teardown удаляет только эту schema. Core provider
проверяется без Filament provider. Negative scenarios: unauthorized write/replay,
missing metadata, stale revision, cross-tenant FK/member, invalid XOR/timeout/cost,
duplicate permit, repeated claim, immutable policy/audit, failed audit rollback,
revive/enable retired endpoint. JUnit и SHA/source digest создаёт package test rig.
