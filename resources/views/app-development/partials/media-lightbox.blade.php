<div id="app-dev-media-lightbox"
     class="app-dev-media"
     hidden
     role="dialog"
     aria-modal="true"
     aria-label="{{ __('app-development.media.open') }}">
    <div class="app-dev-media__backdrop" data-app-dev-media-close></div>
    <div class="app-dev-media__panel">
        <header class="app-dev-media__header">
            <h3 class="app-dev-media__title" data-app-dev-media-title></h3>
            <button type="button"
                    class="kaman-button-ghost kaman-button--sm shrink-0"
                    data-app-dev-media-close
                    aria-label="{{ __('app-development.media.close') }}">
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.modal.close') }}
            </button>
        </header>
        <div class="app-dev-media__body" data-app-dev-media-body></div>
    </div>
</div>
