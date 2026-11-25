<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages\Operations;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
class AttemptsPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.attempts-page';

    protected static string $kind = 'attempts';

    protected static ?string $navigationLabel = 'Попытки';
}
