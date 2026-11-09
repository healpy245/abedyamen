@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.theme-boot')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('access.title') }} — {{ __('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=DM+Sans:wght@400;500;600;700&family=Heebo:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-frame.css') }}?v={{ @filemtime(public_path('css/workspace-frame.css')) }}">
    @include('partials.workspace-ui')
    <link rel="stylesheet" href="{{ asset('css/ds.css') }}?v={{ filemtime(public_path('css/ds.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/users-access.css') }}?v={{ filemtime(public_path('css/users-access.css')) }}">
</head>
<body class="ws-page ds">
    <x-workspace.shell door="users">
        <main class="ua" data-ua data-saved="{{ __('access.saved') }}" data-failed="{{ __('access.save_failed') }}" data-turned-off="{{ __('access.turned_off') }}">
            <header class="ua-head">
                <div>
                    <h1>{{ __('access.title') }}</h1>
                    <p>{{ __('access.lead') }}</p>
                </div>
                <span class="ua-count">{{ __('access.user_count', ['count' => count($rows)]) }}</span>
            </header>

            <div class="ua-list">
                @foreach($rows as $row)
                    <article @class(['ua-user', 'is-turned-off' => $row['turnedOff']]) data-ua-user data-url="{{ $row['url'] }}" data-account-url="{{ $row['accountUrl'] }}" data-search="{{ mb_strtolower($row['name'].' '.$row['email']) }}">
                        <button type="button" class="ua-user__head" data-ua-expand aria-expanded="false" aria-controls="ua-panel-{{ $row['id'] }}" aria-label="{{ __('access.open_details', ['name' => $row['name']]) }}">
                            <span class="ws-avatar" aria-hidden="true">{{ $row['initials'] !== '' ? $row['initials'] : 'K' }}</span>
                            <span class="ua-user__id">
                                <strong><bdi>{{ $row['name'] }}</bdi></strong>
                                <small dir="ltr">{{ $row['email'] }}</small>
                            </span>
                            <span class="ua-role" data-ua-role>{{ $row['turnedOff'] ? __('access.turned_off') : $row['role'] }}</span>
                            <span class="ua-glance">
                                @foreach($row['doors'] as $key => $door)
                                    @php
                                        $total = count($door['subs']);
                                        $state = ! $door['on'] ? 'is-off' : ($total > 0 && $door['openCount'] < $total ? 'is-part' : 'is-on');
                                        $openNames = collect($door['subs'])->filter(fn ($sub) => $sub['open'])->pluck('label')->implode('، ');
                                    @endphp
                                    <span class="ua-chip {{ $state }}" data-ua-chip="{{ $key }}" title="{{ $door['label'] }}{{ $openNames !== '' ? ': '.$openNames : '' }}">
                                        @include('components.workspace.icons', ['name' => $door['icon']])
                                        <span>{{ $door['label'] }}</span>
                                        @if($total > 0)
                                            <b data-ua-chip-count>{{ $door['on'] ? $door['openCount'] : 0 }}/{{ $total }}</b>
                                        @endif
                                    </span>
                                @endforeach
                            </span>
                            <svg class="ua-user__chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="ua-user__panel" id="ua-panel-{{ $row['id'] }}" data-ua-panel>
                            <div class="ua-user__inner">
                                @if($row['accountUrl'] !== null)
                                    <label class="ds-switch ua-account">
                                        <span class="ua-account__name">
                                            <strong>{{ __('access.account_on') }}</strong>
                                            <small>{{ __('access.account_on_hint') }}</small>
                                        </span>
                                        <input type="checkbox" role="switch" data-ua-toggle data-ua-account @checked(! $row['turnedOff'])>
                                        <span class="ds-switch__track"></span>
                                    </label>
                                @endif
                                @if($row['note'] !== null)
                                    <p class="ua-note">{{ $row['note'] }}</p>
                                @endif
                                <div class="ua-doors">
                                    @foreach($row['doors'] as $key => $door)
                                        <section @class(['ua-door', 'is-open' => $door['on']]) data-ua-door="{{ $key }}">
                                            <label @class(['ds-switch', 'ua-door__head', 'is-fixed' => $door['fixed']]) @if($door['fixed']) title="{{ __('access.fixed') }}" @endif>
                                                <span class="ua-door__name">
                                                    @include('components.workspace.icons', ['name' => $door['icon']])
                                                    <strong>{{ $door['label'] }}</strong>
                                                </span>
                                                <input type="checkbox" role="switch" data-ua-toggle data-door="{{ $key }}" @checked($door['on']) @disabled($door['fixed'])>
                                                <span class="ds-switch__track"></span>
                                            </label>
                                            @if($door['subs'] !== [])
                                                <div class="ua-subs">
                                                    <div class="ua-subs__inner">
                                                        @foreach($door['subs'] as $subKey => $sub)
                                                            <label @class(['ds-switch', 'ua-sub', 'is-fixed' => $sub['fixed']]) data-ua-sub="{{ $subKey }}" @if($sub['fixed']) title="{{ __('access.fixed') }}" @endif>
                                                                <span class="ua-sub__name">
                                                                    @include('components.workspace.icons', ['name' => $sub['icon']])
                                                                    <span>{{ $sub['label'] }}</span>
                                                                </span>
                                                                <input type="checkbox" role="switch" data-ua-toggle data-door="{{ $key }}" data-sub="{{ $subKey }}" @checked($sub['on']) @disabled($sub['fixed'] || ! $door['on'])>
                                                                <span class="ds-switch__track"></span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </section>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
                <p class="ua-empty" data-ua-empty hidden>{{ __('access.empty') }}</p>
            </div>
        </main>
    </x-workspace.shell>
    <div class="ua-toast" data-ua-toast role="status" aria-live="polite" hidden></div>
    <script src="{{ asset('js/users-access.js') }}?v={{ filemtime(public_path('js/users-access.js')) }}" defer></script>
</body>
</html>
