<div data-modal-title="{{ __('app-development.nav.new_task') }}" data-modal-variant="form" class="space-y-3">
    <form method="post" action="{{ route('app-development.tasks.store') }}" class="space-y-3">
        @csrf
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tickets.title_col') }} *</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="255" class="kaman-input w-full">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tickets.description') }}</label>
            <textarea name="description" rows="4" class="kaman-input w-full">{{ old('description') }}</textarea>
        </div>
        @include('app-development.partials.search-pick', [
            'name' => 'restaurant_name',
            'label' => __('app-development.tickets.restaurant'),
            'kind' => 'restaurant',
            'placeholder' => __('app-development.tickets.restaurant_search'),
        ])
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('app-development.tasks.assignee') }}</label>
            <select name="assignee_id" class="kaman-input w-full">
                <option value="">{{ __('app-development.tickets.unassigned') }}</option>
                @foreach($developers as $developer)
                    <option value="{{ $developer->id }}" @selected((string) old('assignee_id') === (string) $developer->id)>{{ $developer->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex justify-end gap-2">
            <a href="{{ route('app-development.tasks.index') }}" class="kaman-button-ghost" data-app-dev-modal-close>{{ __('app-development.cancel') }}</a>
            <button type="submit" class="kaman-button">{{ __('app-development.nav.new_task') }}</button>
        </div>
    </form>
</div>
