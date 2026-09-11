@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
    $field = $field ?? 'priority';
    $editable = $editable ?? false;
    $isPriority = $field === 'priority';
    $current = $isPriority ? $ticket->priority : $ticket->status;
    $options = $isPriority
        ? \App\Enums\AppDevelopmentTicketPriority::cases()
        : \App\Enums\AppDevelopmentTicketStatus::cases();
    $url = $isPriority
        ? route('app-development.tickets.priority', $ticket)
        : route('app-development.tickets.status', $ticket);
    $label = $isPriority
        ? __('app-development.tickets.change_priority')
        : __('app-development.tickets.change_status');
    $actor = $isPriority
        ? ($ticket->relationLoaded('priorityChangedBy') ? $ticket->priorityChangedBy : $ticket->priorityChangedBy()->first())
        : ($ticket->relationLoaded('statusChangedBy') ? $ticket->statusChangedBy : $ticket->statusChangedBy()->first());
    $actorName = $actor?->name;
@endphp

<div class="kaman-inline-wrap">
    @if(! $editable)
        @if($isPriority)
            @include('app-development.partials.priority-badge', ['priority' => $current])
        @else
            @include('app-development.partials.status-badge', ['status' => $current])
        @endif
    @else
        <div class="kaman-inline-field"
             data-kaman-inline
             data-field="{{ $field }}"
             data-url="{{ $url }}"
             data-value="{{ $current->value }}">
            <button type="button"
                    class="kaman-inline-field__trigger"
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    aria-label="{{ $label }}">
                <span class="kaman-inline-field__badge">
                    @if($isPriority)
                        @include('app-development.partials.priority-badge', ['priority' => $current])
                    @else
                        @include('app-development.partials.status-badge', ['status' => $current])
                    @endif
                </span>
                <span class="kaman-inline-field__caret" aria-hidden="true">
                    @include('app-development.partials.icon', ['name' => 'chevron-down'])
                </span>
            </button>
            <div class="kaman-inline-field__menu" role="listbox" hidden>
                @foreach($options as $option)
                    <button type="button"
                            class="kaman-inline-field__option {{ $option === $current ? 'is-selected' : '' }}"
                            role="option"
                            data-value="{{ $option->value }}"
                            aria-selected="{{ $option === $current ? 'true' : 'false' }}">
                        @if($isPriority)
                            @include('app-development.partials.priority-badge', ['priority' => $option])
                        @else
                            @include('app-development.partials.status-badge', ['status' => $option])
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif
    <span class="kaman-inline-actor" data-inline-actor @if(! $actorName) hidden @endif>{{ $actorName }}</span>
</div>

@once('kaman-inline-field-script')
    <script src="{{ asset('js/app-development-inline.js') }}?v={{ @filemtime(public_path('js/app-development-inline.js')) ?: time() }}" defer></script>
@endonce
