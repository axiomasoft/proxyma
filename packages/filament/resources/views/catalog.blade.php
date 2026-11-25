<x-filament-panels::page>
    <x-filament::section heading="Каталог">
        <x-filament::button wire:click="newRecord">Новая запись</x-filament::button>
        <ul class="space-y-2">
            @foreach ($records as $record)
                <li wire:key="catalog-{{ $record['id'] }}">
                    <x-filament::button color="gray" wire:click="edit('{{ $record['id'] }}')">
                        {{ $record['label'] }} · r{{ $record['revision'] }}
                    </x-filament::button>
                </li>
            @endforeach
        </ul>
        @if ($records->currentPage() > 1)
            <x-filament::button wire:click="changePage({{ $records->currentPage() - 1 }})">Назад</x-filament::button>
        @endif
        @if ($records->hasMorePages())
            <x-filament::button wire:click="changePage({{ $records->currentPage() + 1 }})">Далее</x-filament::button>
        @endif
    </x-filament::section>

    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <p>Ревизия: {{ $revision }}. После конфликта загрузите запись заново.</p>
        @error ('data') <p role="alert">{{ $message }}</p> @enderror
        @if ($this->canSave())
            <x-filament::button type="submit">Сохранить</x-filament::button>
        @endif
    </form>

    @if ($this instanceof \Proxyma\Filament\Resources\Pages\Endpoints && $revision > 0)
        <form wire:submit="rotateCredentials" class="space-y-4">
            {{ $this->credentials }}
            <p>Credentials доступны только для записи. Пустой ввод и маска не меняют сохранённый секрет.</p>
            @error ('credentialData') <p role="alert">{{ $message }}</p> @enderror
            @if ($this->canSave('rotate-credentials'))
                <x-filament::button type="submit">Заменить credentials</x-filament::button>
            @endif
        </form>
    @endif
</x-filament-panels::page>
