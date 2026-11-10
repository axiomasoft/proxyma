# Словарь Proxyma

**Health observation** — неизменяемый технический факт физической попытки. Не
содержит оценку полноты parser или adapter; поздний receipt дополняет историю
неопределённой попытки, не заменяя ранее известные факты.

**Health scope** — независимая область здоровья: маршрут, пара маршрут–target
или доступность target. У каждой области своё состояние и поколение (epoch).

**Health projection** — применение observation к одной области здоровья.
Факт сохраняется и тогда, когда его поколение уже устарело.

**Independence group** — явно объявленная независимость сетевого пути для
подтверждения недоступности target. Разные маршруты и egress сами по себе
не доказывают независимость.

**Настройка** — именованное правило сетевого поведения с типом, значением по
умолчанию и границами допустимых изменений.

**Scope политики** — область, для которой заданы изменения настроек: global,
tenant, panel или entity. Scope принадлежит определённому tenant.

**Patch настроек** — набор явных изменений настроек одного scope. `set` задаёт
значение, `inherit` удаляет локальное изменение, `disable` отключает только
необязательную настройку.

**Голова политики** — текущая версия настроек одного scope. Её ревизия определяет,
на какой версии основано следующее изменение.

**Ревизия политики** — неизменяемое состояние локальных изменений настроек после
одной принятой команды. Ревизия не является результатом разрешения всей иерархии.

**Панель** — именованный набор сетевых настроек для субъектов одного consumer.
Панель определяет доступные слоты и необходимые полномочия владельца.

**Слот** — отдельное назначение сетевой политики внутри панели. У слота есть свой
набор разрешённых настроек и правило наследования при отсутствии привязки.

**Субъект** — существующая сущность consumer, определённая tenant, типом и
непрозрачным идентификатором. Совпадение идентификаторов разных типов не означает
совпадения субъектов.

**Цепочка субъектов** — листовой субъект и единственная упорядоченная ancestry
от корня до его непосредственного родителя. В цепочке не более 16 субъектов;
один тип может повторяться, одна пара типа и идентификатора — нет.

**Привязка политики** — версионируемое назначение настроек и профиля маршрутов
субъекту в определённом слоте панели. Привязка принадлежит ровно одному tenant;
её отзыв сохраняет историю и позволяет снова наследовать разрешённые defaults.

**Кандидат маршрута** — маршрут из точного состава профиля, рассмотренный для
конкретного target с учётом разрешений, состояния и применимых ограничений.

**Снимок кандидатов** — наблюдение кандидатов и их ограничений в момент выбора.
Оно не является разрешением на сетевую отправку и может устареть до неё.

**Владелец исполнения** — субъект, чьи действующие полномочия, поколение и срок
жизни ограничивают продолжение операции. Владелец исполнения не равен субъекту
настройки политики.

**Кандидат допуска** — предварительный результат допуска физической попытки,
который ещё не даёт права отправить запрос.

**Подтверждение допуска** — свидетельство окончательного сохранения допуска;
оно связывает кандидата с завершённым общим решением всех участвующих сторон.

**Полученный receipt** — сохранённый факт результата физической попытки.
Его существование не означает, что текущий владелец принял результат.

**Операция** — ограниченное сроком и числом физических попыток сетевое намерение
с владельцем исполнения и необязательной связью с внешней операцией или попыткой.

**Demand ticket** — устойчивое место одной физической попытки в очереди допуска.
Повторное обращение к той же попытке не меняет её намерение, порядок и срок жизни.

**Набор конфликтов** — все budget scopes, одновременно ограничивающие одну
попытку. Две попытки конкурируют в очереди, если их наборы пересекаются.

**Reservation** — удержание capacity одной физической попытки одновременно в
нескольких budgets. Оно само по себе не разрешает физическую отправку.

**Reserve debt / send debt** — независимые общие расписания резервирования и
физического допуска отправки. Отмена отдельной попытки не возвращает расписание
назад для остальных участников.

**Sealed wire** — неизменное полное намерение одной физической отправки, включая
содержимое, назначение, выбранный маршрут и подготовленное удостоверение доступа.
Само намерение не даёт права отправки.

**Wire capability** — однократное право исполнить sealed wire до конечного срока
начала, выданное после окончательного сохранения допуска.

**Authorized but not dispatched** — допуск сохранён, но физическая отправка
отклонена до bytes. Такой факт не возвращает потраченный допуск и не разрешает
повторное исполнение той же попытки.

