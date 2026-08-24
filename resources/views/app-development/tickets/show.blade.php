@extends('app-development.layouts.project')

@section('title', $ticket->ticket_number.' — '.$ticket->title)

@section('app')
    @php
        $user = auth()->user();
        $rejection = $ticket->isReturnedFromQa() ? $ticket->latestQaRejection : null;
        $latestRelease = $ticket->releases->first();
    @endphp

    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-[#a78a6c]">{{ $ticket->ticket_number }}</p>
            <h1 class="text-xl sm:text-2xl font-semibold text-[#2b1e11]">{{ $ticket->title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                @include('app-development.partials.type-badge', ['type' => $ticket->type])
                @include('app-development.partials.priority-badge', ['priority' => $ticket->priority])
                @include('app-development.partials.status-badge', ['status' => $ticket->status])
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('update', $ticket)
                <a href="{{ route('app-development.tickets.edit', $ticket) }}" class="kaman-button-ghost kaman-button--sm">{{ __('app.edit') }}</a>
            @endcan
            <a href="{{ route('app-development.tickets.index') }}" class="text-sm text-[#a78a6c] hover:text-[#f16229]">{{ __('app-development.back') }}</a>
        </div>
    </div>

    @if($rejection)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-800">{{ __('app-development.tickets.returned_title') }}</p>
            <p class="mt-1 text-sm text-red-900">{{ __('app-development.tickets.latest_qa_note') }}:</p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-[#2b1e11]">“{{ $rejection->note() }}”</p>
            <p class="mt-2 text-xs text-red-800">
                {{ __('app-development.tickets.returned_by', ['name' => $rejection->user?->name ?? __('app-development.system')]) }}
                · {{ $rejection->created_at?->format('d/m/Y H:i') }}
            </p>
        </div>
    @endif

    <section class="kaman-card kaman-card--compact grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.created_by') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $ticket->creator?->name }}</p>
        </div>
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.created_at') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $ticket->created_at?->format('d/m/Y H:i') }}</p>
        </div>
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.assigned') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $ticket->assignedDeveloper?->name ?? __('app-development.tickets.unassigned') }}</p>
        </div>
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.last_updated') }}</p>
            <p class="font-medium text-[#2b1e11]">{{ $ticket->updated_at?->format('d/m/Y H:i') }}</p>
        </div>
        @if($ticket->completedBy)
            <div>
                <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.completed_by') }}</p>
                <p class="font-medium text-[#2b1e11]">{{ $ticket->completedBy->name }}</p>
            </div>
        @endif
        <div>
            <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.elapsed', ['time' => $ticket->elapsedLabel()]) }}</p>
            <p class="text-xs text-[#7c6a56]">{{ __('app-development.tickets.qa_attempts', ['count' => $ticket->qa_rejection_count]) }}</p>
        </div>
        @if($latestRelease)
            <div class="col-span-2">
                <p class="text-[11px] uppercase tracking-wider text-[#a78a6c]">{{ __('app-development.tickets.available_in') }}</p>
                <a href="{{ route('app-development.releases.show', $latestRelease) }}" class="font-medium text-[#f16229]">v{{ $latestRelease->version_name }}</a>
            </div>
        @endif
    </section>

    <section class="kaman-card kaman-card--pad">
        <h2 class="mb-2 text-sm font-semibold text-[#2b1e11]">{{ __('app-development.tickets.description') }}</h2>
        <div class="whitespace-pre-wrap text-sm leading-relaxed text-[#2b1e11]">{{ $ticket->description }}</div>
    </section>

    <section class="kaman-card kaman-card--compact space-y-3">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.tickets.attachments') }}</h2>
        @forelse($ticket->attachments as $attachment)
            <div class="rounded-xl border border-[#f1dfc5] p-3">
                @if($attachment->isImage())
                    <a href="{{ route('app-development.tickets.attachments.download', [$ticket, $attachment]) }}">
                        <img src="{{ route('app-development.tickets.attachments.download', [$ticket, $attachment]) }}" alt="{{ $attachment->original_name }}" class="max-h-64 rounded-lg object-contain">
                    </a>
                @elseif($attachment->isVideo())
                    <video controls class="max-h-64 w-full rounded-lg" src="{{ route('app-development.tickets.attachments.download', [$ticket, $attachment]) }}"></video>
                @endif
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="text-[#2b1e11]">{{ $attachment->original_name }} <span class="text-[#a78a6c]">({{ $attachment->humanSize() }})</span></span>
                    <a href="{{ route('app-development.tickets.attachments.download', [$ticket, $attachment]) }}" class="text-[#f16229] font-medium">{{ __('app-development.tickets.download') }}</a>
                </div>
            </div>
        @empty
            <p class="text-sm text-[#7c6a56]">{{ __('app-development.tickets.no_attachments') }}</p>
        @endforelse

        @can('uploadAttachment', $ticket)
            <form method="post" action="{{ route('app-development.tickets.attachments.store', $ticket) }}" enctype="multipart/form-data" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                @csrf
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-[#7c6a56]">{{ __('app-development.tickets.add_attachment') }}</label>
                    <input type="file" name="attachment" required class="kaman-input w-full" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.pdf,.txt,.log,.zip">
                </div>
                <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tickets.upload') }}</button>
            </form>
        @endcan
    </section>

    <section class="kaman-card kaman-card--compact space-y-3">
        @if($user->isDeveloper() && $ticket->isOpen())
            <form method="post" action="{{ route('app-development.tickets.start-work', $ticket) }}">
                @csrf
                <button type="submit" class="kaman-button">{{ __('app-development.tickets.start_working') }}</button>
            </form>
        @endif

        @can('submitForQa', $ticket)
            <form method="post" action="{{ route('app-development.tickets.send-to-qa', $ticket) }}" class="space-y-2">
                @csrf
                <label class="block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.developer_note') }}</label>
                <textarea name="note" rows="3" class="kaman-input w-full" placeholder="{{ __('app-development.tickets.developer_note_placeholder') }}">{{ old('note') }}</textarea>
                <button type="submit" class="kaman-button">{{ __('app-development.tickets.send_to_qa') }}</button>
            </form>
        @endcan

        @can('qaApprove', $ticket)
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <form method="post" action="{{ route('app-development.tickets.complete', $ticket) }}" class="flex-1 space-y-2">
                    @csrf
                    <label class="block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.qa_note_optional') }}</label>
                    <textarea name="note" rows="2" class="kaman-input w-full">{{ old('note') }}</textarea>
                    <button type="submit" class="kaman-button">{{ __('app-development.tickets.mark_completed') }}</button>
                </form>
                <form method="post" action="{{ route('app-development.tickets.return-to-development', $ticket) }}" class="flex-1 space-y-2">
                    @csrf
                    <label class="block text-sm font-medium text-[#2b1e11]">{{ __('app-development.tickets.qa_note_required') }} *</label>
                    <textarea name="note" required minlength="3" rows="2" class="kaman-input w-full" placeholder="{{ __('app-development.tickets.qa_note_placeholder') }}">{{ old('note') }}</textarea>
                    <button type="submit" class="kaman-button-danger">{{ __('app-development.tickets.return_to_developer') }}</button>
                </form>
            </div>
        @endcan

        @can('assign', $ticket)
            <form method="post" action="{{ route('app-development.tickets.assign', $ticket) }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                @csrf
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-[#7c6a56]">{{ __('app-development.tickets.assign_to') }}</label>
                    <select name="assigned_to" required class="kaman-input w-full">
                        @foreach($developers as $developer)
                            <option value="{{ $developer->id }}" @selected((int) $ticket->assigned_to === (int) $developer->id)>{{ $developer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tickets.assign') }}</button>
            </form>
        @endcan
    </section>

    <section class="kaman-card kaman-card--compact space-y-3">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.tickets.comments') }}</h2>
        @forelse($ticket->comments as $comment)
            <div class="rounded-xl border border-[#f1dfc5] bg-[#fffaf3] px-3 py-2.5">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-semibold text-[#2b1e11]">{{ $comment->user?->name }}</span>
                    @if($comment->user?->appDevelopmentRole())
                        <span class="rounded-full border border-[#f1dfc5] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-[#a78a6c]">{{ $comment->user->appDevelopmentRole()->label() }}</span>
                    @endif
                    <span class="text-[#a78a6c]">{{ $comment->created_at?->format('d/m/Y H:i') }}</span>
                </div>
                <p class="mt-1 whitespace-pre-wrap text-sm text-[#2b1e11]">{{ $comment->body }}</p>
            </div>
        @empty
            <p class="text-sm text-[#7c6a56]">{{ __('app-development.tickets.no_comments') }}</p>
        @endforelse

        @can('comment', $ticket)
            <form method="post" action="{{ route('app-development.tickets.comments.store', $ticket) }}" class="space-y-2">
                @csrf
                <textarea name="body" required rows="3" class="kaman-input w-full" placeholder="{{ __('app-development.tickets.comment_placeholder') }}">{{ old('body') }}</textarea>
                <button type="submit" class="kaman-button-ghost kaman-button--sm">{{ __('app-development.tickets.post_comment') }}</button>
            </form>
        @endcan
    </section>

    <section class="kaman-card kaman-card--compact space-y-3">
        <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('app-development.tickets.history') }}</h2>
        <ol class="space-y-3">
            @forelse($ticket->activities as $activity)
                <li class="flex gap-3 text-sm">
                    <span class="w-28 shrink-0 text-xs text-[#a78a6c] pt-0.5">{{ $activity->created_at?->format('H:i') }}<br><span class="text-[10px]">{{ $activity->created_at?->format('d/m/Y') }}</span></span>
                    <div class="min-w-0">
                        <p class="text-[#2b1e11]">{{ $activity->message() }}</p>
                        @if($activity->note())
                            <p class="mt-1 whitespace-pre-wrap rounded-lg bg-white px-2 py-1 text-xs text-[#7c6a56] border border-[#f1dfc5]">“{{ $activity->note() }}”</p>
                        @endif
                    </div>
                </li>
            @empty
                <p class="text-sm text-[#7c6a56]">{{ __('app-development.tickets.no_history') }}</p>
            @endforelse
        </ol>
    </section>
@endsection
