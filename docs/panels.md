# Панели и привязки

Core не использует модели consumer или Filament. Панель задаётся PHP-кодом;
БД хранит назначения и typed patches, а не классы, PHP или closures.

В `boot()` provider приложения зарегистрируйте resolver и панели. После boot
всех providers `DefinitionRegistry` замораживается. Регистрация и freeze не
выполняют SQL и не вызывают consumer resolver или extension handlers.

```php
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;

$registry = $app->make(DefinitionRegistry::class);
$registry->registerCapability('network');
$registry->registerSubjectResolver(new SubjectResolverDefinition(
    'files', $app->make(FileSubjects::class), ['folder', 'document'], version: 1,
));
$registry->registerPanel(new PanelDefinition(
    'files', 'files', [
        new PanelSlot('read', ['timeout.connect_ms', 'timeout.read_ms']),
        new PanelSlot('write', ['timeout.connect_ms'], inheritDefaults: false),
    ], requiredCapabilities: ['network'],
));
```

`PanelDefinition::permissions` содержит owner abilities для `view`, `update`,
`execute`; defaults — `binding.view`, `binding.update`, `binding.execute`.
Каждая операция также требует `capability.<id>` для каждой required capability.
Freeze проверяет существование capability, resolver, setting IDs и defaults
в разрешённом subset. Fingerprint зависит от отсортированных definitions,
версий, классов реализаций и settings. Изменение consumer-семантики требует
новой версии descriptor. После freeze изменение definitions запрещено.

`SubjectResolver::resolve(SubjectRef): ?SubjectChain` возвращает null для
несуществующего субъекта в указанном tenant. Возвращаемая chain хранит leaf
отдельно, ancestors идут от корня к непосредственному родителю. Вся chain
содержит максимум 16 узлов. Resolver отвечает за выбор единственного parent;
несколько несогласованных родителей должны приводить к отказу.
`PanelSubjects` перепроверяет каждый ancestor и его префикс, существование,
registered type, tenant и идентичность leaf. Repeated typed IDs, cross-tenant,
nested или конфликтующие ancestry отклоняются. Caller chain не заменяет resolver.
Resolver вызывается до SQL transaction; consumer может читать свой storage.

## Команды и запросы

```php
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\BindingQueries;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;

$binding = $app->make(BindingCommands::class)->bindPolicy(
    new BindSubjectPolicyCommand(
        subject: $subject,
        routeProfileId: $profileId,
        policyKey: 'default',
        actor: $actor, // expectedRevision=0 при создании, затем текущая revision
        panelKey: 'files',
        slotKey: 'read',
        patch: new SettingPatch(1, [
            'timeout.connect_ms' => SettingChange::set(800),
        ]),
    ),
);
$current = $app->make(BindingQueries::class)->findBinding($subject, 'files', 'read');
```

Уникальность: `(tenant_key, panel_key, subject_type, subject_id, slot_key)`.
`policyKey` — каноническая метка назначения, не исполняемый идентификатор класса
или отдельный источник полномочий. Настройки берутся только из typed patch;
profile должен существовать, быть активным и принадлежать тому же tenant.
Отсутствующий profile означает наследование политики профиля из верхнего слоя.

Команды требуют actor/reason/expectedRevision/idempotencyKey. Все записи patch,
binding revision, audit и receipt атомарны; stale revision или несовместимое
повторное использование idempotency key дают `PolicyConflict`. Точный replay
возвращает первоначальную revision, даже после более поздних изменений.
`inherit` удаляет override; null и пустой список не подменяют наследование.
Неправильные fields/types/references не оставляют частичных записей.
Авторизация tenant/actor, owner ability, capability и изменяемых settings
повторяется для каждого вызова, включая replay. По умолчанию authorizer запрещает
операции. Никакого consumer I/O внутри transaction нет.
Отзыв binding также требует permissions всех удаляемых overrides. Resolution
зарегистрированных subjects внутри уже открытой transaction запрещён.

`unbindPolicy(UnbindSubjectPolicyCommand)` с теми же panel/slot и текущей revision
отзывает binding, сохраняя tombstone. Query возвращает его с `retired=true`, чтобы
следующий bind использовал правильную revision. История `pxm_binding_revisions`
и policy revisions неизменяема. Миграция сохраняет существующие binding IDs,
head/profile и revision, добавляя `panel_key=default`, `slot_key=default`.
Пустая схема поддерживает rollback; наличие binding history запрещает rollback
с потерей этой истории.

## Resolution и форма

Получите canonical chain через `PanelSubjects::resolve`, затем вызовите
`PolicyResolver::resolveEffective($chain, $targetId, trafficKey: 'read', panelKey: 'files')`.
Здесь `trafficKey` выбирает slot. Binding lookup ограничен точными tenant,
panel, slot и typed IDs всей chain. Defaults панели применяются перед ordered
ancestor bindings и leaf binding; hard intersections сохраняются. Missing binding
наследует defaults только при `inheritDefaults=true`; иначе нужна явная leaf
binding. Missing subject/orphan всегда запрещает execute, даже при наличии
кэшированной политики. Live revalidation возвращает `ExecutionBlocked` при
исчезновении subject, binding authority или owner permission.

`PanelFormProjection::project($subject, $targetId, $panelKey, $slotKey)` возвращает
`PanelFormData`: binding/revision и разрешённые fields с value, provenance,
inherited/clamped/editable/reason. Projection использует view authorization,
не требует execute permission и не пишет БД. Missing subject и требование explicit
binding представлены `blockedReason`. Sensitive settings не выдаются. Флаг
editable не заменяет authorization команды сохранения. Внутренний параметр
resolution `panelAction='view'` предназначен для read projection и не выдаёт
execution capability. Старые чистые layer reductions и policy resolver без
зарегистрированной панели сохраняют прежний API; новый consumer регистрирует
панель и resolver.

## Конечные расширения

`ExtensionDefinition` принимает только `RouteFilter`, `BudgetScopeContributor`
или `LockedAdmissionGuard` с соответствующим `ExtensionKind`, стабильными ID,
version, dependencies, priority и объявленным `AdmissionLockTier` для guard.
Dependencies связывают descriptors одного kind.
Freeze отклоняет duplicate ID, missing dependency, cycle, одинаковую priority
в одном kind/tier, обратный dependency order и dependency на более поздний tier.
Scheduling использует descriptor tier; freeze не вызывает `tier()` handler.
Runtime по-прежнему обязан соблюдать subset-only/add-constraints-only/deny-only
контракты. БД может передать только существующие IDs в `selectExtensions`,
с полным набором dependencies; произвольный FQCN и неверный kind отклоняются.

Проверяемые сценарии находятся в `packages/core/tests/Contract/PanelBindingTest.php`:
два consumer-типа, ancestry failures, exact scopes, CAS/replay, immutable history,
наследование, owner permissions, projection и freeze без SQL.
