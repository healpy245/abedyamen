{{--
    Base layout for every internal Kaman workspace page.

    Pages provide:
      @section('title')          browser title
      @section('tag')            topbar badge text (e.g. "WhatsApp Bot")
      @section('content')        page body
      @push('head') / @push('scripts')

    Set $activeProject (a Project enum value string) to light the nav tab.
--}}
@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $boardWorkspace = ($activeProject ?? null) === \App\Enums\Project::AppDevelopment->value;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="kaman-booting">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('app.workspace'))</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>

    {{-- Loaded after Tailwind so component classes win over utilities. --}}
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ filemtime(public_path('css/kaman.css')) }}">
    @if($boardWorkspace)
        <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    @endif

    @stack('head')
    @if($boardWorkspace)
        <link rel="stylesheet" href="{{ asset('css/app-development-door.css') }}?v={{ filemtime(public_path('css/app-development-door.css')) }}">
    @endif
    @if(auth()->check())
        <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @endif
</head>
<body class="antialiased min-h-screen flex flex-col {{ auth()->check() ? 'kaman-signed-in' : '' }}{{ $boardWorkspace ? ' ws-page ws-dev' : '' }}">
@if($boardWorkspace && auth()->check())
    @include('partials.boot-splash')
    <x-workspace.shell door="development">
        <x-slot:tools>
            @include('app-development.partials.bell')
        </x-slot:tools>
        <x-slot:nav>
            @include('app-development.partials.nav')
        </x-slot:nav>
        @yield('content')
    </x-workspace.shell>
    @stack('scripts')
    @include('partials.csrf-keepalive')
</body>
</html>
@php return; @endphp
@endif
@include('partials.boot-splash')

@php
    $boardWorkspace = ($activeProject ?? null) === \App\Enums\Project::AppDevelopment->value;
@endphp
@if(auth()->check() && ! $boardWorkspace)
<div class="kaman-shell{{ $boardWorkspace ? ' kaman-shell--board' : '' }}">
    @unless(auth()->user()->isMowahada() || $boardWorkspace)
        @include('partials.sidebar', ['activeProject' => $activeProject ?? null])
    @endunless
    <div class="kaman-shell__main">
@endif

@include('partials.topbar', [
    'tagText' => trim($__env->yieldContent('tag', __('app.partner_portal'))),
    'activeProject' => $activeProject ?? null,
])

<main class="relative flex min-h-0 flex-1 flex-col">
    <div class="stamp-band pointer-events-none absolute inset-x-0 bottom-0 h-32 opacity-70"></div>
    <div class="relative flex min-h-0 flex-1 flex-col">
        @yield('content')
    </div>
</main>

@if(auth()->check())
    </div>
</div>
@endif

@stack('scripts')
@include('partials.csrf-keepalive')
</body>
</html>
