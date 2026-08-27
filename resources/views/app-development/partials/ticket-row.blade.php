@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
    $critical = $ticket->priority === \App\Enums\AppDevelopmentTicketPriority::Critical;
    $creator = $ticket->relationLoaded('creator') ? $ticket->creator : null;
    $commentCount = $ticket->comments_count ?? null;
    $canPriority = auth()->user()?->can('changePriority', $ticket) ?? false;
    $canStatus = auth()->user()?->can('changeStatus', $ticket) ?? false;
    $canAppTypes = auth()->user()?->can('changeAppTypes', $ticket) ?? false;
@endphp
<div class="rounded-xl border px-3 py-2.5 transition-colors duration-200 {{ $critical ? 'border-red-200 bg-red-50/40 is-critical' : 'border-[#f1dfc5] bg-white' }}"
     data-ticket-row
     data-ticket-id="{{ $ticket->id }}"
     data-ticket-status="{{ $ticket->status->value }}">
    <div class="flex items-start justify-between gap-2">
        <a href="{{ route('app-development.tickets.show', $ticket) }}"
           data-app-dev-modal
           class="block min-w-0 flex-1">
            <p class="text-[11px] font-semibold text-[#a78a6c]">{{ $ticket->ticket_number }}</p>
            <p class="truncate text-sm font-semibold text-[#2b1e11]">{{ $ticket->title }}</p>
        </a>
        @can('delete', $ticket)
            <form method="post"
                  action="{{ route('app-development.tickets.destroy', $ticket) }}"
                  class="shrink-0"
                  data-app-dev-confirm
                  data-confirm-title="{{ __('app-development.tickets.delete_title') }}"
                  data-confirm-message="{{ __('app-development.tickets.delete_confirm') }}"
                  data-confirm-action="{{ __('app-development.tickets.delete') }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="kaman-button-ghost kaman-button--sm text-red-700"
                        aria-label="{{ __('app.delete') }}">
                    @include('app-development.partials.icon', ['name' => 'trash'])
                </button>
            </form>
        @endcan
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @include('app-development.partials.inline-app-types', [
            'ticket' => $ticket,
            'editable' => $canAppTypes,
        ])
        @include('app-development.partials.inline-field', [
            'ticket' => $ticket,
            'field' => 'priority',
            'editable' => $canPriority,
        ])
        @include('app-development.partials.inline-field', [
            'ticket' => $ticket,
            'field' => 'status',
            'editable' => $canStatus,
        ])
        @if($commentCount !== null)
            <span class="kaman-table__comments">{{ $commentCount }}</span>
        @endif
    </div>

    @if($creator)
        <p class="mt-1.5 truncate text-xs font-medium text-[#2b1e11]">{{ $creator->name }}</p>
    @endif

    @if(! empty($meta))
        <p class="mt-1 truncate text-xs text-[#7c6a56]">{{ $meta }}</p>
    @endif

    <p class="mt-0.5 text-[11px] text-[#a78a6c]">{{ $ticket->created_at?->format('Y-m-d H:i') }}</p>
</div>
