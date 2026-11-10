<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

class AuditPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.audit-page';

    protected static string $kind = 'audit';

    protected static ?string $navigationLabel = 'Аудит';
}
