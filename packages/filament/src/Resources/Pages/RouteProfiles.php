<?php

declare(strict_types=1);

namespace Proxyma\Filament\Resources\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Filament\Internal\FilamentValue;

#[Api(since: '1.0')]
class RouteProfiles extends CatalogPage
{
    protected static string $kind = 'profile';

    protected static ?string $title = 'Профили маршрутов';

    protected static ?string $slug = 'proxyma-profiles';

    /** @return array<string, mixed> */
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
                ->getOptionLabelsUsing(static function (array $values): array {
                    $browser = app(CatalogBrowser::class);
                    $labels = [];
                    foreach (FilamentValue::strList($values) as $routeId) {
                        $label = $browser->optionLabel('route', $routeId);
                        if ($label !== null) {
                            $labels[$routeId] = $label;
                        }
                    }

                    return $labels;
                })
                ->helperText('Пустой профиль запрещает отправку. Прямой маршрут допускается только при явном включении в профиль и разрешении effective policy.'),
            TextInput::make('strategy_key')->label('Зарегистрированная стратегия')->required()->regex('/^[a-z][a-z0-9_.:-]{0,63}$/D'),
            $this->reason(),
        ])->statePath('data');
    }

    public function edit(string $id): void
    {
        $value = $this->load($id);
        if (! $value instanceof RouteProfile) {
            abort(404);
        }
        $this->form->fill(['profile_key' => $value->profileKey, 'member_route_ids' => array_map(static fn (Uuid $routeId): string => $routeId->value, $value->memberRouteIds),
            'strategy_key' => $value->strategyKey, 'reason' => '']);
    }
}
