@extends('app-development.layouts.project')

@section('title', __('app-development.reports.title'))

@section('app')
    @php
        $presets = [
            'today' => __('app-development.reports.preset_today'),
            'last_7' => __('app-development.reports.preset_last_7'),
            'last_30' => __('app-development.reports.preset_last_30'),
            'this_month' => __('app-development.reports.preset_this_month'),
            'last_month' => __('app-development.reports.preset_last_month'),
            'custom' => __('app-development.reports.preset_custom'),
        ];
        $timeline = collect($ticketStats['timeline'] ?? []);
        $maxTimeline = max(1, (int) $timeline->max());
        $totalHours = round(($timeStats['total_seconds'] ?? 0) / 3600, 1);
        $leaderMax = max(1, (int) collect($timeStats['leaderboard'] ?? [])->max('seconds'));
        $formatDuration = static function (int $seconds): string {
            $seconds = max(0, $seconds);
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;

            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        };
    @endphp

    <div class="app-dev-reports space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-[#2b1e11]">{{ __('app-development.reports.title') }}</h2>
                <p class="text-sm text-[#7c6a56]">{{ __('app-development.reports.subtitle') }}</p>
                <p class="mt-0.5 text-xs font-medium text-[#a78a6c]">{{ $from->toDateString() }} → {{ $to->toDateString() }}</p>
            </div>
            <form method="get" class="flex flex-wrap items-end gap-2 rounded-xl border border-[#eadfce] bg-[#fffaf3] p-2.5">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#7c6a56]">{{ __('app-development.reports.range') }}</label>
                    <select name="preset" class="kaman-input kaman-input--sm" onchange="this.form.submit()">
                        @foreach($presets as $key => $label)
                            <option value="{{ $key }}" @selected($preset === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if($preset === 'custom')
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-[#7c6a56]">{{ __('app-development.reports.from') }}</label>
                        <input type="date" name="from" value="{{ $from->toDateString() }}" class="kaman-input kaman-input--sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-[#7c6a56]">{{ __('app-development.reports.to') }}</label>
                        <input type="date" name="to" value="{{ $to->toDateString() }}" class="kaman-input kaman-input--sm">
                    </div>
                    <button class="kaman-button kaman-button--sm" type="submit">{{ __('app-development.reports.apply') }}</button>
                @endif
            </form>
        </header>

        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <article class="app-dev-report-kpi">
                <p class="app-dev-report-kpi__label">{{ __('app-development.reports.total_tickets') }}</p>
                <p class="app-dev-report-kpi__value">{{ $ticketStats['total'] }}</p>
            </article>
            <article class="app-dev-report-kpi">
                <p class="app-dev-report-kpi__label">{{ __('app-development.reports.total_tasks') }}</p>
                <p class="app-dev-report-kpi__value">{{ $taskStats['available'] ?? false ? ($taskStats['total'] ?? 0) : '—' }}</p>
            </article>
            <article class="app-dev-report-kpi">
                <p class="app-dev-report-kpi__label">{{ __('app-development.reports.total_hours') }}</p>
                <p class="app-dev-report-kpi__value">{{ $timeStats['available'] ?? false ? $totalHours.'h' : '—' }}</p>
            </article>
            <article class="app-dev-report-kpi">
                <p class="app-dev-report-kpi__label">{{ __('app-development.reports.avg_close') }}</p>
                <p class="app-dev-report-kpi__value">{{ $ticketStats['avg_close_hours'] !== null ? $ticketStats['avg_close_hours'].'h' : '—' }}</p>
            </article>
        </div>

        <section class="app-dev-report-section">
            <div class="app-dev-report-section__head">
                <h3>{{ __('app-development.reports.tickets_section') }}</h3>
                <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.tickets', request()->query()) }}">
                    {{ __('app-development.reports.export_csv') }}
                </a>
            </div>

            <div class="grid gap-3 lg:grid-cols-3">
                <div class="app-dev-report-panel">
                    <h4>{{ __('app-development.tickets.status') }}</h4>
                    <ul class="app-dev-report-list">
                        @foreach($ticketStats['by_status'] as $key => $count)
                            <li><span>{{ __('app-development.status.'.$key) }}</span><strong>{{ $count }}</strong></li>
                        @endforeach
                    </ul>
                </div>
                <div class="app-dev-report-panel">
                    <h4>{{ __('app-development.reports.by_priority') }}</h4>
                    <ul class="app-dev-report-list">
                        @foreach($ticketStats['by_priority'] as $key => $count)
                            <li><span>{{ __('app-development.priority.'.$key) }}</span><strong>{{ $count }}</strong></li>
                        @endforeach
                    </ul>
                </div>
                <div class="app-dev-report-panel">
                    <h4>{{ __('app-development.reports.by_app_type') }}</h4>
                    <ul class="app-dev-report-list">
                        @foreach($ticketStats['by_app_type'] as $key => $count)
                            <li><span>{{ __('app-development.app_types.'.$key) }}</span><strong>{{ $count }}</strong></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="app-dev-report-panel mt-3">
                <h4>{{ __('app-development.reports.timeline') }}</h4>
                @if($timeline->isEmpty())
                    <p class="text-xs text-[#a78a6c]">{{ __('app-development.reports.empty') }}</p>
                @else
                    <div class="app-dev-report-timeline">
                        @foreach($timeline as $day => $count)
                            @php $height = max(8, (int) round(($count / $maxTimeline) * 100)); @endphp
                            <div class="app-dev-report-timeline__col" title="{{ $day }}: {{ $count }}">
                                <span class="app-dev-report-timeline__count">{{ $count }}</span>
                                <div class="app-dev-report-timeline__bar" style="height: {{ $height }}%"></div>
                                <span class="app-dev-report-timeline__day">{{ \Illuminate\Support\Carbon::parse((string) $day)->format('d/m') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="app-dev-report-section">
            <div class="app-dev-report-section__head">
                <h3>{{ __('app-development.reports.tasks_section') }}</h3>
                <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.tasks', request()->query()) }}">
                    {{ __('app-development.reports.export_csv') }}
                </a>
            </div>

            @if(! ($taskStats['available'] ?? false))
                <p class="text-sm text-[#a78a6c]">{{ __('app-development.reports.tasks_unavailable') }}</p>
            @else
                <div class="mb-3 flex flex-wrap gap-3 text-sm text-[#7c6a56]">
                    <span>{{ __('app-development.reports.total') }}: <strong class="text-[#2b1e11]">{{ $taskStats['total'] }}</strong></span>
                    <span>{{ __('app-development.reports.overdue') }}: <strong class="text-[#2b1e11]">{{ $taskStats['overdue'] }}</strong></span>
                    @if($taskStats['avg_complete_hours'] !== null)
                        <span>{{ __('app-development.reports.avg_complete') }}: <strong class="text-[#2b1e11]">{{ $taskStats['avg_complete_hours'] }}h</strong></span>
                    @endif
                </div>
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="app-dev-report-panel">
                        <h4>{{ __('app-development.tasks.status') }}</h4>
                        <ul class="app-dev-report-list">
                            @foreach($taskStats['by_status'] as $key => $count)
                                <li><span>{{ __('app-development.task_status.'.$key) }}</span><strong>{{ $count }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="app-dev-report-panel">
                        <h4>{{ __('app-development.reports.by_assignee') }}</h4>
                        <ul class="app-dev-report-list">
                            @forelse($taskStats['by_assignee'] as $row)
                                <li><span>{{ $row->assignee?->name ?? '—' }}</span><strong>{{ $row->aggregate }}</strong></li>
                            @empty
                                <li class="text-[#a78a6c]">{{ __('app-development.reports.empty') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            @endif
        </section>

        <section class="app-dev-report-section">
            <div class="app-dev-report-section__head">
                <h3>{{ __('app-development.reports.time_section') }}</h3>
                <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.time', request()->query()) }}">
                    {{ __('app-development.reports.export_csv') }}
                </a>
            </div>

            @if(! ($timeStats['available'] ?? false))
                <p class="text-sm text-[#a78a6c]">{{ __('app-development.reports.time_unavailable') }}</p>
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="app-dev-report-panel">
                        <h4>{{ __('app-development.reports.leaderboard') }}</h4>
                        <ul class="app-dev-report-bars">
                            @forelse($timeStats['leaderboard'] as $row)
                                @php
                                    $seconds = (int) $row['seconds'];
                                    $width = max(4, (int) round(($seconds / $leaderMax) * 100));
                                @endphp
                                <li>
                                    <div class="app-dev-report-bars__meta">
                                        <span>{{ $row['user']?->name ?? '—' }}</span>
                                        <strong>{{ $formatDuration($seconds) }}</strong>
                                    </div>
                                    <div class="app-dev-report-bars__track">
                                        <span class="app-dev-report-bars__fill" style="width: {{ $width }}%"></span>
                                    </div>
                                </li>
                            @empty
                                <li class="text-xs text-[#a78a6c]">{{ __('app-development.reports.empty') }}</li>
                            @endforelse
                        </ul>
                    </div>

                    <div class="app-dev-report-panel">
                        <h4>{{ __('app-development.reports.entries_table') }}</h4>
                        <div class="app-dev-report-table-wrap">
                            <table class="app-dev-report-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('app-development.reports.col_user') }}</th>
                                        <th>{{ __('app-development.reports.col_ticket') }}</th>
                                        <th>{{ __('app-development.reports.col_hours') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($timeStats['entries'] as $entry)
                                        <tr>
                                            <td>{{ $entry->user?->name ?? '—' }}</td>
                                            <td>
                                                <span class="font-medium">{{ $entry->task?->ticket?->ticket_number ?? '—' }}</span>
                                                <span class="block truncate text-[11px] text-[#a78a6c]">{{ $entry->task?->title }}</span>
                                            </td>
                                            <td><bdi>{{ $formatDuration((int) ($entry->duration_seconds ?? $entry->elapsedSeconds())) }}</bdi></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-[#a78a6c]">{{ __('app-development.reports.empty') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
