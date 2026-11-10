<x-filament-panels::page>
    <p>Unknown after authorization: отправка могла состояться, consumed не возвращается. Только диагностика; blind retry недоступен.</p>
    @forelse ($records as $record)
        <p wire:key="attempt-{{ $record['id'] }}">
            {{ $record['id'] }} · operation {{ $record['operation_id'] }} · {{ $record['status'] }}
            <x-filament::button wire:click="edit('{{ $record['id'] }}')">Диагностика</x-filament::button>
        </p>
    @empty
        <p>Нет доступных попыток.</p>
    @endforelse
    @include('proxyma-filament::operations-pagination')
    @if ($editing !== [])
        <x-filament::section heading="Диагностика попытки">
            <p>Attempt {{ $editing['id'] }} · operation {{ $editing['operation_id'] }} · physical attempt {{ $editing['physical_attempt_id'] }}</p>
            <p>{{ $editing['status'] }} · authorized {{ $editing['authorized_at'] }} · completed {{ $editing['completed_at'] ?? 'нет receipt' }}</p>
            <p>Детали URL, headers, body, owner tokens и secret refs скрыты.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
