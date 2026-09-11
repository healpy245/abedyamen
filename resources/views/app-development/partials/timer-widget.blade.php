@php
    $activeTimer = $activeAppDevTimer ?? null;
    if ($activeTimer === null && auth()->check()) {
        $activeTimer = \App\Models\AppDevelopment\AppDevelopmentTimeEntry::query()
            ->with(['task:id,title,ticket_id', 'task.ticket:id,ticket_number'])
            ->where('user_id', auth()->id())
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }
@endphp
@if($activeTimer)
    <div class="app-dev-timer-widget"
         data-app-dev-timer
         data-started-at="{{ $activeTimer->started_at?->toIso8601String() }}"
         data-base-seconds="0"
         data-task-url="{{ route('app-development.tasks.show', $activeTimer->task) }}">
        <span class="app-dev-timer-widget__dot" aria-hidden="true"></span>
        <span class="app-dev-timer-widget__label">{{ __('app-development.tasks.running') }}</span>
        <a href="{{ route('app-development.tickets.show', ['ticket' => $activeTimer->task?->ticket ?? $activeTimer->task?->ticket_id, 'task' => $activeTimer->task_id]) }}"
           data-app-dev-modal
           class="app-dev-timer-widget__link">
            {{ $activeTimer->task?->ticket?->ticket_number ?? $activeTimer->task?->title }}
        </a>
        <strong class="app-dev-timer-widget__clock" data-timer-clock>{{ gmdate('H:i:s', $activeTimer->elapsedSeconds()) }}</strong>
        <form method="post" action="{{ route('app-development.tasks.timer.pause', $activeTimer->task) }}">
            @csrf
            <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tasks.pause') }}</button>
        </form>
    </div>
@endif
