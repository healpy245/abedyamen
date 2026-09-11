@php
    $appDevNotificationCount = $appDevNotificationCount ?? 0;
    $appDevNotifications = $appDevNotifications ?? collect();
@endphp

<div class="relative" id="app-dev-bell">
    <button type="button"
            class="relative inline-flex h-8 w-8 items-center justify-center rounded-[8px] border border-[#f1dfc5] bg-white text-[#7c6a56] transition hover:border-[#f47a2e]/40 hover:text-[#f16229]"
            data-bell-toggle
            aria-expanded="false"
            aria-label="{{ __('app-development.notifications.title') }}">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 2a6 6 0 00-6 6v1.586l-.707.707A1 1 0 004 12h12a1 1 0 00.707-1.707L16 9.586V8a6 6 0 00-6-6zM8 14a2 2 0 104 0H8z"/>
        </svg>
        @if($appDevNotificationCount > 0)
            <span class="absolute -top-1 -end-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-[#f16229] px-1 text-[10px] font-bold leading-4 text-white">
                {{ $appDevNotificationCount > 9 ? '9+' : $appDevNotificationCount }}
            </span>
        @endif
    </button>

    <div class="app-dev-bell-panel absolute end-0 z-40 mt-2 hidden w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-[#eadfce] bg-[#fffaf3] shadow-xl"
         data-bell-panel
         hidden
         role="menu">
        <div class="flex items-center justify-between gap-2 border-b border-[#eadfce] px-3 py-2">
            <p class="text-xs font-semibold text-[#2b1e11]">{{ __('app-development.notifications.title') }}</p>
            @if($appDevNotificationCount > 0)
                <form method="post" action="{{ route('app-development.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-[11px] font-semibold text-[#f16229] hover:underline">{{ __('app-development.notifications.mark_all') }}</button>
                </form>
            @endif
        </div>
        <div class="max-h-72 overflow-y-auto kaman-scroll">
            @forelse($appDevNotifications as $item)
                @php
                    $data = $item->data;
                    $event = $data['event'] ?? 'updated';
                    $ticket = $data['ticket_number'] ?? '';
                    $actor = $data['actor'] ?? __('app-development.system');
                    $message = match ($event) {
                        'apk_uploaded' => __('app-development.notifications.apk_uploaded', [
                            'actor' => $actor,
                            'version' => $data['version_name'] ?? '',
                        ]),
                        'task_assigned' => __('app-development.notifications.task_assigned', [
                            'actor' => $actor,
                            'title' => $data['task_title'] ?? $data['title'] ?? '',
                        ]),
                        default => __('app-development.notifications.'.$event, [
                            'actor' => $actor,
                            'ticket' => $ticket,
                        ]),
                    };                @endphp
                <a href="{{ route('app-development.notifications.open', $item) }}"
                   class="block border-b border-[#f1dfc5]/70 px-3 py-2.5 text-sm last:border-b-0 {{ $item->unread() ? 'bg-[#fff3e8]' : 'bg-transparent' }}">
                    <p class="font-medium text-[#2b1e11]">{{ $message }}</p>
                    @if(! empty($data['excerpt']))
                        <p class="mt-0.5 line-clamp-2 text-xs text-[#7c6a56]">{{ $data['excerpt'] }}</p>
                    @endif
                    <p class="mt-0.5 text-[11px] text-[#a78a6c]">{{ $item->created_at?->diffForHumans() }}</p>
                </a>
            @empty
                <p class="px-3 py-6 text-center text-xs text-[#a78a6c]">{{ __('app-development.notifications.empty') }}</p>
            @endforelse
        </div>
    </div>
</div>

@once('app-dev-bell-script')
<script>
(function () {
    const root = document.getElementById('app-dev-bell');
    if (!root) return;
    const toggle = root.querySelector('[data-bell-toggle]');
    const panel = root.querySelector('[data-bell-panel]');
    if (!toggle || !panel) return;

    function setOpen(open) {
        panel.classList.toggle('hidden', !open);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        setOpen(panel.hidden);
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
})();
</script>
@endonce
