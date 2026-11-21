# Транзакционная authority

`Proxyma\Application\Admission\TransactionCoordinator` использует одну
PostgreSQL `Connection`, её PDO session и Laravel transaction manager.
Контейнер выдаёт один coordinator и `ConnectionScope` на scope worker/request.
`Proxyma\Contracts\TransactionCoordinator` указывает на тот же экземпляр.
Production-путь прежнего `Laravel\DatabaseTransactionCoordinator` использует
этот же механизм; custom driver сохранён для исторических contract fixtures.

`run()` снаружи транзакции открывает и завершает собственную транзакцию.
Внутри существующей Laravel transaction он присоединяется к ней. Coordinator/
`ConnectionScope` разрешают из контейнера до начала owning transaction: hook
снимает monotonic clock перед её началом. Позднее подключение без этого sample
отклоняется.
`locked($connection, $closure)` требует активную caller-owned transaction,
проверяет точный объект Connection и PDO, не открывает savepoint и не делает
commit/rollback. Совпадения имени connection или database недостаточно.
Raw PDO transaction без Laravel transaction record запрещена. Context связан с
точным transaction record; подделка, другая connection и уже завершённый scope
отклоняются.

Consumer делает owner snapshot до входа в блокировки. Внутри closure он берёт
owner prefix, finance и затем network. Любой отказ после finance должен выйти
наружу как `AdmissionRejected`; `false`, `ClaimDenied`, `ExecutionBlocked`,
`ExecutionDeferred` и `PermitWait`, возвращённые из closure, coordinator сам
превращает в это исключение. Caller обязан пропустить исключение до owning
transaction, чтобы Laravel откатил все изменения. Перехват отказа внутри
транзакции с последующим commit нарушает контракт composition.

## Порядок блокировок

`AuthorityLocker` хранит позицию для outer transaction. Пропуск неприменимого
tier разрешён; возврат назад и повтор уже пройденного ключа запрещены.
Несколько rows одного tier запрашиваются по стабильным ключам в byte order;
budget/policy scope keys сохраняют C ordering. Для нескольких tenant сначала
упорядочивают пары `(scope_key, tenant_key)`; несколько operation roots берут
в порядке UUID до последующих tiers.

| Tier | Rows |
| --- | --- |
| Owner | Project control → subject → work → attempt prefix |
| Finance | Project accounts → periods → requests → calls → assets |
| Operation | Package root mutex |
| Policy | Policy heads по scope key |
| Profile | Выбранные profile rows |
| Endpoint | Endpoint для proxy |
| Route | Route |
| Route health | Route health |
| Route-target health | Route-target health |
| Target | Target |
| Target health | Target health |
| Adapter guard | Только объявленные project adapter rows |
| Budget | Scope keys в C ordering |
| Demand | Demand |
| Permit | Permit |
| Attempt / observation / application | Факты и их применения |

`rows()` блокирует только существующие материализованные rows. Missing state
вызывает typed configuration rejection; под поздними locks нет UPSERT.
Проверки membership, health, budget и final CAS строятся поверх этих locks в
последующих admission этапах. Сам lock helper разрешения на send не выдаёт.

Project owner, finance и adapter guard реализуют `SqlConnectionParticipant`.
`OwnerAuthority.connectionName()` само по себе не доказывает общую session.
Owner adapter отвечает за точный порядок собственного prefix, input/control
versions, subject generations, cancellation и token. `AuthorityLocker.owner()`
сверяет snapshot identity и live state, проверяет deadline свежим
`clock_timestamp()` после блокировок и сохраняет более ранний snapshot/live
deadline. `operation()` повторно проверяет сохранённые owner identity/token/
generation, active status, revocation и deadline на root mutex.

Standalone `DatabaseOwnerAuthority` использует operation root как собственный
owner prefix: `OwnerRef.ownerId` равен UUID operation, сохранённому также в
`operations.owner_id`. Его snapshot включает revision; смена revision,
generation/token, cancellation, revocation или deadline запрещает старый claim.
Такой root не нужно повторно брать через `operation()` — он уже заблокирован
первым package lock. Project adapter использует внешний prefix и затем
`operation()` после finance.

