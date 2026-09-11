@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AppDevelopment\AppDevelopmentMember> $members */
    $audience = $audience ?? 'open';
    $action = $action ?? '#';
    $title = $title ?? '';
    $hint = $hint ?? '';
@endphp
<div class="app-dev-notify"
     id="app-dev-notify-{{ $audience }}"
     data-notify-dialog="{{ $audience }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="app-dev-notify-title-{{ $audience }}"
     hidden>
    <div class="app-dev-notify__backdrop" data-notify-close></div>
    <form method="post" action="{{ $action }}" class="app-dev-notify__panel">
        @csrf
        <h3 id="app-dev-notify-title-{{ $audience }}" class="app-dev-notify__title">{{ $title }}</h3>
        <p class="app-dev-notify__hint">{{ $hint }}</p>

        @if($members->isEmpty())
            <p class="app-dev-notify__empty">{{ __('app-development.whatsapp.no_candidates') }}</p>
        @else
            <div class="app-dev-notify__list kaman-scroll">
                @foreach($members as $member)
                    <label class="app-dev-notify__row">
                        <input type="checkbox"
                               name="user_ids[]"
                               value="{{ $member->user_id }}"
                               @checked($member->whatsapp_notifications_enabled)>
                        <span class="min-w-0">
                            <strong>{{ $member->user?->name ?? '—' }}</strong>
                            @if($member->phone)
                                <small dir="ltr">{{ $member->phone }}</small>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="app-dev-notify__toolbar">
                <button type="button" class="kaman-button-ghost kaman-button--sm" data-notify-all>{{ __('app-development.whatsapp.select_all') }}</button>
                <button type="button" class="kaman-button-ghost kaman-button--sm" data-notify-none>{{ __('app-development.whatsapp.select_none') }}</button>
            </div>
        @endif

        <div class="app-dev-notify__actions">
            <button type="button" class="kaman-button-ghost" data-notify-close>{{ __('app-development.cancel') }}</button>
            <button type="submit" class="kaman-button" @disabled($members->isEmpty())>
                @include('app-development.partials.icon', ['name' => 'send'])
                {{ __('app-development.whatsapp.send') }}
            </button>
        </div>
    </form>
</div>

@once('app-dev-notify-script')
<script>
(function () {
    function closeAll() {
        document.querySelectorAll('[data-notify-dialog]').forEach((dialog) => {
            dialog.hidden = true;
            dialog.classList.remove('is-open');
        });
    }

    function openDialog(audience) {
        closeAll();
        const dialog = document.querySelector(`[data-notify-dialog="${audience}"]`);
        if (!dialog) return;
        dialog.hidden = false;
        dialog.classList.add('is-open');
    }

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-notify-open]');
        if (opener) {
            event.preventDefault();
            openDialog(opener.getAttribute('data-notify-open'));
            return;
        }
        if (event.target.closest('[data-notify-close]')) {
            event.preventDefault();
            closeAll();
            return;
        }
        const allBtn = event.target.closest('[data-notify-all]');
        if (allBtn) {
            event.preventDefault();
            allBtn.closest('form')?.querySelectorAll('input[name="user_ids[]"]').forEach((el) => { el.checked = true; });
            return;
        }
        const noneBtn = event.target.closest('[data-notify-none]');
        if (noneBtn) {
            event.preventDefault();
            noneBtn.closest('form')?.querySelectorAll('input[name="user_ids[]"]').forEach((el) => { el.checked = false; });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAll();
    });
})();
</script>
@endonce
