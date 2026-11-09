@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $page = $page ?? 'dashboard';
    $tabs = [
        'dashboard' => route('workspace.finance.dashboard'),
        'clients' => route('workspace.finance.clients'),
        'deals' => route('workspace.finance.deals'),
        'storage' => route('workspace.finance.storage'),
    ];
    $financeOpen = fn (string $key): bool => auth()->user()->canOpenSubDoor(\App\Enums\Door::Finance, $key);
    $pendingDeals = $financeOpen('deals') ? \App\Models\Sales\SalesQuote::query()->where('status', \App\Enums\Sales\QuoteStatus::Pending)->count() : 0;
    $dealNotices = $financeOpen('deals') ? auth()->user()->unreadNotifications()->where('type', \App\Notifications\SalesDealRequested::class)->limit(8)->get() : collect();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting" data-door="finance">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('finance.nav.'.$page) }} — {{ __('finance.title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/boot-splash.css') }}?v={{ filemtime(public_path('css/boot-splash.css')) }}">
    @stack('styles')
</head>
<body class="fin ws-page ds">
@include('partials.boot-splash')
    <x-workspace.shell door="finance">
        <x-slot:tools>
            <div class="fin-bell" data-fin-bell>
                <button type="button" class="ws-icon fin-bell__btn" data-fin-bell-toggle aria-expanded="false" aria-label="{{ __('finance.deals.notifications') }}">
                    @include('components.workspace.icons', ['name' => 'bell'])
                    @if($dealNotices->isNotEmpty())<span class="fin-bell__count">{{ $dealNotices->count() }}</span>@endif
                </button>
                <div class="fin-bell__panel" data-fin-bell-panel hidden>
                    <strong class="fin-bell__title">{{ __('finance.deals.notifications') }}</strong>
                    @forelse($dealNotices as $notice)
                        <a class="fin-bell__item" href="{{ route('workspace.finance.notifications.open', $notice) }}">
                            <b>{{ __('finance.deals.notice', ['agent' => $notice->data['actor'] ?? '—', 'client' => $notice->data['client'] ?? '']) }}</b>
                            <small>{{ $notice->created_at?->diffForHumans() }}</small>
                        </a>
                    @empty
                        <p class="fin-bell__empty">{{ __('finance.deals.no_notices') }}</p>
                    @endforelse
                </div>
            </div>
        </x-slot:tools>
        <x-slot:nav>
            <nav class="ws-float" data-ws-float aria-label="{{ __('finance.title') }}">
                <span class="ws-float__indicator" data-ws-indicator></span>
                @foreach($tabs as $key => $href)
                    <a href="{{ $href }}" @class(['is-selected' => $page === $key, 'is-locked' => ! $financeOpen($key)]) @if($page === $key) aria-current="page" @endif>
                        @include('components.workspace.icons', ['name' => ['dashboard' => 'chart', 'clients' => 'users', 'deals' => 'receipt', 'storage' => 'box'][$key] ?? 'grid'])
                        {{ __('finance.nav.'.$key) }}
                        @unless($financeOpen($key))<x-workspace.lock-mark />@endunless
                        @if($key === 'deals' && $pendingDeals > 0)<span class="fin-nav-count">{{ $pendingDeals }}</span>@endif
                    </a>
                @endforeach
            </nav>
        </x-slot:nav>
        <div class="fin-page">
            @yield('content')
        </div>
    </x-workspace.shell>

    @if(session('success') || session('error') || $errors->any())
        <div class="fin-toast {{ session('error') || $errors->any() ? 'is-bad' : 'is-ok' }}" role="status" data-fin-toast>
            <span>{{ session('error') ?: ($errors->any() ? $errors->first() : session('success')) }}</span>
            <button type="button" data-fin-toast-close aria-label="{{ __('finance.modal.close') }}">×</button>
        </div>
    @endif

    <dialog class="ds-dialog ds-confirm" data-fin-confirm-dialog aria-labelledby="ds-confirm-text">
        <form method="dialog" class="ds-confirm__card">
            <div class="ds-confirm__body">
                <span class="ds-confirm__icon" aria-hidden="true" data-fin-confirm-icon><x-ds.icon name="trash" /></span>
                <p id="ds-confirm-text" data-fin-confirm-text></p>
            </div>
            <footer class="ds-dialog__foot">
                <button value="cancel" class="ds-btn ds-btn--secondary">{{ __('finance.modal.cancel') }}</button>
                <button value="ok" class="ds-btn ds-btn--danger-solid" data-fin-confirm-ok data-default="{{ __('finance.modal.delete') }}">{{ __('finance.modal.delete') }}</button>
            </footer>
        </form>
    </dialog>

    @stack('dialogs')
    <script src="{{ asset('js/ds.js') }}?v={{ filemtime(public_path('js/ds.js')) }}" defer></script>
    <script src="{{ asset('js/finance-workspace.js') }}?v={{ filemtime(public_path('js/finance-workspace.js')) }}" defer></script>
    @stack('scripts')
</body>
</html>