Project `LockedAdmissionGuard` здесь допускается только в `AdapterGuard` tier,
после target health и до budgets. Его результат может только запретить claim.
Guard не должен брать ранние owner/finance locks. Named lock trace находится
только в тестовых fixtures; production debug hook или запись token не добавлены.

## Commit handoff

`issueCandidate()` возвращает inert, nonserializable `ClaimCandidate`.
Candidate связан с локальным latch, PDO и outer transaction. Commit callback
зарегистрирован на точном root record данной connection. Savepoint release его
не активирует. Rollback savepoint инвалидирует выданные внутри candidates;
outer rollback и commit failure инвалидируют весь handoff.

После успешного outermost commit `activate()` требует transactionLevel zero,
известный локальный candidate, совпадающие wire identity/fingerprint/snapshot и
неистёкший monotonic latestStart. Каждый candidate активируется один раз;
несколько candidates одного commit независимы. Process restart не восстанавливает
latch по persisted IDs. afterCommit callback только меняет latch, отправки нет.
Monotonic clock снимается до открытия outer transaction, раньше DB authorization
interval. Ожидание locks/commit уменьшает
доступное время отправки.

Нормализация, DNS, secrets, TLS и body/object подготовка выполняются до SQL
transaction. `ConnectionScope.assertOutsideTransaction()` — граница для таких
адаптеров и отправки. Она проверяет и Laravel level, и реальную PDO transaction;
это не глобальный перехват произвольных HTTP/file функций consumer. Retry closure
содержит только повторяемую DB работу; внешние эффекты туда не помещают.

Late receipt сохраняется независимо от того, принимает ли его действующий owner.
Completion берёт operation root и совместимый network subset, не owner/finance
prefix. Потеря владельца не стирает факт доставки; acceptance результата и health
reducer отдельно проверяют свои версии. Durable completion/reducer реализуются
в следующих этапах; текущий fixture доказывает порядок и независимую запись.

## Provenance и проверки

Порядок транзакции, отказ network claim и отсутствие owner prefix на late receipt
проверяет `packages/core/tests/Contract/TransactionCompositionTest.php`.

`TransactionCompositionTest.php` использует отдельную schema в отмеченной
`proxyma_test` и реальные PostgreSQL transactions. Проверены совместный rollback
finance/network при exception/false/denial, Connection/context mismatch, полный
commit handoff с savepoints и deferred FK commit failure, owner generation/
cancellation/deadline/version drift, guard tier и отсутствие owner prefix на late
receipt. Это sequential composition evidence; изменения owner другим connection
не обозначаются доказательством одновременной гонки. Interference races относятся
к последующим phase checks.

## Bounded operations и FIFO demands

`OperationCommands.create()` создаёт generic operation с owner claim, profile,
target, конечным deadline и пределом физических попыток (1–10). Внешний
`externalOperationKey` и `parentAttemptId` — только correlation, без FK на
consumer/process tables. Точное повторение команды не меняет deadline или
revision; другое намерение с тем же UUID запрещено. Создание и cancellation
проверяют trusted tenant context и Authorizer до transaction. Новый operation
ограничен hard maximum одного часа; renew API здесь не добавлен.

`DemandQueue.enqueue()` возвращает immutable `DemandTicket` либо `DemandWait`
с reason/retryAt. Identity — `(tenant, operation, physical attempt UUID)`.
Другой wire fingerprint, request deadline, route/credential snapshot или набор
budgets при повторе дают typed conflict. Ticket TTL по умолчанию 30 секунд,
hard maximum 60 секунд; он ограничен operation, request и live/snapshot owner
deadlines. Retry возвращает прежние UUID/sequence/expiry, включая terminal ticket,
и не создаёт бессмертного waiter. Терминальная история остаётся для deduplication;
по timestamp она больше не занимает очередь. Число distinct physical attempts
ограничено operation даже после cancellation/expiry.

