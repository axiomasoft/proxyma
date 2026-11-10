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

Donor: Agelto `f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f`, hashes из
`docs/reference/source-manifest.json`: `NetworkAuthorityRepository.php`,
`NetworkPermitRepository.php`, `NetworkPermitSqlHelper.php`, `NetworkOwnerFence.php`.
`AnalysisProviderSendGate.php` — caller, который после financial mutation
выбрасывает `JointProviderSendRejected` при отказе network claim.

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
