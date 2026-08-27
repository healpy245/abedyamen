<div data-modal-title="{{ __('app-development.tickets.edit') }} · {{ $ticket->ticket_number }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ route('app-development.tickets.update', $ticket) }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        @method('PUT')

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.title_col') }} *</label>
            <input type="text" name="title" value="{{ old('title', $ticket->title) }}" required maxlength="255" class="kaman-input w-full">
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.type') }} *</label>
                <select name="type" required class="kaman-input w-full">
                    @foreach(\App\Enums\AppDevelopmentTicketType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $ticket->type->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.priority') }} *</label>
                <select name="priority" required class="kaman-input w-full">
                    @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority', $ticket->priority->value) === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.app_types') }} *</label>
            @include('app-development.partials.app-type-picker', [
                'selected' => old('app_types', collect($ticket->appTypes())->map->value->all()),
            ])
            @error('app_types')
                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.description') }} *</label>
            <textarea name="description" required rows="4" class="kaman-input w-full">{{ old('description', $ticket->description) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.attachments') }}</label>
            <input type="file"
                   name="attachments[]"
                   multiple
                   class="kaman-input w-full"
                   accept="{{ \App\Support\AppDevelopment\TicketMedia::acceptAttribute() }}">
            <p class="mt-1 text-[11px] text-[#a78a6c]">{{ __('app-development.tickets.attachments_hint') }}</p>
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ route('app-development.tickets.show', $ticket) }}" class="kaman-button-ghost" data-app-dev-modal>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.cancel') }}
            </a>
            <button type="submit" class="kaman-button">
                @include('app-development.partials.icon', ['name' => 'save'])
                {{ __('app-development.save') }}
            </button>
        </div>
    </form>
</div>
