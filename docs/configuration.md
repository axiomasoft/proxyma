# Конфигурация и разрешение эффективной политики (Policy Resolution)

Этот документ фиксирует конфигурацию слоёв политики `Proxyma`, правила детерминированного вычисления `EffectivePolicy`, работу со слепками исполнения (`PinnedPolicySnapshot`), живую перепроверку безопасности (`revalidateLive`), объяснение настроек (`explain`), предпросмотр (`preview`) и кэширование (`PolicyResolutionCache`).

## 1. Десятислойный порядок разрешения (10-Layer Precedence)

Резолвер `Proxyma\Application\Policy\PolicyResolver` объединяет слои строго сверху вниз (`PolicyLayerKind::ordered()`):

1. `package_defaults` — встроенные дефолты пакета (`CoreSettings::descriptors()` и зарегистрированные `SettingDefinitionProvider`).
2. `project_defaults` — статические дефолты приложения (`config('proxyma.policy.project_defaults')`).
3. `global_db` — глобальная политика из БД (`PolicyScope::Global`, `scope_key = global:system`).
4. `tenant_db` — политика арендатора из БД (`PolicyScope::Tenant`, `scope_key = tenant:<tenant_key>`).
5. `panel_definition_defaults` — статические дефолты админ-панели (`config('proxyma.policy.panel_defaults.<panel>')`).
6. `panel_db` — переопределения панели в БД (`PolicyScope::Panel`, `scope_key = panel:<panel>`).
7. `ordered_entity_ancestry` — упорядоченная цепочка предков `SubjectChain::ancestors()` от верхнего предка к ближайшему родителю.
8. `entity_binding` — привязка листовой сущности `SubjectChain::$leaf` (`PolicyScope::Entity` и `pxm_bindings`).
9. `pinned_execution_snapshot` — зафиксированный слепок исполнения (`PinnedPolicySnapshot`), который может только сужать эффективную политику.
10. `request_narrowing` — одноразовое сужение параметров на уровне конкретного запроса.

Последние два слоя (`pinned_execution_snapshot` и `request_narrowing`) являются пост-привязочными сужениями (`isPostBindingNarrowing() === true`): они никогда не расширяют лимиты, не возвращают запрещённые маршруты и не увеличивают бюджеты.

## 2. Семантика состояний патча (`SettingPatch`)

В типизированном патче (`SettingPatch` / `SettingChange`) различаются пять состояний ключа:

- **`missing`** (ключ отсутствует в `SettingPatch::$changes`) — слой не затрагивает настройку; наследуется значение верхнего слоя.
- **`inherit`** (`PatchOperation::Inherit`) — удаляет локальное переопределение в текущем `scope` и возвращает наследование от верхнего слоя.
- **`set(null)`** (`PatchOperation::Set` со значением `null`) — явное пустое значение там, где `SettingDescriptor::$nullable === true` (например, `destinations.allowed = null` означает отсутствие дополнительного фильтр-списка на этом слое, но не может отменить уже заданный верхним слоем список).
- **`set([])`** (`PatchOperation::Set` с пустым массивом `[]`) — явный пустой список:
  - для `routes.denied` (`Union`) не добавляет новых запретов;
  - для `destinations.allowed` (`Intersection`) даёт пустое пересечение $\emptyset$, блокирующее все назначения.
- **`disable`** (`PatchOperation::Disable`) — явное отключение опциональной возможности (`SettingDescriptor::$optional === true`, например `retry.delays_ms`). Если верхний слой отключил настройку через `disable`, нижний слой не может включить её повторно (`clamped = true`, `reason = clamped_by_disabled_upper_scope`).

## 3. Алгебра слияния по полям (`SettingMerge`)

