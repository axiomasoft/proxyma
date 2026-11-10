<?php

declare(strict_types=1);

namespace Proxyma\Filament\Resources\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Proxyma\Filament\CatalogBrowser;

class Routes extends CatalogPage
{
    protected static string $kind = 'route';

    protected static ?string $title = 'Маршруты';

    protected static ?string $slug = 'proxyma-routes';

    protected function defaults(): array
    {
        return ['route_key' => '', 'kind' => 'proxy', 'endpoint_id' => null, 'enabled' => true,
            'egress_key' => '', 'conservative_pool' => true, 'independence_group' => null];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('route_key')->label('Ключ маршрута')->required()->regex('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D'),
            Select::make('kind')->label('Тип маршрута')->options(['proxy' => 'Прокси', 'direct' => 'Прямой (явный выбор)'])->required()->live(),
            Select::make('endpoint_id')->label('Узел')->searchable()->options(fn (): array => app(CatalogBrowser::class)->options('endpoint'))
                ->getSearchResultsUsing(fn (string $search): array => app(CatalogBrowser::class)->options('endpoint', $search))
                ->getOptionLabelUsing(fn ($value): ?string => app(CatalogBrowser::class)->optionLabel('endpoint', $value))
                ->required(fn (Get $get): bool => $get('kind') === 'proxy')
                ->prohibited(fn (Get $get): bool => $get('kind') === 'direct'),
            Toggle::make('enabled')->label('Включён')->required(),
            TextInput::make('egress_key')->label('Общий egress / NAT pool')->required()->regex('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D'),
            Toggle::make('conservative_pool')->label('Неизвестные выходы объединены в общий pool')->required(),
            TextInput::make('independence_group')->label('Группа независимости')->nullable()->regex('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D'),
            $this->reason(),
        ])->statePath('data');
    }

    public function edit(string $id): void
    {
        $value = $this->load($id);
        $this->form->fill(['route_key' => $value->routeKey, 'kind' => $value->kind->value, 'endpoint_id' => $value->endpointId?->value,
            'enabled' => $value->enabled, 'egress_key' => $value->egress->key, 'conservative_pool' => $value->egress->conservativePoolFallback,
            'independence_group' => $value->independenceGroup, 'reason' => '']);
    }
}
