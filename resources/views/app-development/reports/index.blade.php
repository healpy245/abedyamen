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
        $maxTimeline = max(1, (int) collect($ticketStats['timeline'] ?? [])->max());
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-[#2b1e11]">{{ __('app-development.reports.title') }}</h2>
            <p class="text-sm text-[#7c6a56]">{{ $from->toDateString() }} → {{ $to->toDateString() }}</p>
        </div>
        <form method="get" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-[#7c6a56]">{{ __('app-development.reports.range') }}</label>
                <select name="preset" class="kaman-input kaman-input--sm" onchange="this.form.submit()">
                    @foreach($presets as $key => $label)
                        <option value="{{ $key }}" @selected($preset === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($preset === 'custom')
                <div>
                    <label class="mb-1 block text-xs">{{ __('app-development.reports.from') }}</label>
                    <input type="date" name="from" value="{{ $from->toDateString() }}" class="kaman-input kaman-input--sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs">{{ __('app-development.reports.to') }}</label>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" class="kaman-input kaman-input--sm">
                </div>
                <button class="kaman-button kaman-button--sm" type="submit">{{ __('app-development.tickets.filter') }}</button>
            @endif
        </form>
    </div>

    <section class="kaman-card mt-3 space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-[#2b1e11]">{{ __('app-development.reports.tickets') }}</h3>
            <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.tickets', request()->query()) }}">CSV</a>
        </div>
        <p class="text-sm">{{ __('app-development.reports.total') }}: <strong>{{ $ticketStats['total'] }}</strong>
            @if($ticketStats['avg_close_hours'] !== null)
                · {{ __('app-development.reports.avg_close') }}: <strong>{{ $ticketStats['avg_close_hours'] }}h</strong>
            @endif
        </p>
        <div class="grid gap-3 md:grid-cols-3">
            <div>
                <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.tickets.status') }}</h4>
                @foreach($ticketStats['by_status'] as $key => $count)
                    <div class="flex justify-between text-sm"><span>{{ __('app-development.status.'.$key) }}</span><strong>{{ $count }}</strong></div>
                @endforeach
            </div>
            <div>
                <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.tickets.priority') }}</h4>
                @foreach($ticketStats['by_priority'] as $key => $count)
                    <div class="flex justify-between text-sm"><span>{{ __('app-development.priority.'.$key) }}</span><strong>{{ $count }}</strong></div>
                @endforeach
            </div>
            <div>
                <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.tickets.app_types') }}</h4>
                @foreach($ticketStats['by_app_type'] as $key => $count)
                    <div class="flex justify-between text-sm"><span>{{ __('app-development.app_types.'.$key) }}</span><strong>{{ $count }}</strong></div>
                @endforeach
            </div>
        </div>
        <div>
            <h4 class="mb-2 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.reports.timeline') }}</h4>
            <div class="flex h-28 items-end gap-1 overflow-x-auto">
                @forelse($ticketStats['timeline'] as $day => $count)
                    <div class="flex w-4 flex-col items-center gap-1" title="{{ $day }}: {{ $count }}">
                        <div class="w-full rounded-t bg-[#c45c26]" style="height: {{ max(4, (int) round(($count / $maxTimeline) * 100)) }}%"></div>
                    </div>
                @empty
                    <p class="text-xs text-[#a78a6c]">{{ __('app-development.reports.empty') }}</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="kaman-card mt-3 space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-[#2b1e11]">{{ __('app-development.reports.tasks') }}</h3>
            <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.tasks', request()->query()) }}">CSV</a>
        </div>
        @if(! ($taskStats['available'] ?? false))
            <p class="text-sm text-[#a78a6c]">{{ __('app-development.reports.unavailable') }}</p>
        @else
            <p class="text-sm">{{ __('app-development.reports.total') }}: <strong>{{ $taskStats['total'] }}</strong>
                · {{ __('app-development.reports.overdue') }}: <strong>{{ $taskStats['overdue'] }}</strong>
                @if($taskStats['avg_complete_hours'] !== null)
                    · {{ __('app-development.reports.avg_complete') }}: <strong>{{ $taskStats['avg_complete_hours'] }}h</strong>
                @endif
            </p>
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    @foreach($taskStats['by_status'] as $key => $count)
                        <div class="flex justify-between text-sm"><span>{{ __('app-development.task_status.'.$key) }}</span><strong>{{ $count }}</strong></div>
                    @endforeach
                </div>
                <div>
                    <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.tasks.assignee') }}</h4>
                    @forelse($taskStats['by_assignee'] as $row)
                        <div class="flex justify-between text-sm"><span>{{ $row->assignee?->name ?? '—' }}</span><strong>{{ $row->aggregate }}</strong></div>
                    @empty
                        <p class="text-xs text-[#a78a6c]">{{ __('app-development.reports.empty') }}</p>
                    @endforelse
                </div>
            </div>
        @endif
    </section>

    <section class="kaman-card mt-3 space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold text-[#2b1e11]">{{ __('app-development.reports.time') }}</h3>
            <a class="kaman-button-ghost kaman-button--sm" href="{{ route('app-development.reports.export.time', request()->query()) }}">CSV</a>
        </div>
        @if(! ($timeStats['available'] ?? false))
            <p class="text-sm text-[#a78a6c]">{{ __('app-development.reports.unavailable') }}</p>
        @else
            <p class="text-sm">{{ __('app-development.reports.total_hours') }}:
                <strong>{{ round(($timeStats['total_seconds'] ?? 0) / 3600, 1) }}h</strong>
            </p>
            <div class="grid gap-3 lg:grid-cols-2">
                <div>
                    <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.reports.leaderboard') }}</h4>
                    @forelse($timeStats['leaderboard'] as $row)
                        <div class="flex justify-between text-sm">
                            <span>{{ $row['user']?->name ?? '—' }}</span>
                            <strong>{{ gmdate('H:i:s', $row['seconds']) }}</strong>
                        </div>
                    @empty
                        <p class="text-xs text-[#a78a6c]">{{ __('app-development.reports.empty') }}</p>
                    @endforelse
                </div>
                <div>
                    <h4 class="mb-1 text-xs font-bold uppercase text-[#a78a6c]">{{ __('app-development.reports.entries') }}</h4>
                    <div class="max-h-64 space-y-1 overflow-y-auto text-xs">
                        @foreach($timeStats['entries'] as $entry)
                            <div class="rounded border border-[#f1dfc5] px-2 py-1">
                                {{ $entry->user?->name }} · {{ $entry->task?->ticket?->ticket_number }} · {{ $entry->task?->title }}
                                · {{ gmdate('H:i:s', $entry->duration_seconds ?? $entry->elapsedSeconds()) }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection
