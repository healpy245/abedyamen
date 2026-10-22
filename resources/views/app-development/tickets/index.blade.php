@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.title'))

@section('app')
    @php
        $state = $state ?? '';
        $search = $search ?? '';
        $stats = $stats ?? ['total' => 0, 'open' => 0, 'review' => 0, 'closed' => 0];
    @endphp

    <div class="tk-board">
        <header class="tk-board__head">
            <div>
                <h1>{{ __('app-development.tickets.title') }}</h1>
                <p>{{ __('app-development.tickets.lead') }}</p>
            </div>
            <div class="tk-board__tools">
                <div class="kb-switch" role="tablist">
                    <a href="{{ route('app-development.tickets.index', request()->except('page', 'state', 'tab')) }}" @if($state === '') aria-selected="true" @endif>{{ __('app-development.tabs.all') }}</a>
                    <a href="{{ route('app-development.tickets.index', array_merge(request()->except('page', 'tab'), ['state' => 'open'])) }}" @if($state === 'open') aria-selected="true" @endif>{{ __('app-development.tickets.open_state') }}</a>
                    <a href="{{ route('app-development.tickets.index', array_merge(request()->except('page', 'tab'), ['state' => 'closed'])) }}" @if($state === 'closed') aria-selected="true" @endif>{{ __('app-development.tickets.closed_state') }}</a>
                </div>
                @can('create', \App\Models\AppDevelopment\AppDevelopmentTicket::class)
                    <a href="{{ route('app-development.tickets.create') }}" class="tk-new" data-app-dev-modal>
                        + {{ __('app-development.nav.new_ticket') }}
                    </a>
                @endcan
            </div>
        </header>

        <form class="tk-search-proxy" method="get" action="{{ route('app-development.tickets.index') }}">
            @if($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
            <input type="search" name="q" value="{{ $search }}" data-kb-search>
        </form>

        <div class="tk-stats">
            <article class="tk-stat"><div><strong>{{ $stats['total'] }}</strong><small>{{ __('app-development.tickets.total') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['open'] }}</strong><small>{{ __('app-development.tickets.open_state') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['review'] }}</strong><small>{{ __('app-development.tickets.in_review') }}</small></div></article>
            <article class="tk-stat"><div><strong>{{ $stats['closed'] }}</strong><small>{{ __('app-development.tickets.closed_state') }}</small></div></article>
        </div>

        <div class="tk-table-wrap">
            <table class="tk-table">
                <thead>
                    <tr>
                        <th>{{ __('app-development.tickets.number') }}</th>
                        <th>{{ __('app-development.tickets.title_col') }}</th>
                        <th>{{ __('app-development.tickets.status') }}</th>
                        <th>{{ __('app-development.tickets.assigned') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        @php
                            $pill = $ticket->isCompleted() ? 'is-closed' : ($ticket->status === \App\Enums\AppDevelopmentTicketStatus::Qa ? 'is-review' : 'is-open');
                            $label = $ticket->isCompleted()
                                ? __('app-development.tickets.closed_state')
                                : ($ticket->status === \App\Enums\AppDevelopmentTicketStatus::Qa
                                    ? __('app-development.tickets.in_review')
                                    : __('app-development.tickets.open_state'));
                        @endphp
                        <tr>
                            <td><bdi dir="ltr">{{ $ticket->ticket_number }}</bdi></td>
                            <td>
                                <a href="{{ route('app-development.tickets.show', $ticket) }}" data-app-dev-modal>{{ $ticket->title }}</a>
                                @if(filled($ticket->restaurant_name))
                                    <span class="tk-sub">{{ $ticket->restaurant_name }}</span>
                                @endif
                            </td>
                            <td><span class="tk-pill {{ $pill }}"><i></i>{{ $label }}</span></td>
                            <td>{{ $ticket->assignedDeveloper?->name ?? $ticket->creator?->name ?? __('app-development.tickets.unassigned') }}</td>
                            <td>
                                <div class="tk-actions">
                                    <a class="tk-act tk-act--view" href="{{ route('app-development.tickets.show', $ticket) }}" data-app-dev-modal>
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <span>{{ __('app-development.tickets.view') }}</span>
                                    </a>
                                    <a class="tk-act tk-act--up" href="{{ route('app-development.tickets.tasks.create', $ticket) }}" data-app-dev-modal>
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
                                        <span>{{ __('app-development.tasks.escalate') }}</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tk-empty">{{ __('app-development.tickets.empty') }}</td>
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
