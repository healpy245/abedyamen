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
@endphp
        <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('app.workspace'))</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Heebo:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Loaded after Tailwind so component classes win over utilities. --}}
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}">

    @stack('head')
</head>
<body class="antialiased min-h-screen flex flex-col">

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

@stack('scripts')
</body>
</html>
