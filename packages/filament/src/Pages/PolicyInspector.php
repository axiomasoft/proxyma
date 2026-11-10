<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

class PolicyInspector extends BindingsPage
{
    protected static ?string $navigationLabel = 'Объяснение политики';

    protected static bool $readOnly = true;
}
