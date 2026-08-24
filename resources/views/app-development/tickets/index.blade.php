@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.title'))

@section('app')
    @php
        $user = auth()->user();
        $tabs = ['all', 'open', 'working', 'qa', 'completed'];
        if ($user->isDeveloper()) {
            $tabs[] = 'mine';
            $tabs[] = 'returned';
        }
        if ($user->isQa()) {
            $tabs[] = 'waiting-qa';
        }
    @endphp

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <h1 class="text-xl font-semibold text-[#2b1e11]">{{ __('app-development.tickets.title') }}</h1>
    </div>

    <div class="flex flex-wrap items-center gap-1">
        @foreach($tabs as $tabKey)
            <a href="{{ route('app-development.tickets.index', array_filter(['tab' => $tabKey === 'all' ? null : $tabKey] + request()->except('tab', 'page'))) }}"
               class="kaman-nav-link"
               @if($tab === $tabKey || ($tab === 'all' && $tabKey === 'all')) aria-current="page" @endif>
                {{ __('app-development.tabs.'.$tabKey) }}
            </a>
        @endforeach
    </div>

    <form method="get" class="kaman-card kaman-card--compact grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6">
        @if($tab !== 'all')
            <input type="hidden" name="tab" value="{{ $tab }}">
        @endif
        <input type="search" name="q" value="{{ $search }}" class="kaman-input sm:col-span-2" placeholder="{{ __('app-development.tickets.search') }}">
        <select name="type" class="kaman-input">
            <option value="">{{ __('app-development.tickets.type') }}</option>
            @foreach(\App\Enums\AppDevelopmentTicketType::cases() as $type)
                <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
        <select name="priority" class="kaman-input">
            <option value="">{{ __('app-development.tickets.priority') }}</option>
            @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ $priority->label() }}</option>
            @endforeach
        </select>
        <select name="created_by" class="kaman-input">
            <option value="">{{ __('app-development.tickets.created_by') }}</option>
            @foreach($creators as $creator)
                <option value="{{ $creator->id }}" @selected((string) request('created_by') === (string) $creator->id)>{{ $creator->name }}</option>
            @endforeach
        </select>
        <select name="assigned_to" class="kaman-input">
            <option value="">{{ __('app-development.tickets.assigned') }}</option>
            @foreach($developers as $developer)
                <option value="{{ $developer->id }}" @selected((string) request('assigned_to') === (string) $developer->id)>{{ $developer->name }}</option>
            @endforeach
        </select>
        <div class="sm:col-span-2 lg:col-span-6">
            <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tickets.filter') }}</button>
        </div>
    </form>

    <div class="kaman-card overflow-hidden">
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-sm">
                <thead class="bg-[#fffaf3] text-left text-xs uppercase tracking-wider text-[#a78a6c]">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.number') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.title_col') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.type') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.priority') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.status') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.created_by') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.assigned') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.updated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr class="border-t border-[#f1dfc5]/70 {{ $ticket->priority === \App\Enums\AppDevelopmentTicketPriority::Critical ? 'bg-red-50/50' : '' }}">
                            <td class="px-4 py-2.5 font-semibold text-[#f16229] whitespace-nowrap">
                                <a href="{{ route('app-development.tickets.show', $ticket) }}">{{ $ticket->ticket_number }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-[#2b1e11]">
                                <a href="{{ route('app-development.tickets.show', $ticket) }}" class="font-medium hover:text-[#f16229]">{{ $ticket->title }}</a>
                            </td>
                            <td class="px-4 py-2.5">@include('app-development.partials.type-badge', ['type' => $ticket->type])</td>
                            <td class="px-4 py-2.5">@include('app-development.partials.priority-badge', ['priority' => $ticket->priority])</td>
                            <td class="px-4 py-2.5">@include('app-development.partials.status-badge', ['status' => $ticket->status])</td>
                            <td class="px-4 py-2.5 text-[#7c6a56] whitespace-nowrap">{{ $ticket->creator?->name }}</td>
                            <td class="px-4 py-2.5 text-[#7c6a56] whitespace-nowrap">{{ $ticket->assignedDeveloper?->name ?? __('app-development.tickets.unassigned') }}</td>
                            <td class="px-4 py-2.5 text-[#7c6a56] whitespace-nowrap">{{ $ticket->updated_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-[#7c6a56]">
                                {{ $tab === 'qa' || $tab === 'waiting-qa' ? __('app-development.tickets.empty_qa') : ($tab === 'open' ? __('app-development.tickets.empty_open') : __('app-development.tickets.empty')) }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="space-y-2 p-3 md:hidden">
            @forelse($tickets as $ticket)
                @include('app-development.partials.ticket-row', [
                    'ticket' => $ticket,
                    'meta' => ($ticket->assignedDeveloper?->name ?? __('app-development.tickets.unassigned')).' · '.$ticket->updated_at?->format('d/m/Y'),
                ])
            @empty
                <p class="py-6 text-center text-sm text-[#7c6a56]">{{ __('app-development.tickets.empty') }}</p>
            @endforelse
        </div>
    </div>

    @if($tickets->hasPages())
        <div class="pt-1">{{ $tickets->links() }}</div>
    @endif
@endsection
