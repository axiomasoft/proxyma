# Agelto adapters до cutover

Agelto регистрирует package ports, но active network runtime остаётся прежним.
Discovery core и Filament отключён в consumer Composer manifest.
Перед rig target discovery cache пересобирается: отдельный bootstrap volume может
содержать providers от прежнего Composer manifest.
`App\Providers\ProxymaServiceProvider` подключает
`InactiveProxymaServiceProvider`: доступны package bindings и frozen definitions,
без package migrations, scheduler и UI. Package authorizer остаётся deny-by-default.
Полный provider и package schema подключает отдельный cutover.
Frozen registry пересоздаётся в каждом Laravel scope вместе с guard/resolver:
long-lived worker сохраняет fingerprint definitions, но не runtime context прошлого job.
Active wiring после cutover должно сохранить это scoped правило.

## Владельцы и lock order

`ProcessOwnerAuthority::reference()` принимает исходный `ExecutionLease` либо
`OutboundOperationHandle`, для AI — также `ProviderKey`. Возвращённый `OwnerRef`
действует в текущем container scope; его нельзя восстанавливать из токена в payload.
После heartbeat caller передаёт обновлённый lease. Snapshot хранит исходную Process
authority, input hash/revision, control versions, adapter attachment и persisted
selector generation. Несколько snapshots одного owner могут существовать одновременно.

Locked validation использует исходный `NetworkOwnerFence`: controls/subjects/work/
attempt предшествуют finance/network. Сохраняются active intent, exact membership,
handler health/version, lease, generation, token и standalone parent attempt.
Selector читается под SHARE в owner prefix; legacy или изменившаяся generation
запрещает package admission. Owner adapter подтверждает тот же Connection и PDO,
которыми пользуется Agelto default connection.

`AdapterSendGuard::prepare()` закрепляет исходный domain plan за physical attempt
до reserve/claim. Guard проверяет target mapping, source ownership, prepared method/
URL/body, endpoint profile и ограничение timeouts. Он делегирует adapter-health
проверку `AdapterHealthRepository::lockForSend()` в объявленном adapter tier.
Guard не берёт controls, owner, finance или budget locks.
HTTP 200 не становится parser validation; canary/probe token и epoch остаются
project-owned. Новый physical attempt получает новый plan и ID.

## Каталог, bindings и TLS

`TargetCatalogAdapter` требует явный source→target UUID link и точные
platform/provider, capability, origin и source revision. Redirect требует
объявленный target того же owner/capability с точным origin. DNS, SSRF и physical
destination checks остаются обязательной частью package transport.

`BindingAdapter` делегирует live crawler/procurement resolution прежнему
`ProxyBindingResolver`, включая отказ при нескольких активных route profiles.
AcquisitionConfiguration v1–v3 сохраняет старые profile IDs/versions; stage mapping
возвращает точные package profile ID/version. Отсутствующий mapping запрещает
исполнение. Adapter не выбирает маршруты и не рассчитывает бюджеты.

Регистрация definitions не выполняет SQL. Panels содержат acquisition slots
`listing/detail/status_refresh`, document `download`, reference `reference`
и provider `llm`. Разрешены только network settings, без финансовых полей.
Subject resolver строит crawler/procurement→registry→platform цепочку и отказывает
для чужого tenant или отсутствующей сущности.

`TlsTrustAdapter` получает host-specific bundle исключительно через
`SourceTlsTrust`, для origin и HTTPS proxy. Отсутствующий override оставляет
системное доверие. TLS peer/host verification всегда включена. Adapter нельзя
вызывать внутри SQL transaction.

## Additive schema и следующие шаги

Forward migration создаёт шесть project-owned таблиц:
`prc_proxyma_{target,profile,binding,owner}_links`,
`prc_network_authorities`, `prc_network_cutover_journals`.
Unique keys защищают source identity и package correlation. Начальное состояние
selector — `legacy`, generation 1; links и journal пусты.
FK package→consumer не появляется. Старые migrations, runtime и history сохранены.
Schema inventory содержит декларацию нового DDL, а не выдуманный install capture;
реальный каталог сверяет isolated install check кондуктора.

Joint-claim использует тот же scoped owner adapter и ConnectionScope, откатывает
financial mutation при network denial и активирует capability после outermost commit;
подробности — `agelto-joint-send.md`. Caller migration закрепляет каждый physical
plan через guard и заполняет стабильную operation/parent correlation. Populated
transfer заполняет mappings; cutover управляет selector/journal. Joint claim записывает
первый durable authorization в journal до возможной отправки. Начальный journal schema сам по себе
не реализует drain, перенос counters или rollback.

## Проверка поведения

Actual Agelto tests:
`tests/Feature/ProcessCore/Network/ProxymaOwnerAdapterTest.php`,
`ProxymaCatalogAdapterTest.php`; fixtures —
`tests/Support/ProcessCore/ProxymaFixtures.php`.
Исторические `AdapterSendFenceTest` и `ProviderOwnershipTest` остаются без изменений.
Package `ConsumerAuthorityContractTest` отдельно отвергает consumer guards,
объявившие owner/route/budget/CAS tier вместо adapter tier.

Точечная DB-проверка выполняется только через `make core-test-file TEST_FILE=...`.
Список реально исполненных команд, cwd, revision и результатов хранится в
`integration/agelto/adapter-changes.json`. Отсутствующий Docker socket означает
pending consumer checks, а не успешные DB assertions.
