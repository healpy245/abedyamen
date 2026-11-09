@extends('app-development.layouts.project')

@section('title', __('app-development.releases.title'))

@section('app')
    <div class="ds-page">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('app-development.releases.title') }}</h1>
            </div>
            @can('uploadRelease', \App\Models\AppDevelopment\AppDevelopmentRelease::class)
                <a href="{{ route('app-development.releases.create') }}" class="ds-btn ds-btn--primary" data-app-dev-modal>
                    @include('app-development.partials.icon', ['name' => 'upload'])
                    {{ __('app-development.nav.upload_apk') }}
                </a>
            @endcan
        </header>

        <section class="ds-card ds-section">
            @forelse($releases as $release)
                <div class="ds-row dv-release">
                    <span class="dv-release__icon">@include('app-development.partials.icon', ['name' => 'apk'])</span>
                    <a href="{{ route('app-development.releases.show', $release) }}" class="ds-row__main ds-cell-title" data-app-dev-modal>
                        <strong>
                            <bdi dir="ltr">v{{ $release->version_name }}</bdi>
                            @if($release->is_latest)
                                <x-ds.badge soft tone="green">{{ __('app-development.releases.latest') }}</x-ds.badge>
                            @endif
                        </strong>
                        <small>
                            {{ $release->uploader?->name }}
                            · <bdi dir="ltr">{{ $release->created_at?->format('d/m H:i') }}</bdi>
                            · {{ $release->humanSize() }}
                        </small>
                    </a>
                    <a href="{{ route('app-development.releases.download', $release) }}" class="ds-btn ds-btn--secondary ds-btn--sm">
                        @include('app-development.partials.icon', ['name' => 'download'])
                        {{ __('app-development.releases.download') }}
                    </a>
                </div>
            @empty
                <x-ds.empty :title="__('app-development.releases.empty')" icon="receipt" />
            @endforelse
        </section>

        @if($releases->hasPages())
            <div class="kb-pages">{{ $releases->links() }}</div>
        @endif
    </div>
@endsection
