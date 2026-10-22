@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $nav = $nav ?? 'live';
    $limited = (bool) auth()->user()?->limitedSupportConsole();
    $openCount = $limited
        ? 0
        : \App\Models\KamanSupport\SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count();
    $bellTickets = $limited
        ? collect()
        : \App\Models\KamanSupport\SupportTicket::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->latest()
            ->limit(8)
            ->get(['public_id', 'summary', 'restaurant', 'restaurant_name', 'created_at']);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('kaman-support.tag'))</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ filemtime(public_path('css/kaman.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/support-workspace.css') }}?v={{ filemtime(public_path('css/support-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @stack('head')
</head>
<body class="sup-page ws-page">
    <x-workspace.shell door="support" content-class="sup-main">
        <x-slot:tools>
            @unless($limited)
                <details class="ws-bell">
                    <summary class="ws-icon" aria-label="{{ __('kaman-support.nav_tickets') }}">
                        @include('components.workspace.icons', ['name' => 'bell'])
                        @if($openCount > 0)
                            <span>{{ $openCount > 99 ? '99+' : $openCount }}</span>
                        @endif
                    </summary>
                    <div class="ws-bell__panel">
                        <strong>{{ __('kaman-support.nav_tickets') }}</strong>
                        @forelse($bellTickets as $bellTicket)
                            <a href="{{ route('kaman-support.show', $bellTicket) }}">
                                <b>{{ $bellTicket->restaurant_name ?: $bellTicket->restaurant }}</b>
                                <span>{{ $bellTicket->summary }}</span>
                            </a>
                        @empty
                            <p>{{ __('kaman-support.empty') }}</p>
                        @endforelse
                    </div>
                </details>
            @endunless
        </x-slot:tools>
        <x-slot:nav>
            <x-workspace.support-nav :nav="$nav" :limited="$limited" />
        </x-slot:nav>
        @if(session('status'))
            <div class="sup-toast" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </x-workspace.shell>

    @stack('scripts')
    @include('partials.csrf-keepalive')
</body>
</html>
