@extends('layouts.kaman')

@section('title', __('features.title'))
@section('tag', __('features.tag'))

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Caprasimo&family=Figtree:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/kaman-welcome.css') }}?v={{ filemtime(public_path('css/kaman-welcome.css')) }}">
@endpush

@section('content')
    @php
        $user = auth()->user();
        $projects = $user ? $user->accessibleProjects() : [];
        $firstName = $user ? trim(explode(' ', trim($user->name))[0] ?? $user->name) : '';
        $products = __('features.products');
        unset($products['support']);
        $heroShot = ['src' => 'images/features/pos-main.webp', 'frame' => 'screen'];
    @endphp

    <div class="page-container">
        <div class="mx-auto w-full max-w-6xl feat">

            <section class="hero">
                <div class="hero__copy">
                    @if($user)
                        <p class="hero__hello">{{ __('workspace.hello', ['name' => $firstName]) }}</p>
                    @endif
                    <p class="hero__kicker">{{ __('features.kicker') }}</p>
                    <h1>{{ __('features.hero_title') }}</h1>
                    <p class="hero__lead">{{ __('features.hero_lead') }}</p>
                    <div class="hero__actions">
                        @if($user)
                            <a href="#your-tools" class="kaman-button">{{ __('features.open_tools') }}</a>
                        @else
                            <a href="{{ route('login') }}" class="kaman-button">{{ __('auth.sign_in') }}</a>
                        @endif
                    </div>
                    <img class="hero__mascot" src="{{ asset('images/features/kaman-character-welcome.webp') }}?v={{ filemtime(public_path('images/features/kaman-character-welcome.webp')) }}" alt="" aria-hidden="true">
                </div>
                @if(is_file(public_path($heroShot['src'])))
                    <figure class="hero__figure">
                        <div class="tablet">
                            <span class="tablet__edge" aria-hidden="true"></span>
                            <span class="tablet__camera" aria-hidden="true"></span>
                            <img src="{{ asset($heroShot['src']) }}?v={{ filemtime(public_path($heroShot['src'])) }}" alt="Kaman POS">
                            <span class="tablet__glass" aria-hidden="true"></span>
                        </div>
                    </figure>
                @endif
            </section>

            <nav class="app-rail" aria-label="{{ __('features.nav_label') }}">
                @foreach($products as $id => $product)
                    <a href="#product-{{ $id }}" data-feat-link="{{ $id }}">
                        <span class="app-rail__index">{{ $product['index'] }}</span>
                        <span class="app-rail__name">{{ $product['name'] }}</span>
                        <span class="app-rail__role">{{ $product['role'] }}</span>
                    </a>
                @endforeach
            </nav>

            @include('partials.kaman-features', ['products' => $products])

            <section class="feat-close feat-reveal">
                <img class="feat-close__mascot" src="{{ asset('images/features/kaman-character-close.webp') }}?v={{ filemtime(public_path('images/features/kaman-character-close.webp')) }}" alt="" aria-hidden="true">
                <div>
                    <h2>{{ __('features.close_title') }}</h2>
                    <p>{{ $user ? __('features.close_body') : __('features.close_guest') }}</p>
                    @unless($user)
                        <a href="{{ route('login') }}" class="kaman-button feat-close__action">{{ __('auth.sign_in') }}</a>
                    @endunless
                </div>
            </section>

            @if($user)
                <section id="your-tools" class="feat-tools">
                    @unless($user->posLiveLogsOnly())
                        @if(strcasecmp((string) $user->email, (string) config('kaman-app.allowed_email')) === 0)
                            @include('partials.client-downloads')
                        @endif
                    @endunless

                    @if(count($projects) > 0)
                        <div>
                            <x-workspace.section-header
                                :title="__('workspace.your_tools')"
                                :count="count($projects)"
                                class="mb-3"
                            />

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($projects as $project)
                                    <x-workspace.tool-card
                                        :href="$user->projectEntryUrl($project)"
                                        :title="$project->label()"
                                        :description="$project->description()"
                                        :icon="$project->icon()"
                                        :tone="$project->tone()"
                                        :detail="$project->detail()"
                                    />
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="kaman-card kaman-card--compact text-center">
                            <span class="mx-auto mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-[#f47a2e]/10 border border-[#f47a2e]/20">
                                <x-workspace.icon name="lock-closed" class="h-5 w-5 text-[#f47a2e]" />
                            </span>
                            <h2 class="text-base font-semibold text-[#2b1e11] mb-1">{{ __('workspace.no_tools_title') }}</h2>
                            <p class="mx-auto max-w-sm text-sm text-[#7c6a56]">
                                {{ __('workspace.no_tools_body') }}
                            </p>
                        </div>
                    @endif

                    <p class="feat-signed-in">
                        {{ __('workspace.signed_in_as', ['email' => $user->email]) }}
                    </p>
                </section>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/kaman-features.js') }}?v={{ filemtime(public_path('js/kaman-features.js')) }}" defer></script>
@endpush
