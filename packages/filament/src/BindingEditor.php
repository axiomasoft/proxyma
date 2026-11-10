<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Panels\PanelFormProjection;
use Proxyma\Application\Panels\PanelSubjects;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\TenantContextResolver;
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

/** Общая граница section/action: только binding fields, без полей домена владельца. */
class BindingEditor
{
    public function __construct(private PanelFormProjection $projection, private SettingRegistry $settings,
        private BindingCommands $commands, private TenantContextResolver $tenants, private PanelSubjects $subjects) {}

    public static function fieldId(string $key): string
    {
        return bin2hex($key);
    }

    public function project(SubjectRef $subject, Uuid $target, string $panel, string $slot): PanelFormData
    {
        if (! $this->tenants->resolve()->tenant->equals($subject->tenant)) {
            throw new AuthorizationDenied;
        }

        return $this->projection->project($subject, $target, $panel, $slot);
    }

    public function open(SubjectRef $subject, Uuid $target, string $panel, string $slot, ?PanelFormData $projection = null): array
    {
        $projection ??= $this->project($subject, $target, $panel, $slot);
        if ($projection->blockedReason !== null) {
            return ['context' => '', 'fields' => [], 'reason' => ''];
        }
        $fields = [];
        foreach ($projection->fields as $key => $field) {
            $local = $projection->binding?->retired ? null : ($projection->binding?->patch->changes[$key] ?? null);
            $mode = $local === null ? 'inherit' : ($local->operation->value === 'disable' ? 'disable' : ($local->value === null ? 'null' : 'set'));
            $fields[self::fieldId($key)] = ['mode' => $mode, 'value' => $local?->value ?? $field['value']];
        }
        $context = [$subject->tenant->value, $subject->type, $subject->id, $target->value, $panel, $slot,
            $projection->binding?->revision ?? 0, $projection->editVersion,
            array_map(static fn (SubjectRef $s): string => $s->key(), $this->subjects->resolve($panel, $subject)->ordered)];

        return ['context' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), 'fields' => $fields, 'reason' => ''];
    }

    /** Сохраняет состояние только своей section; scheduling и прочие siblings сюда не передаются. */
    public function save(array $data): void
    {
        if (! is_string($data['context'] ?? null)) {
            throw new AuthorizationDenied;
        }
        try {
            $context = json_decode(Crypt::decryptString($data['context'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new AuthorizationDenied;
        }
        [$tenant, $type, $id, $target, $panel, $slot, $revision, $version, $chain] = $context;
        $subject = new SubjectRef(new TenantKey($tenant), $type, $id);
        try {
            $projection = $this->project($subject, new Uuid($target), $panel, $slot);
            if ($projection->blockedReason !== null) {
                throw new SubjectResolutionFailed($projection->blockedReason);
            }
            if ($version !== $projection->editVersion || $chain !== array_map(static fn (SubjectRef $s): string => $s->key(), $this->subjects->resolve($panel, $subject)->ordered)) {
                throw new PolicyConflict;
            }
            $expectedIds = array_map(self::fieldId(...), array_keys($projection->fields));
            if (! is_array($data['fields'] ?? null) || array_diff(array_keys($data['fields']), $expectedIds) !== []) {
                throw new InvalidSettingPatch;
            }
            $changes = [];
            foreach ($projection->fields as $key => $field) {
                $state = $data['fields'][self::fieldId($key)] ?? null;
                // Filament исключает disabled fields из dehydrated state.
                if ($state === null && ! $field['editable']) {
                    continue;
                }
                $descriptor = $this->settings->descriptor($key);
                $change = match ($state['mode'] ?? '') {
                    'inherit' => SettingChange::inherit(),
                    'null' => $descriptor->nullable ? SettingChange::set(null) : throw new InvalidSettingPatch,
                    'disable' => $descriptor->optional ? SettingChange::disable() : throw new InvalidSettingPatch,
                    'set' => SettingChange::set($state['value'] ?? null),
                    default => throw new InvalidSettingPatch,
                };
                $local = $projection->binding?->patch->changes[$key] ?? null;
                if (($local === null && $change->operation->value === 'inherit') || ($local !== null && $local->toArray() === $change->toArray())) {
                    continue;
                }
                if (! $field['editable']) {
                    throw new AuthorizationDenied;
                }
                $changes[$key] = $change;
            }
            $reason = $data['reason'] ?? '';
            validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:512']])->validate();
            $actor = $this->tenants->resolve()->actor;
            $this->commands->bindPolicy(new BindSubjectPolicyCommand($subject, $projection->binding?->routeProfileId,
                $projection->binding?->policyKey ?? 'default', new CatalogMutationActor($actor, $reason, $revision, Uuid::random()->value),
                $panel, $slot, new SettingPatch(1, $changes), $version));
        } catch (PolicyConflict) {
            throw ValidationException::withMessages(['data' => 'Политика изменена. Перезагрузите форму.']);
        } catch (InvalidSettingPatch) {
            throw ValidationException::withMessages(['data' => 'Недопустимое значение или операция настройки.']);
        } catch (SubjectResolutionFailed) {
            throw ValidationException::withMessages(['data' => 'Субъект недоступен: отсутствует или нарушена цепочка родителей.']);
        }
    }
}
