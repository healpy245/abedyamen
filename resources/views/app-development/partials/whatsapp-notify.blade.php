@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AppDevelopment\AppDevelopmentMember> $members */
    $members = $members ?? collect();
    $counts = $counts ?? ['open' => 0, 'qa' => 0];
@endphp
<div class="wa-notify" data-wa-notify>
    <button type="button"
            class="wa-notify__toggle"
            data-wa-toggle
            aria-expanded="false"
            aria-haspopup="dialog"
            title="{{ __('app-development.whatsapp.button') }}"
            aria-label="{{ __('app-development.whatsapp.button') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23a8.2 8.2 0 0 1 5.83 2.42 8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.7 8.23-8.24 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.42.08-.17.04-.31-.02-.43-.06-.13-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28z"/>
        </svg>
    </button>

    <form method="post"
          action="{{ route('app-development.notify.open') }}"
          class="wa-notify__panel"
          data-wa-panel
          role="dialog"
          aria-label="{{ __('app-development.whatsapp.button') }}"
          hidden>
        @csrf
        <header class="wa-notify__head">
            <span class="wa-notify__badge" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2z"/></svg>
            </span>
            <div>
                <strong>{{ __('app-development.whatsapp.button') }}</strong>
                <small>{{ __('app-development.whatsapp.pick_team') }}</small>
            </div>
        </header>

        <div class="wa-notify__kinds" role="radiogroup" aria-label="{{ __('app-development.whatsapp.about') }}">
            <label>
                <input type="radio" name="wa_kind" value="{{ route('app-development.notify.open') }}" checked data-wa-kind>
                <span>{{ __('app-development.whatsapp.kind_open') }} <b>{{ $counts['open'] }}</b></span>
            </label>
            <label>
                <input type="radio" name="wa_kind" value="{{ route('app-development.notify.qa') }}" data-wa-kind>
                <span>{{ __('app-development.whatsapp.kind_qa') }} <b>{{ $counts['qa'] }}</b></span>
            </label>
        </div>

        @if($members->isEmpty())
            <p class="wa-notify__empty">{{ __('app-development.whatsapp.no_candidates') }}</p>
        @else
            <div class="wa-notify__tools">
                <button type="button" data-wa-all>{{ __('app-development.whatsapp.select_all') }}</button>
                <button type="button" data-wa-none>{{ __('app-development.whatsapp.select_none') }}</button>
            </div>
            <div class="wa-notify__list">
                @foreach($members as $member)
                    @php
                        $name = $member->user?->name ?? '—';
                        $initial = mb_strtoupper(mb_substr($name, 0, 1));
                    @endphp
                    <label class="wa-notify__row">
                        <input type="checkbox" name="user_ids[]" value="{{ $member->user_id }}" @checked($member->whatsapp_notifications_enabled)>
                        <span class="wa-notify__avatar" aria-hidden="true">{{ $initial }}</span>
                        <span class="wa-notify__who">
                            <strong>{{ $name }}</strong>
                            <small>{{ $member->role?->label() }} · <bdi dir="ltr">{{ $member->phone }}</bdi></small>
                        </span>
                        <span class="wa-notify__check" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M6 12.5l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <footer class="wa-notify__foot">
            <button type="submit" class="wa-notify__send" data-wa-send @disabled($members->isEmpty())>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2.5-6.5L4 12z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                <span>{{ __('app-development.whatsapp.send') }}</span>
                <b data-wa-count>0</b>
            </button>
        </footer>
    </form>
</div>

@once('app-dev-wa-notify-script')
<script>
(function () {
    document.querySelectorAll('[data-wa-notify]').forEach(function (root) {
        const toggle = root.querySelector('[data-wa-toggle]');
        const panel = root.querySelector('[data-wa-panel]');
        if (!toggle || !panel) return;
        const boxes = () => Array.from(panel.querySelectorAll('input[name="user_ids[]"]'));
        const send = panel.querySelector('[data-wa-send]');
        const count = panel.querySelector('[data-wa-count]');

        function refresh() {
            const picked = boxes().filter((el) => el.checked).length;
            if (count) count.textContent = String(picked);
            if (send) send.disabled = picked === 0;
        }

        function setOpen(open) {
            if (open) {
                panel.hidden = false;
                panel.classList.remove('is-leaving');
                toggle.setAttribute('aria-expanded', 'true');
                refresh();
                return;
            }
            if (panel.hidden || panel.classList.contains('is-leaving')) return;
            panel.classList.add('is-leaving');
            toggle.setAttribute('aria-expanded', 'false');
            window.setTimeout(function () {
                panel.hidden = true;
                panel.classList.remove('is-leaving');
            }, 160);
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(panel.hidden);
        });
        panel.addEventListener('change', function (event) {
            if (event.target.matches('[data-wa-kind]')) panel.action = event.target.value;
            refresh();
        });
        panel.addEventListener('click', function (event) {
            const all = event.target.closest('[data-wa-all]');
            const none = event.target.closest('[data-wa-none]');
            if (!all && !none) return;
            boxes().forEach((el) => { el.checked = Boolean(all); });
            refresh();
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setOpen(false);
        });
    });
})();
</script>
@endonce
