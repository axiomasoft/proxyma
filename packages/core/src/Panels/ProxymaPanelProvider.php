<?php

declare(strict_types=1);

namespace Proxyma\Panels;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * Headless Packman panel provider descriptor and authorization bridge for Proxyma policy panels.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ProxymaPanelProvider implements PanelProvider
{
    /** @var array<string, string> */
    private const array ACTION_PERMISSIONS = [
        'view' => 'binding.view',
        'update' => 'binding.update',
        'execute' => 'binding.execute',
    ];

    public function __construct(
        private ?Authorizer $authorizer = null,
    ) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'proxyma',
            owningModule: 'axiomasoft/proxyma-core',
            requiredCapability: null,
            supportedActions: array_keys(self::ACTION_PERMISSIONS),
            label: 'Proxyma Egress Policy',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        if ($context->actorKind === 'guest') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        if ($this->authorizer === null) {
            return new PanelAccess(
                view: true,
                actions: array_keys(self::ACTION_PERMISSIONS),
                reason: null,
            );
        }

        $actor = new ActorRef($context->actorKind, $context->actorId ?? 'system');
        $tenant = new TenantKey($context->tenant->id());
        $resourceId = new Uuid(RamseyUuid::uuid5(RamseyUuid::NAMESPACE_OID, 'proxyma:panel:'.$panel->panelId)->toString());

        $permitted = [];

        foreach (self::ACTION_PERMISSIONS as $action => $ability) {
            try {
                $this->authorizer->authorize($actor, $tenant, $ability, $resourceId);
                $permitted[] = $action;
            } catch (AuthorizationDenied) {
                continue;
            }
        }

        if (! in_array('view', $permitted, true)) {
            return PanelAccess::deny('authorization_denied');
        }

        return new PanelAccess(
            view: true,
            actions: $permitted,
            reason: null,
        );
    }
}
