<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Override;
use Packman\Attributes\Api;
use Packman\Filament\Exceptions\Binding\BindingAuthorizationException;
use Packman\Filament\Exceptions\Binding\BindingConflictException;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\Forms\BindingEditor as PackmanBindingEditor;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider as BuilderPanelProvider;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Contracts\Subjects\PanelFormProjector;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;
use Packman\Panels\Data\Panel\PanelFormData as PackmanPanelFormData;
use Packman\Panels\Data\Panel\PanelSettings;
use Packman\Panels\Data\Subjects\SubjectChain as PackmanSubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Exceptions\Permissions\PolicyConflictException;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed as PackmanSubjectResolutionFailed;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Panels\PanelFormProjection;
use Proxyma\Application\Panels\PanelSubjects;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Panels\PanelFormData;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Throwable;

/** Общая граница section/action: только binding fields, без полей домена владельца. */
#[Api(since: '1.0')]
class BindingEditor
{
    public function __construct(
        private PanelFormProjection $projection,
        private SettingRegistry $settings,
        private BindingCommands $commands,
        private TenantContextResolver $tenants,
        private PanelSubjects $subjects,
        private ?CatalogMutationFence $fence = null,
    ) {}

    public static function fieldId(string $key): string
    {
        return PackmanBindingEditor::fieldId($key);
    }

    public function project(SubjectRef $subject, Uuid $target, string $panel, string $slot): PanelFormData
    {
        if (! $this->tenants->resolve()->tenant->equals($subject->tenant)) {
            throw new AuthorizationDenied;
        }

        return $this->projection->project($subject, $target, $panel, $slot);
    }

    /** @return array{context: string, fields: array<string, array{mode: string, value: mixed}>, reason: string} */
    public function open(SubjectRef $subject, Uuid $target, string $panel, string $slot, ?PanelFormData $projection = null): array
    {
        $expectedTenant = $this->tenants->resolve()->tenant;
        $resolvedProjection = $projection ?? $this->project($subject, $target, $panel, $slot);
        $rawAuthority = ($this->fence ?? app(CatalogMutationFence::class))->version();
        $authorityVersion = ($rawAuthority !== null && is_numeric($rawAuthority)) ? (int) $rawAuthority : 0;

        try {
            return $this->packmanEditor($target, $panel, $resolvedProjection)->open(
                expectedTenantId: $expectedTenant->value,
                panelId: $panel,
                subject: self::toSubjectNode($subject),
                slotKey: $slot,
                targetId: $target->value,
                authorityVersion: $authorityVersion,
                projection: $resolvedProjection->toPackmanFormData(),
            );
        } catch (BindingAuthorizationException) {
            throw new AuthorizationDenied;
        }
    }

    /**
     * Сохраняет состояние только своей section; scheduling и прочие siblings сюда не передаются.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        [$target, $panel] = self::resolveContextHeader($data['context'] ?? null);
        $expectedTenant = $this->tenants->resolve()->tenant;
        $actor = $this->tenants->resolve()->actor;

        /** @var array<string, PanelFormData> $projectedCache */
        $projectedCache = [];
        $descriptorRules = $this->descriptorRules();

