@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.title'))

@section('app')
    @php
        $user = auth()->user();
        $counts = $statusCounts ?? [];
        $appTypeCounts = $appTypeCounts ?? [];
        $selectedAppTypes = $selectedAppTypes ?? [];
        $chips = [
            ['all', null, null, 'layers'],
            ['open', 'open', $counts['open'] ?? 0, 'inbox'],
            ['working', 'working', $counts['working'] ?? 0, 'wrench'],
            ['qa', 'qa', $counts['qa'] ?? 0, 'clipboard'],
            ['completed', 'completed', $counts['completed'] ?? 0, 'check-circle'],
        ];
        $notifyBells = [
            'open' => ['title' => __('app-development.whatsapp.notify_open')],
            'qa' => ['title' => __('app-development.whatsapp.notify_qa')],
        ];
        $filterQuery = request()->except('page', 'q');
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex flex-wrap items-start gap-1.5" data-app-dev-status-filters data-current-tab="{{ $tab }}">
            @foreach($chips as [$label, $tabKey, $count, $icon])
                <div class="inline-flex flex-col items-center gap-1">
                    <a href="{{ route('app-development.index', array_filter(['tab' => $tabKey] + request()->except('tab', 'page', 'q'))) }}"
                       class="kaman-filter-chip kaman-filter-chip--{{ $label }} {{ $tab === ($tabKey ?? 'all') ? 'is-active' : '' }}"
                       @if($tabKey) data-status-tab="{{ $tabKey }}" @endif>
                        @include('app-development.partials.icon', ['name' => $icon])
                        {{ __('app-development.tabs.'.$label) }}
                        @if($count !== null)
                            <span data-status-count="{{ $tabKey }}">{{ $count }}</span>
                        @endif
                    </a>
                    @if(isset($notifyBells[$label]))
                        <button type="button"
                                class="kaman-filter-chip-bell"
                                data-notify-open="{{ $label }}"
                                title="{{ $notifyBells[$label]['title'] }}"
                                aria-label="{{ $notifyBells[$label]['title'] }}">
                            @include('app-development.partials.icon', ['name' => 'bell'])
                        </button>
                    @endif
                </div>
            @endforeach
        </div>

        <form method="get" class="shrink-0">
            @if($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            @foreach($selectedAppTypes as $appType)
                <input type="hidden" name="app_type[]" value="{{ $appType }}">
            @endforeach
            <select name="priority" class="kaman-input kaman-input--sm w-32" onchange="this.form.submit()">
                <option value="">{{ __('app-development.tickets.priority') }}</option>
                @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5" data-app-type-filters>
        <span class="text-xs font-semibold text-[#7c6a56]">{{ __('app-development.tickets.app_types') }}:</span>
        @foreach(\App\Enums\AppDevelopmentAppType::cases() as $appType)
            @php
                $isActive = in_array($appType->value, $selectedAppTypes, true);
                $next = $isActive
                    ? array_values(array_diff($selectedAppTypes, [$appType->value]))
                    : array_values(array_unique([...$selectedAppTypes, $appType->value]));
                $params = request()->except('page', 'app_type');
                if ($next !== []) {
                    $params['app_type'] = $next;
                }
            @endphp
            <a href="{{ route('app-development.index', $params) }}"
               class="kaman-filter-chip kaman-filter-chip--app-type {{ $isActive ? 'is-active' : '' }}">
                @include('app-development.partials.icon', ['name' => $appType->icon()])
                {{ $appType->label() }}
                <span data-app-type-count="{{ $appType->value }}">{{ $appTypeCounts[$appType->value] ?? 0 }}</span>
            </a>
        @endforeach
        @if($selectedAppTypes !== [])
            <a href="{{ route('app-development.index', request()->except('page', 'app_type')) }}" class="text-xs text-[#c45c26] underline">
                {{ __('app-development.tickets.clear_app_types') }}
            </a>
        @endif
    </div>

    <div class="kaman-card app-dev-board">
        <div class="hidden md:block">
            <table class="kaman-table kaman-table--tickets">
                <thead>
                    <tr>
                        <th>{{ __('app-development.tickets.number') }}</th>
                        <th>{{ __('app-development.tickets.title_col') }}</th>
                        <th>{{ __('app-development.tickets.created_by') }}</th>
                        <th>{{ __('app-development.tickets.app_types') }}</th>
                        <th>{{ __('app-development.tickets.priority') }}</th>
                        <th>{{ __('app-development.tickets.status') }}</th>
                        <th>{{ __('app-development.tickets.created_at') }}</th>
                        <th>{{ __('app-development.tickets.comments') }}</th>
                        <th>{{ __('app-development.tickets.options') }}</th>
                    </tr>
                </thead>
                <tbody data-app-dev-ticket-tbody>
                    @forelse($tickets as $ticket)
                        <tr class="{{ $ticket->priority === \App\Enums\AppDevelopmentTicketPriority::Critical ? 'is-critical' : '' }}"
                            data-ticket-row
                            data-ticket-id="{{ $ticket->id }}"
                            data-ticket-status="{{ $ticket->status->value }}">
                            <td class="kaman-table__num">{{ $ticket->ticket_number }}</td>
                            <td>
                                <a href="{{ route('app-development.tickets.show', $ticket) }}" class="kaman-table__title" data-app-dev-modal>{{ $ticket->title }}</a>
                            </td>
                            <td>
                                <div class="kaman-table__person">
                                    <strong>{{ $ticket->creator?->name ?? '—' }}</strong>
                                </div>
                            </td>
                            <td>
                                @include('app-development.partials.inline-app-types', [
                                    'ticket' => $ticket,
                                    'editable' => auth()->user()->can('changeAppTypes', $ticket),
                                ])
                            </td>
                            <td>
                                @include('app-development.partials.inline-field', [
                                    'ticket' => $ticket,
                                    'field' => 'priority',
                                    'editable' => auth()->user()->can('changePriority', $ticket),
                                ])
                            </td>
                            <td>
                                @include('app-development.partials.inline-field', [
                                    'ticket' => $ticket,
                                    'field' => 'status',
                                    'editable' => auth()->user()->can('changeStatus', $ticket),
                                ])
                            </td>
                            <td class="kaman-table__date">{{ $ticket->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <span class="kaman-table__comments">{{ $ticket->comments_count }}</span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('app-development.tickets.show', $ticket) }}"
                                       class="kaman-table__view"
                                       data-app-dev-modal
                                       aria-label="{{ __('app-development.tickets.view') }}">
                                        @include('app-development.partials.icon', ['name' => 'eye'])
                                    </a>
                                    @can('delete', $ticket)
                                        <form method="post"
                                              action="{{ route('app-development.tickets.destroy', $ticket) }}"
                                              class="inline"
                                              data-app-dev-confirm
                                              data-confirm-title="{{ __('app-development.tickets.delete_title') }}"
                                              data-confirm-message="{{ __('app-development.tickets.delete_confirm') }}"
                                              data-confirm-action="{{ __('app-development.tickets.delete') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="kaman-table__view text-red-700 hover:text-red-800"
                                                    aria-label="{{ __('app.delete') }}"
                                                    title="{{ __('app.delete') }}">
                                                @include('app-development.partials.icon', ['name' => 'trash'])
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr data-app-dev-empty-row>
                            <td colspan="9" class="kaman-table__empty">{{ __('app-development.tickets.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="space-y-2 p-3 md:hidden" data-app-dev-ticket-mobile>
            @forelse($tickets as $ticket)
                @include('app-development.partials.ticket-row', ['ticket' => $ticket])
            @empty
                <p class="py-6 text-center text-sm text-[#7c6a56]" data-app-dev-empty-mobile>{{ __('app-development.tickets.empty') }}</p>
            @endforelse
        </div>
    </div>

    @if($tickets->hasPages())
        <div class="pt-1">{{ $tickets->links() }}</div>
    @endif

    @include('app-development.partials.notify-picker', [
        'audience' => 'open',
        'action' => route('app-development.notify.open'),
        'title' => __('app-development.whatsapp.notify_open'),
        'hint' => __('app-development.whatsapp.pick_developers'),
        'members' => $notifyDevelopers ?? collect(),
    ])
    @include('app-development.partials.notify-picker', [
        'audience' => 'qa',
        'action' => route('app-development.notify.qa'),
        'title' => __('app-development.whatsapp.notify_qa'),
        'hint' => __('app-development.whatsapp.pick_testers'),
        'members' => $notifyTesters ?? collect(),
    ])
@endsection
