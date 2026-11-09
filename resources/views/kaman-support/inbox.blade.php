@php
    $nav = 'tickets';
    $state = $state ?? '';
    $search = $search ?? '';
    $stats = $stats ?? ['total' => 0, 'open' => 0, 'progress' => 0, 'closed' => 0];
@endphp
@extends('layouts.support')

@section('title', __('kaman-support.nav_tickets'))

@section('content')
    <div class="ds-page sp-inbox">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('kaman-support.nav_tickets') }}</h1>
                <p class="ds-sub">{{ __('kaman-support.ticket_lead') }}</p>
            </div>
            <nav class="ds-seg" aria-label="{{ __('app-development.tickets.status') }}">
                <a href="{{ route('kaman-support.inbox') }}" @if($state === '') aria-current="page" @endif>{{ __('app-development.tabs.all') }}</a>
                <a href="{{ route('kaman-support.inbox', ['state' => 'open']) }}" @if($state === 'open') aria-current="page" @endif>{{ __('app-development.tickets.open_state') }}</a>
                <a href="{{ route('kaman-support.inbox', ['state' => 'closed']) }}" @if($state === 'closed') aria-current="page" @endif>{{ __('app-development.tickets.closed_state') }}</a>
            </nav>
        </header>

        {{-- The topbar search writes into this hidden field. --}}
        <form class="tk-search-proxy" method="get" action="{{ route('kaman-support.inbox') }}">
            @if($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" data-sup-search>
        </form>

        <section class="ds-stats ds-card">
            <div class="ds-stat">
                <span class="ds-label">{{ __('app-development.tickets.total') }}</span>
                <strong class="ds-num">{{ $stats['total'] }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('app-development.tickets.open_state') }}</span>
                <strong class="ds-num">{{ $stats['open'] }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('kaman-support.status.in_progress') }}</span>
                <strong class="ds-num {{ $stats['progress'] > 0 ? 'is-warn' : '' }}">{{ $stats['progress'] }}</strong>
            </div>
            <div class="ds-stat">
                <span class="ds-label">{{ __('app-development.tickets.closed_state') }}</span>
                <strong class="ds-num">{{ $stats['closed'] }}</strong>
            </div>
        </section>

        <div class="ds-table-wrap">
            <table class="ds-table ds-table--stack">
                <thead>
                    <tr>
                        <th class="ds-col-tight">ID</th>
                        <th>{{ __('app-development.tickets.title_col') }}</th>
                        <th>{{ __('app-development.tickets.status') }}</th>
                        <th class="ds-hide-narrow">{{ __('app-development.tickets.restaurant') }}</th>
                        <th class="ds-col-tight"><span class="ds-sr-only">{{ __('app-development.tickets.view') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        @php
                            $closed = $ticket->status === \App\Enums\KamanSupport\SupportTicketStatus::Closed;
                            $escalated = filled($ticket->escalated_ticket_id);
                            $tone = $closed ? null : ($ticket->status === \App\Enums\KamanSupport\SupportTicketStatus::InProgress ? 'amber' : 'green');
                            $restaurantKey = app(\App\Services\KamanSupport\RestaurantSupport::class)->keyFor(auth()->user(), $ticket->restaurant);
                        @endphp
                        <tr
                            data-public="{{ $ticket->public_id }}"
                            data-title="{{ $ticket->summary }}"
                            data-details="{{ $ticket->details }}"
                        >
                            <td class="ds-col-tight"><bdi dir="ltr" class="ds-num">{{ $ticket->public_id }}</bdi></td>
                            <td class="ds-cell-lead">
                                <button type="button" class="ds-cell-title" data-ticket-view>
                                    <span class="sp-ticket-rest">
                                        @include('kaman-support.partials.restaurant-logo', [
                                            'slug' => $ticket->restaurant,
                                            'name' => $ticket->restaurant_name ?: $ticket->restaurant,
                                        ])
                                        <span>
                                            <strong>{{ $ticket->summary }}</strong>
                                            @include('kaman-support.partials.level', ['ticket' => $ticket])
                                            @if($closed && (filled($ticket->close_note) || $ticket->closedBy || $ticket->closeDurationLabel()))
                                                <small>
                                                    {{ $ticket->close_note }}
                                                    @if($ticket->closedBy) · {{ __('kaman-support.closed_by', ['name' => $ticket->closedBy->name]) }} @endif
                                                    @if($ticket->closeDurationLabel()) · {{ __('kaman-support.time_to_close', ['time' => $ticket->closeDurationLabel()]) }} @endif
                                                </small>
                                            @endif
                                        </span>
                                    </span>
                                </button>
                            </td>
                            <td>
                                <span class="sp-status">
                                    <x-ds.badge plain :tone="$tone">{{ $ticket->status?->label() }}</x-ds.badge>
                                    @if($escalated && ! $closed)
                                        <x-ds.badge soft tone="blue">{{ __('kaman-support.escalated') }}</x-ds.badge>
                                    @endif
                                </span>
                            </td>
                            <td class="ds-hide-phone ds-hide-narrow">
                                @if($restaurantKey !== '')
                                    <button type="button" class="sp-rest-link" data-act="open-support" data-id="{{ $restaurantKey }}">{{ $ticket->restaurant_name ?: $ticket->restaurant }}</button>
                                @else
                                    {{ $ticket->restaurant_name ?: $ticket->restaurant }}
                                @endif
                            </td>
                            <td class="ds-col-tight ds-cell-end">
                                <div class="ds-actions">
                                    <button type="button" class="ds-btn ds-btn--ghost ds-btn--sm" data-ticket-view>{{ __('app-development.tickets.view') }}</button>
                                    @unless($closed)
                                        @unless($escalated)
                                            <button type="button" class="ds-btn ds-btn--secondary ds-btn--sm" data-escalate-open="{{ $ticket->public_id }}">{{ __('kaman-support.escalate') }}</button>
                                        @endunless
                                        <button type="button" class="ds-btn ds-btn--secondary ds-btn--sm" data-close-open="{{ $ticket->public_id }}">
                                            <x-ds.icon name="check" />
                                            {{ __('kaman-support.close_ticket') }}
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="ds-empty-cell">
                                <x-ds.empty :title="__('kaman-support.empty')" icon="receipt" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="kb-pages">{{ $tickets->withQueryString()->links() }}</div>
    </div>


    <dialog class="tk-assure" data-escalate-dialog>
        <form method="post" data-escalate-form>
            @csrf
            <div class="tk-assure__head">
                <div class="tk-assure__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 19V5M6 11l6-6 6 6"/></svg></div>
                <div>
                    <h2>{{ __('kaman-support.escalate') }}</h2>
                    <p data-escalate-name></p>
                </div>
                <button class="ds-btn ds-btn--ghost ds-btn--icon tk-assure__x" type="button" data-dialog-cancel aria-label="{{ __('app-development.cancel') }}"><x-ds.icon name="x" /></button>
            </div>
            <p class="tk-assure__done" data-escalate-done hidden></p>
            <div data-escalate-actions>
                <label>
                    {{ __('kaman-support.details') }}
                    <textarea class="ds-textarea" name="description" rows="5" data-escalate-description></textarea>
                </label>
                <div class="tk-assure__foot">
                    <button class="ds-btn ds-btn--secondary" type="button" data-dialog-cancel>{{ __('app-development.cancel') }}</button>
                    <button class="ds-btn ds-btn--primary" type="button" data-escalate-send>{{ __('kaman-support.escalate') }}</button>
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
                <button class="ds-btn ds-btn--ghost ds-btn--icon tk-assure__x" type="button" data-dialog-cancel aria-label="{{ __('app-development.cancel') }}"><x-ds.icon name="x" /></button>
            </div>
            <label>
                {{ __('kaman-support.close_note') }}
                <textarea class="ds-textarea" name="note" rows="4"></textarea>
            </label>
            <div class="tk-assure__foot">
                <button class="ds-btn ds-btn--secondary" type="button" data-dialog-cancel>{{ __('app-development.cancel') }}</button>
                <button class="ds-btn ds-btn--primary" type="submit">{{ __('kaman-support.close_ticket') }}</button>
            </div>
        </form>
    </dialog>
    @include('workspace.partials.restaurant-modal', ['surface' => 'support'])
@endsection

@push('scripts')
<script src="{{ asset('js/ticket-view.js') }}?v={{ filemtime(public_path('js/ticket-view.js')) }}"></script>
<script>
(function () {
    const base = @json(url('/kaman-support/tickets'));
    const closeDialog = document.querySelector('[data-close-dialog]');
    const closeForm = document.querySelector('[data-close-form]');
    const ticketUrl = @json(route('kaman-support.show', ['ticket' => '__ID__']));
    const openTicket = (publicId) => window.TicketView && publicId && window.TicketView.open(ticketUrl.replace('__ID__', encodeURIComponent(publicId)));
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

    const logoMarks = Array.from(document.querySelectorAll('.sp-inbox [data-logo]'));
    const logoSlugs = [...new Set(logoMarks.map((mark) => mark.getAttribute('data-logo')).filter(Boolean))];
    if (logoSlugs.length) {
        const query = logoSlugs.map((slug) => 'restaurants[]=' + encodeURIComponent(slug)).join('&');
        fetch(@json(route('kaman-support.logos')) + '?' + query, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((payload) => {
                const logos = (payload && payload.logos) || {};
                logoMarks.forEach((mark) => {
                    const src = logos[mark.getAttribute('data-logo')];
                    if (!src || mark.querySelector('img')) return;
                    const image = document.createElement('img');
                    image.alt = '';
                    image.src = src;
                    mark.prepend(image);
                });
            })
            .catch(() => {});
    }

    document.addEventListener('click', (event) => {
        const view = event.target.closest('[data-ticket-view]');
        if (view) openTicket(view.closest('tr')?.getAttribute('data-public'));

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

    [closeDialog, escalateDialog].forEach((dialog) => {
        if (!dialog) return;
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) shut(dialog);
        });
    });

    // Links to a ticket land here and open it in the ticket modal.
    const linked = new URLSearchParams(window.location.search).get('ticket');
    if (linked) openTicket(linked);
})();
</script>
@endpush
