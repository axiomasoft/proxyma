<?php

declare(strict_types=1);

namespace Proxyma\Filament\Resources\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\CatalogBrowser;

class RouteProfiles extends CatalogPage
{
    protected static string $kind = 'profile';

    protected static ?string $title = 'Профили маршрутов';

    protected static ?string $slug = 'proxyma-profiles';

    protected function defaults(): array
    {
        return ['profile_key' => '', 'member_route_ids' => [], 'strategy_key' => 'uniform_random'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('profile_key')->label('Ключ профиля')->required()->regex('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D'),
            Select::make('member_route_ids')->label('Точный состав маршрутов')->multiple()->searchable()->default([])
                ->options(fn (): array => app(CatalogBrowser::class)->options('route'))
                ->getSearchResultsUsing(fn (string $search): array => app(CatalogBrowser::class)->options('route', $search))
                ->getOptionLabelsUsing(fn (array $values): array => collect($values)->mapWithKeys(fn (string $id): array => [$id => app(CatalogBrowser::class)->optionLabel('route', $id)])->filter()->all())
                ->helperText('Пустой профиль запрещает отправку. Прямой маршрут допускается только при явном включении в профиль и разрешении effective policy.'),
            TextInput::make('strategy_key')->label('Зарегистрированная стратегия')->required()->regex('/^[a-z][a-z0-9_.:-]{0,63}$/D'),
            $this->reason(),
        ])->statePath('data');
    }

    public function edit(string $id): void
    {
        $value = $this->load($id);
        $this->form->fill(['profile_key' => $value->profileKey, 'member_route_ids' => array_map(static fn (Uuid $id): string => $id->value, $value->memberRouteIds),
            'strategy_key' => $value->strategyKey, 'reason' => '']);
    }
}
