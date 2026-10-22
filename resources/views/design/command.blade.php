@extends('design.layout')

@php
    $concept = 'command';
    $liveItem = collect($items)->first(fn ($item) => $item['label'] === __('app.today_live_label'));
    $feed = collect($items)->reject(fn ($item) => $item === $liveItem)->values();
    preg_match('/\d+/', (string) ($liveItem['title'] ?? ''), $liveMatch);
    $liveCount = (int) ($liveMatch[0] ?? 0);
@endphp

@section('title', 'Kaman Command')
@section('body-class', 'concept-command')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/design-command.css') }}?v={{ filemtime(public_path('css/design-command.css')) }}">
@endpush

@section('content')
    <div class="cmd">
        <aside class="cmd-side">
            <a class="cmd-brand" href="{{ route('design.modern-light') }}">
                <img src="{{ asset('kaman.png') }}" alt="{{ __('app.name') }}">
                <span>
                    <strong>{{ __('app.name') }}</strong>
                    <span>Workspace</span>
                </span>
            </a>
            <nav class="cmd-nav" aria-label="{{ __('app.today_doors') }}">
                @foreach($doors as $project)
                    <a href="{{ auth()->user()->projectEntryUrl($project) }}">{{ $project->label() }}</a>
                @endforeach
            </nav>
            <p class="cmd-side-foot">{{ auth()->user()->name }}</p>
        </aside>

        <main class="cmd-main">
            <header class="cmd-top">
                <div>
                    <p class="cmd-kicker">{{ __('app.today_tag') }}</p>
                    <h1>{{ __('app.today_hello', ['name' => auth()->user()->name]) }}</h1>
                    <p>{{ __('app.today_subtitle') }}</p>
                </div>
            </header>

            <section class="cmd-metrics" aria-label="{{ __('app.today_waiting') }}">
                <article class="cmd-metric">
                    <span>{{ __('app.today_live_label') }}</span>
                    <strong>{{ $liveCount }}</strong>
                    <em>{{ __('app.today_live_detail') }}</em>
                </article>
                <article class="cmd-metric">
                    <span>{{ __('app.today_waiting') }}</span>
                    <strong>{{ $feed->count() }}</strong>
                </article>
                <article class="cmd-metric">
                    <span>{{ __('app.today_doors') }}</span>
                    <strong>{{ count($doors) }}</strong>
                </article>
            </section>

            <div class="cmd-board">
                <section class="cmd-panel">
                    <h2>{{ __('app.today_waiting') }}</h2>
                    @forelse($feed as $item)
                        <div class="cmd-feed">
                            <a href="{{ $item['href'] }}">
                                <small>{{ $item['label'] }}</small>
                                <strong>{{ $item['title'] }}</strong>
                                @if($item['detail'] !== '')
                                    <p>{{ $item['detail'] }}</p>
                                @endif
                            </a>
                        </div>
                    @empty
                        <p class="cmd-empty">{{ __('app.today_clear') }}</p>
                    @endforelse
                </section>

                <section class="cmd-panel">
                    <h2>{{ __('app.today_doors') }}</h2>
                    <div class="cmd-doors">
                        @foreach($doors as $project)
                            <a href="{{ auth()->user()->projectEntryUrl($project) }}">
                                <small>{{ __('app.open') }}</small>
                                <strong>{{ $project->label() }}</strong>
                                <p>{{ $project->description() }}</p>
                            </a>
                        @endforeach
                    </div>
                </section>
            </div>
        </main>
    </div>
@endsection
