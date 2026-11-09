@php
    $commentAttachmentIds = $ticket->comments
        ->pluck('attachment_id')
        ->filter()
        ->map(fn ($id): int => (int) $id)
        ->all();
    $files = $ticket->attachments->reject(
        fn ($attachment): bool => in_array((int) $attachment->id, $commentAttachmentIds, true),
    );
    $tone = $ticket->isCompleted()
        ? null
        : ($ticket->status === \App\Enums\AppDevelopmentTicketStatus::Qa ? 'amber' : 'green');
@endphp

<div data-modal-title="{{ $ticket->ticket_number }}" data-modal-variant="view" class="dv-ticket">
    <header class="dv-ticket__head">
        <h3>{{ $ticket->title }}</h3>
        <div class="dv-ticket__meta">
            <x-ds.badge plain :tone="$tone">{{ $ticket->status?->label() }}</x-ds.badge>
            @if(filled($ticket->restaurant_name))
                <span>{{ $ticket->restaurant_name }}</span>
            @endif
            <span><bdi dir="ltr">{{ $ticket->created_at?->format('d/m/Y') }}</bdi></span>
        </div>
    </header>

    @if($ticket->isReturnedFromQa() && $ticket->latestQaRejection)
        <p class="dv-ticket__alert">“{{ $ticket->latestQaRejection->note() }}”</p>
    @endif

    @if(filled($ticket->description))
        <p class="dv-ticket__desc">{{ $ticket->description }}</p>
    @endif

    @can('update', $ticket)
        <section class="dv-ticket__section">
            <p class="ds-section-title">{{ __('app-development.tickets.status') }}</p>
            <div class="dv-ticket__grid">
                <form method="post" action="{{ route('app-development.tickets.status', $ticket) }}" class="ds-field">
                    @csrf
                    @method('PATCH')
                    <label class="ds-label" for="ticket-status">{{ __('app-development.tickets.status') }}</label>
                    <select id="ticket-status" name="status" data-ds-select onchange="this.form.submit()">
                        @foreach(\App\Enums\AppDevelopmentTicketStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </form>
                <form method="post" action="{{ route('app-development.tickets.restaurant', $ticket) }}" class="ds-field">
                    @csrf
                    @method('PATCH')
                    <label class="ds-label" for="ticket-restaurant">{{ __('app-development.tickets.restaurant') }}</label>
                    <div class="dv-ticket__inline">
                        <input id="ticket-restaurant" type="text" name="restaurant_name" value="{{ $ticket->restaurant_name }}" maxlength="191" class="ds-input">
                        <button type="submit" class="ds-btn ds-btn--secondary">{{ __('app.save') }}</button>
                    </div>
                </form>
            </div>
        </section>
    @endcan

    @if($files->isNotEmpty() || auth()->user()?->can('uploadAttachment', $ticket))
        <section class="dv-ticket__section">
            <p class="ds-section-title">{{ __('app-development.tickets.attachments') }}</p>
            @if($files->isNotEmpty())
                <div class="dv-ticket__files">
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
                </div>
            @endif
            @can('uploadAttachment', $ticket)
                <form method="post" action="{{ route('app-development.tickets.attachments.store', $ticket) }}" enctype="multipart/form-data" class="dv-ticket__inline">
                    @csrf
                    <input type="file" name="attachments[]" multiple class="kaman-input" accept="{{ \App\Support\AppDevelopment\TicketMedia::acceptAttribute() }}">
                    <button type="submit" class="ds-btn ds-btn--secondary">{{ __('app-development.tickets.upload') }}</button>
                </form>
            @endcan
        </section>
    @endif

    @can('create', \App\Models\AppDevelopment\AppDevelopmentTask::class)
        <footer class="dv-ticket__foot">
            <a href="{{ route('app-development.tickets.tasks.create', $ticket) }}" class="ds-btn ds-btn--primary" data-app-dev-modal>
                {{ __('app-development.tasks.escalate') }}
            </a>
        </footer>
    @endcan
</div>
