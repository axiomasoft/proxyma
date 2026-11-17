<?php

declare(strict_types=1);

namespace Proxyma\Domain\Bindings;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SubjectRef;

#[Api(since: '1.0')]
final class BindingIdentity
{
    public static function id(SubjectRef $subject, string $panel, string $slot): Uuid
    {
        PanelSlot::identifier($panel);
        PanelSlot::identifier($slot);

        return PolicyScope::Entity->authorizationId($subject->tenant, json_encode([$panel, $subject->type, $subject->id, $slot], JSON_THROW_ON_ERROR));
    }
}
