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

<div class="report-header">
    <div>
        <div class="report-eyebrow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            {{ __('app-development.reports.eyebrow') }}
        </div>
        <h1>{{ __('app-development.reports.headline') }}</h1>
        <p>{{ __('app-development.reports.lead') }}</p>
    </div>
    <form method="get" action="{{ route('app-development.reports.index') }}" class="report-actions">
        <label class="report-period-label" for="reportPeriod">{{ __('app-development.reports.period') }}</label>
        <select id="reportPeriod" name="preset" class="report-period" onchange="this.form.submit()">
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
    <div class="report-metrics">
        <article class="report-metric">
            <div class="metric-top"><span>{{ __('app-development.reports.completed') }}</span></div>
            <div class="metric-main">
                <strong>{{ $dash['completed']['value'] }}</strong>
                <span class="metric-delta {{ $completedChip['class'] }}">{{ $completedChip['text'] }}</span>
            </div>
            <div class="metric-note">{{ __('app-development.reports.vs_previous') }}</div>
            @if ($dash['completed']['spark'])
                <div class="metric-spark"><svg viewBox="0 0 160 40" preserveAspectRatio="none"><path d="{{ $dash['completed']['spark'] }}" fill="none" stroke="#21b58d" stroke-width="2.8" stroke-linecap="round"/></svg></div>
            @endif
        </article>
        <article class="report-metric">
            <div class="metric-top"><span>{{ __('app-development.reports.open_tasks') }}</span></div>
            <div class="metric-main">
                <strong>{{ $dash['open']['value'] }}</strong>
                <span class="metric-delta {{ $openChip['class'] }}">{{ $openChip['text'] }}</span>
            </div>
            <div class="metric-note">{{ __('app-development.reports.awaiting') }}</div>
        </article>
        <article class="report-metric">
            <div class="metric-top"><span>{{ __('app-development.reports.cycle') }}</span></div>
            <div class="metric-main">
                <strong>
                    @if ($dash['cycle']['days'] === null)
                        —
                    @else
                        {{ number_format((float) $dash['cycle']['days'], 1) }}<span class="metric-unit">{{ __('app-development.reports.day_short') }}</span>
                    @endif
                </strong>
                <span class="metric-delta {{ $cycleChip['class'] }}">{{ $cycleChip['text'] }}</span>
            </div>
            <div class="metric-note">{{ __('app-development.reports.cycle_note') }}</div>
            @if ($dash['cycle']['spark'])
                <div class="metric-spark"><svg viewBox="0 0 160 40" preserveAspectRatio="none"><path d="{{ $dash['cycle']['spark'] }}" fill="none" stroke="#7b89e9" stroke-width="2.8" stroke-linecap="round"/></svg></div>
            @endif
        </article>
        <article class="report-metric">
            <div class="metric-top"><span>{{ __('app-development.reports.apk_releases') }}</span></div>
            <div class="metric-main">
                <strong>{{ $dash['releases']['value'] }}</strong>
                <span class="metric-delta {{ $releaseChip['class'] }}">{{ $releaseChip['text'] }}</span>
            </div>
            <div class="metric-note">{{ __('app-development.reports.builds') }}</div>
            @if ($dash['releases']['spark'])
                <div class="metric-spark"><svg viewBox="0 0 160 40" preserveAspectRatio="none"><path d="{{ $dash['releases']['spark'] }}" fill="none" stroke="#f0b642" stroke-width="2.8" stroke-linejoin="round"/></svg></div>
            @endif
        </article>
    </div>

    <div class="report-two-col">
        <section class="report-panel">
            <div class="report-panel-head">
                <div>
                    <h2>{{ __('app-development.reports.trend') }}</h2>
                    <p>{{ __('app-development.reports.trend_sub') }}</p>
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
                    <p>{{ __('app-development.reports.breakdown_sub') }}</p>
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
                    <p>{{ __('app-development.reports.workload_sub') }}</p>
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
                    <p>{{ __('app-development.reports.activity_sub') }}</p>
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
