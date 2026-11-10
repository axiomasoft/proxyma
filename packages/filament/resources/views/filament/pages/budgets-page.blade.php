<x-filament-panels::page>
    <p>Advisory snapshot: отправка заново проверяет все бюджеты под блокировкой.</p>
    <p>Inflight — lease accounting bound, не число физических сокетов после crash.</p>
    <p>Показаны собственные бюджеты tenant. Общие ограничения проверяются core admission; этот список не выдаёт send authority.</p>
    @forelse ($records as $record)
        <x-filament::section wire:key="budget-{{ $record['id'] }}" heading="Бюджет">
            <p>{{ $record['id'] }} · {{ $record['scope'] }} · r{{ $record['revision'] }}</p>
            <p>Scope fingerprint: {{ $record['scope_fingerprint'] }}</p>
            <p>GCRA rate: interval {{ $record['emission_interval_us'] }} µs · burst {{ $record['burst'] }}. Это не календарная квота.</p>
            <p>Inflight leases: {{ $record['inflight'] }} / {{ $record['max_inflight'] }}</p>
            <p>Next eligible UTC (advisory): {{ $record['next_eligible_at'] ?? 'не определено: запрет, inflight или окно не материализовано' }}</p>
            <p>Cooldown UTC: {{ $record['cooldown_until'] ?? 'нет' }}</p>
            @forelse ($record['quotas'] as $quota)
                <p>Fixed UTC calendar {{ $quota['window'] }}: consumed {{ $quota['consumed'] ?? 'не материализовано' }} · reserved {{ $quota['reserved'] ?? 'не материализовано' }} · limit {{ $quota['limit'] }} · window {{ $quota['starts_at'] ?? '—' }} → {{ $quota['ends_at'] ?? '—' }}</p>
            @empty
                <p>Дополнительная UTC quota не настроена; остальные ограничения действуют.</p>
            @endforelse
            <x-filament::button wire:click="edit('{{ $record['id'] }}')">Открыть бюджет</x-filament::button>
        </x-filament::section>
    @empty
        <p>Нет доступных бюджетов. Пустой список не разрешает отправку.</p>
    @endforelse
    @include('proxyma-filament::operations-pagination')
    @if ($editing !== [])
        <form wire:submit="updateBudget" class="space-y-4">
            <p>Exact budget {{ $editing['id'] }} · r{{ $editing['revision'] }}</p>
            {{ $this->form }}
            @error ('data') <p role="alert">{{ $message }}</p> @enderror
            @if ($editing['editable'] && $this->canCommand('catalog.budget.configure'))
                <x-filament::button type="submit">Обновить квоту</x-filament::button>
            @endif
        </form>
    @endif
</x-filament-panels::page>
