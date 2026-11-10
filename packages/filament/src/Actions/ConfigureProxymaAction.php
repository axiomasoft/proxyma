<?php

declare(strict_types=1);

namespace Proxyma\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Schemas\ProxymaSettingsSection;

class ConfigureProxymaAction
{
    /** @param  Closure(): SubjectRef  $subject */
    public static function make(Closure $subject, string $panel, string $slot, Uuid $target, string $name = 'configureProxyma'): Action
    {
        return Action::make($name)->label('Настроить Proxyma')
            ->schema(fn (): array => [ProxymaSettingsSection::make($subject, $panel, $slot, $target)])
            ->action(function (array $data): void {
                try {
                    app(BindingEditor::class)->save($data['proxyma']);
                } catch (AuthorizationDenied) {
                    abort(403);
                }
            });
    }
}
