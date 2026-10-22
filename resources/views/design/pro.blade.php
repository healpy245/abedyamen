@extends('design.layout')

@php $concept = 'pro'; @endphp

@section('title', 'Kaman Workspace Pro')
@section('body-class', 'concept-pro')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/design-pro.css') }}?v={{ filemtime(public_path('css/design-pro.css')) }}">
@endpush

@section('content')
    <header class="pro-bar">
        <a class="pro-brand" href="{{ route('design.modern-light') }}">
            <img src="{{ asset('kaman.png') }}" alt="{{ __('app.name') }}">
            {{ __('app.name') }}
        </a>
        <nav class="pro-links" aria-label="{{ __('app.today_doors') }}">
            @foreach($doors as $project)
                <a href="{{ auth()->user()->projectEntryUrl($project) }}">{{ $project->label() }}</a>
            @endforeach
        </nav>
    </header>

    <main class="pro">
        <h1 class="pro-hello">{{ __('app.today_hello', ['name' => auth()->user()->name]) }}</h1>
        <p class="pro-lead">{{ __('app.today_subtitle') }}</p>

        <h2>{{ __('app.today_waiting') }}</h2>
        <div class="pro-list">
            @forelse($items as $item)
                <a href="{{ $item['href'] }}">
                    <small>{{ $item['label'] }}</small>
                    <span>
                        <strong>{{ $item['title'] }}</strong>
                        @if($item['detail'] !== '')
                            <p>{{ $item['detail'] }}</p>
                        @endif
                    </span>
                </a>
            @empty
                <p class="pro-empty">{{ __('app.today_clear') }}</p>
            @endforelse
        </div>

        <h2>{{ __('app.today_doors') }}</h2>
        <div class="pro-doors">
            @foreach($doors as $project)
                <a href="{{ auth()->user()->projectEntryUrl($project) }}">
                    <strong>{{ $project->label() }}</strong>
                    <p>{{ $project->description() }}</p>
                </a>
            @endforeach
        </div>
    </main>
@endsection
