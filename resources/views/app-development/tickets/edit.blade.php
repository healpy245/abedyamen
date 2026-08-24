@extends('app-development.layouts.project')

@section('title', __('app-development.tickets.edit'))

@section('app')
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-[#2b1e11]">{{ __('app-development.tickets.edit') }} · {{ $ticket->ticket_number }}</h1>
        <a href="{{ route('app-development.tickets.show', $ticket) }}" class="text-sm text-[#a78a6c] hover:text-[#f16229]">{{ __('app-development.back') }}</a>
    </div>

    <form method="post" action="{{ route('app-development.tickets.update', $ticket) }}" class="kaman-card kaman-card--pad space-y-4">
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
            <label class="mb-1 block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.description') }} *</label>
            <textarea name="description" required rows="8" class="kaman-input w-full">{{ old('description', $ticket->description) }}</textarea>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('app-development.tickets.show', $ticket) }}" class="kaman-button-ghost">{{ __('app-development.cancel') }}</a>
            <button type="submit" class="kaman-button">{{ __('app-development.save') }}</button>
        </div>
    </form>
@endsection
