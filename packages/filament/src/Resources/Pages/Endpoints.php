<?php

declare(strict_types=1);

namespace Proxyma\Filament\Resources\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Filament\Actions\SaveCatalog;

/** @property-read Schema $credentials */
class Endpoints extends CatalogPage
{
    protected static string $kind = 'endpoint';

    protected static ?string $title = 'Узлы прокси';

    protected static ?string $slug = 'proxyma-endpoints';

    public array $credentialData = [];

    protected function defaults(): array
    {
        $this->credentialData = [];

        return ['protocol' => 'http', 'host' => '', 'port' => 8080, 'credential_status' => 'Не заданы'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('protocol')->label('Протокол')->options(array_column(ProxyProtocol::cases(), 'value', 'value'))->required(),
            TextInput::make('host')->label('Хост')->required()->maxLength(253),
            TextInput::make('port')->label('Порт')->integer()->minValue(1)->maxValue(65535)->required(),
            TextInput::make('credential_status')->label('Credentials')->disabled()->dehydrated(false),
            $this->reason(),
        ])->statePath('data');
    }

    public function credentials(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('username')->label('Новое имя пользователя')->autocomplete('off'),
            TextInput::make('password')->label('Новый секрет')->password()->autocomplete('new-password')
                ->placeholder('••••••••')->required(),
            Textarea::make('reason')->label('Причина ротации')->required()->maxLength(512),
        ])->statePath('credentialData');
    }

    public function edit(string $id): void
    {
        $this->credentialData = [];
        $value = $this->load($id);
        $this->form->fill(['protocol' => $value->protocol->value, 'host' => $value->host, 'port' => $value->port,
            'credential_status' => $value->credential === null ? 'Не заданы' : '•••••••• · v'.$value->credential->version, 'reason' => '']);
    }

    public function rotateCredentials(): void
    {
        try {
            $this->guardWrite('rotate-credentials');
            abort_if($this->revision === 0, 404);
            $state = $this->credentials->getState();
            $value = app(SaveCatalog::class)->rotate($this->recordId, $this->revision, $state['reason'], $state, $this->authorityVersion);
            $this->revision = $value->revision;
            $this->data['credential_status'] = '•••••••• · v'.$value->credential->version;
        } catch (CatalogConflict) {
            throw ValidationException::withMessages(['credentialData' => 'Данные изменены другим оператором. Перезагрузите запись.']);
        } catch (CatalogNotFound) {
            abort(404);
        } catch (AuthorizationDenied) {
            abort(403);
        } finally {
            $this->credentialData = [];
        }
    }
}
