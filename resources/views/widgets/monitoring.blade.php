<x-filament-widgets::widget>
    <div class="ferry-monitoring" wire:poll.30s="$refresh">
        <header class="ferry-monitoring-header">
            <div>
                <div class="ferry-monitoring-heading">
                    <h2>{{ __('phpinnacle-ferry::monitoring.title') }}</h2>
                    <x-filament::badge :color="$snapshot->status->getColor()" :icon="$snapshot->status->getIcon()">
                        {{ $snapshot->status->getLabel() }}
                    </x-filament::badge>
                </div>
                <p class="ferry-monitoring-muted">
                    {{ __('phpinnacle-ferry::monitoring.fields.sampled_at') }}:
                    @if ($sampledAt)
                        <time datetime="{{ $sampledAt->toIso8601String() }}">
                            {{ $sampledAt->diffForHumans() }} · {{ $sampledAt->toIso8601String() }}
                        </time>
                    @else
                        —
                    @endif
                </p>
            </div>
            <p class="ferry-monitoring-muted">{{ __('phpinnacle-ferry::monitoring.refresh') }}</p>
        </header>

        <div class="ferry-monitoring-pipeline">
            @foreach ($stages as $stage)
                @if (!$loop->first)
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ArrowRight" class="ferry-monitoring-arrow" />
                @endif
                <x-filament::section class="ferry-monitoring-stage" wire:key="stage-{{ $loop->index }}">
                    <div class="ferry-monitoring-stage-title">
                        <x-filament::icon :icon="$stage['icon']" />
                        <h3>{{ $stage['title'] }}</h3>
                    </div>
                    <p class="ferry-monitoring-muted">{{ $stage['status'] }}</p>
                    <p class="ferry-monitoring-value">{{ $stage['value'] }}</p>
                    <p class="ferry-monitoring-muted">{{ $stage['label'] }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <div class="ferry-monitoring-heading ferry-monitoring-chart-heading">
            <h3>{{ __('phpinnacle-ferry::monitoring.recent_activity') }}</h3>
            <span class="ferry-monitoring-muted">{{ __('phpinnacle-ferry::monitoring.history_window') }}</span>
        </div>
        <div class="ferry-monitoring-charts">
            @foreach ($charts as $chart)
                @if ($chart['data']['datasets'] !== [])
                    @livewire(\PHPinnacle\Ferry\Widgets\MonitoringChart::class, [
                        'title' => $chart['title'],
                        'summary' => $chart['summary'],
                        'unit' => $chart['unit'],
                        'chartData' => $chart['data'],
                    ], key('monitoring-chart-' . $this->getId() . '-' . $loop->index))
                @else
                    <x-filament::section :heading="$chart['title']" wire:key="empty-chart-{{ $loop->index }}">
                        <p class="ferry-monitoring-empty ferry-monitoring-muted">
                            {{ __($historyAvailable ? 'phpinnacle-ferry::monitoring.no_history' : 'phpinnacle-ferry::monitoring.history_unavailable') }}
                        </p>
                    </x-filament::section>
                @endif
            @endforeach
        </div>

        <x-filament::section>
            <dl class="ferry-monitoring-summary">
                @if ($queue !== null)
                    <div class="ferry-monitoring-queue">
                        <dt>{{ __('phpinnacle-ferry::monitoring.fields.queue') }}</dt>
                        <dd>{{ $queue['text'] }}@if ($queue['percent'] !== null) · {{ number_format($queue['percent'], 1) }}%@endif</dd>
                        @if ($queue['percent'] !== null)
                            <meter min="0" max="100" value="{{ $queue['percent'] }}" aria-label="{{ __('phpinnacle-ferry::monitoring.fields.queue') }}">{{ $queue['percent'] }}%</meter>
                        @endif
                    </div>
                @endif
                @foreach ($summary as $label => $value)
                    <div wire:key="summary-{{ $loop->index }}">
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section :heading="$detailsHeading" collapsible collapsed>
            <dl class="ferry-monitoring-details">
                @foreach ($details as $label => $value)
                    <div wire:key="detail-{{ $loop->index }}"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
                <div><dt>{{ __('phpinnacle-ferry::monitoring.fields.worker') }}</dt><dd>{{ $worker }}</dd></div>
            </dl>
            <p class="ferry-monitoring-muted">{{ $detailsNote }}</p>
        </x-filament::section>
        <p class="ferry-monitoring-muted">{{ $note }}</p>
    </div>
</x-filament-widgets::widget>
