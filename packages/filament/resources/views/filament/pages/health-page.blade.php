<x-filament-panels::page>
    <p>Route hold, route/target pair hold и target hold независимы. Reset возвращает unknown и не снимает quota cooldown.</p>
    <div class="flex gap-2">
        <x-filament::button wire:click="chooseScope('route_health')">Route hold</x-filament::button>
        <x-filament::button wire:click="chooseScope('route_target_health')">Pair hold</x-filament::button>
        <x-filament::button wire:click="chooseScope('target_health')">Target hold</x-filament::button>
    </div>
    <p>Точная область: {{ $healthScope }}</p>
    @forelse ($records as $record)
        <x-filament::section wire:key="health-{{ $healthScope }}-{{ $record['id'] }}" heading="Health scope">
            <p>{{ $record['scope'] }}:{{ $record['id'] }} · route {{ $record['route_id'] ?? '—' }} · target {{ $record['target_id'] ?? '—' }}</p>
            <p>{{ $record['state'] }} · epoch {{ $record['health_epoch'] }} · revision {{ $record['state_version'] }} · причина {{ $record['reason_code'] ?? 'нет' }}</p>
            <p>Hold until: {{ $record['open_until'] ?? 'без срока / отсутствует' }} · probe lease until: {{ $record['probe_until'] ?? 'нет' }}</p>
            <x-filament::button wire:click="edit('{{ $record['id'] }}')">Открыть область</x-filament::button>
        </x-filament::section>
    @empty
        <p>Нет доступных health scopes. Отсутствующее состояние запрещает отправку.</p>
    @endforelse
    @include('proxyma-filament::operations-pagination')
    @if ($editing !== [])
        <form wire:submit="resetHealth" class="space-y-4">
            <p>Reset exact {{ $editing['scope'] }}:{{ $editing['id'] }} · epoch {{ $editing['health_epoch'] }} · revision {{ $editing['state_version'] }}</p>
            {{ $this->form }}
            @if ($this->canCommand('health.reset'))
                <x-filament::button type="submit">Reset в unknown</x-filament::button>
            @endif
        </form>
    @endif
    <form wire:submit="previewProbe" class="space-y-4">
        {{ $this->probeSelector }}
        <x-filament::button type="submit">Проверить exact probe scopes</x-filament::button>
    </form>
    @if ($probeReview !== [])
        <p>Probe использует обычные origin/route/egress/probe budgets, один HEAD, без повторов.</p>
        <p>Profile {{ $probeReview['profile'] }} · route {{ $probeReview['route'] }} · target {{ $probeReview['target'] }}</p>
        @foreach ($probeReview['versions'] as $scope => $revision)
            <p>{{ $scope }} · revision {{ $revision }} · epoch {{ $probeReview['epochs'][$scope] ?? '—' }}</p>
        @endforeach
        <form wire:submit="probe" class="space-y-4">
            {{ $this->probeForm }}
            @if ($this->canProbe())
                <x-filament::button type="submit">Выполнить probe</x-filament::button>
            @endif
        </form>
    @endif
    @if ($probeResult !== [])
        <p>Probe принят: operation {{ $probeResult['operation_id'] }}. Проверьте Attempts; неизвестный исход требует диагностики, не повторной отправки.</p>
    @endif
    @error ('data') <p role="alert">{{ $message }}</p> @enderror
</x-filament-panels::page>