**Календарная квота** — предел числа физических допусков в фиксированном UTC
часе или дне для одной области и устойчивой политики учёта.

**Quota reservation** — удержанная единица календарной квоты до отправки. После
допуска она становится необратимым расходом; отменить можно только удержание.

**Traffic class** — настраиваемое имя назначения трафика. Его доля capacity
добавляет ограничение к общим лимитам и не даёт отдельный origin allowance.

**NeedsReplan** — завершение старого неиспользованного допуска, после которого
продолжение требует нового намерения физической попытки.

**Unknown after authorization** — допуск сохранён, но достоверный результат
попытки неизвестен. Освобождение её lease не доказывает отсутствие удалённого
эффекта и не разрешает повторную отправку.

**Reconciliation** — восстановление учёта по сохранённым фактам без повторного
исполнения неизвестных попыток.

**Completion fact** — проверенный результат одной авторизованной попытки,
связанный с исходным wire и неизменным receipt identity.

**Credential reference** — непрозрачная ссылка на удостоверение доступа в пределах
одного tenant. Ссылка не содержит логин или секрет.

**Credential version** — возрастающее поколение удостоверения endpoint. Замена
поколения делает прежнее подготовленное удостоверение непригодным для нового допуска.

**Origin TLS trust / proxy TLS trust** — независимые правила доверия сертификату
исходного узла назначения и сертификату HTTPS proxy соответственно.
# Destination preparation

`DestinationPolicy` checks the exact target origin and revision and validates every DNS answer outside a transaction. `ResolvedUrl` returned by that policy pins the complete address set and original host; the wire sealer rejects descriptors constructed directly. A redirect requires a new policy resolution and a new authorization. PHP-configured exact target, host and address exceptions are trusted authority and must only include isolated fixture addresses when used by the test rig.

**Proxy destination** — выбранный до admission endpoint с локально разрешённым IP.
Protocol, hostname, port, revision и credential version входят в sealed wire;
physical dispatch не перечитывает endpoint и не делегирует DNS proxy.

**Response profile** — закреплённые в wire fingerprint вид ответа и независимые
пределы wire, entity и text, а также default charset. Text декодируется после
проверки content encoding; binary сохраняет исходные entity bytes, включая gzip.

**Binary lease** — временный файл с единственным владельцем. Consumer принимает
его callback вне SQL и сохраняет метаданные после успешного копирования; ошибка
callback и close закрывают файл. Lease нельзя сериализовать или открыть по пути.
Временный файл tmpfile удаляется при закрытии либо завершении процесса; он не
регистрируется в recovery и не влияет на unknown attempt facts или DB retention.

`PhysicalHttpTransport` реализует один dispatch на fresh cURL connection:
direct HTTP(S), HTTP/HTTPS proxy forwarding/CONNECT и SOCKS5 local DNS.
Capability consume происходит после bind options непосредственно перед dispatch;
повторный consume, поздний старт и SQL transaction отказывают без socket bytes.
Protocol qualification и actual socket crash checkpoints описаны в `docs/transports.md`.

**Attempt facts** — неизменяемая сводка фактов одной физической попытки (идентификаторы
операции и попытки, номер повтора, шаг редиректа, маршрут, egress, версия учётных данных,
класс идемпотентности, HTTP-статус либо транспортный сбой, счётчики байт, дайджест receipt,
значения `Retry-After` и `Location`, воспроизводимость тела), передаваемая классификатору
и политике исходов без раскрытия секретов или сырого тела.

**Technical classification** — независимая техническая оценка фактов попытки
(`TechnicalOutcomeClassifier`), определяющая атрибуцию здоровья (`HealthAttribution`),
обязательные защитные флаги (`holdsProxyCredential`, `extendsOriginCooldown`,
`appliesWafHold`, `isAmbiguousAfterAuthorization`) и базовое решение до применения
пользовательской политики исходов.

**Outcome policy registry** — реестр политик классификации исходов с иерархией приоритетов
`SecurityOwnership > Project > Panel > Default`. Отвергает конфликты одного уровня при
заморозке и защищает обязательные инварианты безопасности (запрет повтора неоднозначной
записи после авторизации, удержание учётных данных при 407, запрет ротации маршрутов при 429)
от ослабления пользовательской политикой.

**Outbound executor** — оркестратор логической операции (`OutboundExecutor`), выполняющий
повторы и редиректы вне SQL-транзакции. Каждая физическая попытка и каждый шаг редиректа
проходят проверку остатка времени и лимитов до DNS и получают новый permit, новый claim и
заново запечатанный `SealedWire`. Правила оркестрации описаны в `docs/execution.md`.

