@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.title'))

@section('app')
    @php
        $state = $state ?? '';
        $search = $search ?? '';
        $stats = $stats ?? ['total' => 0, 'open' => 0, 'review' => 0, 'closed' => 0];
    @endphp

    <div class="ds-page dv-tickets">
        <header class="ds-head">
            <div>
                <h1 class="ds-h1">{{ __('app-development.tickets.title') }}</h1>
                <p class="ds-sub">{{ __('app-development.tickets.lead') }}</p>
            </div>
            <div class="ds-head__actions">
                <nav class="ds-seg" aria-label="{{ __('app-development.tickets.status') }}">
                    <a href="{{ route('app-development.tickets.index', request()->except('page', 'state', 'tab')) }}" @if($state === '') aria-current="page" @endif>{{ __('app-development.tabs.all') }}</a>
                    <a href="{{ route('app-development.tickets.index', array_merge(request()->except('page', 'tab'), ['state' => 'open'])) }}" @if($state === 'open') aria-current="page" @endif>{{ __('app-development.tickets.open_state') }}</a>
                    <a href="{{ route('app-development.tickets.index', array_merge(request()->except('page', 'tab'), ['state' => 'closed'])) }}" @if($state === 'closed') aria-current="page" @endif>{{ __('app-development.tickets.closed_state') }}</a>
                </nav>
                @can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class)
                    <a href="{{ route('app-development.tickets.create') }}" class="ds-btn ds-btn--primary" data-app-dev-modal>
                        <x-ds.icon name="plus" />
                        {{ __('app-development.nav.new_ticket') }}
                    </a>
                @endcan
            </div>
        </header>

        {{-- The topbar search writes into this hidden field. --}}
        <form class="tk-search-proxy" method="get" action="{{ route('app-development.tickets.index') }}">
            @if($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" data-kb-search>
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
                <span class="ds-label">{{ __('app-development.tickets.in_review') }}</span>
                <strong class="ds-num {{ $stats['review'] > 0 ? 'is-warn' : '' }}">{{ $stats['review'] }}</strong>
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
                        <th class="ds-col-tight">{{ __('app-development.tickets.number') }}</th>
                        <th>{{ __('app-development.tickets.title_col') }}</th>
                        <th>{{ __('app-development.tickets.status') }}</th>
                        <th class="ds-hide-narrow">{{ __('app-development.tickets.assigned') }}</th>
                        <th class="ds-col-tight"><span class="ds-sr-only">{{ __('app-development.tickets.view') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        @php
                            [$tone, $label] = $ticket->isCompleted()
                                ? [null, __('app-development.tickets.closed_state')]
                                : ($ticket->status === \App\Enums\AppDevelopmentTicketStatus::Qa
                                    ? ['amber', __('app-development.tickets.in_review')]
                                    : ['green', __('app-development.tickets.open_state')]);
                            $owner = $ticket->assignedDeveloper ?? $ticket->creator;
                        @endphp
                        <tr>
                            <td class="ds-col-tight"><bdi dir="ltr" class="ds-num">{{ $ticket->ticket_number }}</bdi></td>
                            <td class="ds-cell-lead">
                                <a class="ds-cell-title" href="{{ route('app-development.tickets.show', $ticket) }}" data-app-dev-modal>
                                    <strong>{{ $ticket->title }}</strong>
                                    @if(filled($ticket->restaurant_name))
                                        <small>{{ $ticket->restaurant_name }}</small>
                                    @endif
                                </a>
                            </td>
                            <td><x-ds.badge plain :tone="$tone">{{ $label }}</x-ds.badge></td>
                            <td class="ds-hide-phone ds-hide-narrow">
                                @if($owner)
                                    <span class="ds-person">
                                        <x-ds.avatar :name="$owner->name" />
                                        <span>{{ $owner->name }}</span>
                                    </span>
                                @else
                                    <span class="ds-muted">{{ __('app-development.tickets.unassigned') }}</span>
                                @endif
                            </td>
                            <td class="ds-col-tight ds-cell-end">
                                <div class="ds-actions">
                                    <a class="ds-btn ds-btn--ghost ds-btn--sm" href="{{ route('app-development.tickets.show', $ticket) }}" data-app-dev-modal>
                                        {{ __('app-development.tickets.view') }}
                                    </a>
                                    <a class="ds-btn ds-btn--secondary ds-btn--sm" href="{{ route('app-development.tickets.tasks.create', $ticket) }}" data-app-dev-modal>
                                        {{ __('app-development.tasks.escalate') }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="ds-empty-cell">
                                <x-ds.empty :title="__('app-development.tickets.empty')" icon="receipt" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($tickets->hasPages())
            <div class="kb-pages">{{ $tickets->links() }}</div>
        @endif
    </div>
@endsection
