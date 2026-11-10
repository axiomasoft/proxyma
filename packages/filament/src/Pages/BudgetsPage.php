<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Proxyma\Application\Admission\QuotaService;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;

class BudgetsPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.budgets-page';

    protected static string $kind = 'budgets';

    protected static ?string $navigationLabel = 'Бюджеты';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('hour_limit')->label('Квота за календарный час UTC')->integer()->minValue(0)->maxValue(PHP_INT_MAX)->helperText('Пусто — нет дополнительной квоты; 0 — запрет.'),
            TextInput::make('day_limit')->label('Квота за календарные сутки UTC')->integer()->minValue(0)->maxValue(PHP_INT_MAX),
            Textarea::make('reason')->label('Причина изменения')->required()->maxLength(512),
        ])->statePath('data');
    }

    public function updateBudget(): void
    {
        $this->command(function (): void {
            $this->assertEditing();
            abort_unless($this->canCommand('catalog.budget.configure') && $this->editing['editable'], 403);
            $state = $this->form->getState();
            app(QuotaService::class)->configure(app(TenantContextResolver::class)->resolve()->tenant, new Uuid($this->editing['id']),
                filled($state['hour_limit'] ?? null) ? (int) $state['hour_limit'] : null,
                filled($state['day_limit'] ?? null) ? (int) $state['day_limit'] : null,
                $this->editing['revision'], $this->actor($state));
            $this->edit($this->editing['id']);
        });
    }

    protected function editState(array $row): array
    {
        return ['hour_limit' => $row['hour_limit'], 'day_limit' => $row['day_limit']];
    }
}