Conflict set включает обязательный materialized origin budget, настроенные
route-origin/egress-origin и дополнительные policy scopes. Для неизвестного
shared egress требуется materialized pool budget. Tenant и system ограничения
применяются одновременно. Membership и egress сверяются с catalog до чтения
budgets; смена egress snapshot требует нового плана. Все scopes должны существовать заранее; очередь не
создаёт budgets. Обязательные дополнительные policy scopes передаёт resolver;
финальная admission authority повторно пересекает live policy перед permit.

Enqueue под operation root блокирует весь conflict set в C ordering до INSERT.
Sequence выдаётся после этих locks: пересекающиеся enqueue сериализованы, а
повтор попытки не меняет порядок. По каждому budget разрешено максимум 64 live
pending tickets (конфиг может сузить лимит); счётчик включает active и admitted.
Проверки всех scopes предшествуют вставке, поэтому queue-full не оставляет
частичных tickets/связей. PostgreSQL защищает attempt uniqueness, неизменяемость
identity/lifetime, необратимость lifecycle и точный sealed scope set, включая
deferred проверку набора связей при commit. Legacy rows сохраняются и не выдаются
как новые immutable tickets.

`take()` выдаёт durable scheduler handoff (`DemandState::Admitted`), а не permit,
quota reservation или send capability. Он проверяет FIFO и readiness на всех
budgets. Для каждого старшего конфликтующего ticket читается его полный набор,
затем объединённые budgets блокируются в одном порядке. Готового старшего
обгонять нельзя. Неготового можно пропустить, но не более `maxEligibleSkip`
(default 8, hard maximum 64) за решение. После восстановления readiness старший
снова имеет приоритет. При достижении skip bound возвращается retryAt до его
readiness/expiry. Scan ограничен 1024 старшими конфликтующими tickets; превышение
означает typed rejection. Глобального strict FIFO для независимых scopes нет.

Старшие operation rows читаются без блокировки и не изменяются: их terminal
status/deadline могут только убрать препятствие. Поэтому take не захватывает
чужие operation roots после budget locks. Новая более ранняя конфликтующая
запись не появляется после snapshot: enqueue выдаёт sequence только под общими
budget locks, а ticket никогда не возвращается из terminal/admitted в active.

До каждого enqueue/take заново снимаются owner snapshot и project `WorkBudget`.
`WorkBudget.canStart(Duration)` работает вне SQL и может отказать из-за work
slice; Process context в очереди отсутствует. Locked owner revalidation повторяется
внутри transaction. Queue сама не спит: caller получает bounded retryAt, ждёт
вне transaction и обращается снова. Финальный UPDATE использует свежий
`clock_timestamp()`, поэтому истечение TTL во время ожидания SQL lock не даёт
admission. GCRA reserve/send debts и quota/inflight читаются для readiness, но
queue не меняет accounting и cancellation не отматывает debt.

Cancellation ticket блокирует его operation root, полный scope set и demand;
повтор terminal cancellation идемпотентен. После создания permit cancellation
передаётся accounting-aware admission/reconcile, иначе очередь могла бы забыть
lease/quota. Переход scheduler handoff и реальное резервирование budgets должны
выполняться одной owning transaction при подключении следующего admission этапа.
Самостоятельный `take()` не доказывает наличие capacity к моменту будущего send.

`BoundedDemandTest.php` связывает identity/correlation, FIFO, TTL, cancellation,
scope isolation и negative DB guards с исходниками. `DemandFifoTest.php` запускает
независимые PHP процессы/PG sessions, удерживает общий budget lock до наблюдаемого
барьера и проверяет queue bound, eligible FIFO, cancellation/take и expiry при
ожидании. Тестовый trace/worker не входит в production API.

## Atomic reservations и GCRA

