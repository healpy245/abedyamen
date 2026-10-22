@php
    $activeProject = \App\Enums\Project::AppDevelopment->value;
@endphp

@extends('layouts.kaman')

@section('tag', __('app-development.board.title'))

@section('content')
    <div class="app-dev-workspace">
        <div class="app-dev-workspace__body">
            @unless($hidePageFlash ?? false)
                @include('app-development.partials.flash')
            @endunless
            @yield('app')
        </div>
    </div>
    @if(request()->routeIs('app-development.tasks.*'))
        @include('app-development.partials.timer-widget')
    @endif
    @include('app-development.partials.modal')
@endsection

@push('head')
    <link rel="stylesheet" href="{{ asset('css/app-development-board.css') }}?v={{ @filemtime(public_path('css/app-development-board.css')) ?: time() }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/app-development-timer.js') }}?v={{ @filemtime(public_path('js/app-development-timer.js')) ?: time() }}" defer></script>
@endpush
