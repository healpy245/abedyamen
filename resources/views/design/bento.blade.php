@extends('design.layout')

@php
    $concept = 'bento';
    $liveItem = collect($items)->first(fn ($item) => $item['label'] === __('app.today_live_label'));
    $feed = collect($items)->reject(fn ($item) => $item === $liveItem)->values();
    preg_match('/\d+/', (string) ($liveItem['title'] ?? ''), $liveMatch);
    $liveCount = (int) ($liveMatch[0] ?? 0);
@endphp

@section('title', 'Kaman Bento')
@section('body-class', 'concept-bento')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/design-bento.css') }}?v={{ filemtime(public_path('css/design-bento.css')) }}">
@endpush

@section('content')
    <main class="bento">
        <div class="bento-grid">
            <section class="tile tile-hello" @if($liveItem === null) style="grid-column: span 12" @endif>
                <a class="bento-brand" href="{{ route('design.modern-light') }}">
                    <img src="{{ asset('kaman.png') }}" alt="{{ __('app.name') }}">
                    {{ __('app.name') }}
                </a>
                <div>
                    <h1>{{ __('app.today_hello', ['name' => auth()->user()->name]) }}</h1>
                    <p>{{ __('app.today_subtitle') }}</p>
                </div>
            </section>

            @if($liveItem)
                <a class="tile tile-live" href="{{ $liveItem['href'] }}">
                    <span class="tile-kicker">{{ __('app.today_live_label') }}</span>
                    <strong>{{ $liveCount }}</strong>
                    <p class="detail">{{ __('app.today_live_detail') }}</p>
                </a>
            @endif

            @forelse($feed as $item)
                <a class="tile tile-item" href="{{ $item['href'] }}">
                    <span class="tile-kicker">{{ $item['label'] }}</span>
                    <h2>{{ $item['title'] }}</h2>
                    @if($item['detail'] !== '')
                        <p class="detail">{{ $item['detail'] }}</p>
                    @endif
                </a>
            @empty
                @if($feed->isEmpty() && $liveItem === null)
                    <p class="tile tile-empty">{{ __('app.today_clear') }}</p>
                @endif
            @endforelse

            @foreach($doors as $project)
                <a class="tile tile-door" href="{{ auth()->user()->projectEntryUrl($project) }}">
                    <span class="tile-kicker">{{ __('app.today_doors') }}</span>
                    <h2>{{ $project->label() }}</h2>
                    <p class="detail">{{ $project->description() }}</p>
                </a>
            @endforeach
        </div>
    </main>
@endsection
