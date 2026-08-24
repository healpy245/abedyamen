@extends('app-development.layouts.project')

@section('title', __('app-development.releases.title'))

@section('app')
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-[#2b1e11]">{{ __('app-development.releases.title') }}</h1>
        @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
            <a href="{{ route('app-development.releases.create') }}" class="kaman-button kaman-button--sm">{{ __('app-development.releases.upload') }}</a>
        @endcan
    </div>

    <div class="space-y-3">
        @forelse($releases as $release)
            <article class="kaman-card kaman-card--compact flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold text-[#2b1e11]">v{{ $release->version_name }}</h2>
                        @if($release->is_latest)
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800">{{ __('app-development.releases.latest') }}</span>
                        @endif
                    </div>
                    @if($release->version_code)
                        <p class="text-xs text-[#a78a6c]">{{ __('app-development.releases.build') }} {{ $release->version_code }}</p>
                    @endif
                    <p class="mt-1 text-sm text-[#7c6a56]">
                        {{ __('app-development.releases.uploaded_by', ['name' => $release->uploader?->name]) }}
                        · {{ $release->created_at?->format('d M Y • H:i') }}
                        · {{ $release->humanSize() }}
                    </p>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('app-development.releases.related_count', ['count' => $release->tickets_count]) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('app-development.releases.download', $release) }}" class="kaman-button kaman-button--sm">{{ __('app-development.releases.download') }}</a>
                    <a href="{{ route('app-development.releases.show', $release) }}" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.releases.view') }}</a>
                </div>
            </article>
        @empty
            <div class="kaman-card kaman-card--compact text-center text-sm text-[#7c6a56]">
                {{ __('app-development.releases.empty') }}
            </div>
        @endforelse
    </div>

    @if($releases->hasPages())
        <div>{{ $releases->links() }}</div>
    @endif
@endsection
