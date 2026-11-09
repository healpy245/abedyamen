@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
    $lockUser = auth()->user();
    $tabs = array_filter($door->subDoors(), static fn (array $sub): bool => $sub['route'] !== null);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('access.locked_title', ['name' => $name]) }} — {{ __('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
</head>
<body class="ws-page ds">
    <x-workspace.shell :door="$door->value">
        @if($tabs !== [])
            <x-slot:nav>
                <nav class="ws-float" data-ws-float aria-label="{{ $door->label() }}">
                    <span class="ws-float__indicator" data-ws-indicator></span>
                    @foreach($tabs as $key => $tab)
                        @php $tabOpen = $lockUser->canOpenSubDoor($door, $key); @endphp
                        @if($tabOpen || ! ($door === \App\Enums\Door::Support && $lockUser->limitedSupportConsole()))
                            <a href="{{ $door->url($key) }}" @class(['is-locked' => ! $tabOpen, 'is-selected' => $subDoor === $key]) @if($subDoor === $key) aria-current="page" @endif>
                                @include('components.workspace.icons', ['name' => $tab['icon']])
                                {{ $tab['label'] }}
                                @unless($tabOpen)<x-workspace.lock-mark />@endunless
                            </a>
                        @endif
                    @endforeach
                </nav>
            </x-slot:nav>
        @endif
        <section class="door-lock" aria-labelledby="door-lock-title">
            <div class="door-lock__ghost" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
            <div class="door-lock__card">
                <div class="door-lock__mark" aria-hidden="true">
                    <span class="door-lock__ring"></span>
                    <span class="door-lock__ring"></span>
                    <span class="door-lock__halo"></span>
                    @include('workspace.access.partials.padlock')
                </div>
                @if($subDoor !== null)
                    <p class="door-lock__door">{{ $door->label() }}</p>
                @endif
                <h1 id="door-lock-title">{{ __('access.locked_title', ['name' => $name]) }}</h1>
                <p class="door-lock__body">{{ __('access.locked_body') }}</p>
                @if($wayOut !== null)
                    <a class="ds-btn ds-btn--primary door-lock__way" href="{{ $wayOut }}">{{ __('access.locked_way_out') }}</a>
                @endif
            </div>
        </section>
    </x-workspace.shell>
</body>
</html>
