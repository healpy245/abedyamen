@extends('app-development.layouts.project')

@section('title', __('app-development.nav.qa_queue'))

@section('app')
    <h1 class="text-xl font-semibold text-[#2b1e11]">{{ __('app-development.nav.qa_queue') }}</h1>

    <div class="kaman-card overflow-hidden">
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-sm">
                <thead class="bg-[#fffaf3] text-left text-xs uppercase tracking-wider text-[#a78a6c]">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.number') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.title_col') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.priority') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.assigned') }}</th>
                        <th class="px-4 py-2.5 font-semibold">{{ __('app-development.tickets.updated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr class="border-t border-[#f1dfc5]/70 {{ $ticket->priority === \App\Enums\AppDevelopmentTicketPriority::Critical ? 'bg-red-50/50' : '' }}">
                            <td class="px-4 py-2.5 font-semibold text-[#f16229]"><a href="{{ route('app-development.tickets.show', $ticket) }}">{{ $ticket->ticket_number }}</a></td>
                            <td class="px-4 py-2.5"><a href="{{ route('app-development.tickets.show', $ticket) }}" class="font-medium">{{ $ticket->title }}</a></td>
                            <td class="px-4 py-2.5">@include('app-development.partials.priority-badge', ['priority' => $ticket->priority])</td>
                            <td class="px-4 py-2.5 text-[#7c6a56]">{{ $ticket->assignedDeveloper?->name ?? __('app-development.tickets.unassigned') }}</td>
                            <td class="px-4 py-2.5 text-[#7c6a56]">{{ $ticket->submitted_for_qa_at?->format('d/m/Y H:i') ?? $ticket->updated_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-[#7c6a56]">{{ __('app-development.tickets.empty_qa') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-2 p-3 md:hidden">
            @forelse($tickets as $ticket)
                @include('app-development.partials.ticket-row', ['ticket' => $ticket, 'meta' => $ticket->assignedDeveloper?->name])
            @empty
                <p class="py-6 text-center text-sm text-[#7c6a56]">{{ __('app-development.tickets.empty_qa') }}</p>
            @endforelse
        </div>
    </div>

    @if($tickets->hasPages())
        <div>{{ $tickets->links() }}</div>
    @endif
@endsection
