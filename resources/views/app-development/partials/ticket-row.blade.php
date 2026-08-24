@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
    $critical = $ticket->priority === \App\Enums\AppDevelopmentTicketPriority::Critical;
@endphp
<a href="{{ route('app-development.tickets.show', $ticket) }}"
   class="block rounded-xl border px-3 py-2.5 hover:border-[#f47a2e]/40 {{ $critical ? 'border-red-200 bg-red-50/40' : 'border-[#f1dfc5] bg-white' }}">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold text-[#a78a6c]">{{ $ticket->ticket_number }}</p>
            <p class="truncate text-sm font-semibold text-[#2b1e11]">{{ $ticket->title }}</p>
        </div>
        @include('app-development.partials.status-badge', ['status' => $ticket->status])
    </div>
    @if(! empty($meta))
        <p class="mt-1 truncate text-xs text-[#7c6a56]">{{ $meta }}</p>
    @endif
</a>
