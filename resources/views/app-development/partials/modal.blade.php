@php
    $open = (bool) ($modalOpen ?? ! empty($modalBodyView));
    $variant = $modalVariant ?? 'view';
    $title = $modalTitle ?? '';
    $fallback = $modalFallback ?? route('app-development.tickets.index');
@endphp

<div id="app-dev-modal"
     class="app-dev-modal {{ $open ? 'is-open is-standalone' : '' }} app-dev-modal--{{ $variant }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="app-dev-modal-title"
     @if(! $open) hidden @endif
     data-standalone="{{ $open ? '1' : '0' }}"
     data-fallback="{{ $fallback }}"
     data-loading="{{ __('app-development.modal.loading') }}">
    <div class="app-dev-modal__backdrop" data-app-dev-modal-close></div>
    <div class="app-dev-modal__panel">
        <header class="app-dev-modal__header">
            <h2 id="app-dev-modal-title" class="app-dev-modal__title">{{ $title }}</h2>
            <button type="button" class="kaman-button-ghost kaman-button--sm shrink-0" data-app-dev-modal-close>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.modal.close') }}
            </button>
        </header>
        <div id="app-dev-modal-body" class="app-dev-modal__body kaman-scroll">
            @if(! empty($modalBodyView))
                @include('app-development.partials.flash')
                @include($modalBodyView)
            @endif
        </div>
        <div class="app-dev-modal__loader" aria-hidden="true">
            @include('partials.kaman-ai-loader', ['size' => 'sm'])
            <span class="app-dev-modal__loader-label" data-app-dev-upload-label>{{ __('app-development.modal.loading') }}</span>
            <div class="app-dev-upload-progress" data-app-dev-upload-progress hidden>
                <div class="app-dev-upload-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-app-dev-upload-bar>
                    <span class="app-dev-upload-progress__fill" data-app-dev-upload-fill></span>
                </div>
                <div class="app-dev-upload-progress__meta">
                    <strong data-app-dev-upload-percent>0%</strong>
                    <span data-app-dev-upload-eta></span>
                </div>
                <p class="app-dev-upload-progress__hint" data-app-dev-upload-hint></p>
            </div>
        </div>
    </div>
</div>

@include('app-development.partials.confirm-dialog')
@include('app-development.partials.media-lightbox')

@once('app-dev-modal-assets')
    <style>{!! file_get_contents(public_path('css/app-development.css')) !!}</style>
    <script>
        window.__appDevVoiceI18n = {
            unsupported: @json(__('app-development.tickets.voice_unsupported')),
            denied: @json(__('app-development.tickets.voice_denied')),
            recording: @json(__('app-development.tickets.voice_recording')),
            clip: @json(__('app-development.tickets.voice_clip')),
            remove: @json(__('app-development.tickets.voice_remove')),
        };
        window.__appDevUpload = {
            initUrl: @json(route('app-development.uploads.init')),
            chunkUrlTemplate: @json(route('app-development.uploads.chunk', ['uuid' => '__UUID__'])),
            i18n: {
                uploading: @json(__('app-development.upload.uploading')),
                creating: @json(__('app-development.upload.creating')),
                almost: @json(__('app-development.upload.almost')),
                keepOpen: @json(__('app-development.upload.keep_open')),
                canLeave: @json(__('app-development.upload.can_leave')),
                eta: @json(__('app-development.upload.eta')),
                failed: @json(__('app-development.upload.failed')),
            },
        };
    </script>
    <script>{!! file_get_contents(public_path('js/app-development-modal.js')) !!}</script>
    <script>{!! file_get_contents(public_path('js/app-development-upload.js')) !!}</script>
    <script>{!! file_get_contents(public_path('js/app-development-media.js')) !!}</script>
    <script>{!! file_get_contents(public_path('js/app-development-confirm.js')) !!}</script>
    <script>{!! file_get_contents(public_path('js/app-development-voice.js')) !!}</script>
@endonce
