@extends('app-development.layouts.project')

@section('title', __('app-development.reports.headline'))

@section('app')
@php
    $chip = function (?float $delta, bool $lowerIsBetter = false): array {
        if ($delta === null) {
            return ['class' => 'neutral', 'text' => __('app-development.reports.this_period')];
        }

        $good = $lowerIsBetter ? $delta < 0 : $delta > 0;
        $arrow = $delta < 0 ? '↘' : ($delta > 0 ? '↗' : '→');

        return [
            'class' => $good ? 'positive' : 'neutral',
            'text' => $arrow.' '.number_format(abs($delta), 1).'%',
        ];
    };
    $completedChip = $chip($dash['completed']['delta']);
    $openChip = $chip($dash['open']['delta']);
    $cycleChip = $chip($dash['cycle']['delta'], true);
    $releaseChip = $chip($dash['releases']['delta']);
    $hasTrend = collect($dash['trend'])->sum(fn (array $point): int => $point['created'] + $point['completed']) > 0;
@endphp

<div class="report-header ds-head">
    <div>
        <h1 class="ds-h1">{{ __('app-development.reports.headline') }}</h1>
    </div>
    <form method="get" action="{{ route('app-development.reports.index') }}" class="report-actions">
        <select id="reportPeriod" name="preset" class="report-period" aria-label="{{ __('app-development.reports.period') }}" onchange="this.form.submit()">
            @foreach (['last_7', 'last_30', 'last_90'] as $key)
                <option value="{{ $key }}" @selected($preset === $key)>{{ __('app-development.reports.preset_'.$key) }}</option>
            @endforeach
        </select>
        <a class="report-export" href="{{ route('app-development.reports.export.summary', ['preset' => $preset]) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
            {{ __('app-development.reports.export_csv') }}
        </a>
    </form>
</div>

@if (! $dash['available'])
    <p class="report-empty">{{ __('app-development.reports.unavailable') }}</p>
@else
    <section class="ds-stats ds-card dv-report-stats">
        @foreach([
            ['label' => __('app-development.reports.completed'), 'value' => $dash['completed']['value'], 'chip' => $completedChip],
            ['label' => __('app-development.reports.open_tasks'), 'value' => $dash['open']['value'], 'chip' => $openChip],
            ['label' => __('app-development.reports.cycle'), 'value' => $dash['cycle']['days'] === null ? '—' : number_format((float) $dash['cycle']['days'], 1).__('app-development.reports.day_short'), 'chip' => $cycleChip],
            ['label' => __('app-development.reports.apk_releases'), 'value' => $dash['releases']['value'], 'chip' => $releaseChip],
        ] as $metric)
            <div class="ds-stat">
                <span class="ds-label">{{ $metric['label'] }}</span>
                <strong class="ds-num">{{ $metric['value'] }}</strong>
                <small class="ds-delta {{ $metric['chip']['class'] === 'positive' ? 'is-up' : '' }}"><span dir="ltr">{{ $metric['chip']['text'] }}</span></small>
            </div>
        @endforeach
    </section>

    <div class="report-two-col">
        <section class="report-panel">
            <div class="report-panel-head">
                <div>
                    <h2>{{ __('app-development.reports.trend') }}</h2>
                </div>
                <div class="report-legend">
                    <span><i class="legend-dot completed"></i> {{ __('app-development.reports.legend_completed') }}</span>
                    <span><i class="legend-dot created"></i> {{ __('app-development.reports.legend_created') }}</span>
                </div>
            </div>
            @if (! $hasTrend)
                <p class="report-empty">{{ __('app-development.reports.empty') }}</p>
            @else
                <div class="report-chart" role="img" aria-label="{{ __('app-development.reports.trend') }}">
                    @foreach ($dash['trend'] as $point)
                        <div class="report-chart-group">
                            <div class="report-bars">
                                <div class="report-bar done" style="height: {{ $point['completed'] === 0 ? 0 : round($point['completed'] / $dash['trend_max'] * 100, 1) }}%"></div>
                                <div class="report-bar created" style="height: {{ $point['created'] === 0 ? 0 : round($point['created'] / $dash['trend_max'] * 100, 1) }}%"></div>
                            </div>
                            <small>{{ $point['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        <section class="report-panel">
            <div class="report-panel-head">
                <div>
                    <h2>{{ __('app-development.reports.breakdown') }}</h2>
                </div>
            </div>
            <div class="report-donut-area">
                <div class="report-donut" @if($dash['donut']) style="background: {{ $dash['donut'] }}" @endif>
                    <div class="report-donut-center">
                        <strong>{{ $dash['status_total'] }}</strong>
                        <small>{{ __('app-development.reports.total_tasks') }}</small>
                    </div>
                </div>
                <div class="report-breakdown">
                    @foreach ($dash['statuses'] as $status)
                        <div class="report-breakdown-row">
                            <i style="background: {{ $status['color'] }}"></i>
                            <span>{{ $status['label'] }}</span>
                            <b>{{ $status['count'] }}</b>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>

    <div class="report-two-col">
        <section class="report-panel">
            <div class="report-panel-head">
                <div>
                    <h2>{{ __('app-development.reports.workload') }}</h2>
                </div>
            </div>
            @if ($dash['apps'] === [])
                <p class="report-empty">{{ __('app-development.reports.empty') }}</p>
            @else
                <div class="report-workload">
                    @foreach ($dash['apps'] as $app)
                        <div>
                            <div class="report-workload-top"><span>{{ $app['label'] }}</span><span>{{ $app['count'] }}</span></div>
                            <div class="report-workload-track"><div class="report-workload-fill" style="width: {{ $app['percent'] }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        <section class="report-panel">
            <div class="report-panel-head">
                <div>
                    <h2>{{ __('app-development.reports.activity') }}</h2>
                </div>
            </div>
            @if ($dash['activity'] === [])
                <p class="report-empty">{{ __('app-development.reports.empty') }}</p>
            @else
                <div class="report-activity">
                    @foreach ($dash['activity'] as $event)
                        <div class="report-activity-item">
                            <span class="report-event-icon {{ $event['tone'] }}"></span>
                            <div>
                                <strong>{{ $event['title'] }}</strong>
                                @if ($event['detail'] !== '')
                                    <p>{{ $event['detail'] }}</p>
                                @endif
                            </div>
                            <small>{{ $event['when'] }}</small>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endif
@endsection