`ReservationService.prepare()` выполняется до transaction: проверяет trusted
tenant/permissions, выбранные catalog references, request/policy identity,
снимает owner/work snapshots и вызывает выбранные finite registry IDs
`BudgetScopeContributor`. Contributor возвращает только дополнительные/более
строгие `BudgetConstraint`; mandatory origin сохраняется, probe добавляет свой
scope. Scope names разрешаются только среди tenant/system budgets, поэтому
contributor не выбирает чужой tenant. Внешние DNS/secret/object обращения внутри
reserve closure отсутствуют; contributor повторно под locks не вызывается.

Порядок API: prepare → enqueue prepared scopes → reserve. Набор scopes входит в
immutable demand; поздний contributor или изменившийся набор требует нового
плана, а не добавления связей к старому ticket. Уже materialized route-origin,
egress-origin, policy и EXTRA scopes учитываются одновременно. Missing scope
даёт hold без UPSERT. Budget owner сохраняется отдельно от consumer tenant в
reservation reference, в том числе для одновременных system и tenant constraints.

Standalone `reserve()` владеет transaction. `reserveLocked()` требует context
той же Connection, соединяет FIFO handoff и все accounting updates в общей
transaction. Composable caller сначала берёт owner prefix, затем finance и
передаёт LockedOwnerState; первый package mutex — operation. Catalog/policy,
health/adapter и budgets блокируются в объявленном порядке до demand/permit.
Все applicable budgets и полные наборы старших FIFO tickets заблокированы до
первой записи. Перед accounting проверяются capacity, integer GCRA/config,
immutable intent и deadline свежим `clock_timestamp()`.

Любой wait из Locked API выбрасывает `ReservationDeferred`; он должен дойти до
owning transaction и откатить предшествующую finance mutation. Standalone метод
преобразует это исключение в `DemandWait` только после rollback. Точный повтор
reserve возвращает тот же permit/slot/expiry без новой lease или debt. Другой
cost, wire body/headers, route либо sealed scope set отклоняется.

Чистый `Domain\Budget\Gcra` использует integer microseconds и проверяет overflow
до сложения/умножения. Reserve вычисляет общий slot, затем продвигает `tat` и
увеличивает inflight во всех scopes либо ни в одном. `send_tat` остаётся
независимым debt для финального send admission. `BudgetRepository.availability()`
— отдельное advisory чтение/вычисление: оно не создаёт permit и не заменяет
повторную проверку под locks. Временный capacity/rate hold возвращает retryAt;
финальная physical-send authority остаётся отдельным этапом.

`cancel()` берёт operation root, budgets, demand и permit; persisted references
освобождаются ровно один раз. Используется существующий `PermitState.transitionTo`;
после durable authorization before-send cancel запрещён. Shared `tat` и
`send_tat` не отматываются. Изменение reservation intent/reference и возрождение
released lease запрещены DB guard. Rollback миграции с новой историей запрещён.

Fixed UTC hour/day accounting выполняется вместе с rate/concurrency через
отдельный quota ledger; подробности и provisioning API описаны в `budgets.md`. Reservation выдаёт только расписание и lease references. Wire capability появляется
после отдельного final claim CAS и успешного outer commit.

`MultiScopeReservationTest.php` проверяет simultaneous origin/route/egress/probe/
system leases, zero partial spend, immutable replay, EXTRA constraints, integer
overflow, cancel и stale catalog/policy. `GcraReservationTest.php` использует
независимые PHP/PG workers и наблюдаемые SQL barriers: capacity contention,
duplicate reserve, shared egress разных routes и реальные catalog/policy command
writers, ожидающие ранние locks до commit reservation.


## Sealed send и final CAS

