@php
    /** @var \App\Models\AppDevelopment\AppDevelopmentTicket $ticket */
    $people = collect($developers)->map(static fn ($developer): array => [
        'value' => (string) $developer->id,
        'label' => (string) $developer->name,
    ])->values()->all();
@endphp

<div data-modal-title="{{ __('app-development.tasks.escalate') }} · {{ $ticket->ticket_number }}"
     data-modal-variant="form"
     class="space-y-3">
    <form method="post" action="{{ route('app-development.tickets.tasks.store', $ticket) }}" class="space-y-3">
        @csrf

        <div class="dev-source">
            <p class="dev-source__kicker">{{ __('app-development.tasks.escalating_ticket') }}</p>
            <h3>{{ $ticket->title }}</h3>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @include('app-development.partials.priority-badge', ['priority' => $ticket->priority])
                @include('app-development.partials.status-badge', ['status' => $ticket->status])
            </div>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tasks.description') }}</label>
            <textarea name="description" rows="5" class="kaman-input w-full">{{ old('description', $ticket->description) }}</textarea>
            <p class="mt-1 text-[11px] opacity-70">{{ __('app-development.tasks.escalate_hint') }}</p>
        </div>

        @include('app-development.partials.search-pick', [
            'name' => 'assignee_id',
            'label' => __('app-development.tasks.assignee'),
            'kind' => 'person',
            'required' => true,
            'placeholder' => __('app-development.tasks.assignee_search'),
            'choices' => $people,
        ])
        @error('assignee_id')
            <p class="text-xs text-red-700">{{ $message }}</p>
        @enderror

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
