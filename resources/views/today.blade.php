@extends('layouts.kaman')

@section('title', __('app.today_title'))
@section('tag', __('app.today_tag'))

@section('content')
    <div class="page-container page-container--tight">
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <section class="hero-panel hero-panel--compact">
                <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">
                    {{ __('app.today_hello', ['name' => auth()->user()->name]) }}
                </h1>
                <p class="mt-1 text-sm text-[#7c6a56]">{{ __('app.today_subtitle') }}</p>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-semibold text-[#7c6a56]">{{ __('app.today_waiting') }}</h2>

                @forelse($items as $item)
                    <a href="{{ $item['href'] }}" class="kaman-card kaman-card--compact block hover:border-[#e7c9a4]">
                        <p class="text-xs font-semibold text-[#c67139]">{{ $item['label'] }}</p>
                        <p class="mt-1 text-base font-semibold text-[#2b1e11]">{{ $item['title'] }}</p>
                        @if($item['detail'] !== '')
                            <p class="mt-1 text-sm text-[#7c6a56]">{{ $item['detail'] }}</p>
                        @endif
                    </a>
                @empty
                    <p class="kaman-card kaman-card--compact text-sm text-[#7c6a56]">{{ __('app.today_clear') }}</p>
                @endforelse
            </section>

            @if($doors !== [])
                <section class="space-y-3">
                    <h2 class="text-sm font-semibold text-[#7c6a56]">{{ __('app.today_doors') }}</h2>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($doors as $project)
                            <a href="{{ auth()->user()->projectEntryUrl($project) }}" class="kaman-card block hover:border-[#e7c9a4]">
                                <h3 class="text-base font-semibold text-[#2b1e11]">{{ $project->label() }}</h3>
                                <p class="mt-1 text-sm text-[#7c6a56]">{{ $project->description() }}</p>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
