<?php

declare(strict_types=1);

namespace Proxyma\Filament\Schemas;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Filament\BindingEditor;

class ProxymaSettingsSection
{
    /**
     * SubjectRef берётся из trusted записи владельца, не из browser tenant state.
     * BindingEditor::save() принимает только state этой section; modal — ConfigureProxymaAction.
     *
     * @param  Closure(): SubjectRef  $subject
     */
    public static function make(Closure $subject, string $panel, string $slot, Uuid $target, string $statePath = 'proxyma', bool $readOnly = false): Section
    {
        $editor = app(BindingEditor::class);
        $ref = $subject();
        try {
            $projection = $editor->project($ref, $target, $panel, $slot);
        } catch (AuthorizationDenied) {
            abort(403);
        }
        $initial = $editor->open($ref, $target, $panel, $slot, $projection);
        $components = [Hidden::make('context')->default($initial['context'])];
        if ($projection->blockedReason !== null) {
            $components[] = TextEntry::make('blocked')->state($projection->blockedReason)->label('Редактирование недоступно');
        }
        foreach ($projection->fields as $key => $field) {
            $descriptor = app(SettingRegistry::class)->descriptor($key);
            $id = BindingEditor::fieldId($key);
            $modes = ['inherit' => 'Наследовать', 'set' => 'Задать значение'];
            if ($descriptor->nullable) {
                $modes['null'] = 'Явный null';
            }
            if ($descriptor->optional) {
                $modes['disable'] = 'Отключить';
            }
            $value = match ($descriptor->type) {
                SettingType::Boolean => Toggle::make('value'),
                SettingType::Integer => TextInput::make('value')->integer()->minValue($descriptor->minimum)->maxValue($descriptor->maximum)
                    ->dehydrateStateUsing(static fn ($state) => is_numeric($state) && (string) (int) $state === (string) $state ? (int) $state : $state),
                SettingType::Identifier => Select::make('value')->options(array_combine($descriptor->allowedValues, $descriptor->allowedValues)),
                SettingType::IntegerList, SettingType::IdentifierList => Repeater::make('value')->simple(
                    $descriptor->type === SettingType::IntegerList
                        ? TextInput::make('item')->integer()->minValue($descriptor->minimum)->maxValue($descriptor->maximum)
                            ->dehydrateStateUsing(static fn ($state) => is_numeric($state) && (string) (int) $state === (string) $state ? (int) $state : $state)
                        : TextInput::make('item')->regex('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D')
                )->maxItems(256)->defaultItems(0),
            };
            $components[] = Section::make($key)->statePath('fields.'.$id)->description(
                $descriptor->type->value.' · entity · schema '.$descriptor->schemaVersion.' · '.
                json_encode($field['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).' · '.$field['source'].'@r'.$field['revision'].
                ' · '.($field['inherited'] ? 'inherited' : 'explicit').' · clamp: '.($field['clamped'] ? 'yes' : 'no').
                ' · bounds: '.($field['minimum'] ?? '—').'..'.($field['maximum'] ?? '—').
                ' · '.($field['clamped_by'] ?? '').($field['clamped_revision'] === null ? '' : '@r'.$field['clamped_revision']).' · '.$field['reason']
            )->schema([
                Select::make('mode')->label('Локальная настройка')->options($modes)->default($initial['fields'][$id]['mode'])->required()->live(),
                $value->label('Значение')->default($initial['fields'][$id]['value'])
                    ->visible(static fn (Get $get): bool => $get('mode') === 'set'),
            ])->disabled($readOnly || ! $field['editable']);
        }
        if (! $readOnly && $projection->blockedReason === null) {
            $components[] = Textarea::make('reason')->label('Причина изменения')->required()->maxLength(512);
        }

        return Section::make('Proxyma · '.$panel.' / '.$slot)->statePath($statePath)->schema($components)->columnSpanFull();
    }
}
