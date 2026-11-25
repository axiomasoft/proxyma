<x-filament-panels::page>
    <form wire:submit="loadBinding" class="space-y-4">
        {{ $this->selector }}
        <x-filament::button type="submit">Загрузить настройки</x-filament::button>
    </form>
    @if ($editing !== [])
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            @error ('data') <p role="alert">{{ $message }}</p> @enderror
            @if (! $this->isReadOnly())
                <x-filament::button type="submit">Сохранить</x-filament::button>
            @endif
        </form>
    @endif
</x-filament-panels::page>
