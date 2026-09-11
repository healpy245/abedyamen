@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
@endphp

<div data-modal-title="{{ __('app-development.tasks.escalate') }} · {{ $ticket->ticket_number }}"
     data-modal-variant="form"
     class="space-y-3">
    <form method="post" action="{{ route('app-development.tickets.tasks.store', $ticket) }}" class="space-y-3">
        @csrf

        <div class="rounded-xl border border-[#f1dfc5] bg-[#fffaf3] p-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-[#a78a6c]">
                {{ __('app-development.tasks.escalating_ticket') }}
            </p>
            <h3 class="mt-1 text-base font-semibold text-[#2b1e11]">{{ $ticket->title }}</h3>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @include('app-development.partials.priority-badge', ['priority' => $ticket->priority])
                @include('app-development.partials.status-badge', ['status' => $ticket->status])
            </div>
            @if(filled($ticket->description))
                <p class="mt-2 max-h-28 overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-[#2b1e11]">{{ $ticket->description }}</p>
            @endif
            <p class="mt-2 text-[11px] text-[#7c6a56]">{{ __('app-development.tasks.escalate_hint') }}</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.assignee') }} *</label>
            <select name="assignee_id" required class="kaman-input w-full">
                <option value="">{{ __('app-development.tasks.pick_assignee') }}</option>
                @foreach($developers as $developer)
                    <option value="{{ $developer->id }}" @selected((string) old('assignee_id') === (string) $developer->id)>
                        {{ $developer->name }}
                    </option>
                @endforeach
            </select>
            @error('assignee_id')
                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ route('app-development.tickets.show', $ticket) }}"
               class="kaman-button-ghost"
               data-app-dev-modal>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.cancel') }}
            </a>
            <button type="submit" class="kaman-button">
                @include('app-development.partials.icon', ['name' => 'send'])
                {{ __('app-development.tasks.escalate') }}
            </button>
        </div>
    </form>
</div>
