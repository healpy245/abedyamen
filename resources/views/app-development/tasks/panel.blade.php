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
    $ticket = $task->ticket;
    $apps = $ticket?->appTypes() ?? [];
    $person = $task->assignee;
    $initial = $person && trim((string) $person->name) !== ''
        ? mb_strtoupper(mb_substr(trim($person->name), 0, 1))
        : '';
    $info = __('app-development.board.task_info');
    $editId = 'ti-edit-'.$task->id;
    if ($ticket) {
        $info .= ' '.$ticket->ticket_number;
    }
@endphp

<div data-modal-title="{{ $info }}" data-modal-variant="view" class="ti task-modal-layout">
    <div class="ti-main task-modal-main">
        @can('update', $task)
            {{-- Edit in place: title and description here, assignee/priority/due in the side column (form="..."). --}}
            <form id="{{ $editId }}" method="post" action="{{ route('app-development.tasks.update', $task) }}" class="ti-edit" data-ti-edit>
                @csrf
                @method('PUT')
                <input type="hidden" name="status" value="{{ $task->status->value }}">
                <h2 class="ti-title" id="modalTaskTitle">
                    <input type="text" name="title" value="{{ old('title', $task->title) }}" required maxlength="255" class="ti-title-input" aria-label="{{ __('app-development.tasks.title_label') }}">
                </h2>
                <textarea name="description" rows="2" class="ti-desc-input task-description" placeholder="{{ __('app-development.tasks.description') }}" aria-label="{{ __('app-development.tasks.description') }}">{{ old('description', $task->description) }}</textarea>
                <div class="ti-edit-bar" data-ti-edit-bar hidden>
                    <button type="button" class="dev-btn" data-ti-edit-reset>{{ __('app.cancel') }}</button>
                    <button type="submit" class="dev-btn dev-btn--primary">{{ __('app-development.save') }}</button>
                </div>
            </form>
        @else
            <h2 class="ti-title" id="modalTaskTitle">{{ $task->title }}</h2>

            @if(filled($task->description))
                <p class="ti-desc task-description">{{ $task->description }}</p>
            @endif
        @endcan

        @if($ticket)
            <div class="ti-meta task-metadata">
                <a class="task-meta-pill ticket" href="{{ route('app-development.tickets.show', $ticket) }}" data-app-dev-modal>
                    <bdi dir="ltr">{{ $ticket->ticket_number }}</bdi>
                </a>
                @foreach($apps as $app)
                    <span class="task-meta-pill">{{ $app->label() }}</span>
                @endforeach
            </div>
        @endif

        @if(!empty($suggestCompleteTicket) && $ticket)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                {{ __('app-development.tasks.suggest_complete_ticket', ['ticket' => $ticket->ticket_number]) }}
            </div>
        @endif

        @if($ticket && ($ticket->comments->isNotEmpty() || auth()->user()?->can('comment', $ticket)))
            <section class="ti-block task-section">
                <h3>{{ __('app-development.tickets.comments') }}</h3>
                @foreach($ticket->comments as $comment)
                    <div class="task-comment">
                        <span class="mini-avatar">{{ mb_strtoupper(mb_substr(trim((string) ($comment->user?->name ?: '?')), 0, 1)) }}</span>
                        <div class="task-comment-body">
                    <p class="ti-note">
                        <b class="task-comment-name">{{ $comment->user?->name }}</b>
                        <bdi class="task-comment-date" dir="ltr">{{ $comment->created_at?->format('d/m H:i') }}</bdi>
                        @if(filled($comment->body))
                            <br>{{ $comment->body }}
                        @endif
                    </p>
                        </div>
                    </div>
                @endforeach
                @can('comment', $ticket)
                    <form method="post" action="{{ route('app-development.tickets.comments.store', $ticket) }}" class="task-comment-compose">
                        @csrf
                        <textarea name="body" rows="2" placeholder="{{ __('app-development.tickets.comment_placeholder') }}"></textarea>
                        <button type="submit" class="dev-btn dev-btn--primary">{{ __('app-development.tickets.post_comment') }}</button>
                    </form>
                @endcan
            </section>
        @endif

        @if($ticket && $ticket->attachments->isNotEmpty())
            <section class="ti-block task-section task-section--files">
                <h3>{{ __('app-development.tickets.attachments') }}</h3>
                @foreach($ticket->attachments as $attachment)
                    <a class="task-file" href="{{ route('app-development.tickets.attachments.download', [$ticket, $attachment]) }}">{{ $attachment->original_name }}</a>
                @endforeach
            </section>
        @endif

        @if($ticket && $ticket->activities->isNotEmpty())
            <section class="ti-block task-section task-section--activity">
                <h3>{{ __('app-development.modal.activity') }}</h3>
                @foreach($ticket->activities as $activity)
                    <p class="ti-note">
                        <bdi dir="ltr">{{ $activity->created_at?->format('d/m H:i') }}</bdi>
                        {{ $activity->message() }}
                    </p>
                @endforeach
            </section>
        @endif

        @if($task->timeEntries->isNotEmpty())
            <details class="kb-fold ti-block">
                <summary>{{ __('app-development.tasks.time_entries') }}</summary>
                <div class="mt-2 space-y-2">
                    @foreach($task->timeEntries as $entry)
                        <div class="text-sm text-[#2b1e11]">
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
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>

    <aside class="ti-side task-modal-aside">
        @can('update', $task)
            <label class="ti-field">
                <span class="task-detail-label">{{ __('app-development.tasks.assignee') }}</span>
                <select name="assignee_id" form="{{ $editId }}" data-ds-select>
                    <option value="">{{ __('app-development.tickets.unassigned') }}</option>
                    @foreach($developers as $developer)
                        <option value="{{ $developer->id }}" @selected((string) old('assignee_id', $task->assignee_id) === (string) $developer->id)>{{ $developer->name }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <div class="ti-person">
                @if($initial !== '')
                    <span class="tc-avatar" title="{{ $person->name }}">{{ $initial }}</span>
                    <span>{{ $person->name }}</span>
                @else
                    <span>{{ __('app-development.tickets.unassigned') }}</span>
                @endif
            </div>
        @endcan

        @can('update', $task)
            <label class="ti-field">
                <span class="task-detail-label">{{ __('app-development.tasks.status') }}</span>
                <select data-kb-status-select data-ds-select data-status-url="{{ route('app-development.tasks.status', $task) }}" data-current="{{ $task->status->value }}">
                    @foreach(\App\Enums\AppDevelopmentTaskStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($task->status === $status)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <p class="ti-field"><span>{{ __('app-development.tasks.status') }}</span><b>{{ $task->status->label() }}</b></p>
        @endcan

        @can('update', $task)
            <label class="ti-field">
                <span class="task-detail-label">{{ __('app-development.tickets.priority') }}</span>
                <select name="priority" form="{{ $editId }}" data-ds-select>
                    @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority', $task->priority->value) === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <p class="ti-field">
                <span class="task-detail-label">{{ __('app-development.tickets.priority') }}</span>
                <b class="task-priority">{{ $task->priority->label() }}</b>
            </p>
        @endcan

        @if($canTimer && ! $task->isCompleted())
            @if($activeEntry)
                <form method="post" action="{{ route('app-development.tasks.timer.pause', $task) }}" data-ajax-submit>
                    @csrf
                    <button type="submit" class="ti-start task-timer running">{{ __('app-development.tasks.pause_timer') }}</button>
                </form>
            @else
                <form method="post" action="{{ route('app-development.tasks.timer.start', $task) }}" data-ajax-submit>
                    @csrf
                    <button type="submit" class="ti-start task-timer">{{ __('app-development.tasks.start_timer') }}</button>
                </form>
            @endif
        @endif

        <p class="ti-time {{ $activeEntry ? 'app-dev-live-timer' : '' }}"
           @if($activeEntry)
               data-app-dev-timer
               data-started-at="{{ $activeEntry->started_at?->toIso8601String() }}"
               data-base-seconds="{{ $closedSeconds }}"
           @endif>
            <span class="task-elapsed">{{ __('app-development.tasks.total_time') }}
            <strong class="app-dev-live-timer__clock" data-timer-clock><bdi>{{ gmdate('H:i:s', $liveSeconds) }}</bdi></strong></span>
        </p>
    </aside>
</div>
