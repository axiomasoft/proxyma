# Публичный API и контракты Proxyma 0.1

## Принципы API

- Публичный API состоит исключительно из интерфейсов `Proxyma\Contracts\*` и неизменяемых DTO/VO в `Proxyma\Domain\*`.
- Удобные обёртки без подтверждённого сценария потребителя (`get()`, `post()`, `json()` на `OutboundClient`, публичные конструкторы в обход `CommitLatch`) не добавляются.
- Секреты, подписанные URL и заголовки авторизации защищены от сериализации (`__serialize()` выбрасывает `LogicException`) и скрыты в `__debugInfo()`.

## Порты приложения (`Proxyma\Contracts`)

- **`CatalogCommands` / `CatalogQueries`**: версионированное управление `Endpoint`, `Route`, `RouteProfile`, `Target` с обязательным `CatalogMutationActor` (`actorId`, `reason`, `expectedRevision`) и чтение в рамках `TenantKey`. Удаление используемых сущностей не предоставляется; вывод из эксплуатации выполняется через `retireEndpoint` / `retireTarget`.
- **`PolicyResolver`**: `resolve(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): EffectivePolicySnapshot` и `explain(...): PolicyExplanation`.
- **`BindingCommands`**: привязка и отвязка политик и профилей маршрутов к `SubjectRef`.
- **`OutboundClient`**: единственный метод `execute(OutboundRequest $request, ExecutionContext $context): ExecutionResult`.
- **`Admission`**:
  - `reserve(WireRequest $request, ExecutionContext $context, RouteSelection $route): ScheduledPermit|PermitWait|ExecutionDeferred|ExecutionBlocked`
  - `claimSendLocked(ScheduledPermit $permit, WireRequest $request, LockedOwnerState $ownerState, LockedTransactionContext $transaction): ClaimCandidate|PermitWait|ClaimDenied|ExecutionDeferred|ExecutionBlocked`
  - `abandon(DemandTicket $ticket, ExecutionContext $context): void`
- **`Completion`**:
  - `record(Uuid $operationId, Uuid $physicalAttemptId, AttemptResult $result, ?ContentHash $receiptDigest = null): ?ExecutionDeferred`
  - `recordLateReceipt(LateReceiptClaim $receipt): LateReceiptResult`
- **`DiagnosticsQueries`**: проверка готовности схемы `verifySchemaCapabilities()` и чтение `HealthSnapshot`.
- **`PhysicalTransport`**: `sendOnce(AuthorizedWireRequest $request): AttemptResult` — принимает только одноразовую capability `AuthorizedWireRequest`.
- **`TransactionCoordinator`**: объявляет `connectionName()`, `transactionLevel()`, `inTransaction()`, выполняет композируемые транзакции `run()`, выпускает `ClaimCandidate` внутри транзакции (`issueCandidate`) и активирует `AuthorizedWireRequest` после коммита внешней транзакции (`activate`).

## Точки расширения (`Proxyma\Contracts`)

1. **`OwnerAuthority`**: объявляет `connectionName()`, детерминированные методы без побочных эффектов `snapshot(OwnerRef)` и `validateSnapshot(OwnerRef, OwnerSnapshot)`, а также `lockAndValidate(OwnerRef, OwnerSnapshot, LockedTransactionContext): LockedOwnerState|ExecutionBlocked`.
2. **`PolicyDefinitionProvider`**: регистрация определений политик (`PolicyDefinition`).
3. **`SubjectResolver`**: доверенное построение `SubjectChain` из `SubjectRef`.
4. **`RouteSelectionStrategy`**: детерминированный или рандомизированный выбор маршрута из пула кандидатов.
5. **`SecretResolver`**: позднее разрешение `CredentialRef` в несериализуемый `ResolvedSecret`.
6. **`TlsTrustPolicy`**: адаптер политики доверия TLS (`TlsTrustConfiguration`, где `verifyPeer` и `verifyHost` всегда `true`).
7. **`DestinationResolver`**: ограниченное по времени разрешение и проверка IP-адресов (`ResolvedUrl`).
8. **`OutcomePolicy`**: классификация полученного ответа или транспортного сбоя (`OutcomeClassification`).
9. **`RouteFilter`**: сужение пула маршрутов профиля (`RouteSubsetEnforcer::enforceSubset` отвергает любой маршрут вне профиля).
10. **`BudgetScopeContributor`**: добавление дополнительных бюджетных ограничений (`BudgetConstraintSet::mergeMandatoryWithContributed` сохраняет и пересекает обязательные бюджеты ядра).
11. **`LockedAdmissionGuard`**: дополнительная проверка внутри транзакции на объявленном ярусе блокировок (`AdmissionLockTier`), способная только вернуть `null` или `ClaimDenied`.
12. **`OutboundObserver`**: read-only наблюдатель (`ReadOnlyAttemptObservation`).

## Разделение факта доставки и бизнес-результата

- **`ResponseDescriptor`** и **`AttemptResult`** фиксируют физический факт получения HTTP-ответа по сети (включая ответы `404 Not Found`, `429 Too Many Requests`, `503 Service Unavailable`) либо транспортный отказ (`TransportFailure`).
- **`OutcomeClassification`** (`OutcomeDecision`, `businessMeaning`, `HealthAttribution`, `?RetryAt`) выражает доменную интерпретацию ответа и влияние на health-контур, не изменяя транспортный факт доставки (`AttemptResult::wasDelivered()`).
- **`ExecutionResult`** представлен четырьмя закрытыми типами:
  - `ExecutionSucceeded` — ответ физически доставлен и принят политикой исхода (`ResponseDescriptor`, непустой список `attemptIds`, `finalAttemptId`, опциональный `OutcomeClassification`).
  - `ExecutionDeferred` — выполнение отложено до `RetryAt` с типизированным `DeferralReason`.
  - `ExecutionBlocked` — выполнение заблокировано до вмешательства или смены состояния с типизированным `BlockedReason` и очищенными от секретов `safeDetails`.
  - `ExecutionUnknownAfterAuthorization` — отправка была авторизована (`PermitState::UnknownAfterAuthorization`), но доставка байтов или удалённый эффект остались неопределёнными (`operationId`, `attemptId`, `permitId`, `wireFingerprint`, `?TransportFailure`).

## Совместный claim владельца, финансов и сети (Joint Claim)

Для сценариев, где процессный владелец, финансовая резервация и сетевой permit должны быть захвачены атомарно в одной транзакции PostgreSQL:

1. До открытия транзакции вызывающий код запрашивает `$snapshot = $ownerAuthority->snapshot($ownerRef)` и резервирует `ScheduledPermit`.
2. В общей транзакции `$coordinator->run(function (LockedTransactionContext $tx) ...)` на одном соединении `connectionName()`:
   - проверяется и блокируется владелец: `$lockedOwner = $ownerAuthority->lockAndValidate($ownerRef, $snapshot, $tx)`;
   - фиксируется финансовый claim потребителя на том же соединении;
   - вызывается `$claim = $admission->claimSendLocked($permit, $wireRequest, $lockedOwner, $tx)`.
   - если любой шаг возвращает отказ или ожидание (`ExecutionBlocked`, `ClaimDenied`, `PermitWait`), транзакция откатывается целиком без частичного списания бюджета и без авторизации отправки.
3. После успешного коммита внешней транзакции (`transactionLevel() === 0`) вызывается `$authorizedWire = $coordinator->activate($claim, $wireRequest, $wireSnapshot)` без открытия второй транзакции.
