@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $page = $page ?? 'sales';
    $agentsOpen = fn (string $key): bool => auth()->user()->canOpenSubDoor(\App\Enums\Door::Agents, $key);
    $tabs = [
        'leads' => [route('workspace.agents', ['page' => 'leads']), 'target'],
        'calendar' => [route('workspace.agents', ['page' => 'calendar']), 'calendar'],
        'sales' => [route('workspace.agents.sales'), 'receipt'],
        'packages' => [route('workspace.agents.packages'), 'box'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting" data-door="agents" data-pdf-failed="{{ __('sales.errors.pdf_failed') }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('app.agents_'.$page) }} — {{ __('app.agents_title') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/agents-sales.css') }}?v={{ filemtime(public_path('css/agents-sales.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/boot-splash.css') }}?v={{ filemtime(public_path('css/boot-splash.css')) }}">
</head>
<body class="fin ws-page ds">
@include('partials.boot-splash')
    <x-workspace.shell door="agents">
        <x-slot:nav>
            <nav class="ws-float" data-ws-float aria-label="{{ __('app.agents_title') }}">
                <span class="ws-float__indicator" data-ws-indicator></span>
                @foreach($tabs as $key => [$href, $icon])
                    <a href="{{ $href }}" @class(['is-selected' => $page === $key, 'is-locked' => ! $agentsOpen($key)]) @if($page === $key) aria-current="page" @endif>
                        @include('components.workspace.icons', ['name' => $icon])
                        {{ __('app.agents_'.$key) }}
                        @unless($agentsOpen($key))<x-workspace.lock-mark />@endunless
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
            <button type="button" data-fin-toast-close aria-label="{{ __('sales.close') }}">×</button>
        </div>
    @endif

    <dialog class="ds-dialog ds-confirm" data-fin-confirm-dialog aria-labelledby="ds-confirm-text">
        <form method="dialog" class="ds-confirm__card">
            <div class="ds-confirm__body">
                <span class="ds-confirm__icon" aria-hidden="true" data-fin-confirm-icon><x-ds.icon name="trash" /></span>
                <p id="ds-confirm-text" data-fin-confirm-text></p>
            </div>
            <footer class="ds-dialog__foot">
                <button value="cancel" class="ds-btn ds-btn--secondary">{{ __('sales.cancel') }}</button>
                <button value="ok" class="ds-btn ds-btn--danger-solid" data-fin-confirm-ok data-default="{{ __('sales.delete') }}">{{ __('sales.delete') }}</button>
            </footer>
        </form>
    </dialog>

    @stack('dialogs')
    <script src="{{ asset('js/ds.js') }}?v={{ filemtime(public_path('js/ds.js')) }}" defer></script>
    <script src="{{ asset('js/agents-sales.js') }}?v={{ filemtime(public_path('js/agents-sales.js')) }}" defer></script>
</body>
</html>