| Поле / категория | Правило слияния | Поведение на нижних слоях |
|---|---|---|
| `timeout.connect_ms`, `timeout.read_ms`, `timeout.total_ms` | `Minimum` + кросс-полевая граница `total_ms` | Первый явный слой политики (`project_defaults..entity_binding`) задаёт верхнюю границу в пределах `minimum..maximum`; все последующие слои применяют `min(upper, lower)`. Если `connect_ms` или `read_ms` превышает эффективный `total_ms`, они зажимаются до `total_ms` (`clamped_by_total_timeout`). |
| `retry.max_attempts`, `http.max_redirects` | `Minimum` | Нижние слои могут только уменьшать число попыток и редиректов (`min`). Попытка увеличить значение выше верхнего слоя зажимается (`clamped = true`, `reason = clamped_by_upper_bound`). |
| `retry.delays_ms` | Поэлементный `min` или `disable` | Нижний слой может сократить задержки ретраев (`min` по каждому шагу) или полностью отключить их (`disable`). Увеличение задержки или числа шагов зажимается верхней границей. |
| `routes.denied` | `Union` ($A \cup B$) | Любой маршрут, запрещённый на верхнем слое, остаётся запрещённым на всех нижних слоях и в устаревших слепках (`stale_snapshot_cannot_restore_denied_route`). |
| `destinations.allowed` | `Intersection` ($A \cap B$) | Сужает множество разрешённых политик назначения. Если верхний слой задал список $A$, нижний слой со списком $B$ даёт $A \cap B$ (элементы вне $A$ помечаются `clamped_by_intersection`), а попытка передать `null` на нижнем слое зажимается и сохраняет $A$. |
| Профиль маршрутов (`profileKey`) | Одиночное переопределение (`Replace`) внутри `allowedProfileKeys` | Выбирает один профиль без объединения пулов маршрутов. Если верхние слои ограничили допустимое множество `allowedProfileKeys`, попытка выбрать профиль вне пересечения зажимается (`clamped_by_allowed_profiles`). |
| `route.allow_direct` | Явный direct-участник в профиле + жёсткая граница | Никогда не включается неявно при сбое прокси. Требует `route.allow_direct = true` (без запрета на верхнем слое или в `hard_bounds`) **и** наличия активного `direct`-маршрута в выбранном профиле. |
| Бюджеты (`BudgetConstraint`) | Все активные бюджеты действуют одновременно; пересечение по `scopeKey` | Бюджеты разных уровней (`global`, `tenant`, `panel`, `entity`, `route`, `target`) проверяются одновременно. Бюджеты с одинаковым `scopeKey` пересекаются через `BudgetConstraint::intersect()` (`max(emissionIntervalUs)`, `min(burst)`, `min(maxInflight)`). |

## 4. Разделение Pinned Snapshot и Live Safety (`revalidateLive`)

### Что фиксируется в `PinnedPolicySnapshot`

Вызов `$effectivePolicy->pin()` создаёт иммутабельный `PinnedPolicySnapshot` (`schemaVersion = 1`), который сериализуется через `encode()` / `decode()` и хранит:

- `tenantKey`, `targetId`, `destinationPolicyKey`;
- `routeProfileId`, `routeProfileKey`, `routeProfileRevision`;
- `effectiveRevision`, `policyHash`, `layerRevisions`;
- `strategyKey`, `outcomePolicyKey`, `timeouts` (`RequestTimeoutPolicy`), `retryDelaysDisabled`;
- `allowDirectRoute`, `deniedRoutes`, `allowedDestinations`, `budgets`.

### Что всегда проверяется вживую через SQL (`revalidateLive`)

Слепок никогда не считается авторитетным доказательством безопасности отправки после паузы в очереди. Метод `PolicyResolver::revalidateLive()` всегда обращается к PostgreSQL в обход `PolicyResolutionCache` и возвращает один из четырёх типизированных исходов:

