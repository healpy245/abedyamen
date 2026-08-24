@php
    $activeProject = \App\Enums\Project::AppDevelopment->value;
@endphp

@extends('layouts.kaman')

@section('tag', __('app-development.tag'))

@section('content')
    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-4">
            @include('app-development.partials.nav')
            @include('app-development.partials.flash')
            @yield('app')
        </div>
    </div>
@endsection
