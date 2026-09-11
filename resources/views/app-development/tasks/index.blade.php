@extends('app-development.layouts.project')

@section('title', __('app-development.tasks.title'))

@section('app')
    @php
        $user = auth()->user();
        $statusColumns = \App\Enums\AppDevelopmentTaskStatus::cases();
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex flex-wrap items-center gap-1.5">
            @if($user->isAppDevelopmentAdmin())
                <a href="{{ route('app-development.tasks.index', array_merge(request()->except('scope', 'page'), ['scope' => 'all'])) }}"
                   class="kaman-filter-chip {{ $scope === 'all' ? 'is-active' : '' }}">
                    {{ __('app-development.tasks.scope_all') }}
                </a>
                <a href="{{ route('app-development.tasks.index', array_merge(request()->except('scope', 'page'), ['scope' => 'mine'])) }}"
                   class="kaman-filter-chip {{ $scope === 'mine' ? 'is-active' : '' }}">
                    {{ __('app-development.tasks.scope_mine') }}
                </a>
            @else
                <span class="kaman-filter-chip is-active">{{ __('app-development.tasks.scope_mine') }}</span>
            @endif
        </div>

        @can('create', \App\Models\AppDevelopment\AppDevelopmentTask::class)
            <p class="max-w-xs text-end text-[11px] text-[#7c6a56]">{{ __('app-development.tasks.escalate_from_ticket_only') }}</p>
        @endcan
    </div>

    <form method="get" class="mt-3 flex flex-wrap items-end gap-2">
        @if($scope !== 'all')
            <input type="hidden" name="scope" value="{{ $scope }}">
        @endif
        <div>
            <label class="mb-1 block text-[11px] font-medium text-[#7c6a56]">{{ __('app-development.tasks.assignee') }}</label>
            <select name="assignee" class="kaman-input kaman-input--sm w-36" onchange="this.form.submit()">
                <option value="">{{ __('app-development.tasks.any_assignee') }}</option>
                @foreach($developers as $developer)
                    <option value="{{ $developer->id }}" @selected((string) ($filters['assignee'] ?? '') === (string) $developer->id)>{{ $developer->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-[#7c6a56]">{{ __('app-development.tickets.priority') }}</label>
            <select name="priority" class="kaman-input kaman-input--sm w-32" onchange="this.form.submit()">
                <option value="">{{ __('app-development.tasks.any_priority') }}</option>
                @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-[#7c6a56]">{{ __('app-development.tasks.status') }}</label>
            <select name="status" class="kaman-input kaman-input--sm w-36" onchange="this.form.submit()">
                <option value="">{{ __('app-development.tasks.any_status') }}</option>
                @foreach($statusColumns as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-[#7c6a56]">{{ __('app-development.tickets.app_types') }}</label>
            <select name="app_type[]" class="kaman-input kaman-input--sm w-40" onchange="this.form.submit()">
                <option value="">{{ __('app-development.tasks.any_app_type') }}</option>
                @foreach(\App\Enums\AppDevelopmentAppType::cases() as $appType)
                    <option value="{{ $appType->value }}" @selected(in_array($appType->value, $selectedAppTypes ?? [], true))>{{ $appType->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-[#7c6a56]">{{ __('app-development.tasks.ticket') }}</label>
            <input type="text" name="ticket" value="{{ $filters['ticket'] ?? '' }}" placeholder="KAM-…" class="kaman-input kaman-input--sm w-28">
        </div>
        <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tickets.filter') }}</button>
    </form>

    <div class="mt-4 grid gap-3 lg:grid-cols-4">
        @foreach($statusColumns as $status)
            @php $columnTasks = $grouped->get($status->value, collect()); @endphp
            <section class="rounded-xl border border-[#eadfce] bg-[#fffaf3]/p-2.5">
                <header class="mb-2 flex items-center justify-between gap-2 px-1">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-[#7c6a56]">{{ $status->label() }}</h2>
                    <span class="rounded-md bg-white px-1.5 text-[11px] font-bold text-[#2b1e11]">{{ $columnTasks->count() }}</span>
                </header>
                <div class="space-y-2">
                    @forelse($columnTasks as $task)
                        <a href="{{ $task->ticket ? route('app-development.tickets.show', ['ticket' => $task->ticket, 'task' => $task->id]) : route('app-development.tasks.index') }}"
                           class="block rounded-lg border border-[#f1dfc5] bg-white px-2.5 py-2 hover:border-[#f47a2e]/data-app-dev-modal>
                            <p class="text-sm font-medium text-[#2b1e11]">{{ $task->ticket?->ticket_number ?? $task->title }}</p>
                            <p class="mt-0.5 text-[11px] text-[#a78a6c]">
                                {{ $task->assignee?->name ?? __('app-development.tickets.unassigned') }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                @include('app-development.partials.priority-badge', ['priority' => $task->priority])
                                @if($task->isOverdue())
                                    <span class="rounded-md border border-red-200 bg-red-50 px-1.5 text-[10px] font-semibold text-red-800">{{ __('app-development.tasks.overdue') }}</span>
                                @endif
                            </div>
                        </a>
                    @empty
                        <p class="px-1 py-3 text-center text-[11px] text-[#a78a6c]">{{ __('app-development.tasks.empty_column') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-3">
        {{ $tasks->links() }}
    </div>
@endsection
