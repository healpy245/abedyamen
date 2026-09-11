<div id="app-dev-confirm"
     class="app-dev-confirm"
     role="dialog"
     aria-modal="true"
     aria-labelledby="app-dev-confirm-title"
     hidden>
    <div class="app-dev-confirm__backdrop" data-app-dev-confirm-cancel></div>
    <div class="app-dev-confirm__panel">
        <div class="app-dev-confirm__icon" aria-hidden="true">
            @include('app-development.partials.icon', ['name' => 'trash'])
        </div>
        <h3 id="app-dev-confirm-title" class="app-dev-confirm__title" data-app-dev-confirm-title></h3>
        <p class="app-dev-confirm__message" data-app-dev-confirm-message></p>
        <div class="app-dev-confirm__actions">
            <button type="button" class="kaman-button-ghost" data-app-dev-confirm-cancel>
                {{ __('app-development.cancel') }}
            </button>
            <button type="button" class="kaman-button app-dev-confirm__ok" data-app-dev-confirm-ok>
                {{ __('app-development.tickets.delete') }}
            </button>
        </div>
    </div>
</div>
