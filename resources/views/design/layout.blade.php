@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $concept = $concept ?? null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Kaman')</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&family=Heebo:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ filemtime(public_path('css/kaman.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/kaman-tokens.css') }}?v={{ filemtime(public_path('css/kaman-tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/design-preview.css') }}?v={{ filemtime(public_path('css/design-preview.css')) }}">
    @stack('head')
</head>
<body class="@yield('body-class')">
    @yield('content')

    <nav class="design-dock" aria-label="Design previews">
        <a href="{{ route('design.modern-light') }}">Overview</a>
        @foreach(\App\Http\Controllers\DesignConceptController::CONCEPTS as $name)
            <a href="{{ route('design.show', $name) }}" @if($concept === $name) aria-current="page" @endif>{{ ucfirst($name) }}</a>
        @endforeach
    </nav>
</body>
</html>