- **`LivePolicyAuthority`** — профиль, целевой ресурс, гранты, версии кредов, эпохи здоровья и квотные окна валидны; содержит отфильтрованный список живых маршрутов и пересечённые бюджеты.
- **`PolicyChanged`** — ревизия или хэш живой политики изменились с момента создания слепка; содержит `livePolicy` с применённым жёстким пересечением (`min` для таймаутов/попыток/бюджетов, `union` для `routes.denied`, `intersection` для `destinations.allowed`), список изменённых полей (`changedFields`) и слоёв (`changedLayers`).
- **`NeedsReplan`** — требуется перепланирование маршрута без скрытого отката на direct:
  - выбранный маршрут отозван, отключён или попал в `routes.denied`, но в профиле есть альтернативные здоровые маршруты;
  - прокси-эндпоинт выведен из эксплуатации (`endpoint_retired`) или повернулась версия учётных данных (`credential_rotated`);
  - отозван или изменён кросс-тенантный грант (`shared_grant_revoked_or_changed`);
  - изменилась эпоха здоровья (`health_epoch_changed`) или ревизия/ключ профиля маршрутов (`route_profile_changed`).
- **`ExecutionBlocked`** — отправка заблокирована (`CrossTenantDenied`, `TargetRetired`, `UrlPolicyDenied`, `EmptyProfile`, `DirectRouteNotAllowed`, `ConfigurationHold`, `PolicyReview`). Отсутствие живых прокси-маршрутов никогда не приводит к автоматическому прямому выходу (`DirectRouteNotAllowed` / `EmptyProfile`).

## 5. Объяснение (`explain`) и предпросмотр (`preview`)

- **`explain(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default')`**:
  - возвращает `PolicyExplanation` с картой `FieldExplanation` по каждому несекретному полю;
  - указывает `effectiveValue`, `sourceScope`, `sourceLayer`, `sourceRevision`, флаги `inherited`, `explicit`, `disabled`, `clamped`, `editable`, требуемое право `permission` (`policy.timeout.edit`, `policy.route.edit` и т.д.), причину блокировки/зажатия (`reason`, `minimumBound`, `maximumBound`, `clampedByScope`, `clampedByRevision`) и пошаговую трассу `provenance` (`FieldProvenanceStep`);
  - поля с `sensitive: true` редактируются (`effectiveValue = null`, `inputValue = null`) и исключаются из публичной карты `PolicyExplanation::$fields`.
- **`preview(SubjectChain $subjects, Uuid $targetId, PolicyScope $scope, string $scopeId, SettingPatch|string $unsavedPatch, ...)`**:
  - валидирует несохранённый патч через `SettingRegistry::validate()` и прогоняет его через тот же чистый резолвер;
  - возвращает `PolicyPreview` (`before`, `after`, `diff`, `constraints`, `budgets`) без записи в `pxm_policy_heads`/`pxm_policy_revisions`/`pxm_audit_events`, без модификации `PolicyResolutionCache` и без создания резерваций в `pxm_reservations`.

## 6. Кэширование разрешения (`PolicyResolutionCache`)

- Ключ кэша детерминированно включает:
  `(tenant, panel, slot, target_id, target_revision, ordered_subjects, policy_versions, registry_fingerprint)` плюс поколения инвалидации (`global_generation`, `tenant_generation`).
- **Изоляция**:
  - разные арендаторы с одинаковыми непрозрачными ID субъектов (например `crawler:42`) никогда не делят записи кэша;
  - изменение `SettingRegistry::fingerprint()` (добавление/изменение провайдера настроек или границ) меняет ключ кэша;
  - при попадании в кэш в долгоживущем воркере флаги `editable` и `reason` пересчитываются относительно прав текущего актора (`CatalogAccess`).
- **Транзакционная безопасность и инвалидация**:
  - внутри открытой транзакции (`$db->transactionLevel() > 0`) записи в общий кэш не сохраняются, поэтому откат транзакции не загрязняет кэш;
  - `UpdatePolicy` вызывает `PolicyResolutionCache::invalidateScope()` только после успешного коммита транзакции.
