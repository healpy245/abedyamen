@php
    $user = auth()->user();
    $waiting = $navWaitingForQa ?? 0;
    $returned = $navReturnedFromQa ?? 0;
    $ticketBadge = $waiting > 0 ? $waiting : ($returned > 0 ? $returned : null);
    $ticketsActive = request()->routeIs('app-development.index')
        || (request()->routeIs('app-development.tickets.*') && ! request()->routeIs('app-development.tickets.create'));
    $tasksActive = request()->routeIs('app-development.tasks.*');
    $releasesActive = request()->routeIs('app-development.releases.*');
    $teamActive = request()->routeIs('app-development.team.*');
    $reportsActive = request()->routeIs('app-development.reports.*');
@endphp

<div class="app-dev-sidebar-backdrop" data-app-dev-sidebar-backdrop hidden></div>

<aside class="app-dev-sidebar" data-app-dev-sidebar aria-label="{{ __('app-development.tag') }}">
    <div class="app-dev-sidebar__head">
        <p class="app-dev-sidebar__brand">{{ __('app-development.nav.menu') }}</p>
        <button type="button"
                class="app-dev-sidebar__collapse kaman-button-ghost kaman-button--sm"
                data-app-dev-sidebar-toggle
                aria-pressed="false"
                title="{{ __('app-development.nav.collapse') }}"
                aria-label="{{ __('app-development.nav.collapse') }}">
            @include('app-development.partials.icon', ['name' => 'sidebar'])
        </button>
        <button type="button"
                class="app-dev-sidebar__close kaman-button-ghost kaman-button--sm"
                data-app-dev-sidebar-close
                aria-label="{{ __('app-development.nav.close_menu') }}">
            @include('app-development.partials.icon', ['name' => 'x'])
        </button>
    </div>

    <nav class="app-dev-sidebar__nav">
        <a href="{{ route('app-development.index') }}"
           class="kaman-nav-link app-dev-sidebar__link"
           @if($ticketsActive) aria-current="page" @endif>
            @include('app-development.partials.icon', ['name' => 'ticket'])
            <span class="app-dev-sidebar__label">{{ __('app-development.nav.tickets') }}</span>
            @if($ticketBadge)
                <span class="app-dev-sidebar__badge">{{ $ticketBadge }}</span>
            @endif
        </a>

        <a href="{{ route('app-development.tasks.index') }}"
           class="kaman-nav-link app-dev-sidebar__link"
           @if($tasksActive) aria-current="page" @endif>
            @include('app-development.partials.icon', ['name' => 'tasks'])
            <span class="app-dev-sidebar__label">{{ __('app-development.nav.tasks') }}</span>
        </a>

        <a href="{{ route('app-development.releases.index') }}"
           class="kaman-nav-link app-dev-sidebar__link"
           @if($releasesActive) aria-current="page" @endif>
            @include('app-development.partials.icon', ['name' => 'apk'])
            <span class="app-dev-sidebar__label">{{ __('app-development.nav.releases') }}</span>
        </a>

        @if($user->isAppDevelopmentAdmin())
            <a href="{{ route('app-development.team.index') }}"
               class="kaman-nav-link app-dev-sidebar__link"
               @if($teamActive) aria-current="page" @endif>
                @include('app-development.partials.icon', ['name' => 'user'])
                <span class="app-dev-sidebar__label">{{ __('app-development.nav.team') }}</span>
            </a>

            <a href="{{ route('app-development.reports.index') }}"
               class="kaman-nav-link app-dev-sidebar__link"
               @if($reportsActive) aria-current="page" @endif>
                @include('app-development.partials.icon', ['name' => 'chart'])
                <span class="app-dev-sidebar__label">{{ __('app-development.nav.reports') }}</span>
            </a>
        @endif
    </nav>

    <div class="app-dev-sidebar__footer">
        @can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class)
            <a href="{{ route('app-development.tickets.create') }}" class="kaman-button kaman-button--sm app-dev-sidebar__cta" data-app-dev-modal>
                @include('app-development.partials.icon', ['name' => 'plus'])
                <span class="app-dev-sidebar__label">{{ __('app-development.nav.new_ticket') }}</span>
            </a>
        @endcan
        @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
            <a href="{{ route('app-development.releases.create') }}" class="kaman-button-ghost kaman-button--sm app-dev-sidebar__cta" data-app-dev-modal>
                @include('app-development.partials.icon', ['name' => 'upload'])
                <span class="app-dev-sidebar__label">{{ __('app-development.nav.upload_apk') }}</span>
            </a>
        @endcan
    </div>
</aside>
