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
