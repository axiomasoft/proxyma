<?php

declare(strict_types=1);

namespace Proxyma\Application\Panels;

use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Bindings\BindingContext;
use Proxyma\Application\Bindings\BindingEditVersion;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Bindings\BindingQueries;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Panels\PanelFormData;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\SubjectRef;

#[Api(since: '1.0')]
final readonly class PanelFormProjection
{
    public function __construct(
        private DefinitionRegistry $definitions,
        private BindingContext $context,
        private BindingQueries $bindings,
        private PolicyResolver $policies,
        private SettingRegistry $settings,
        private BindingEditVersion $versions,
    ) {}

    public function project(SubjectRef $subject, Uuid $targetId, string $panelKey, string $slotKey): PanelFormData
    {
        $slot = $this->definitions->panel($panelKey)->slot($slotKey);
        try {
            $chain = $this->context->authorize($subject, $panelKey, $slotKey, 'view');
        } catch (SubjectResolutionFailed $failure) {
            return new PanelFormData($panelKey, $slotKey, null, [], $failure->getMessage());
        }
        $version = $this->versions->current($subject->tenant);
        $binding = $this->bindings->findBinding($subject, $panelKey, $slotKey);
        if (! $slot->inheritDefaults && ($binding === null || $binding->retired)) {
            return new PanelFormData($panelKey, $slotKey, $binding, [], 'Explicit binding required.');
        }
        $policy = $this->policies->resolveEffective($chain, $targetId, trafficKey: $slotKey, panelKey: $panelKey, bypassCache: true, panelAction: 'view');
        $editable = true;
        try {
            $this->context->authorize($subject, $panelKey, $slotKey, 'update');
        } catch (AuthorizationDenied) {
            $editable = false;
        }
        $fields = [];
        foreach ($slot->settings as $key) {
            if ($this->settings->descriptor($key)->sensitive) {
                continue;
            }
            $field = $policy->explanations[$key];
            $fields[$key] = ['value' => $field->effectiveValue, 'inherited' => $field->inherited,
                'source' => $field->sourceScope, 'revision' => $field->sourceRevision, 'clamped' => $field->clamped,
                'minimum' => $field->minimumBound, 'maximum' => $field->maximumBound,
                'clamped_by' => $field->clampedByScope, 'clamped_revision' => $field->clampedByRevision,
                'editable' => $editable && $field->editable, 'reason' => $editable ? $field->reason : 'permission_denied'];
        }

        if (! hash_equals($version, $this->versions->current($subject->tenant))) {
            throw new PolicyConflict;
        }

        return new PanelFormData($panelKey, $slotKey, $binding, $fields, editVersion: $version);
    }
}
