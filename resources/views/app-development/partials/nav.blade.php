@php
    $user = auth()->user();
    $waiting = $navWaitingForQa ?? 0;
    $returned = $navReturnedFromQa ?? 0;

    $items = [
        [
            'label' => __('app-development.nav.dashboard'),
            'route' => 'app-development.index',
            'active' => request()->routeIs('app-development.index'),
            'show' => true,
            'badge' => null,
        ],
        [
            'label' => __('app-development.nav.tickets'),
            'route' => 'app-development.tickets.index',
            'active' => request()->routeIs('app-development.tickets.*') && ! request()->routeIs('app-development.tickets.create'),
            'show' => true,
            'badge' => null,
        ],
    ];

    if ($user?->isQa()) {
        $items[] = [
            'label' => __('app-development.nav.qa_queue'),
            'route' => 'app-development.qa.index',
            'active' => request()->routeIs('app-development.qa.*') || request()->query('tab') === 'waiting-qa',
            'show' => true,
            'badge' => $waiting > 0 ? $waiting : null,
        ];
    }

    if ($user?->isDeveloper()) {
        $items[] = [
            'label' => __('app-development.nav.my_tickets'),
            'route' => 'app-development.tickets.index',
            'params' => ['tab' => 'mine'],
            'active' => request()->routeIs('app-development.tickets.index') && request()->query('tab') === 'mine',
            'show' => true,
            'badge' => $returned > 0 ? $returned : null,
        ];
    }

    $items[] = [
        'label' => __('app-development.nav.releases'),
        'route' => 'app-development.releases.index',
        'active' => request()->routeIs('app-development.releases.*'),
        'show' => true,
        'badge' => null,
    ];
@endphp

<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <nav class="flex flex-wrap items-center gap-1" aria-label="{{ __('app-development.tag') }}">
        @foreach($items as $item)
            @continue(! ($item['show'] ?? true))
            <a href="{{ route($item['route'], $item['params'] ?? []) }}"
               class="kaman-nav-link inline-flex items-center gap-1.5"
               @if($item['active']) aria-current="page" @endif>
                {{ $item['label'] }}
                @if(! empty($item['badge']))
                    <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-[#f47a2e] px-1.5 text-[10px] font-bold leading-5 text-white">{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="flex flex-wrap items-center gap-2">
        @can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class)
            <a href="{{ route('app-development.tickets.create') }}" class="kaman-button kaman-button--sm">
                {{ __('app-development.nav.new_ticket') }}
            </a>
        @endcan
        @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
            <a href="{{ route('app-development.releases.create') }}" class="kaman-button-ghost kaman-button--sm">
                {{ __('app-development.nav.upload_apk') }}
            </a>
        @endcan
    </div>
</div>