        try {
            $prepared = $this->packmanEditor($target, $panel, null, $projectedCache)->prepareSave(
                data: $data,
                expectedTenantId: $expectedTenant->value,
                descriptorRules: $descriptorRules,
            );

            $subject = new SubjectRef(
                new TenantKey($prepared['tenant']),
                $prepared['subject']->type,
                $prepared['subject']->id,
            );
            $cacheKey = $prepared['panel'].':'.$prepared['slot'].':'.$subject->key();
            $domainProjection = $projectedCache[$cacheKey] ?? $this->project($subject, $target, $prepared['panel'], $prepared['slot']);

            $changes = [];

            foreach ($prepared['changes'] as $key => $entry) {
                $changes[$key] = match ($entry['operation']) {
                    'inherit' => SettingChange::inherit(),
                    'disable' => SettingChange::disable(),
                    'set' => SettingChange::set($entry['value']),
                    default => throw new InvalidSettingPatch,
                };
            }

            if ($domainProjection->binding?->retired === true) {
                foreach (array_keys($domainProjection->binding->patch->changes) as $staleKey) {
                    if (! array_key_exists($staleKey, $changes)) {
                        $changes[$staleKey] = SettingChange::inherit();
                    }
                }
            }

            $authorityVersion = $prepared['authority_version'] > 0
                ? (string) $prepared['authority_version']
                : ($this->fence ?? app(CatalogMutationFence::class))->version();

            $this->commands->bindPolicy(new BindSubjectPolicyCommand(
                $subject,
                $domainProjection->binding?->routeProfileId,
                $domainProjection->binding->policyKey ?? 'default',
                new CatalogMutationActor(
                    $actor,
                    $prepared['reason'],
                    $prepared['revision'],
                    Uuid::random()->value,
                    $authorityVersion,
                ),
                $prepared['panel'],
                $prepared['slot'],
                new SettingPatch(1, $changes),
                $prepared['edit_version'],
            ));
        } catch (BindingAuthorizationException|AuthorizationDenied) {
            throw new AuthorizationDenied;
        } catch (BindingConflictException|PolicyConflictException|PolicyConflict|CatalogConflict) {
            throw ValidationException::withMessages(['data' => 'Политика изменена. Перезагрузите форму.']);
        } catch (InvalidBindingStateException|InvalidSettingPatch) {
            throw ValidationException::withMessages(['data' => 'Недопустимое значение или операция настройки.']);
        } catch (PackmanSubjectResolutionFailed|SubjectResolutionFailed) {
            throw ValidationException::withMessages(['data' => 'Субъект недоступен: отсутствует или нарушена цепочка родителей.']);
        }
    }

    /** @param array<string, PanelFormData> $projectedCache */
    private function packmanEditor(Uuid $target, string $panelId, ?PanelFormData $preloaded = null, array &$projectedCache = []): PackmanBindingEditor
    {
        $registry = new class implements PanelRegistry
        {
            #[Override]
            public function get(string $id): Panel
            {
                return new Panel(
                    id: $id,
                    label: $id,
                    description: null,
                    default: false,
                    settings: new PanelSettings(null, null, null, 1, Reads::Primary, StateRefresh::Request, false, []),
                    subjectModels: [],
                    pluginIds: ['axiomasoft.proxyma'],
                );
            }

            #[Override]
            public function find(string $id): ?Panel
            {
                return $id !== '' ? $this->get($id) : null;
            }

            /** @return array<string, Panel> */
            #[Override]
            public function all(): array
            {
                return [];
            }

            /** @param class-string<Model> $modelClass
             * @return list<Panel>
             */
            #[Override]
            public function forModel(string $modelClass): array
            {
                return [];
            }

            /** @param class-string<Model> $modelClass */
            #[Override]
            public function defaultFor(string $modelClass): ?Panel
            {
                return null;
            }

            /** @param class-string<BuilderPanelProvider> $providerClass */
            #[Override]
            public function register(string $providerClass): void {}

            /** @param class-string<BuilderPanelProvider> $providerClass */
            #[Override]
            public function replace(string $providerClass): void {}

            /** @param Closure(PanelBuilder): mixed $callback */
            #[Override]
            public function configure(string $id, Closure $callback): void {}

            /** @param Closure(PanelBuilder): mixed $callback */
            #[Override]
            public function configureAll(Closure $callback): void {}

            #[Override]
            public function isFrozen(): bool
            {
                return true;
            }
        };

        $editor = $this;
        $onProjected = static function (string $cacheKey, PanelFormData $data) use (&$projectedCache): void {
            $projectedCache[$cacheKey] = $data;
        };
        $projector = new class($editor, $target, $preloaded, $onProjected) implements PanelFormProjector
        {
            /** @param Closure(string, PanelFormData): void $onProjected */
            public function __construct(
                private readonly BindingEditor $editor,
                private readonly Uuid $target,
                private readonly ?PanelFormData $preloaded,
                private readonly Closure $onProjected,
            ) {}

            #[Override]
            public function project(Panel $panel, SubjectNode $subject, string $slotKey): PackmanPanelFormData
            {
                $ref = new SubjectRef(new TenantKey($subject->tenantId), $subject->type, $subject->id);
                $data = $this->preloaded ?? $this->editor->project($ref, $this->target, $panel->id(), $slotKey);
                ($this->onProjected)($panel->id().':'.$slotKey.':'.$ref->key(), $data);

                return $data->toPackmanFormData();
            }
        };

        $subjects = $this->subjects;
        $chainResolver = new class($subjects, $panelId) implements SubjectChainResolver
        {
            public function __construct(
                private readonly PanelSubjects $subjects,
                private readonly string $panelId,
            ) {}

            #[Override]
            public function resolve(SubjectNode $leaf): PackmanSubjectChain
            {
                $ref = new SubjectRef(new TenantKey($leaf->tenantId), $leaf->type, $leaf->id);

                try {
                    $resolved = $this->subjects->resolve($this->panelId, $ref);
                } catch (SubjectResolutionFailed $exception) {
                    throw new PackmanSubjectResolutionFailed($exception->getMessage(), 0, $exception);
                }

                $ancestors = $resolved->ancestors();

                return new PackmanSubjectChain(
                    new SubjectNode($resolved->leaf->tenant->value, $resolved->leaf->type, $resolved->leaf->id),
                    array_map(static fn (SubjectRef $item): SubjectNode => new SubjectNode($item->tenant->value, $item->type, $item->id), $ancestors),
                );
            }

            #[Override]
            public function verify(PackmanSubjectChain $supplied): void {}
        };

        return new PackmanBindingEditor($registry, $projector, $chainResolver);
    }

    /** @return array<string, array{nullable?: bool, optional?: bool}> */
    private function descriptorRules(): array
    {
        $rules = [];

        foreach ($this->settings->all() as $descriptor) {
            $rules[$descriptor->key] = [
                'nullable' => $descriptor->nullable,
                'optional' => $descriptor->optional,
            ];
        }

        return $rules;
    }

    private static function toSubjectNode(SubjectRef $subject): SubjectNode
    {
        return new SubjectNode($subject->tenant->value, $subject->type, $subject->id);
    }

    /** @return array{0: Uuid, 1: string} */
    private static function resolveContextHeader(mixed $ciphertext): array
    {
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new AuthorizationDenied;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new AuthorizationDenied;
        }

        $target = is_array($decoded) ? ($decoded['target'] ?? null) : null;
        $panel = is_array($decoded) ? ($decoded['panel'] ?? null) : null;

        if (! is_string($target) || $target === '' || ! is_string($panel) || $panel === '') {
            throw new AuthorizationDenied;
        }

        try {
            return [new Uuid($target), $panel];
        } catch (Throwable) {
            throw new AuthorizationDenied;
        }
    }
}
