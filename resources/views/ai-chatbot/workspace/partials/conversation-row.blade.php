@php
    $preview = $c->latestMessage->message ?? '';
    $time = $c->last_message_at?->diffForHumans(short: true) ?? '';
    $waitingAccount = (bool) ($waitingAccount ?? false);
    $phone = trim((string) ($c->contact_phone ?: ''));
@endphp
<div class="conversation-row flex items-stretch border-b border-[#eadfce]/80 hover:bg-white/70 transition
          {{ isset($activeId) && (int)$activeId === (int)$c->id ? 'bg-white border-s-4 border-s-[#f47a2e]' : '' }}"
     data-id="{{ $c->id }}"
     data-updated="{{ optional($c->updated_at)?->toIso8601String() }}">
    <a href="{{ route('ai-chatbot.workspace.conversations.show', array_filter(['instance' => $instance, 'conversation' => $c, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
       class="flex gap-3 flex-1 min-w-0 px-3 py-3">
        <div class="w-11 h-11 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-sm font-bold shrink-0" aria-hidden="true">
            {{ $c->initials() }}
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-semibold text-[#2b1e11] truncate">{{ $c->displayName() }}</p>
                <time class="text-[10px] text-[#a78a6c] shrink-0" datetime="{{ optional($c->last_message_at)?->toIso8601String() }}">{{ $time }}</time>
            </div>
            @if($phone !== '')
                <p class="text-[11px] text-[#a78a6c] truncate mt-0.5" dir="ltr">{{ $phone }}</p>
            @endif
            <p class="text-xs text-[#7c6a56] truncate mt-0.5">{{ \Illuminate\Support\Str::limit($preview, 70) }}</p>
            <div class="mt-1 flex flex-wrap gap-1 row-badges">
                @if($c->unread_count > 0)
                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-[#f47a2e] text-white text-[10px] font-bold">{{ $c->unread_count }}</span>
                @endif
                @if($c->bot_mode === 'human_takeover')
                    <span class="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-1.5 py-0.5">{{ __('chatbot.workspace.mode_human') }}</span>
                @elseif($c->bot_mode === 'paused')
                    <span class="text-[10px] font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded-full px-1.5 py-0.5">{{ __('chatbot.workspace.mode_paused') }}</span>
                @endif
                @if($c->attention_status === 'needs_attention')
                    <span class="text-[10px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-full px-1.5 py-0.5">{{ __('chatbot.workspace.needs_attention') }}</span>
                @endif
            </div>
        </div>
    </a>
    @if($waitingAccount)
        <button type="button"
                class="mark-user-created shrink-0 self-center me-2 rounded-full border border-[#f47a2e]/40 bg-[#f47a2e]/10 px-2 py-1 text-[10px] font-semibold text-[#f16229] hover:bg-[#f47a2e]/20"
                data-url="{{ route('ai-chatbot.workspace.conversations.user-created', [$instance, $c]) }}"
                data-id="{{ $c->id }}">
            {{ __('chatbot.workspace.mark_user_created') }}
        </button>
    @endif
</div>
