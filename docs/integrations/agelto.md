# Agelto: callers и предел физических отправок

P10.4 закрыт по решению владельца D19 с отклонениями. Полная приёмка приватной 0.1
остаётся незавершённой; оптимизация и исходная матрица проверок перенесены в
[PLAN2:P1.1](../../integration/agelto/performance-followup.md).

`integration/agelto/caller-map.json` перечисляет каждый outbound entry и его composition;
`caller-inventory.log` сохраняет конкретные rg callsites. Directory allowlist отсутствует.
Базовые revisions: Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`, Proxyma
`93fc7c9f184e51022e3d901e61131db59c4df021`. Финальные commits и проверки фиксирует координатор.

Persisted `prc_network_authorities` разрешает исполнение только после drained cutover на
package generation. До switch новые отправки получают configuration hold. Generic resolver,
selector, policy loop, transport, reducer и duplicate UI удалены из Agelto.
`CallerOutbound` преобразует domain envelope и storage artifact; конечные retries/redirects
выполняет package `FiniteOutboundExecutor` через `FiniteExecutionPort`.
Каждый физический send получает отдельный immutable package operation/permit и заново
проверяет Process owner, текущую policy и adapter health. Redirect использует только явно
mapped target той же platform/provider/capability; отсутствующий mapping, downgrade и cycle
запрещены до следующего send. Cross-origin удаляет credentials. Caller ceilings пересекаются
с текущей policy; задержку задаёт уже разрешённая effective policy, общий send limit не расширяется.
Package claim проверяет selector generation и атомарно ставит irreversible marker journal.

Adapter health, validation receipt, process work budget, terminal HTTP evidence и source TLS
остаются Agelto-owned. Package completion служит provenance для validator и diagnostic counters.
Package physical IDs сохраняются в validation details; nullable legacy request FK заполняется
только для legacy attempts. TLS adapter хеширует выбранный SourceTlsTrust CA bundle вне SQL,
чтобы sealed dispatch мог проверить его неизменность.
Adapter canary с закрытым network path использует diagnostic admission; при network hold получает
отдельный package probe token для physical attempt, сохраняя project adapter owner и его token.
Provider generation/file effects продолжают использовать joint financial gate из P9.3;
generic caller разрешает только объявленный read-only provider probe. Unowned OpenAI запрещён.

DownloadStorageRepository суммирует исторические legacy attempts и package authorization facts.
Package operation хранит process owner/generation и parent attempt, поэтому signed refresh и
дочерний canary расходуют тот же download ceiling. Reservation FK остаётся привязанным к
project outbound operation. Binary artifact передаётся в owned temporary storage вне SQL,
с проверенными length/hash и cleanup. Gzip artifact содержит decompressed bytes; wire counter
учитывает compressed bytes отдельно. Binary profile допускает существующий конечный предел
Agelto 256 MiB; default transport и text buffering сохраняют 64 MiB.

Telegram также перечислен явно. При настроенном bot token catalog sync объявляет notification
target до transfer. После переключения отдельный diagnostic owner отправляет один ambiguous POST;
неуспех оставляет alert без dedup-ключа до следующего тика. Credentials не попадают в catalog.
Перед cutover нужны target/profile mappings и policy binding для notification system subject.

Локальные fixtures: ProxymaCallerParityTest и DownloadSendAccountingTest в Agelto Network;
package BoundedResponseTest проверяет gzip bytes/hash и отрицательные resource bounds.
Executor запускает только целевой файл; V1–V4 и итоговая SHA-bound qualification принадлежат conductor.

## UI и права

Admin panel регистрирует `ProxymaPlugin`. До переключения `prc_network_authorities`
package pages скрыты и прямые команды запрещены: legacy `ProxyResource` и
`NetworkRouteProfilesPage` остаются единственными редакторами. Редакторы используют только package catalog и проверяют тот же selector;
старые ProxyResource и NetworkRouteProfilesPage удалены. Нет dual-write и автоматического fallback.

Consumer `UiTenantContext` выводит `system` tenant и operator из authenticated User.
`UiAuthorizer` проверяет точный actor и tenant, затем AzGuard `ProxyPermission` для
панели admin. List/read соответствуют ViewAny/View; upsert различает Create/Update,
retire требует Delete, policy/binding/credentials/reset — Update. Неизвестные abilities
запрещены; Process permissions и `App\User` не попадают в package. Probes не получают
неявного разрешения: их project-owned capability wiring остаётся отдельным контрактом.

`CatalogMutationActor.authorityVersion` — ожидаемая opaque версия selector.
Новый core port `CatalogMutationFence` по умолчанию допускает единственную standalone
authority. Agelto требует `engine:generation` и берёт share lock той же строки selector
в начале SQL-транзакции catalog/policy/binding/credential/reset команды, до package
locks. Проверка revision и audit остаются в той же транзакции. Cutover обязан менять
эту строку атомарно: он ждёт текущего редактора, старая форма получает conflict.
Внутри fence выполняется только SQL, без HTTP/DNS/secret-store/object I/O.

Crawler/platform forms встраивают `ConfigureProxymaAction` с package settings section.
SubjectRef строится по сохранённой owner-записи, target — по актуальному mapping её
площадки. Нет mapping — действие недоступно. Scheduling, dates, cursor, parser и
filter остаются в Agelto. BindingEditor сохраняет только state своей секции с
encrypted context, expected revision и generation. Старые owner edit forms также
отвергают смену selector; legacy route-profile field после switch не сохраняется.

`throttle.requestsPerMinutePerProxy` убран из формы. DTO продолжает читать старое
значение для совместимости; оно не участвует в admission и при пересохранении формы
может исчезнуть из JSON. Origin quota общая для маршрутов и не умножается на их число.
`ProxyScope` сохраняет только описательные метаданные; authority задают membership
и effective policy, включая явное разрешение direct. Пустой профиль означает deny.

## Перенос сценариев

`integration/agelto/ui-and-provenance.json` хранит owning paths, revision и результаты.
Consumer `ProxymaUiAuthorityTest.php` проверяет pre/post-switch writes, stale forms,
подмену permission/actor/tenant, exact membership, write-only credentials и embedded
patch. `scenario-transfers.json` ссылается на точные исходные file/name и эти consumer
тесты; package test names не заменяют Agelto JUnit. Frozen original baseline и
`scenario_proof` не меняются. Удалённые implementation tests сохранены в архиве `RetiredNetwork`; их точные
исторические identities связаны с consumer UI и native contract replay.

До проверки нужен coordinator commit Agelto, подтверждение общего установленного SHA
обоих пакетов и refresh изолированного rig. Исправление Core требует повторной
package qualification и общего re-pin обоих пакетов; точный SHA хранится в
`integration/agelto/candidate.json` и сверяется с установленным vendor.
Карта transfer задаёт обязательства проверки; успешность доказывает
только actual consumer JUnit финального candidate. `proxyma-contract-replay.json`
фиксирует source paths/hashes package tests; consumer исполняет их в отдельных disposable
схемах guarded `agelto_test`. Все перенаправления остаются обязательствами до зелёного JUnit
кондуктора; Unit ownership test не заменяет migration, upgrade или regression checks.

Runtime использует production `AgeltoTenantContext`/`AgeltoAuthorizer`: UI по-прежнему
проходит через AzGuard, а worker получает короткий `RuntimeCaller` execution grant только
после SQL-проверки live owner, token/generation, controls и ownership target. Grant
охватывает один outbound loop и очищается в `finally`; callback, DNS и transport
идут вне SQL. Он разрешает policy/binding execution, catalog reads/select и создание/
отмену execution operation/reservation, очередь demand, owned artifact и receipt своего
operation ID. Probe claim доступен только live probe owner. Этот grant
не даёт UI mutations, lists, health/reset или доступа к чужому tenant. Prepared
provider transport повторяет проверку owner перед своим physical send, не меняя
совместную финансовую/network claim и receipt/settlement. Отдельный receipt-only grant
проверяет immutable attempt/operation и исходные owner token/generation; потеря live
owner после HTTP не мешает завершению, но не даёт повторного права отправить запрос.

Для повторной проверки уже running Process owner `NetworkOwnerFence` использует
`FOR SHARE` только на capacity lanes. Work, subjects и storage domains сохраняют
эксклюзивные блокировки; порядок полного authority prefix и проверки token/generation,
lease, controls, handler, intent и exact membership не меняются. Admission, finalize,
recovery и остальные mutation paths используют прежний `FOR UPDATE` по умолчанию.
Совместный financial/network claim не изменяет Process lanes после этой проверки.

Этот режим допускает независимые проверки владельцев и сохраняет запрет изменения
lane до завершения транзакции: [PostgreSQL `FOR SHARE`](https://www.postgresql.org/docs/current/explicit-locking.html#LOCKING-ROWS)
конфликтует с изменением строки. Регрессия `ProxymaOwnerAdapterTest` проверяет две
реальные PostgreSQL-сессии: совместимый reader проходит, capacity writer и reader
при mutation prefix получают `55P03`. Локальный отчёт
`20261114T024924.655348Z-921259` содержит 26 тестов и 48 assertions. Это доказательство
границы блокировки; итоговая installed-приёмка остаётся незавершённой из-за throughput.

Caller берёт panel/slot формы (`agelto.acquisition/listing|detail|status_refresh`,
`agelto.document/download`, `agelto.reference/reference`) и trusted subject chain
из persisted Process owner, включая crawler origin run и registry/platform ancestors.
Standalone diagnostics без crawler owner наследуют platform. Notification имеет
отдельный `agelto.notification/notification`; provider сохраняет `agelto.provider/llm`.
Package wire traffic key совпадает с generic slot; исходный Agelto TrafficClass
остаётся у project caller и его retry/owner controls. При переносе traffic-specific
windows в P10 требуется соответствующее явное сопоставление legacy классов и slots.

Новый запрос читает текущую package revision и membership активного профиля.
Legacy ProfileLink определяет перенесённую identity, не замораживает новый трафик
на revision импорта. `snapshotProfile()` отдельно сохраняет исходные ID/version
исторического snapshot: UI save не переписывает эти mappings.

Owning outbound loop применяет минимум effective и caller limits для attempts,
redirects и distinct routes. Верхние границы full-jitter delays также пересекаются;
новый physical operation не сбрасывает лимиты loop. Повторное разрешение сверяет
позицию loop с актуальной policy до создания operation и claim.
Download probe preflight читает effective package profile с теми же panel/slot и
owner chain, затем использует текущий membership и health для выбора candidates.
Пустой legacy membership после переключения не блокирует маршрут, добавленный через
package UI; legacy catalog при этом не меняется.


## Историческая совместимость после удаления runtime

`App\Models\Procurement\Crawler\Proxy` содержит только table attribute `prc_proxies`.
Единственный caller — неизменная migration `2026_07_04_100040`. Три additive forward migrations
`2026_11_11_100000`–`100002` используют отдельные `Legacy*` table carriers; изменены только
imports/getTable identities, DDL сохранён (ответ владельца Q1). Runtime imports этих carriers
запрещает `ProxymaOwnershipBoundaryTest`.

Исторические route/profile/budget/permit/request и FK tables физически сохранены. Inventory,
transfer и cutover читают их через точные read-only exceptions. Download ceiling, adapter
provenance и historical reader сохраняют старые physical IDs. Ни reaper, ни provider, ни
operator command больше не записывает legacy network tables. Profile snapshot adapter читает
старые IDs/version без secrets, hooks и route selection; после switch использует mappings.

Proxy seed credentials больше не создаются из URL/environment в Agelto. `EisCrawlerProxySeeder`
проверяет заранее созданные package profiles; audit seeder использует mapped `eis.audit` и
продолжает отвергать reseed активного Process run. Новую конфигурацию оператор создаёт через
Proxyma UI/commands. Crawler сохраняет scheduling/filter и historical profile identity.

Ответ Q2 реализован additive package port: immutable operation target не меняется при redirect;
следующий hop получает новый operation с явным mapped target. SQL authority/schema и совместный
LLM financial claim не меняются. HTTP/DNS/secrets/artifact I/O остаются вне SQL transactions.

`integration/agelto/legacy-removal.json` перечисляет точные paths обоих checkout и revisions.
Координатор квалифицирует финальный SHA: V1 ownership, V2 manifests/layout, V3 два clean resets
полного migration graph, V4 populated old baseline upgrade. После первой новой authorization
поддерживается pause + roll-forward fix; возврат одного legacy кода не является rollback.

При проверке старого acquisition snapshot adapter сначала ищет immutable mapping по точным
source profile ID и version, в том числе до switch. Current historical profile revision
используется только пока mapping отсутствует; неизвестная версия отклоняется. Повреждённый
cutover checkpoint останавливает перенос, не подменяя сохранённые фазы пустым состоянием.
# Финальная installed-приёмка

До native consumer report статус остаётся private candidate. Команда
`python3 scripts/package-check.py --accepted integration/agelto/acceptance.json`
отказывает для pending descriptor, устаревшего package proof, другого runtime или
неполного consumer report. `PROXYMA_AGELTO_ROOT` задаёт checkout; также можно передать
`--consumer-root`. Collector читает фактические Agelto gates, проверяет исходные
artifacts и повторно собирает summary без запуска тестов.

В regression обязательны отдельные файлы owner/catalog/UI, caller/send accounting,
joint financial claim, transfer, populated upgrade, cutover и rollback. Исчезнувшие
network test paths в T01–T48 заменены именованными replay inventories; каждый binding
сохраняет hash исторического source и его исходную group. Эти inventories получены
через Pest discovery, а успешное выполнение подтверждается только actual JUnit.
Frozen original-executed-scenarios и scenario-transfers остаются прежними.

Все семь финальных gates требуют одинаковые image/config/dependency identities и
hashes файлов установленных пакетов внутри app container. Host Composer path
references сверяются с pinned manifest; symlink vendor не считается установкой.
Установка в fixture не разрешает production rollout или публичный release.
Полная последовательность — [agelto-acceptance.md](agelto-acceptance.md).

Consumer adapters сохраняют отдельный отказ `paused` и проверяют financial/owner state
во время ограниченного ожидания provider reservation вне SQL transaction. Runtime
fact projections читают package journals; diagnostic purpose берётся из operation,
поскольку FIFO queue использует собственные значения `normal/probe`. В adapter
observation package request IDs остаются в immutable `details.request_ids`, а legacy
`request_id` FK остаётся пустым для package attempts.

Telegram endpoint в Agelto допускает `services.telegram.api_url` с прежним default
`https://api.telegram.org`. Изолированный тест задаёт локальный wire endpoint,
явный source catalog и URL policy; уведомления проходят тот же package send gate.

Ответ `Q104_correction_ownership=b` разрешает точное исключение из удаления runtime
дублей: Agelto содержит `AgeltoPhysicalHttpTransport`, основанный на package source
`393ec0f68eb38eef0fb698734d3e3e6da85fb2af`, с сохранением download headers и partial
decoded-byte counters. Ownership guard допускает cURL только в этом consumer классе.
Также разрешён перевод начальных package health rows из `unknown` в due `open`
перед probe; существующие holds не сбрасываются, время задаёт PostgreSQL.
Эти consumer exceptions учитываются отдельно от package qualification текущего candidate.
