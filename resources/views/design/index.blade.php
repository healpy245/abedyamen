@extends('design.layout')

@section('title', 'Kaman design previews')
@section('body-class', 'concept-pro')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/design-pro.css') }}?v={{ filemtime(public_path('css/design-pro.css')) }}">
@endpush

@section('content')
    <main class="pro">
        <a class="pro-brand" href="{{ route('design.modern-light') }}">
            <img src="{{ asset('kaman.png') }}" alt="{{ __('app.name') }}">
            {{ __('app.name') }}
        </a>
        <h1 class="pro-hello">Three previews</h1>
        <p class="pro-lead">The current Today page is unchanged. These are separate screens that use the same doors and the same waiting list.</p>
        <div class="pro-doors">
            <a href="{{ route('design.show', 'command') }}">
                <strong>Command</strong>
                <p>Dark operational desk. Compact counts, a feed, and a sidebar.</p>
            </a>
            <a href="{{ route('design.show', 'pro') }}">
                <strong>Pro</strong>
                <p>Quiet light page. Large type, a thin list, almost no chrome.</p>
            </a>
            <a href="{{ route('design.show', 'bento') }}">
                <strong>Bento</strong>
                <p>A grid of tiles. Each door and each waiting item is its own block.</p>
            </a>
        </div>
    </main>
@endsection