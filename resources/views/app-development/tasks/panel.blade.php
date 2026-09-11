@php
    $user = auth()->user();
    $activeEntry = $user ? $task->activeEntryFor($user) : null;
    $canTimer = $user?->can('startTimer', $task);
    $closedSeconds = $task->relationLoaded('timeEntries')
        ? (int) $task->timeEntries->whereNotNull('ended_at')->sum('duration_seconds')
        : (int) $task->timeEntries()->whereNotNull('ended_at')->sum('duration_seconds');
    $liveSeconds = $activeEntry
        ? $closedSeconds + $activeEntry->elapsedSeconds()
        : $task->totalDurationSeconds();
@endphp

<div data-modal-title="{{ $task->title }}" data-modal-variant="view" class="space-y-3">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <h3 class="text-base font-semibold leading-snug text-[#2b1e11]">{{ $task->title }}</h3>
            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                @include('app-development.partials.priority-badge', ['priority' => $task->priority])
                @include('app-development.partials.task-status-badge', ['status' => $task->status])
                @if($task->isOverdue())
                    <span class="rounded-md border border-red-200 bg-red-50 px-1.5 text-[10px] font-semibold text-red-800">{{ __('app-development.tasks.overdue') }}</span>
                @endif
            </div>
            <p class="mt-1.5 text-xs text-[#7c6a56] {{ $activeEntry ? 'app-dev-live-timer' : '' }}"
               @if($activeEntry)
                   data-app-dev-timer
                   data-started-at="{{ $activeEntry->started_at?->toIso8601String() }}"
                   data-base-seconds="{{ $closedSeconds }}"
               @endif>
                @if($task->ticket)
                    <a href="{{ route('app-development.tickets.show', $task->ticket) }}" class="font-medium text-[#f16229]" data-app-dev-modal>
                        {{ $task->ticket->ticket_number }}
                    </a>
                    ·
                @endif
                {{ $task->assignee?->name ?? __('app-development.tickets.unassigned') }}
                · {{ __('app-development.tasks.total_time') }}:
                <bdi class="app-dev-live-timer__clock" data-timer-clock>{{ gmdate('H:i:s', $liveSeconds) }}</bdi>
                @if($activeEntry)
                    <span class="app-dev-live-timer__badge">{{ __('app-development.tasks.running') }}</span>
                @endif
            </p>
        </div>
    </div>

    @if(!empty($suggestCompleteTicket) && $task->ticket)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {{ __('app-development.tasks.suggest_complete_ticket', ['ticket' => $task->ticket->ticket_number]) }}
        </div>
    @endif

    @if(filled($task->description))
        <p class="whitespace-pre-wrap text-sm leading-relaxed text-[#2b1e11]">{{ $task->description }}</p>
    @endif

    @if($canTimer && ! $task->isCompleted())
        <div class="flex flex-wrap items-center gap-2 border-t border-[#f1dfc5] pt-3">
            @if($activeEntry)
                <span class="app-dev-live-timer app-dev-live-timer--inline"
                      data-app-dev-timer
                      data-started-at="{{ $activeEntry->started_at?->toIso8601String() }}"
                      data-base-seconds="0">
                    <span class="app-dev-timer-widget__dot" aria-hidden="true"></span>
                    <bdi class="app-dev-live-timer__clock" data-timer-clock>{{ gmdate('H:i:s', $activeEntry->elapsedSeconds()) }}</bdi>
                </span>
                <form method="post" action="{{ route('app-development.tasks.timer.pause', $task) }}">
                    @csrf
                    <button type="submit" class="kaman-button-ghost kaman-button--sm">
                        {{ __('app-development.tasks.pause_timer') }}
                    </button>
                </form>
            @else
                <form method="post" action="{{ route('app-development.tasks.timer.start', $task) }}">
                    @csrf
                    <button type="submit" class="kaman-button kaman-button--sm">
                        {{ __('app-development.tasks.start_timer') }}
                    </button>
                </form>
            @endif
            <form method="post" action="{{ route('app-development.tasks.timer.complete', $task) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="text"
                       name="completion_note"
                       class="kaman-input kaman-input--sm w-40"
                       placeholder="{{ __('app-development.tasks.completion_note') }}">
                <button type="submit" class="kaman-button-ghost kaman-button--sm">
                    {{ __('app-development.tasks.complete') }}
                </button>
            </form>
        </div>
    @endif

    @can('update', $task)
        <form method="post" action="{{ route('app-development.tasks.update', $task) }}" class="space-y-3 border-t border-[#f1dfc5] pt-3">
            @csrf
            @method('PUT')
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.title_label') }}</label>
                <input type="text" name="title" value="{{ old('title', $task->title) }}" required class="kaman-input w-full">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.description') }}</label>
                <textarea name="description" rows="3" class="kaman-input w-full">{{ old('description', $task->description) }}</textarea>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.assignee') }}</label>
                    <select name="assignee_id" class="kaman-input w-full">
                        <option value="">{{ __('app-development.tickets.none') }}</option>
                        @foreach($developers as $developer)
                            <option value="{{ $developer->id }}" @selected((string) old('assignee_id', $task->assignee_id) === (string) $developer->id)>{{ $developer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.priority') }}</label>
                    <select name="priority" class="kaman-input w-full">
                        @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(old('priority', $task->priority->value) === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.status') }}</label>
                    <select name="status" class="kaman-input w-full">
                        @foreach(\App\Enums\AppDevelopmentTaskStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(old('status', $task->status->value) === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.due_at') }}</label>
                <input type="datetime-local"
                       name="due_at"
                       value="{{ old('due_at', $task->due_at?->format('Y-m-d\TH:i')) }}"
                       class="kaman-input w-full">
            </div>
            <div class="flex justify-end">
                <button type="submit" class="kaman-button kaman-button--sm">{{ __('app-development.save') }}</button>
            </div>
        </form>
    @endcan

    <div class="space-y-2 border-t border-[#f1dfc5] pt-3">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-[#7c6a56]">{{ __('app-development.tasks.time_entries') }}</h4>
        @forelse($task->timeEntries as $entry)
            <div class="rounded-xl bg-white px-3 py-2 text-sm text-[#2b1e11]">
                <p class="text-[11px] text-[#a78a6c]">
                    {{ $entry->user?->name }}
                    · {{ $entry->started_at?->format('d/m H:i') }}
                    @if($entry->ended_at)
                        – {{ $entry->ended_at->format('H:i') }}
                    @else
                        · {{ __('app-development.tasks.running') }}
                    @endif
                </p>
                <p class="mt-0.5 font-medium">
                    <bdi>{{ gmdate('H:i:s', $entry->elapsedSeconds()) }}</bdi>
                    @if($entry->note)
                        — {{ $entry->note }}
                    @endif
                </p>
                @can('editTimeEntry', $entry)
                    @unless($entry->isActive())
                        <form method="post" action="{{ route('app-development.time-entries.update', $entry) }}" class="mt-2 grid gap-2 sm:grid-cols-3">
                            @csrf
                            @method('PUT')
                            <input type="number" name="duration_seconds" value="{{ $entry->duration_seconds }}" min="0" class="kaman-input kaman-input--sm" required>
                            <input type="text" name="edit_reason" placeholder="{{ __('app-development.tasks.edit_reason') }}" class="kaman-input kaman-input--sm" required>
                            <div class="flex gap-1">
                                <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app.save') }}</button>
                            </div>
                        </form>
                        <form method="post" action="{{ route('app-development.time-entries.destroy', $entry) }}" class="mt-1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-700 hover:underline">{{ __('app.delete') }}</button>
                        </form>
                    @endunless
                @endcan
            </div>
        @empty
            <p class="text-xs text-[#a78a6c]">{{ __('app-development.tasks.no_time_entries') }}</p>
        @endforelse
    </div>
</div>