**Origin cooldown** — независимый `budgets.cooldown_until`, продлеваемый через max
по исходному origin из authorization snapshot. Не зависит от burst/GCRA и проверяется
в admission и финальном send CAS. Даже stale epoch не отменяет консервативное
продление; атрибуция новому egress или изменённой цели запрещена.

**Cooldown snapshot** — authorization закрепляет origin, egress, endpoint/version,
profile revision и точный состав маршрутов, health epochs и зарегистрированную
WAF-интерпретацию policy. HTTP 429 продлевает исходный origin budget и явно
настроенные route-origin/egress-origin budgets через max; 403 открывает pair
только при закреплённом opt-in WAF. Retry-After ограничен 1..86400 секундами
от DB clock, fallback — 60 секунд. Registry и текущий catalog при receipt
не меняют интерпретацию. Отдельный healthy route не освобождает origin.

**Compound probe claim** — `ProbeCoordinator::claim(ReservationInput, leaseSeconds)`
переводит непустой точный набор due unknown/open scopes в half_open одной CAS
транзакцией. Token закрепляет operation, route, target, sorted scope/epoch set,
purpose probe и lease 1..300 секунд, ограниченный сроком owner/operation.
Повтор token запрещён; один claim допускает ровно одну physical attempt.
Перед reserve/send проверяется весь набор, а completion восстанавливает только
его scopes при сохранении всего владения. Потеря одного epoch или истечение
lease ограждает весь старый probe. Claim при обнаружении expired lease сначала
открывает circuit с backoff 30..300 секунд; новый token выдаётся после backoff.
Пустой набор, постоянный hold и отсутствующая health row не дают probe authority.

Probe отправляется через обычные reservation, common origin/route/egress budgets,
обязательный `origin.probeScopeKey()` budget и `ClaimSend`; `OutboundExecutor`
использует ровно закреплённый маршрут и выполняет не более одной отправки probe.
Boot provider не запускает outbound I/O и не регистрирует probe scheduler.

**Credential hold identity** — route health хранит исходный endpoint и credential
version. Success, probe и таймер не снимают hold. Matching credential rotation
снимает только этот hold, продвигает epoch и возвращает unknown для нового probe.
NULL credential version означает implicit version 1: первая установка secret
через rotation продвигает её до 2. `ResetHealth::handle(scope, id, tenant, actor)`
требует permission `health.reset`, reason, expected state_version и idempotency key;
аудируемый reset тоже возвращает unknown, повтор возвращает прежний outcome,
конфликт ключа отвергается. Reset не очищает quota cooldown.
Удаление credential у существующего endpoint запрещено: переход на endpoint без
credential требует новой identity, чтобы не переиспользовать implicit version 1.

Authorization facts, reservation references и budgets сохраняются как минимум
до late receipt horizon. Текущий pruner консервативно сохраняет все authorized
attempts и связанные исходные scopes даже после retention horizon; неизвестный
receipt и retired target не теряют исходную атрибуцию.
**Advisory admission diagnosis** — read-only policy explain + candidate snapshot,
с DB as-of time и revisions, без audit/reservation/last-authorized mutations.
Не является send authority; отправка повторяет locked validation.

**Operations metric sample** — bounded gauges по очереди и последним permits,
с конечными reason labels без URL/tenant; correlation UUID хранится в redacted log.
Lease inflight не доказывает число физических сокетов.

**Maintenance opt-in** — scheduler по умолчанию пуст; явное включение регистрирует
только bounded reconcile/prune в trusted tenant context. Probe — отдельный один
HEAD через обычный admission с exact scope claim. Facts/windows/health history
удерживаются консервативно; prune удаляет только безопасную preauthorization history.

**Extension definition** — versioned именованная регистрация одного конечного typed
порта. Freeze подтверждает dependency DAG, capabilities, priority и фиксированный
adapter tier; fingerprint включает schemas/grants. Config выбирает только ID
из trusted PHP providers, не runtime FQCN или mutable authority models.

**Budget scope schema** — namespace/version дополнительных scopes с tenant-bound
prefix и необязательными точными legacy keys. Shared system scope требует явного
consumer tenant grant; contributor не удаляет origin и не расширяет limits.

**Attempt observer** — локальный best-effort listener после committed completion.
Durable факты находятся в attempts/observations. Ошибка listener редактированно
сообщается, не меняет receipt и не повторяет отправку; общего event outbox нет.
