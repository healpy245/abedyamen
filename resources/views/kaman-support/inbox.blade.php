@php
    $nav = 'tickets';
    $state = $state ?? '';
    $search = $search ?? '';
    $stats = $stats ?? ['total' => 0, 'open' => 0, 'progress' => 0, 'closed' => 0];
@endphp
@extends('layouts.support')

@section('title', __('kaman-support.nav_tickets'))

@section('content')
    <div class="tk-board">
        <header class="tk-board__head">
            <div>
                <h1>{{ __('kaman-support.nav_tickets') }}</h1>
                <p>{{ __('kaman-support.ticket_lead') }}</p>
            </div>
            <div class="tk-board__tools">
                <div class="kb-switch" role="tablist">
                    <a href="{{ route('kaman-support.inbox') }}" @if($state === '') aria-selected="true" @endif>{{ __('app-development.tabs.all') }}</a>
                    <a href="{{ route('kaman-support.inbox', ['state' => 'open']) }}" @if($state === 'open') aria-selected="true" @endif>{{ __('app-development.tickets.open_state') }}</a>
                    <a href="{{ route('kaman-support.inbox', ['state' => 'closed']) }}" @if($state === 'closed') aria-selected="true" @endif>{{ __('app-development.tickets.closed_state') }}</a>
                </div>
            </div>
        </header>

        <form class="tk-search-proxy" method="get" action="{{ route('kaman-support.inbox') }}">
            @if($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" data-sup-search>
        </form>

        <div class="tk-stats">
            <article class="tk-stat"><div><strong>{{ $stats['total'] }}</strong><small>{{ __('app-development.tickets.total') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['open'] }}</strong><small>{{ __('app-development.tickets.open_state') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['progress'] }}</strong><small>{{ __('kaman-support.status.in_progress') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['closed'] }}</strong><small>{{ __('app-development.tickets.closed_state') }}</small></div></article>
        </div>

        <div class="tk-table-wrap">
            <table class="tk-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>{{ __('app-development.tickets.title_col') }}</th>
                        <th>{{ __('app-development.tickets.status') }}</th>
                        <th>{{ __('app-development.tickets.restaurant') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        @php
                            $closed = $ticket->status === \App\Enums\KamanSupport\SupportTicketStatus::Closed;
                            $escalated = filled($ticket->escalated_ticket_id);
                            $pill = $closed ? 'is-closed' : ($ticket->status === \App\Enums\KamanSupport\SupportTicketStatus::InProgress ? 'is-review' : 'is-open');
                        @endphp
                        <tr
                            data-public="{{ $ticket->public_id }}"
                            data-title="{{ $ticket->summary }}"
                            data-details="{{ $ticket->details }}"
                            data-restaurant="{{ $ticket->restaurant_name ?: $ticket->restaurant }}"
                        >
                            <td><bdi dir="ltr">{{ $ticket->public_id }}</bdi></td>
                            <td>
                                <button type="button" class="tk-title" data-ticket-view>{{ $ticket->summary }}</button>
                                @if($closed)
                                    <span class="tk-sub">
                                        {{ $ticket->close_note }}
                                        @if($ticket->closedBy) · {{ __('kaman-support.closed_by', ['name' => $ticket->closedBy->name]) }} @endif
                                        @if($ticket->closeDurationLabel()) · {{ __('kaman-support.time_to_close', ['time' => $ticket->closeDurationLabel()]) }} @endif
                                    </span>
                                @endif
                            </td>
                            <td><span class="tk-pill {{ $pill }}"><i></i>{{ $ticket->status?->label() }}</span></td>
                            <td>{{ $ticket->restaurant_name ?: $ticket->restaurant }}</td>
                            <td>
                                <div class="tk-actions">
                                    <button type="button" class="tk-act tk-act--view" data-ticket-view>
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <span>{{ __('app-development.tickets.view') }}</span>
                                    </button>
                                    @unless($closed)
                                        @if($escalated)
                                            <span class="tk-pill is-escalated"><i></i>{{ __('kaman-support.escalated') }}</span>
                                        @else
                                            <button type="button" class="tk-act tk-act--up" data-escalate-open="{{ $ticket->public_id }}">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
                                                <span>{{ __('kaman-support.escalate') }}</span>
                                            </button>
                                        @endif
                                        <button type="button" class="tk-act tk-act--close" data-close-open="{{ $ticket->public_id }}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12.5 10 16.5 18 8"/></svg>
                                            <span>{{ __('kaman-support.close_ticket') }}</span>
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('kaman-support.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $tickets->withQueryString()->links() }}</div>
    </div>

    <dialog class="tk-assure" data-view-dialog>
        <div class="tk-assure__sheet">
            <div class="tk-assure__head">
                <div class="tk-assure__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></div>
                <div>
                    <h2 data-view-title></h2>
                </div>
                <button class="tk-assure__x" type="button" data-dialog-cancel aria-label="{{ __('kaman-support.ws_close') }}">×</button>
            </div>
            <dl class="tk-assure__body">
                <div>
                    <dt>{{ __('kaman-support.details') }}</dt>
                    <dd data-view-details></dd>
                </div>
                <div>
                    <dt>{{ __('kaman-support.restaurant') }}</dt>
                    <dd data-view-restaurant></dd>
                </div>
            </dl>
            <div class="tk-assure__foot">
                <button class="tk-act" type="button" data-dialog-cancel>{{ __('kaman-support.ws_close') }}</button>
            </div>
        </div>
    </dialog>

    <dialog class="tk-assure" data-escalate-dialog>
        <form method="post" data-escalate-form>
            @csrf
            <div class="tk-assure__head">
                <div class="tk-assure__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 19V5M6 11l6-6 6 6"/></svg></div>
                <div>
                    <h2>{{ __('kaman-support.escalate') }}</h2>
                    <p data-escalate-name></p>
                </div>
                <button class="tk-assure__x" type="button" data-dialog-cancel aria-label="{{ __('app-development.cancel') }}">×</button>
            </div>
            <p class="tk-assure__done" data-escalate-done hidden></p>
            <div data-escalate-actions>
                <label>
                    {{ __('kaman-support.details') }}
                    <textarea name="description" rows="5" data-escalate-description></textarea>
                </label>
                <div class="tk-assure__foot">
                    <button class="tk-act" type="button" data-dialog-cancel>{{ __('app-development.cancel') }}</button>
                    <button class="tk-new" type="button" data-escalate-send>{{ __('kaman-support.escalate') }}</button>
                </div>
            </div>
        </form>
    </dialog>

    <dialog class="tk-assure" data-close-dialog>
        <form method="post" data-close-form>
            @csrf
            <div class="tk-assure__head">
                <div class="tk-assure__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 12.5l3.2 3.2L18 8"/><circle cx="12" cy="12" r="9"/></svg></div>
                <div>
                    <h2>{{ __('kaman-support.close_title') }}</h2>
                </div>
                <button class="tk-assure__x" type="button" data-dialog-cancel aria-label="{{ __('app-development.cancel') }}">×</button>
            </div>
            <label>
                {{ __('kaman-support.close_note') }}
                <textarea name="note" rows="4"></textarea>
            </label>
            <div class="tk-assure__foot">
                <button class="tk-act" type="button" data-dialog-cancel>{{ __('app-development.cancel') }}</button>
                <button class="tk-new" type="submit">{{ __('kaman-support.close_ticket') }}</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
<script>
(function () {
    const base = @json(url('/kaman-support/tickets'));
    const closeDialog = document.querySelector('[data-close-dialog]');
    const closeForm = document.querySelector('[data-close-form]');
    const viewDialog = document.querySelector('[data-view-dialog]');
    const escalatedLabel = @json(__('kaman-support.escalated'));
    const escalateDialog = document.querySelector('[data-escalate-dialog]');
    const escalateForm = document.querySelector('[data-escalate-form]');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || escalateForm?.querySelector('input[name="_token"]')?.value
        || '';
    const shut = window.kamanCloseDialog || function (dialog) { dialog.close(); };

    function resetEscalate() {
        const done = escalateDialog?.querySelector('[data-escalate-done]');
        const actions = escalateDialog?.querySelector('[data-escalate-actions]');
        if (done) {
            done.hidden = true;
            done.textContent = '';
        }
        if (actions) actions.hidden = false;
        const submit = escalateForm?.querySelector('[data-escalate-send]');
        if (submit) submit.disabled = false;
    }

    function fillView(row) {
        if (!row || !viewDialog) return;
        viewDialog.querySelector('[data-view-title]').textContent = row.getAttribute('data-title') || '';
        viewDialog.querySelector('[data-view-details]').textContent = row.getAttribute('data-details') || '—';
        viewDialog.querySelector('[data-view-restaurant]').textContent = row.getAttribute('data-restaurant') || '—';
        if (typeof viewDialog.showModal === 'function') viewDialog.showModal();
    }

    document.addEventListener('click', (event) => {
        const view = event.target.closest('[data-ticket-view]');
        if (view) fillView(view.closest('tr'));

        const escalate = event.target.closest('[data-escalate-open]');
        if (escalate && escalateForm && escalateDialog) {
            const row = escalate.closest('tr');
            const publicId = escalate.getAttribute('data-escalate-open') || '';
            escalateForm.action = base + '/' + encodeURIComponent(publicId) + '/escalate';
            escalateForm.dataset.publicId = publicId;
            const name = escalateDialog.querySelector('[data-escalate-name]');
            if (name) name.textContent = row ? (row.getAttribute('data-title') || '') : '';
            resetEscalate();
            const description = escalateForm.querySelector('[data-escalate-description]');
            if (description) description.value = row ? (row.getAttribute('data-details') || '') : '';
            if (typeof escalateDialog.showModal === 'function') escalateDialog.showModal();
        }

        const closer = event.target.closest('[data-close-open]');
        if (closer && closeForm && closeDialog) {
            closeForm.action = base + '/' + encodeURIComponent(closer.getAttribute('data-close-open')) + '/close';
            if (typeof closeDialog.showModal === 'function') closeDialog.showModal();
        }

        const cancel = event.target.closest('[data-dialog-cancel]');
        if (cancel) {
            const dialog = cancel.closest('dialog');
            if (dialog) shut(dialog);
        }
    });

    function sendEscalate() {
        if (!escalateForm || !escalateForm.action) return;
        const submit = escalateForm.querySelector('[data-escalate-send]');
        if (submit) submit.disabled = true;
        fetch(escalateForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: new FormData(escalateForm),
            }).then((res) => res.json().then((data) => ({ ok: res.ok, data }))).then((result) => {
                const done = escalateDialog.querySelector('[data-escalate-done]');
                const actions = escalateDialog.querySelector('[data-escalate-actions]');
                if (!result.ok) {
                    if (submit) submit.disabled = false;
                    if (done) {
                        done.hidden = false;
                        done.textContent = (result.data && (result.data.message || result.data.error)) || '';
                    }
                    return;
                }
                if (actions) actions.hidden = true;
                if (done) {
                    done.hidden = false;
                    done.textContent = (result.data && result.data.message) || '';
                }
                markEscalated(escalateForm.dataset.publicId || '');
            }).catch(() => {
                if (submit) submit.disabled = false;
            });
    }

    function markEscalated(publicId) {
        if (!publicId || !window.CSS || !CSS.escape) return;
        const row = document.querySelector('tr[data-public="' + CSS.escape(publicId) + '"]');
        const button = row?.querySelector('[data-escalate-open]');
        if (!button) return;
        const pill = document.createElement('span');
        pill.className = 'tk-pill is-escalated';
        const dot = document.createElement('i');
        pill.append(dot, document.createTextNode(escalatedLabel));
        button.replaceWith(pill);
    }

    if (escalateForm) {
        escalateForm.addEventListener('submit', (event) => {
            event.preventDefault();
            sendEscalate();
        });
        escalateForm.querySelector('[data-escalate-send]')?.addEventListener('click', sendEscalate);
    }

    [closeDialog, viewDialog, escalateDialog].forEach((dialog) => {
        if (!dialog) return;
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) shut(dialog);
        });
    });
})();
</script>
@endpush
