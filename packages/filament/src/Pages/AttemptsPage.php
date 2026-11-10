<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

class AttemptsPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.attempts-page';

    protected static string $kind = 'attempts';

    protected static ?string $navigationLabel = 'Попытки';
}