До enqueue вызывается `WireSealer::seal`: caller уже подготовил DNS addresses,
TLS trust, immutable body и credential material. Сервис не выполняет resolver I/O.
`proxyma.wire.digest_key` должен содержать отдельный стабильный секрет длиной не
менее 32 bytes (`PROXYMA_WIRE_DIGEST_KEY`); отсутствие ключа даёт configuration
hold. Значение не включается в debug или evidence. Ключ одинаков для workers;
ротация требует нового physical attempt. Sealed request передаётся в
`ReservationService::prepare`, enqueue и reserve. Изменение URL, method, prepared
headers, body, route, endpoint/credential version, pinned addresses или TLS меняет
полное намерение. Body bytes неизменны; raw headers скрыты, digest keyed.

`ClaimSend::claim` выполняет preflight и владеет standalone transaction. Для
caller-owned пути `ClaimSend::prepare(permit, input, wire)` вызывается до BEGIN;
он измеряет DB interval вместе с предшествующим monotonic sample.
`claimSendLocked` требует
зарегистрированный context той же Connection/PDO и бросает typed rejection при
отказе, чтобы предшествующее finance изменение откатилось вместе с network.
Перед caller transaction нужно разрешить scoped coordinator/ConnectionScope,
чтобы monotonic sample предшествовал BEGIN. Supplied owner state принимается
только после `AuthorityLocker::owner` в этом outer transaction; rolled back
savepoint инвалидирует его. Затем берутся operation root, policy heads, profile,
endpoint, route, health, target, declared adapter guards, полный набор budgets,
demand и permit. Operation writers начинают с этого же root. Receipt persistence
не требует повторного owner prefix.

Policy commands и binding commands используют exclusive policy-set mutex;
reserve/claim берут shared mutex до отсортированных policy heads. Bounded snapshot
включает появление нового head или binding. Binding writer блокирует head раньше
profile. Отсутствующие authority rows дают hold, send не выполняет UPSERT.

Final UPDATE использует один `clock_timestamp()` в materialized CTE после всех
locked reads. CAS проверяет owner generation/token/status/deadline, operation
attempt bound, актуальные revisions/health epochs, membership, demand identity и
lifecycle, immutable wire fingerprint, срок permit с total timeout + cleanup,
все reservation references и отдельную send eligibility каждого budget. GCRA
сравнивается как integer/numeric microseconds. Permit authorization, advancement
`send_tat`, reserved→spent references, operation attempt count и immutable attempt
fact сохраняются одной transaction; inflight остаётся lease до completion/reaping.
Fixed UTC quotas проверяются этим же CAS; stale window вызывает NeedsReplan
с release старой reservation после outer rollback.

Claim возвращает inert `ClaimCandidate`. `ClaimSend::activate` использует только
успешный outer CommitLatch при transactionLevel=0. Savepoint release, rollback и
commit failure не дают отправки. Прямой latch без local SQL provenance и
реконструкция committed IDs не создают capability. Production coordinator больше
не принимает synthetic transaction drivers; illustrative driver существует только
в tests. Внутренние factory/provenance ports не являются consumer extensions.

Transport получает `AuthorizedWireRequest` с неизменными prepared material и
snapshot. `consume()` повторно проверяет отсутствие SQL transaction и monotonic
latestStart непосредственно перед bytes; caller clock override запрещён. Candidate
и capability однократные, не клонируются и не восстанавливаются serialization/JSON.
latestStart консервативно выводится из sample до outer BEGIN и DB authorization
interval, поэтому SQL wait и commit delay сокращают окно. Scheduler freeze после
проверки нельзя атомарно связать с socket send.

Поздний отказ на activation/consume сохраняет `unknown_after_authorization` и
`dispatch_outcome=not_dispatched` без возврата send debt или повторного claim.
При аварии остаётся durable authorized/unknown identity для reconciliation;
exactly-once remote effect не обещается. Receipt остаётся независимым фактом даже
после потери owner. Реальный HTTP transport и replay проверяются в P5.3; здесь
используется только fixture PhysicalTransport.

## Authority interference checkpoint

