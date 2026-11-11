# Agelto: callers и предел физических отправок

`integration/agelto/caller-map.json` перечисляет каждый outbound entry и его composition;
`caller-inventory.log` сохраняет конкретные rg callsites. Directory allowlist отсутствует.
Базовые revisions: Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`, Proxyma
`93fc7c9f184e51022e3d901e61131db59c4df021`. Финальные commits и проверки фиксирует координатор.

Persisted `prc_network_authorities` выбирает engine. Legacy остаётся default до drain.
При package generation общий конечный OutboundExecutor loop использует CallerAttemptGate:
package catalog, budgets, owner validation, sealed capability, transport и completion.
Перед legacy claim selector generation проверяется под SQL share lock; package claim проверяет
тот же selector и атомарно ставит irreversible marker journal. Ошибка package path не запускает legacy.
Каждый retry/redirect имеет отдельный permit; количество sends и distinct routes ограничивает
caller loop. Повтор URL блокируется. Cross-origin сохраняет только безопасные payload headers.

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
`NetworkRouteProfilesPage` остаются единственными редакторами. После переключения
источник чтения и команды выбираются тем же selector; legacy UI сохраняется до P10.3,
но больше не принимает изменения. Нет dual-write и автоматического fallback.

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
`scenario_proof` не меняются. Пока legacy UI сохранён, исходные UI-тесты также остаются.

До проверки нужен coordinator commit/repin обоих пакетов на один SHA и refresh
изолированного rig. Карта transfer задаёт обязательства проверки; успешность доказывает
только actual consumer JUnit финального candidate. P10.3 может удалить старые тесты
лишь с сохранённым source-to-replay mapping и прошедшими replacements.

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
