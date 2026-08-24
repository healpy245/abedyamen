@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.create'))

@section('app')
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-[#2b1e11]">{{ __('app-development.tickets.create') }}</h1>
        <a href="{{ route('app-development.tickets.index') }}" class="text-sm text-[#a78a6c] hover:text-[#f16229]">{{ __('app-development.back') }}</a>
    </div>

    <form method="post" action="{{ route('app-development.tickets.store') }}" enctype="multipart/form-data" class="kaman-card kaman-card--pad space-y-4">
        @csrf

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.title_col') }} *</label>
            <input type="text" name="title" value="{{ old('title') }}" required maxlength="255" class="kaman-input w-full">
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.type') }} *</label>
                <select name="type" required class="kaman-input w-full">
                    @foreach(\App\Enums\AppDevelopmentTicketType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
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
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.description') }} *</label>
            <textarea name="description" required rows="8" class="kaman-input w-full" placeholder="{{ __('app-development.tickets.current_behavior') }}">{{ old('description') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.suggested_developer') }}</label>
            <select name="assigned_to" class="kaman-input w-full">
                <option value="">{{ __('app-development.tickets.none') }}</option>
                @foreach($developers as $developer)
                    <option value="{{ $developer->id }}" @selected((string) old('assigned_to') === (string) $developer->id)>{{ $developer->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.attachments') }}</label>
            <input type="file" name="attachments[]" multiple class="kaman-input w-full" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.pdf,.txt,.log,.zip">
            <p class="mt-1 text-xs text-[#a78a6c]">jpg, png, webp, mp4, mov, pdf, txt, log, zip</p>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="kaman-button">{{ __('app-development.tickets.create') }}</button>
        </div>
    </form>
@endsection
