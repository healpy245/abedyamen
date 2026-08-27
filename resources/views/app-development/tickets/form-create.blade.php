<div data-modal-title="{{ __('app-development.tickets.create') }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ route('app-development.tickets.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.title_col') }} *</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="255" class="kaman-input w-full">
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.priority') }} *</label>
            <select name="priority" required class="kaman-input w-full">
                @foreach(\App\Enums\AppDevelopmentTicketPriority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected(old('priority', 'normal') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.app_types') }} *</label>
            @include('app-development.partials.app-type-picker', ['selected' => old('app_types', [])])
            @error('app_types')
                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.description') }} *</label>
            <div class="app-dev-comment-bar items-start">
                <textarea name="description"
                          rows="4"
                          class="kaman-input min-w-0 flex-1"
                          placeholder="{{ __('app-development.tickets.current_behavior') }}">{{ old('description') }}</textarea>
                @include('app-development.partials.voice-recorder', ['inputName' => 'voices[]'])
            </div>
            <p class="mt-1 text-[11px] text-[#a78a6c]">{{ __('app-development.tickets.description_or_voice_hint') }}</p>
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
            <a href="{{ route('app-development.tickets.index') }}" class="kaman-button-ghost" data-app-dev-modal-close>
                @include('app-development.partials.icon', ['name' => 'x'])
                {{ __('app-development.cancel') }}
            </a>
            <button type="submit" class="kaman-button">
                @include('app-development.partials.icon', ['name' => 'plus'])
                {{ __('app-development.tickets.create') }}
            </button>
        </div>
    </form>
</div>
