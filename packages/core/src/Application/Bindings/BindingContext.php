<?php

declare(strict_types=1);

namespace Proxyma\Application\Bindings;

use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Panels\PanelSubjects;
use Proxyma\Domain\Bindings\BindingIdentity;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

#[Api(since: '1.0')]
final readonly class BindingContext
{
    public function __construct(private CatalogAccess $access, private DefinitionRegistry $definitions) {}

    public function authorize(SubjectRef $subject, string $panelKey, string $slotKey, string $action, ?ActorRef $actor = null): SubjectChain
    {
        $panel = $this->definitions->panel($panelKey);
        $panel->slot($slotKey);
        $id = BindingIdentity::id($subject, $panelKey, $slotKey);
        $this->access->authorize($subject->tenant, $panel->permissions[$action], $id, $actor);
        foreach ($panel->requiredCapabilities as $capability) {
            $this->access->authorize($subject->tenant, 'capability.'.$capability, $id, $actor);
        }

        return new PanelSubjects($this->definitions)->resolve($panelKey, $subject);
    }
}
