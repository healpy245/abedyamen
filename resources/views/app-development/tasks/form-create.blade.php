@php
    $ticket = $ticket ?? null;
    $action = $ticket
        ? route('app-development.tickets.tasks.store', $ticket)
        : route('app-development.tasks.store');
@endphp

<div data-modal-title="{{ __('app-development.tasks.create') }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ $action }}" class="space-y-3">
        @csrf

        @if($ticket)
            <p class="text-xs text-[#7c6a56]">
                {{ __('app-development.tasks.for_ticket') }}:
                <strong class="text-[#2b1e11]">{{ $ticket->ticket_number }} — {{ $ticket->title }}</strong>
            </p>
        @else
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.ticket') }} *</label>
                <select name="ticket_id" required class="kaman-input w-full">
                    <option value="">{{ __('app-development.tasks.pick_ticket') }}</option>
                    @foreach($tickets as $option)
                        <option value="{{ $option->id }}" @selected((string) old('ticket_id') === (string) $option->id)>
                            {{ $option->ticket_number }} — {{ $option->title }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.title_label') }} *</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="255" class="kaman-input w-full">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.description') }}</label>
            <textarea name="description" rows="3" class="kaman-input w-full">{{ old('description') }}</textarea>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.assignee') }}</label>
                <select name="assignee_id" class="kaman-input w-full">
                    <option value="">{{ __('app-development.tickets.none') }}</option>
                    @foreach($developers as $developer)
                        <option value="{{ $developer->id }}" @selected((string) old('assignee_id') === (string) $developer->id)>{{ $developer->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.priority') }} *</label>
                <select name="priority" required class="kaman-input w-full">
                    @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority', 'normal') === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tasks.due_at') }}</label>
            <input type="datetime-local" name="due_at" value="{{ old('due_at') }}" class="kaman-input w-full">
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ $ticket ? route('app-development.tickets.show', $ticket) : route('app-development.tasks.index') }}"
               class="kaman-button-ghost"
               data-app-dev-modal-close>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.cancel') }}
            </a>
            <button type="submit" class="kaman-button">
                @include('app-development.partials.icon', ['name' => 'plus'])
                {{ __('app-development.tasks.create') }}
            </button>
        </div>
    </form>
</div>
