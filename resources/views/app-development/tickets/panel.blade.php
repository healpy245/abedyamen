@php
    $user = auth()->user();
    $rejection = $ticket->isReturnedFromQa() ? $ticket->latestQaRejection : null;
    $latestRelease = $ticket->releases->first();
    $meta = array_values(array_filter([
        $ticket->creator?->name,
        $ticket->created_at?->format('d/m H:i'),
        $ticket->elapsedLabel(),
        $ticket->qa_rejection_count > 0 ? $ticket->qa_rejection_count.' QA' : null,
        $ticket->completedBy?->name,
    ]));
@endphp

<div data-modal-title="{{ $ticket->ticket_number }}" data-modal-variant="view" class="space-y-3">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <h3 class="text-base font-semibold leading-snug text-[#2b1e11]">{{ $ticket->title }}</h3>
            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                @include('app-development.partials.type-badge', ['type' => $ticket->type])
                @include('app-development.partials.app-type-badges', ['types' => $ticket->appTypes(), 'compact' => false])
                @include('app-development.partials.priority-badge', ['priority' => $ticket->priority])
                @include('app-development.partials.status-badge', ['status' => $ticket->status])
            </div>
            <p class="mt-1.5 text-xs text-[#7c6a56]">
                {{ implode(' · ', $meta) }}
                @if($latestRelease)
                    · <a href="{{ route('app-development.releases.show', $latestRelease) }}" class="font-medium text-[#f16229]" data-app-dev-modal>v{{ $latestRelease->version_name }}</a>
                @endif
            </p>
            @if($ticket->assignedDeveloper)
                <p class="mt-1 text-xs font-medium text-[#f16229]">
                    {{ __('app-development.tickets.accepted_by', ['name' => $ticket->assignedDeveloper->name]) }}
                </p>
            @endif
        </div>
        <div class="flex shrink-0 items-start gap-1.5">
            @can('comment', $ticket)
                @if(($notifyMembers ?? collect())->isNotEmpty())
                    <div class="relative" data-comment-notify>
                        <button type="button"
                                class="kaman-button-ghost kaman-button--sm"
                                data-comment-notify-toggle
                                aria-expanded="false"
                                aria-controls="ticket-comment-notify-panel"
                                title="{{ __('app-development.tickets.notify_workers') }}">
                            @include('app-development.partials.icon', ['name' => 'bell'])
                            <span class="sr-only">{{ __('app-development.tickets.notify_workers') }}</span>
                        </button>
                        <div id="ticket-comment-notify-panel"
                             class="absolute end-0 z-20 mt-1 hidden w-56 rounded-xl border border-[#eadfce] bg-[#fffaf3] p-2 shadow-lg"
                             data-comment-notify-panel
                             hidden>
                            <p class="px-1 pb-1.5 text-[11px] font-semibold text-[#7c6a56]">{{ __('app-development.tickets.notify_workers_hint') }}</p>
                            <div class="max-h-48 space-y-1 overflow-y-auto kaman-scroll">
                                @foreach($notifyMembers as $member)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-[#2b1e11] hover:bg-white">
                                        <input type="checkbox"
                                               name="notify_user_ids[]"
                                               value="{{ $member->id }}"
                                               form="ticket-comment-form"
                                               class="rounded border-[#eadfce] text-[#f16229] focus:ring-[#f47a2e]">
                                        <span class="min-w-0 truncate">{{ $member->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endcan
            @can('update', $ticket)
                <a href="{{ route('app-development.tickets.edit', $ticket) }}" class="kaman-button-ghost kaman-button--sm shrink-0" data-app-dev-modal>
                    @include('app-development.partials.icon', ['name' => 'pencil'])
                    {{ __('app.edit') }}
                </a>
            @endcan
            @can('delete', $ticket)
                <form method="post"
                      action="{{ route('app-development.tickets.destroy', $ticket) }}"
                      class="inline"
                      data-app-dev-confirm
                      data-confirm-title="{{ __('app-development.tickets.delete_title') }}"
                      data-confirm-message="{{ __('app-development.tickets.delete_confirm') }}"
                      data-confirm-action="{{ __('app-development.tickets.delete') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="kaman-button-ghost kaman-button--sm shrink-0 text-red-700 hover:text-red-800">
                        @include('app-development.partials.icon', ['name' => 'trash'])
                        {{ __('app.delete') }}
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @if($rejection)
        <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2">
            <p class="whitespace-pre-wrap text-sm text-[#2b1e11]">“{{ $rejection->note() }}”</p>
            <p class="mt-1 text-[11px] text-red-800">{{ $rejection->user?->name }} · {{ $rejection->created_at?->format('d/m H:i') }}</p>
        </div>
    @endif

    <p class="whitespace-pre-wrap text-sm leading-relaxed text-[#2b1e11]">{{ $ticket->description }}</p>

    @if(!empty($suggestCompleteTicket))
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {{ __('app-development.flash.suggest_complete_ticket', ['ticket' => $ticket->ticket_number]) }}
        </div>
    @endif

    <section class="rounded-xl border border-[#f1dfc5] bg-[#fffaf3] p-3">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h4 class="text-sm font-bold text-[#2b1e11]">{{ __('app-development.tasks.section') }}</h4>
            @can('create', \App\Models\AppDevelopment\AppDevelopmentTask::class)
                <a href="{{ route('app-development.tickets.tasks.create', $ticket) }}" class="kaman-button-ghost kaman-button--sm" data-app-dev-modal>
                    @include('app-development.partials.icon', ['name' => 'plus'])
                    {{ __('app-development.tasks.create') }}
                </a>
            @endcan
        </div>
        <div class="space-y-1.5">
            @forelse($ticket->tasks as $task)
                <a href="{{ route('app-development.tasks.show', $task) }}"
                   data-app-dev-modal
                   class="flex items-center justify-between gap-2 rounded-lg border border-[#eadfce] bg-white px-2.5 py-2 text-sm hover:border-[#c45c26]">
                    <div class="min-w-0">
                        <span class="block truncate font-semibold text-[#2b1e11]">{{ $task->title }}</span>
                        <span class="text-[11px] text-[#a78a6c]">{{ $task->assignee?->name ?? __('app-development.tickets.unassigned') }}</span>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        @include('app-development.partials.task-status-badge', ['status' => $task->status])
                        @include('app-development.partials.priority-badge', ['priority' => $task->priority])
                    </div>
                </a>
            @empty
                <p class="text-xs text-[#a78a6c]">{{ __('app-development.tasks.empty_column') }}</p>
            @endforelse
        </div>
    </section>

    @php
        $commentAttachmentIds = $ticket->comments
            ->pluck('attachment_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $fileAttachments = $ticket->attachments->reject(
            fn ($attachment): bool => in_array((int) $attachment->id, $commentAttachmentIds, true),
        );
        $descriptionVoices = $fileAttachments->filter(fn ($attachment): bool => $attachment->isAudio())->values();
        $otherAttachments = $fileAttachments->reject(fn ($attachment): bool => $attachment->isAudio())->values();
    @endphp

    @if($descriptionVoices->isNotEmpty())
        <div class="space-y-2">
            @foreach($descriptionVoices as $attachment)
                @php $url = route('app-development.tickets.attachments.download', [$ticket, $attachment]); @endphp
                <div class="wa-voice wa-voice--card" data-wa-voice-player>
                    <button type="button" class="wa-voice__play" data-voice-play aria-label="Play">
                        @include('app-development.partials.icon', ['name' => 'play'])
                    </button>
                    <div class="wa-voice__wave">
                        <input type="range" class="wa-voice__seek" data-voice-seek min="0" max="100" value="0" step="1">
                    </div>
                    <span class="wa-voice__time"><span data-voice-current>0:00</span>/<span data-voice-duration>0:00</span></span>
                    <audio data-voice-audio preload="metadata" src="{{ $url }}"></audio>
                </div>
            @endforeach
        </div>
    @endif

    @if($otherAttachments->isNotEmpty() || auth()->user()?->can('uploadAttachment', $ticket))
        <div class="space-y-2">
            @if($otherAttachments->isNotEmpty())
                <div class="kaman-attachments">
                    @foreach($otherAttachments as $attachment)
                        @php $url = route('app-development.tickets.attachments.download', [$ticket, $attachment]); @endphp
                        @if($attachment->isVideo() || $attachment->isImage())
                            <button type="button"
                                    class="kaman-attachment"
                                    data-app-dev-media
                                    data-media-type="{{ $attachment->isVideo() ? 'video' : 'image' }}"
                                    data-media-url="{{ $url }}"
                                    data-media-name="{{ $attachment->original_name }}"
                                    aria-label="{{ __('app-development.media.open') }}: {{ $attachment->original_name }}">
                                <span class="kaman-attachment__preview">
                                    @if($attachment->isImage())
                                        <img src="{{ $url }}" alt="{{ $attachment->original_name }}" loading="lazy">
                                    @else
                                        <video src="{{ $url }}" preload="none" muted playsinline></video>
                                        <span class="kaman-attachment__play" aria-hidden="true">
                                            @include('app-development.partials.icon', ['name' => 'play'])
                                        </span>
                                    @endif
                                </span>
                                <span class="kaman-attachment__meta">
                                    <strong>{{ $attachment->original_name }}</strong>
                                    <small><bdi>{{ $attachment->humanSize() }}</bdi></small>
                                </span>
                            </button>
                        @else
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="kaman-attachment">
                                <span class="kaman-attachment__preview">
                                    <span class="kaman-attachment__file" aria-hidden="true">
                                        @include('app-development.partials.icon', ['name' => 'download'])
                                        <em>{{ $attachment->extension() ?: 'file' }}</em>
                                    </span>
                                </span>
                                <span class="kaman-attachment__meta">
                                    <strong>{{ $attachment->original_name }}</strong>
                                    <small><bdi>{{ $attachment->humanSize() }}</bdi></small>
                                </span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
            @can('uploadAttachment', $ticket)
                <form method="post" action="{{ route('app-development.tickets.attachments.store', $ticket) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <div class="flex items-center gap-2">
                        <input type="file"
                               name="attachments[]"
                               multiple
                               class="kaman-input min-w-0 flex-1"
                               accept="{{ \App\Support\AppDevelopment\TicketMedia::acceptAttribute() }}">
                        <button type="submit" class="kaman-button-ghost kaman-button--sm shrink-0">
                            @include('app-development.partials.icon', ['name' => 'upload'])
                            {{ __('app-development.tickets.upload') }}
                        </button>
                    </div>
                    <p class="text-[11px] text-[#a78a6c]">{{ __('app-development.tickets.attachments_hint') }}</p>
                </form>
            @endcan
        </div>
    @endif

    <div class="space-y-2 border-t border-[#f1dfc5] pt-3">
        @foreach($ticket->comments as $comment)
            <div class="rounded-xl bg-white px-3 py-2">
                <p class="text-[11px] text-[#a78a6c]">{{ $comment->user?->name }} · {{ $comment->created_at?->format('d/m H:i') }}</p>
                @if($comment->attachment && $comment->attachment->isAudio())
                    @php
                        $voiceUrl = route('app-development.tickets.attachments.download', [$ticket, $comment->attachment]);
                        $voiceLabels = [
                            trans('app-development.tickets.voice_note', [], 'ar'),
                            trans('app-development.tickets.voice_note', [], 'en'),
                            trans('app-development.tickets.voice_note', [], 'he'),
                        ];
                    @endphp
                    <div class="mt-1.5 wa-voice" data-wa-voice-player>
                        <button type="button" class="wa-voice__play" data-voice-play aria-label="Play">
                            @include('app-development.partials.icon', ['name' => 'play'])
                        </button>
                        <div class="wa-voice__wave">
                            <input type="range" class="wa-voice__seek" data-voice-seek min="0" max="100" value="0" step="1">
                        </div>
                        <span class="wa-voice__time"><span data-voice-current>0:00</span>/<span data-voice-duration>0:00</span></span>
                        <audio data-voice-audio preload="metadata" src="{{ $voiceUrl }}"></audio>
                    </div>
                    @if(filled($comment->body) && ! in_array($comment->body, $voiceLabels, true))
                        <p class="mt-1 whitespace-pre-wrap text-sm text-[#2b1e11]">{{ $comment->body }}</p>
                    @endif
                @else
                    <p class="mt-0.5 whitespace-pre-wrap text-sm text-[#2b1e11]">{{ $comment->body }}</p>
                @endif
            </div>
        @endforeach

        @can('comment', $ticket)
            <form id="ticket-comment-form"
                  method="post"
                  action="{{ route('app-development.tickets.comments.store', $ticket) }}"
                  enctype="multipart/form-data"
                  class="space-y-1.5">
                @csrf
                <div class="app-dev-comment-bar">
                    <textarea name="body" rows="2" class="kaman-input min-w-0 flex-1" placeholder="{{ __('app-development.tickets.comment_placeholder') }}">{{ old('body') }}</textarea>
                    @include('app-development.partials.voice-recorder', ['inputName' => 'voices[]'])
                    <button type="submit" class="kaman-button-ghost kaman-button--sm shrink-0 self-end">
                        @include('app-development.partials.icon', ['name' => 'send'])
                        <span class="sr-only">{{ __('app-development.tickets.post_comment') }}</span>
                    </button>
                </div>
            </form>
        @endcan

        @if($ticket->activities->isNotEmpty())
            <ol class="space-y-1.5 pt-1">
                @foreach($ticket->activities as $activity)
                    <li class="text-xs text-[#7c6a56]">
                        <span class="text-[#a78a6c]">{{ $activity->created_at?->format('d/m H:i') }}</span>
                        {{ $activity->message() }}
                        @if($activity->note())
                            <span class="text-[#2b1e11]"> — “{{ $activity->note() }}”</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>

@once('ticket-comment-notify-script')
<script>
(function () {
    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-comment-notify-toggle]');
        if (toggle) {
            event.preventDefault();
            const root = toggle.closest('[data-comment-notify]');
            const panel = root?.querySelector('[data-comment-notify-panel]');
            if (!panel) return;
            const open = panel.hasAttribute('hidden');
            document.querySelectorAll('[data-comment-notify-panel]').forEach((p) => {
                p.hidden = true;
                p.classList.add('hidden');
                p.closest('[data-comment-notify]')?.querySelector('[data-comment-notify-toggle]')?.setAttribute('aria-expanded', 'false');
            });
            if (open) {
                panel.hidden = false;
                panel.classList.remove('hidden');
                toggle.setAttribute('aria-expanded', 'true');
            }
            return;
        }
        if (!event.target.closest('[data-comment-notify]')) {
            document.querySelectorAll('[data-comment-notify-panel]').forEach((p) => {
                p.hidden = true;
                p.classList.add('hidden');
                p.closest('[data-comment-notify]')?.querySelector('[data-comment-notify-toggle]')?.setAttribute('aria-expanded', 'false');
            });
        }
    });
})();
</script>
@endonce
