@php
    $activeProject = \App\Enums\Project::AppDevelopment->value;
@endphp

@extends('layouts.kaman')

@section('tag', __('app-development.tag'))

@section('content')
    <div class="page-container page-container--tight page-container--app-dev">
        <div class="app-dev-shell">
            @include('app-development.partials.nav')
            <div class="app-dev-main">
                <div class="app-dev-main__strip">
                    <button type="button"
                            class="app-dev-sidebar-open kaman-button-ghost kaman-button--sm"
                            data-app-dev-sidebar-open
                            aria-label="{{ __('app-development.nav.open_menu') }}">
                        @include('app-development.partials.icon', ['name' => 'menu'])
                    </button>
                    <div class="app-dev-main__actions">
                        @can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class)
                            <a href="{{ route('app-development.tickets.create') }}" class="kaman-button kaman-button--sm" data-app-dev-modal>
                                @include('app-development.partials.icon', ['name' => 'plus'])
                                <span>{{ __('app-development.nav.new_ticket') }}</span>
                            </a>
                        @endcan
                        @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
                            <a href="{{ route('app-development.releases.create') }}" class="kaman-button-ghost kaman-button--sm" data-app-dev-modal>
                                @include('app-development.partials.icon', ['name' => 'upload'])
                                <span>{{ __('app-development.nav.upload_apk') }}</span>
                            </a>
                        @endcan
                    </div>
                </div>
                @unless($hidePageFlash ?? false)
                    @include('app-development.partials.flash')
                @endunless
                @yield('app')
            </div>
        </div>
    </div>
    @include('app-development.partials.timer-widget')
    @include('app-development.partials.modal')
@endsection

@push('scripts')
    <script src="{{ asset('js/app-development-sidebar.js') }}?v={{ @filemtime(public_path('js/app-development-sidebar.js')) ?: time() }}" defer></script>
    <script src="{{ asset('js/app-development-timer.js') }}?v={{ @filemtime(public_path('js/app-development-timer.js')) ?: time() }}" defer></script>
@endpush
