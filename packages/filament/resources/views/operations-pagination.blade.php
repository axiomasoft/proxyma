<div class="flex gap-2">
    @if ($records->currentPage() > 1)
        <x-filament::button wire:click="changePage({{ $records->currentPage() - 1 }})">Назад</x-filament::button>
    @endif
    <p>Страница {{ $records->currentPage() }} · до 25 записей</p>
    @if ($records->hasMorePages())
        <x-filament::button wire:click="changePage({{ $records->currentPage() + 1 }})">Далее</x-filament::button>
    @endif
</div>
