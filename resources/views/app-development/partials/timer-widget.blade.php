@php
    $activeTimer = null;
    if (auth()->check()) {
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
         data-task-url="{{ route('app-development.tasks.show', $activeTimer->task) }}">
        <span class="app-dev-timer-widget__dot" aria-hidden="true"></span>
        <a href="{{ route('app-development.tasks.show', $activeTimer->task) }}" data-app-dev-modal class="app-dev-timer-widget__link">
            {{ $activeTimer->task?->ticket?->ticket_number }} · {{ $activeTimer->task?->title }}
        </a>
        <strong class="app-dev-timer-widget__clock" data-timer-clock>00:00:00</strong>
        <form method="post" action="{{ route('app-development.tasks.timer.pause', $activeTimer->task) }}">
            @csrf
            <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tasks.pause') }}</button>
        </form>
    </div>
    <script src="{{ asset('js/app-development-timer.js') }}?v={{ @filemtime(public_path('js/app-development-timer.js')) ?: time() }}" defer></script>
@endif
