# Архитектура Proxyma 0.1

## Два пакета и границы зависимостей

Репозиторий `axiomasoft/proxyma` поставляет два пакета:

- `packages/core` (`axiomasoft/proxyma`, namespace `Proxyma\`) — предметная модель, контракты, политики, допуск (admission), бюджеты, сетевой health, транспорт и адаптер Laravel.
- `packages/filament` (`axiomasoft/proxyma-filament`, namespace `Proxyma\Filament\`) — опциональный административный интерфейс Filament 5.

Жёсткие границы слоёв:

- `Proxyma\Domain\{Catalog,Policy,Execution,Health}` содержит только чистые PHP value objects, перечисления и детерминированные переходы состояний. Зависимости от `Illuminate\*`, `Eloquent`, `Filament\*`, `App\*` и `ProcessCore\*` в `Domain` запрещены.
- `Proxyma\Contracts` определяет публичные порты приложения и ограниченные контракты расширений без зависимостей от фреймворка и потребителя.
- `packages/core` никогда не зависит от `App\*`, `Filament\*`, `ProcessCore\*`, процессного леджера Agelto, биллинга LLM или платформенных перечислений/заголовков (`x-goog-api-key`).

## Обязательный контур безопасности (D2)

В линейке `0.1` единственным stateful-хранилищем является PostgreSQL. Контуры `admission`, `budgets` и `health` всегда активны в ядре:

- Нет режима `stateless`, `NullAuthority`, `NullPermit` или `NullOwner`.
- Отсутствие пользовательских лимитов означает использование базовых инвариантов допуска, а не отключение фиксации `demand`/`permit`/`attempt`.
- Любое расширение может только ужесточать допуск или сужать пул маршрутов, никогда не обходя базовые проверки.

## Публичный и внутренний контур (Public vs Internal)

| Категория | Пространство имён / путь | Статус | Правило использования |
|---|---|---|---|
| Value Objects и Enums | `Proxyma\Domain\{Catalog,Policy,Execution,Health}\*` | **Public** | Неизменяемые типы (`final readonly` / `enum`). |
| Порты и точки расширения | `Proxyma\Contracts\*` | **Public** | Единственные интерфейсы для вызова и адаптеров потребителя. |
| Service Provider и координатор транзакций | `Proxyma\Laravel\ProxymaServiceProvider`, `Proxyma\Laravel\DatabaseTransactionCoordinator` | **Public** | Регистрация пакета и композиция транзакций. |
| Конфигурация | `packages/core/config/proxyma.php` | **Public** | Настройки соединения, базового тенанта и реестра расширений. |
| Модели ORM и SQL-репозитории | `Proxyma\Infrastructure\*`, Eloquent Models, SQL Helpers | **Internal** | Внутренняя реализация; прямая подмена моделей или обход команд запрещены. |

## Идентичности, тенантность и иерархия субъектов (`architecture#identity`)

- **`Uuid`**: собственный неизменяемый тип канонического UUID в нижнем регистре, независимый от `ProcessUuid` и `Illuminate\Support\Str`.
- **`TenantKey`**: непустой идентификатор тенанта; значение по умолчанию — `TenantKey::system()` (`system`). Wildcard (`*`) и пустые значения запрещены. Кросс-тенантный доступ к маршрутам запрещён по умолчанию и разрешается только при явном `sharedGrant: true` на `Route`.
- **`SubjectRef` и `SubjectChain`**: ссылка на субъект настроек (`TenantKey $tenant`, `string $type`, `string $id`) и упорядоченная цепочка предков глубиной не более 16 элементов. Циклы, дублирование типов предков с разными ID (неоднозначный родитель) и кросс-тенантные предки отвергаются.
- **Разделение субъекта настроек и владельца исполнения**: `SubjectRef` задаёт привязку политики, а `OwnerRef` (`tenantKey`, `ownerType`, `ownerId`, `ownerToken`, `generation`) удостоверяет владение активным исполнением. Подмена одного другим невозможна на уровне типов.
- **`Endpoint`**: прокси-узел (`id`, `tenantKey`, `ProxyProtocol`, нормализованный `host`, `port`, опциональный `CredentialRef` с версией `>= 1`, `revision`, `retiredAt`).
- **`Route`**: маршрут (`id`, `tenantKey`, `routeKey`, `RouteKind::Direct|Proxy`, `?Uuid $endpointId`, `enabled`, `EgressIdentity`, `?string $independenceGroup`, `credentialVersion`, `revision`, `sharedGrant`). Выполняется строгий XOR: `Direct` не может ссылаться на `Endpoint`, `Proxy` обязан иметь `endpointId`.
- **`EgressIdentity`**: идентичность выходного адреса; не равна `routeKey` или `independenceGroup` и не может быть случайным UUID. Неизвестный выход группируется консервативно по пулу провайдера или NAT (`EgressIdentity::conservativePool(...)`).
- **`RouteProfile`**: профиль маршрутов (`id`, `tenantKey`, `profileKey`, точный список `memberRouteIds`, `strategyKey`, `revision`). Пустой профиль всегда запрещает отправку (`empty deny`); маршрут `Direct` допускается только при одновременном явном членстве в профиле и разрешении политики (`directAllowedByPolicy: true`).
- **`Target`**: логическая цель назначения (`id`, `tenantKey`, `targetKey`, `CanonicalOrigin`, `destinationPolicyKey`, `revision`, `retiredAt`) без внешних ключей на таблицы площадок потребителя.

## Жизненный цикл Operation, Demand, Permit и Late Receipt (`authority#lifecycle`)

### Operation и Demand

- **`OperationCorrelation`** связывает внутренний `operationId` (`Uuid`), внешний `OwnerRef`, конечный UTC `deadlineAt`, назначение `DemandPurpose` (`Ordinary`, `Probe`, `Diagnostic`) и опциональный `probeToken`.
- **`DemandState`** переходит строго по графу:
  - `active -> admitted -> completed | cancelled | expired`
  - `active -> cancelled | expired`
  - Терминальные состояния (`completed`, `cancelled`, `expired`) необратимы.
- **`DemandTicket`** фиксирует позицию в ограниченной FIFO-очереди по лимитирующему scope (`ticketSequence >= 1`) с честным разрешением равенства по `(ticketSequence, demandId)` и инвариантом `expiresAt <= operationDeadlineAt`.

### Permit и отображение исторических статусов

- **`PermitState`** имеет явный словарь состояний:
  - `reserved -> authorized -> completed | unknown_after_authorization`
  - `reserved -> cancelled_before_send | expired_before_send`
  - Переход `unknown_after_authorization -> completed` разрешён только при верифицированном позднем receipt (`LateReceiptTransition`) для того же `physicalAttemptId` и `wireFingerprint`.
  - После `authorized` возврат в `reserved` или переход в `*_before_send` запрещён; квота расходуется необратимо в момент `authorized`.
- **Отображение legacy-статусов (`PermitState::fromLegacyStatus`)**:
  - Исторический статус `released` из схемы Agelto **нельзя** безусловно переименовывать в `completed`:
    - `released` без факта авторизации отправки (`wasAuthorized === false`) отображается в `cancelled_before_send`;
    - `released` после авторизации (`wasAuthorized === true`) отображается в `completed` только при наличии подтверждённого receipt (`hasVerifiedReceipt === true`), иначе — в `unknown_after_authorization`.

## Передача полномочий после коммита (Commit Handoff)

1. До начала SQL-транзакции фиксируется монотонное время `hrtime(true)`.
2. Внутри транзакции `TransactionCoordinator::run(...)` создаётся корневой `CommitLatch` и `LockedTransactionContext`. Вложенные вызовы `run()` (savepoints) разделяют внешний токен транзакции; освобождение savepoint **не** активирует `CommitLatch`.
3. `Admission::claimSendLocked(...)` возвращает инертный несериализуемый `ClaimCandidate`, а не транспортную capability.
4. Только после успешного коммита внешней транзакции (`transactionLevel() === 0`) вызов `TransactionCoordinator::activate(...)` проверяет закоммиченный `CommitLatch`, консервативный монотонный дедлайн `latestStartMonotonicNs` и однократно выпускает `AuthorizedWireRequest` для `PhysicalTransport::sendOnce(...)`.
5. Откат внешней транзакции или откат savepoint немедленно аннулирует выпущенный `ClaimCandidate`.

## Product ownership of shared internals

The explicit `gates.internal_ownership_groups` declaration in `packman.json` makes
Proxyma's listed runtime subpackages one product owner for internal implementation
sharing. It does not publish those implementations as an external SPI. Packman
Internal types and other external dependencies cannot join the group. Development
packages remain excluded; declared Composer edges, layer rules and runtime/dev
separation still apply independently.

The Proxyma owner is responsible for compatibility of the subpackages' declared
Composer version constraints and verifies their installation together. Reviewed
development snapshots use the same repository commit for grouped packages; the
local ecosystem receipt records those exact sources. Applications and other
products continue to use the public API/SPI. No consumer version tags are introduced
by this ownership declaration.
