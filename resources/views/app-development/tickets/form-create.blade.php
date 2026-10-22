<div data-modal-title="{{ __('app-development.tickets.create') }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ route('app-development.tickets.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tickets.title_col') }} *</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="255" class="kaman-input w-full">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tickets.description') }} *</label>
            <textarea name="description" rows="4" class="kaman-input w-full">{{ old('description') }}</textarea>
        </div>
        @include('app-development.partials.search-pick', [
            'name' => 'restaurant_name',
            'label' => __('app-development.tickets.restaurant'),
            'kind' => 'restaurant',
            'placeholder' => __('app-development.tickets.restaurant_search'),
        ])
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tickets.attachments') }}</label>
            <input type="file" name="attachments[]" multiple class="kaman-input w-full" accept="{{ \App\Support\AppDevelopment\TicketMedia::acceptAttribute() }}">
            <p class="mt-1 text-[11px] opacity-70">{{ __('app-development.tickets.attachments_hint') }}</p>
        </div>
        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ route('app-development.tickets.index') }}" class="kaman-button-ghost" data-app-dev-modal-close>{{ __('app-development.cancel') }}</a>
            <button type="submit" class="kaman-button">{{ __('app-development.tickets.create') }}</button>
        </div>
    </form>
</div>
