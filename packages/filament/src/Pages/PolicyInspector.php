<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
class PolicyInspector extends BindingsPage
{
    protected static ?string $navigationLabel = 'Объяснение политики';

    protected static bool $readOnly = true;
}