`AuthorityInterleavingTest` связывает named pairs с одним SQL worker/barrier
harness (`tests/Support/ConcurrentWorkers.php`). В каждой строке catalog matrix
проверяются оба порядка: writer commit до grant/receipt и writer, ожидающий
ранний lock уже начатого grant/receipt. Commands и admission/recovery repositories
настоящие; project owner/finance prefix — fixture tables на той же PG Connection.

| Interference | Проверка matrix | Результат при writer-first |
| --- | --- | --- |
| Profile update vs reserve/claim | `profile:reserve`, `profile:claim` | `profile_membership_changed`, ноль send/partial spend |
| Credential rotation vs claim/receipt | `credential:claim`, `credential:receipt` | Claim отвергнут; receipt старого authorization сохраняется |
| Target retirement vs send/late completion | `target:claim`, `target:receipt` | Новый send запрещён; late receipt не теряется |
| Budget limit vs reserve/send/reconcile | `budget:reserve`, `budget:claim`, `budget:reconcile` | Zero quota denies; old claim held; reconcile сохраняет consumed |
| Policy mutation vs reserve | `policy:reserve` | `policy_revision_changed` без permit/subset spend |
| Probe claim vs normal send | `probe_claim:normal_send` | Только token owner проходит half-open fence; origin общий |
| Process cancel vs joint finance/network | `process_cancel:joint_claim` | Prefix сериализует cancel; отказ откатывает finance и network |
| Missing prerequisite / cooldown | `prerequisite:*` | Permit остаётся reserved; finance rollback; concurrent quota writer сохраняет cooldown |
| External prefix deadlock | `external_prefix:real_pg_deadlock` | Два PG workers, один deadlock retry, два финансовых commits |
| Retry до outer commit | `claim_before_commit:retry:*` | Один durable claim/charge либо held permit после exhaustion |

Standalone coordinator повторяет только PostgreSQL SQLSTATE `40P01`/`40001`
в пределах `maxAttempts`. Локализованный message не определяет retry. Любой иной
SQLSTATE, включая constraint `23514`, не повторяется. Framework rollback и при
необходимости оставшийся PDO transaction завершаются до следующей попытки.
Caller-owned transaction самостоятельно не повторяется. Monotonic sample остаётся
полученным до первого BEGIN, поэтому retry не продлевает latestStart. Closure
содержит только SQL; физическая отправка выполняется после успешного outer commit.
Уже committed latch запрещает retry даже при concurrency SQLSTATE из afterCommit
callback: authorization остаётся charged, а отсутствие receipt трактуется
консервативно как unknown, без повторной физической отправки.

После каждого matrix run сверяются ledger↔counter continuity для inflight и
hour/day quota, одна authorization на attempt, operation authorization count,
receipt ownership и отсутствие ложного completed. Сохранённый cooldown не может
исчезнуть или сократиться. Trace в `build/p43-matrix-trace.jsonl` содержит case,
worker OS/PG PIDs, barrier events, lock table names, retries и duration; wire,
credentials и SQL bindings в trace отсутствуют.

Барьер подтверждает ожидаемую lock table; ожидание общего catalog advisory mutex
не подменяет ожидание profile row. Это проверяется отдельным сценарием с удержанным
advisory lock. Harness закрывает оба своих PDO при `finish`, даже если PHPUnit
сохраняет test object; fixture disconnect выполняется после удаления схемы.
Необработанная ошибка worker выдаёт только тип, SQLSTATE и место ошибки без SQL
bindings; startup failure имеет отдельную отрицательную проверку.

Существующие `GcraReservationTest`, `DemandFifoTest`, `ClaimSendFenceTest`,
`QuotaBoundaryTest`, `CompletionReconcileTest` сохраняют дополнительные сценарии:
shared egress/provisioning, FIFO cancellation, fresh-clock CAS и late start,
last quota unit/UTC rollover, crash checkpoints и completion↔expiry. Общий
`make test-concurrency` проверяет их вместе с matrix одной acceptance-командой.
Health interpretation позднего 429 относится к P6; этот checkpoint проверяет
receipt persistence, stale-epoch fence и сохранение уже materialized cooldown.
