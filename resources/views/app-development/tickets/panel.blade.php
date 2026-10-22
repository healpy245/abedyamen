@php
    $commentAttachmentIds = $ticket->comments
        ->pluck('attachment_id')
        ->filter()
        ->map(fn ($id): int => (int) $id)
        ->all();
    $files = $ticket->attachments->reject(
        fn ($attachment): bool => in_array((int) $attachment->id, $commentAttachmentIds, true),
    );
@endphp

<div data-modal-title="{{ $ticket->ticket_number }}" data-modal-variant="view" class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold">{{ $ticket->title }}</h3>
        <p class="mt-1 text-xs opacity-70">{{ $ticket->ticket_number }}</p>
    </div>

    @can('update', $ticket)
        <form method="post" action="{{ route('app-development.tickets.status', $ticket) }}" class="flex items-center gap-2">
            @csrf
            @method('PATCH')
            <label class="text-sm font-medium" for="ticket-status">{{ __('app-development.tickets.status') }}</label>
            <select id="ticket-status" name="status" class="kaman-input" onchange="this.form.submit()">
                @foreach(\App\Enums\AppDevelopmentTicketStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </form>
        <form method="post" action="{{ route('app-development.tickets.restaurant', $ticket) }}" class="flex items-end gap-2">
            @csrf
            @method('PATCH')
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-sm font-medium" for="ticket-restaurant">{{ __('app-development.tickets.restaurant') }}</label>
                <input id="ticket-restaurant" type="text" name="restaurant_name" value="{{ $ticket->restaurant_name }}" maxlength="191" class="kaman-input w-full">
            </div>
            <button type="submit" class="kaman-button-ghost">{{ __('app.save') }}</button>
        </form>
    @else
        <p class="text-sm">{{ $ticket->status?->label() }}</p>
        @if(filled($ticket->restaurant_name))
            <p class="text-sm">{{ $ticket->restaurant_name }}</p>
        @endif
    @endcan

    @if($ticket->isReturnedFromQa() && $ticket->latestQaRejection)
        <p class="whitespace-pre-wrap text-sm leading-relaxed">“{{ $ticket->latestQaRejection->note() }}”</p>
    @endif

    <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ $ticket->description }}</p>

    <div class="space-y-2">
        @foreach($files as $attachment)
            @php $url = route('app-development.tickets.attachments.download', [$ticket, $attachment]); @endphp
            @if($attachment->isVideo() || $attachment->isImage())
                <button type="button" class="kaman-attachment" data-app-dev-media data-media-type="{{ $attachment->isVideo() ? 'video' : 'image' }}" data-media-url="{{ $url }}" data-media-name="{{ $attachment->original_name }}">
                    <span class="kaman-attachment__meta"><strong>{{ $attachment->original_name }}</strong></span>
                </button>
            @else
                <a href="{{ $url }}" class="kaman-attachment" target="_blank" rel="noopener">{{ $attachment->original_name }}</a>
            @endif
        @endforeach
        @can('uploadAttachment', $ticket)
            <form method="post" action="{{ route('app-development.tickets.attachments.store', $ticket) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <input type="file" name="attachments[]" multiple class="kaman-input min-w-0 flex-1" accept="{{ \App\Support\AppDevelopment\TicketMedia::acceptAttribute() }}">
                <button type="submit" class="kaman-button-ghost">{{ __('app-development.tickets.upload') }}</button>
            </form>
        @endcan
    </div>

    @can('create', \App\Models\AppDevelopment\AppDevelopmentTask::class)
        <a href="{{ route('app-development.tickets.tasks.create', $ticket) }}" class="kaman-button" data-app-dev-modal>
            {{ __('app-development.tasks.escalate') }}
        </a>
    @endcan
</div>
