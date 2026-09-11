@extends('app-development.layouts.project')

@section('title', __('app-development.releases.title'))

@section('app')
    <div class="mb-2 flex flex-wrap items-center justify-end gap-1.5">
        @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
            <a href="{{ route('app-development.releases.create') }}" class="kaman-button kaman-button--sm" data-app-dev-modal>
                @include('app-development.partials.icon', ['name' => 'upload'])
                {{ __('app-development.nav.upload_apk') }}
            </a>
        @endcan
    </div>

    <div class="app-dev-board space-y-2">
        @forelse($releases as $release)
            <article class="kaman-card kaman-card--compact flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('app-development.releases.show', $release) }}" class="min-w-0" data-app-dev-modal>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold text-[#2b1e11]">v{{ $release->version_name }}</h2>
                        @if($release->is_latest)
                            <span class="kaman-badge border-emerald-200 bg-emerald-50 text-emerald-800">
                                @include('app-development.partials.icon', ['name' => 'check-circle'])
                                {{ __('app-development.releases.latest') }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-0.5 truncate text-xs text-[#7c6a56]">
                        {{ $release->uploader?->name }}
                        · {{ $release->created_at?->format('d/m H:i') }}
                        · {{ $release->humanSize() }}
                        · {{ $release->tickets_count }}
                    </p>
                </a>
                <a href="{{ route('app-development.releases.download', $release) }}" class="kaman-button kaman-button--sm shrink-0">
                    @include('app-development.partials.icon', ['name' => 'download'])
                    {{ __('app-development.releases.download') }}
                </a>
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
