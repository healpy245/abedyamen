@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $initials = collect(preg_split('/\s+/', trim(auth()->user()->name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $appDevNotificationCount = $unread ?? 0;
    $appDevNotifications = $notifications ?? collect();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.hub_title') }} — {{ __('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ @filemtime(public_path('css/kaman.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/design-modern-light.css') }}?v={{ filemtime(public_path('css/design-modern-light.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @include('partials.workspace-ui')
</head>
<body class="hub ws-page">
    <x-workspace.shell door="overview">
        <main class="hub-main">
        <h1>{{ __('app.hub_title') }}</h1>
        <p class="hub-lead">{{ __('app.hub_subtitle') }}</p>

        <div class="hub-grid">
            <x-workspace.portal
                :href="route('workspace.agents')"
                index="01"
                :name="__('app.hub_agents')"
                :description="__('app.hub_agents_desc')"
            >
                <x-slot:mark><svg viewBox="0 0 24 24" fill="none"><path d="M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16.5 11a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3.5 19c.4-2.4 2.4-4 4.5-4s4.1 1.6 4.5 4M14 15.2c1.6-.4 3.3.3 4.3 1.8.5.8.8 1.6.9 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></x-slot:mark>
                @include('workspace.previews.agents')
            </x-workspace.portal>

            <x-workspace.portal
                :href="$supportHref"
                index="02"
                :name="__('app.hub_support')"
                :description="__('app.hub_support_desc')"
                :status="$supportStatus"
            >
                <x-slot:mark><svg viewBox="0 0 24 24" fill="none"><path d="M5 12a7 7 0 0 1 14 0v4a2 2 0 0 1-2 2h-2v-6h3M5 12v6h2a2 2 0 0 0 2-2v-4H5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></x-slot:mark>
                @include('workspace.previews.support')
            </x-workspace.portal>

            <x-workspace.portal
                :href="$devHref"
                index="03"
                :name="__('app.hub_dev')"
                :description="__('app.hub_dev_desc')"
                :status="$devStatus"
            >
                <x-slot:mark><svg viewBox="0 0 24 24" fill="none"><path d="M9 8 5 12l4 4M15 8l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></x-slot:mark>
                @include('workspace.previews.dev')
            </x-workspace.portal>

            <x-workspace.portal
                :href="route('workspace.finance.dashboard')"
                index="04"
                :name="__('app.hub_finance')"
                :description="__('app.hub_finance_desc')"
            >
                <x-slot:mark><svg viewBox="0 0 24 24" fill="none"><path d="M4 16.5 9 11l3 3 8-8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 6h5v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></x-slot:mark>
                @include('workspace.previews.finance')
            </x-workspace.portal>
        </div>
        <p class="hub-empty" data-hub-empty hidden>{{ __('app.today_clear') }}</p>
    </main>
    </x-workspace.shell>

    <script>
        (function () {
            var input = document.querySelector('[data-hub-search]');
            var cards = Array.prototype.slice.call(document.querySelectorAll('[data-hub-portal]'));
            var empty = document.querySelector('[data-hub-empty]');
            if (input) {
                input.addEventListener('input', function () {
                    var q = input.value.trim().toLowerCase();
                    var shown = 0;
                    cards.forEach(function (card) {
                        var hit = !q || (card.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1;
                        card.hidden = !hit;
                        if (hit) shown++;
                    });
                    if (empty) empty.hidden = shown !== 0;
                });
            }
            var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            cards.forEach(function (card) {
                card.addEventListener('click', function (event) {
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
                    if (reduce) return;
                    event.preventDefault();
                    card.classList.add('is-opening');
                    window.setTimeout(function () { window.location.href = card.href; }, 180);
                });
            });
        })();
    </script>
</body>
</html>
