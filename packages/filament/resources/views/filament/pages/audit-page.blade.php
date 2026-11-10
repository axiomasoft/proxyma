<x-filament-panels::page>
    <p>Actor и reason показаны SHA-256 fingerprints. Свободный текст, secrets и patch values не экспортируются.</p>
    @forelse ($records as $record)
        <x-filament::section wire:key="audit-{{ $record['id'] }}" heading="Audit event">
            <p>{{ $record['id'] }} · {{ $record['event_kind'] }} · {{ $record['ability'] }} · {{ $record['created_at'] }}</p>
            <p>{{ $record['entity_type'] }}:{{ $record['entity_id'] }} · revision {{ $record['previous_revision'] }} → {{ $record['revision'] }}</p>
            <p>Actor type: {{ $record['actor_type'] }} · actor fingerprint: {{ $record['actor_id'] }} · reason fingerprint: {{ $record['reason'] }}</p>
        </x-filament::section>
    @empty
        <p>Нет доступных событий аудита.</p>
    @endforelse
    @include('proxyma-filament::operations-pagination')
</x-filament-panels::page>
