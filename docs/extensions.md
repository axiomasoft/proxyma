# Расширения Proxyma 0.1

Публичные порты находятся в `Proxyma\Contracts`. Application services и immutable
DTO доступны consumer; Eloquent records, SQL repositories, activation/latch primitives
и authority internals не являются заменяемыми моделями или extension API.
`PhysicalTransport::sendOnce` принимает только однократный `AuthorizedWireRequest`,
который выдаёт core после outer commit. Стратегия выбирает кандидата и не получает permit.

Потребительские расширения подключаются через Packman `ExtensionPlugin` / `WithSlots`
(`packman.plugins` или `ExtensionRegistrar::builtIn`) и объявляют зависимость от `axiomasoft.proxyma`.
При необходимости структурированных определений (panels, capabilities, subject resolvers,
`BudgetScopeSchema`) плагин может дополнительно реализовать `ExtensionProvider::register(DefinitionRegistry)`
и внести себя в slot `ExtensionProvider::class`. Регистрация не должна выполнять SQL,
DNS, secret-store, object I/O и не должна читать consumer state. Провайдеры регистрации и
сохранённые в definitions обработчики должны быть stateless: tenant/subject/request
передаются в typed arguments. Зависимости с состоянием запроса регистрируются как
Laravel `scoped`, а не сохраняются в singleton definitions. Конструкторы провайдеров
также не выполняют I/O. Package register/boot не разрешает секреты, не мигрирует БД
и не запускает probes.

```php
// config/packman.php
'plugins' => [
    ConsumerExtensions::class, // implements ExtensionProvider, WithSlots (requires: ['axiomasoft.proxyma'])
],

// config/proxyma.php -> 'extensions'
'extensions' => [
    'route_strategies' => ['consumer.first'],
    'outcome_policies' => ['consumer.accept'],
    'route_filters' => [],
    'budget_contributors' => [],
    'locked_admission_guards' => [],
    'observers' => [],
    'policy_definitions' => [],
],
```

Config содержит только scalars, arrays и заранее разрешённые class strings.
Selections — зарегистрированные ID, а не FQCN из БД. `ExtensionDefinition` связывает
ID, конечный kind, typed implementation, version, dependencies, priority и required
capabilities. Provider также регистрирует `SubjectResolverDefinition`, `PanelDefinition`
и capabilities. Freeze происходит после boot всех service providers. Duplicate IDs,
missing dependencies/capabilities, cycles и одинаковые priorities одного kind/tier
останавливают boot. Dependency выполняется раньше dependent; конфликт priority с
этим порядком тоже ошибка. Повторный freeze идемпотентен; дальнейшие записи запрещены.
Fingerprint включает версии definitions, настройки, schemas и grants и не зависит
от порядка регистрации. После изменения реализации consumer повышает её version.

Настройки с descriptors регистрируются через `proxyma.settings` и
`SettingDefinitionProvider`; defaults слотов принадлежат `PanelDefinition`.
`ExtensionKind::PolicyDefinitions` принимает `PolicyDefinitionProvider`: его named
immutable definitions доступны через `DefinitionRegistry::policy(id)` и могут задавать
параметры `OutboundRequest`; core всё равно пересекает их с effective/live policy.
Дубликаты policy keys запрещены. Strategy и OutcomePolicy должны возвращать key,
равный ID definition; selected strategy IDs становятся допустимыми `route.strategy`.

RouteFilter получает только eligible candidates и возвращает их подмножество.
Foreign ID отвергается, одноимённая подмена объекта возвращает исходный core объект.
Пустой результат запрещает выбор. Strategy работает после фильтров; foreign selection
отвергается повторно. Никакой выбор не заменяет locked admission.

BudgetScopeContributor вызывается до materialization/SQL locks. Он возвращает только
добавочные `BudgetConstraint`; core всегда сохраняет origin и пересекает лимиты.
Пустой массив ничего не удаляет, более слабый лимит ничего не расширяет.
Definition обязана задать `BudgetScopeSchema(namespace, version, keys, sharedTenants)`.
Новые keys начинаются с `schema->prefix(trustedTenant)`; `keys` разрешает точные
предварительно объявленные legacy keys. Возврат чужой namespace/tenant key запрещён.
Существующие core scopes можно только ужесточать. Contributor детерминирован для
одинакового immutable input; никакого I/O в locked фазе нет. Неизвестный scope не
создаётся автоматически. Доступ к system budget через contributor требует явного
`sharedTenants` grant для текущего trusted tenant; reserve и claim проверяют его
независимо. Grants поступают из доверенного PHP, не из запроса пользователя.

LockedAdmissionGuard обязан реализовать `SqlConnectionParticipant`, объявить ровно
`AdmissionLockTier::AdapterGuard` и соответствовать tier в definition. Freeze проверяет
binding и metadata, не открывая connection. Runtime проверяет ту же SQL connection
и последовательность фиксированных authority tiers через `AuthorityLocker`.
Owner/finance adapters используют документированные prefix tiers. Возврат `null`
означает отсутствие дополнительного запрета; `ClaimDenied` запрещает admission.
Guard не меняет readonly wire и не выдаёт capability. Ранний owner lock через
authority contract после adapter tier отвергается. Произвольные callbacks с неизвестными
locks, произвольный SQL lock protocol и замена authority-моделей не поддерживаются.
PHP extensions — доверенный исполняемый код, не sandbox для враждебного PHP с raw PDO.

OutboundObserver получает read-only observation только после committed completion.
URL template редактирован, секретов/headers/body в observation нет. Ошибка listener
пишется как `proxyma.observer_failed` с ID observer и attempt, без exception text.
Она не меняет receipt, не откатывает completion и не запускает повтор sent HTTP.
Остальные observers продолжают работу. Durable audit результата — сохранённые
attempt/receipt и append-only observations; доставка listener локальная best-effort,
без outbox и без обещания delivery через Laravel dispatch. Сбой процесса между commit
и notify может пропустить listener. Повтор операции не является доставкой события.

Два исполняемых consumer без Agelto и Filament находятся в
`tests/Fixtures/Consumers/{minimal,custom}`. У каждого свой Composer lock/autoloader;
custom регистрирует SubjectResolver, Strategy и OutcomePolicy.

```sh
cd tests/Fixtures/Consumers/minimal # либо custom; PHP 8.4 с требуемыми extensions
composer install
php artisan package:discover
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan proxyma:doctor
```

Cache/discovery работают до migrations и не требуют secret material или доступной БД.
Runtime doctor с доступным PostgreSQL и отсутствующей схемой возвращает unhealthy и
перечисляет missing tables/constraints. Authorizer по умолчанию запрещает операции;
trusted tenant resolver обязателен. `forgetScopedInstances()` между заданиями worker
создаёт новый transaction coordinator; access adapters разрешают актуальный tenant.
