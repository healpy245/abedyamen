@php
    $activeProject = 'ai-chatbot';
    $statusKey = 'chatbot.workspace.campaigns.status_'.$campaign->status;
    $statusLabel = __($statusKey);
    if ($statusLabel === $statusKey) {
        $statusLabel = $campaign->status;
    }
    $lastMessageId = $messages->isNotEmpty() ? (int) $messages->last()->id : 0;
    $listLabelsJson = e(json_encode([
        'mode_human' => __('chatbot.workspace.mode_human_takeover'),
        'mode_paused' => __('chatbot.workspace.mode_paused'),
        'needs_attention' => __('chatbot.workspace.needs_attention'),
    ], JSON_UNESCAPED_UNICODE));
    $modeLabelsJson = e(json_encode([
        'active' => __('chatbot.workspace.mode_active'),
        'paused' => __('chatbot.workspace.mode_paused'),
        'human_takeover' => __('chatbot.workspace.mode_human_takeover'),
    ], JSON_UNESCAPED_UNICODE));
@endphp
@extends('layouts.kaman')

@section('title', $campaign->name.' — '.__('chatbot.workspace.campaigns.title'))
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[1400px] mx-auto px-3 sm:px-4 pb-3"
     id="campaign-workspace"
     data-csrf="{{ csrf_token() }}"
     data-analytics-url="{{ route('ai-chatbot.workspace.campaigns.analytics', [$instance, $campaign]) }}">
    @include('ai-chatbot.workspace.partials.nav')

    @if(session('status'))
        <div class="mb-3 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-3 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">{{ $errors->first() }}</div>
    @endif

    @include('ai-chatbot.workspace.campaigns.partials.subnav')
    <p class="mb-3 text-[11px] text-[#a78a6c] flex items-center gap-2">
        <span>{{ $statusLabel }}</span>
        <span id="live-sync-dot" class="inline-flex items-center gap-1 rounded-full border border-[#eadfce] bg-white px-2 py-0.5 text-[10px] text-[#a78a6c]">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true"></span>
            {{ __('chatbot.workspace.live') }}
        </span>
    </p>

    <div class="kaman-card wa-chat-shell flex flex-col flex-1 min-h-0">
        <div class="grid grid-cols-1 md:grid-cols-[280px_1fr] xl:grid-cols-[260px_1fr_240px] flex-1 min-h-0 h-full overflow-hidden">
            {{-- Conversation list --}}
            <aside id="workspace-list-pane"
                   class="border-e border-[#eadfce]/80 flex flex-col min-h-0 bg-[#f7efe3]/30 overflow-hidden"
                   data-poll-url="{{ route('ai-chatbot.workspace.campaigns.conversations.poll', [$instance, $campaign]) }}"
                   data-list-labels="{{ $listLabelsJson }}"
                   data-insight="all"
                   data-insight-conversation-ids="{{ e(json_encode($analytics['conversation_ids'] ?? [], JSON_UNESCAPED_UNICODE)) }}"
                   data-empty-label="{{ __('chatbot.workspace.campaigns.select_conversation_hint') }}"
                   data-filter-empty-label="{{ __('chatbot.workspace.campaigns.filter_empty') }}">
                <div class="px-3 py-2.5 border-b border-[#eadfce]/80 shrink-0">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.conversations') }}</p>
                        <button type="button" id="insight-filter-clear"
                                class="hidden text-[10px] font-semibold text-[#f47a2e] hover:underline">
                            {{ __('chatbot.workspace.campaigns.clear_filter') }}
                        </button>
                    </div>
                    <p id="insight-filter-label" class="mt-1 hidden text-[10px] text-[#a78a6c]"></p>
                </div>
                <div id="conversation-list" class="flex-1 overflow-y-auto kaman-scroll min-h-0">
                    @forelse($conversations as $c)
                        @php
                            $preview = $c->latestMessage->message ?? '';
                            $time = $c->last_message_at?->diffForHumans(short: true) ?? '';
                            $activeId = $activeConversation?->id;
                            $isActive = $activeId && (int) $activeId === (int) $c->id;
                        @endphp
                        <a href="{{ route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign, 'conversation' => $c->id]) }}"
                           class="conversation-row flex gap-3 px-3 py-3 border-b border-[#eadfce]/80 hover:bg-white/70 transition
                                  {{ $isActive ? 'bg-white border-s-4 border-s-[#f47a2e]' : '' }}"
                           data-id="{{ $c->id }}"
                           data-updated="{{ optional($c->updated_at)?->toIso8601String() }}"
                           data-unread="{{ (int) $c->unread_count }}">
                            <div class="w-10 h-10 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-xs font-bold shrink-0">
                                {{ $c->initials() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-[#2b1e11] truncate">{{ $c->displayName() }}</p>
                                    <time class="text-[10px] text-[#a78a6c] shrink-0" datetime="{{ optional($c->last_message_at)?->toIso8601String() }}">{{ $time }}</time>
                                </div>
                                <p class="text-xs text-[#7c6a56] truncate mt-0.5">{{ \Illuminate\Support\Str::limit($preview, 60) }}</p>
                                <div class="mt-1 flex flex-wrap gap-1 row-badges">
                                    @if((int) $c->unread_count > 0)
                                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-[#f47a2e] text-white text-[10px] font-bold">{{ (int) $c->unread_count }}</span>
                                    @endif
                                    @if($c->bot_mode === 'human_takeover')
                                        <span class="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-1.5 py-0.5">{{ __('chatbot.workspace.mode_human_takeover') }}</span>
                                    @elseif($c->bot_mode === 'paused')
                                        <span class="text-[10px] font-semibold text-slate-600 bg-slate-50 border border-slate-200 rounded-full px-1.5 py-0.5">{{ __('chatbot.workspace.mode_paused') }}</span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <p id="list-empty" class="p-4 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.select_conversation_hint') }}</p>
                    @endforelse
                </div>
            </aside>

            {{-- Chat pane --}}
            <section class="wa-chat-pane bg-[#f7efe3]/20"
                @if($activeConversation)
                     id="workspace-chat"
                     data-csrf="{{ csrf_token() }}"
                     data-can-control="{{ ! empty($canControlBot) ? '1' : '0' }}"
                     data-can-reply="{{ ! empty($canReply) ? '1' : '0' }}"
                     data-messages-url="{{ route('ai-chatbot.workspace.campaigns.conversations.messages', [$instance, $campaign, $activeConversation]) }}"
                     data-bot-mode-url="{{ route('ai-chatbot.workspace.campaigns.conversations.bot-mode', [$instance, $campaign, $activeConversation]) }}"
                     data-read-url="{{ route('ai-chatbot.workspace.campaigns.conversations.read', [$instance, $campaign, $activeConversation]) }}"
                     data-reply-url="{{ route('ai-chatbot.workspace.campaigns.conversations.reply', [$instance, $campaign, $activeConversation]) }}"
                     data-last-message-id="{{ $lastMessageId }}"
                     data-conversation-id="{{ $activeConversation->id }}"
                     data-mode-labels="{{ $modeLabelsJson }}"
                @endif
            >
                @if($activeConversation)
                    <header class="wa-chat-header px-4 py-3 border-b border-[#eadfce]/80 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-[#2b1e11]">{{ $activeConversation->displayName() }}</p>
                            <p class="text-xs text-[#a78a6c]" dir="ltr">{{ $activeConversation->contact_phone }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span id="bot-mode-badge"
                                  class="kaman-chip text-xs {{ $activeConversation->bot_mode === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($activeConversation->bot_mode === 'paused' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-sky-50 text-sky-700 border-sky-200') }}"
                                  data-mode="{{ $activeConversation->bot_mode }}">
                                {{ __('chatbot.workspace.mode_'.$activeConversation->bot_mode) }}
                            </span>
                            @if(! empty($canControlBot))
                                <button type="button"
                                        id="chat-bot-toggle"
                                        class="kaman-button-ghost kaman-button--sm"
                                        data-mode-on="{{ \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_ACTIVE }}"
                                        data-mode-off="{{ \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_PAUSED }}"
                                        data-on-label="{{ __('chatbot.workspace.chat_bot_on') }}"
                                        data-off-label="{{ __('chatbot.workspace.chat_bot_off') }}"
                                        aria-pressed="{{ $activeConversation->bot_mode === \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_ACTIVE ? 'true' : 'false' }}">
                                    {{ $activeConversation->bot_mode === \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_ACTIVE
                                        ? __('chatbot.workspace.chat_bot_on')
                                        : __('chatbot.workspace.chat_bot_off') }}
                                </button>
                                <button type="button"
                                        class="bot-mode-btn kaman-button kaman-button--sm"
                                        data-mode="{{ \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER }}">
                                    {{ __('chatbot.workspace.human_takeover') }}
                                </button>
                            @endif
                        </div>
                    </header>
                    <div id="message-thread" class="wa-chat-thread kaman-scroll p-4 space-y-3" role="log" aria-live="polite">
                        @forelse($messages as $message)
                            @include('ai-chatbot.workspace.partials.message-bubble', [
                                'message' => $message,
                                'instance' => $instance,
                            ])
                        @empty
                            <p class="text-center text-sm text-[#a78a6c] py-12">{{ __('chatbot.workspace.no_messages') }}</p>
                        @endforelse
                        <div id="thread-bottom-anchor" class="h-px w-full shrink-0" aria-hidden="true"></div>
                    </div>
                    <footer class="wa-chat-composer p-2.5 sm:p-3">
                        @if(! empty($canReply))
                            <form id="reply-form" class="flex gap-2 items-end">
                                <label class="sr-only" for="reply-input">{{ __('chatbot.workspace.reply_placeholder') }}</label>
                                <textarea id="reply-input" rows="1" required maxlength="4000"
                                          placeholder="{{ __('chatbot.workspace.reply_placeholder_whatsapp') }}"
                                          class="kaman-input wa-reply-input flex-1 text-sm"></textarea>
                                <button type="submit" id="reply-submit" class="kaman-button shrink-0">{{ __('chatbot.workspace.send') }}</button>
                            </form>
                            <p id="reply-error" class="hidden mt-1.5 text-xs text-red-600" role="alert"></p>
                        @else
                            <p class="text-sm text-[#a78a6c]">{{ __('chatbot.workspace.viewer_no_reply') }}</p>
                        @endif
                    </footer>
                @else
                    <div class="flex-1 flex flex-col items-center justify-center p-8 text-center">
                        <div class="w-14 h-14 rounded-full bg-[#f1dfc5]/80 flex items-center justify-center mb-3 text-[#f16229]">
                            <x-workspace.icon name="megaphone" class="h-7 w-7" />
                        </div>
                        <p class="text-sm font-medium text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.select_conversation') }}</p>
                        <p class="mt-1 text-xs text-[#a78a6c] max-w-xs">{{ __('chatbot.workspace.campaigns.select_conversation_hint') }}</p>
                    </div>
                @endif
            </section>

            {{-- Analytics sidebar --}}
            @php
                $analyticsCards = [
                    [
                        'key' => 'leads',
                        'label' => __('chatbot.workspace.campaigns.stat_leads'),
                        'hint' => __('chatbot.workspace.campaigns.stat_leads_hint'),
                        'icon' => 'check-circle',
                        'tone' => 'bg-emerald-50 border-emerald-200/80 text-emerald-800',
                        'iconTone' => 'bg-emerald-100 text-emerald-700',
                        'count' => (int) ($analytics['leads'] ?? 0),
                        'export' => 'leads',
                    ],
                    [
                        'key' => 'no_response',
                        'label' => __('chatbot.workspace.campaigns.stat_no_response'),
                        'hint' => __('chatbot.workspace.campaigns.stat_no_response_hint'),
                        'icon' => 'clock',
                        'tone' => 'bg-amber-50 border-amber-200/80 text-amber-900',
                        'iconTone' => 'bg-amber-100 text-amber-700',
                        'count' => (int) ($analytics['no_response'] ?? 0),
                        'export' => 'no_response',
                    ],
                    [
                        'key' => 'rejected',
                        'label' => __('chatbot.workspace.campaigns.stat_rejected'),
                        'hint' => __('chatbot.workspace.campaigns.stat_rejected_hint'),
                        'icon' => 'x-circle',
                        'tone' => 'bg-rose-50 border-rose-200/80 text-rose-900',
                        'iconTone' => 'bg-rose-100 text-rose-700',
                        'count' => (int) ($analytics['rejected'] ?? 0),
                        'export' => 'rejected',
                    ],
                    [
                        'key' => 'engaged',
                        'label' => __('chatbot.workspace.campaigns.stat_engaged'),
                        'hint' => __('chatbot.workspace.campaigns.stat_engaged_hint'),
                        'icon' => 'chat-bubble-left-right',
                        'tone' => 'bg-sky-50 border-sky-200/80 text-sky-900',
                        'iconTone' => 'bg-sky-100 text-sky-700',
                        'count' => (int) ($analytics['engaged'] ?? 0),
                        'export' => 'responded',
                    ],
                    [
                        'key' => 'messages',
                        'label' => __('chatbot.workspace.campaigns.stat_messages'),
                        'hint' => __('chatbot.workspace.campaigns.stat_messages_hint'),
                        'icon' => 'megaphone',
                        'tone' => 'bg-orange-50 border-orange-200/80 text-orange-900',
                        'iconTone' => 'bg-orange-100 text-orange-700',
                        'count' => (int) ($analytics['messages'] ?? 0),
                        'export' => 'messages',
                    ],
                    [
                        'key' => 'contacts',
                        'label' => __('chatbot.workspace.campaigns.stat_contacts'),
                        'hint' => __('chatbot.workspace.campaigns.stat_contacts_hint'),
                        'icon' => 'users',
                        'tone' => 'bg-stone-50 border-stone-200/80 text-stone-800',
                        'iconTone' => 'bg-stone-100 text-stone-600',
                        'count' => (int) ($analytics['contacts'] ?? 0),
                        'export' => 'contacts',
                    ],
                ];
            @endphp
            <aside class="hidden xl:flex flex-col min-h-0 h-full overflow-y-auto border-s border-[#eadfce]/80 bg-white/50 p-4 gap-2.5" id="campaign-analytics-desktop">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.live_analytics') }}</h3>
                <p class="text-[10px] leading-snug text-[#b6987a]">{{ __('chatbot.workspace.campaigns.live_analytics_hint') }}</p>
                <p class="text-[10px] leading-snug text-[#b6987a]">{{ __('chatbot.workspace.campaigns.tap_card_filter') }}</p>
                @foreach ($analyticsCards as $card)
                    <div class="analytics-card rounded-xl border px-3 py-2.5 {{ $card['tone'] }} cursor-pointer transition ring-offset-1 hover:brightness-[0.98]"
                         role="button"
                         tabindex="0"
                         data-insight-filter="{{ $card['key'] === 'engaged' ? 'responded' : $card['key'] }}"
                         data-insight-label="{{ $card['label'] }}"
                         aria-pressed="false"
                         title="{{ __('chatbot.workspace.campaigns.tap_card_filter') }}">
                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $card['iconTone'] }}">
                                <x-workspace.icon :name="$card['icon']" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80">{{ $card['label'] }}</p>
                                <p class="text-2xl font-semibold leading-tight" data-analytics="{{ $card['key'] }}">{{ $card['count'] }}</p>
                                <p class="mt-0.5 text-[10px] leading-snug opacity-70">{{ $card['hint'] }}</p>
                            </div>
                        </div>
                        <a
                            href="{{ route('ai-chatbot.workspace.campaigns.analytics.export', [$instance, $campaign, $card['export']]) }}"
                            class="analytics-download mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-white/70 px-2 py-1.5 text-[11px] font-medium text-current ring-1 ring-black/5 hover:bg-white transition"
                            title="{{ __('chatbot.workspace.campaigns.download_excel') }}"
                        >
                            <x-workspace.icon name="arrow-down-tray" class="h-3.5 w-3.5" />
                            {{ __('chatbot.workspace.campaigns.download_phones') }}
                        </a>
                    </div>
                @endforeach
                <div class="mt-auto pt-2">
                    <p class="text-[10px] text-[#a78a6c] mb-1">{{ __('chatbot.workspace.campaigns.webhook_url') }}</p>
                    <input type="text" readonly value="{{ $webhookUrl }}" class="kaman-input w-full !text-[10px] font-mono" onclick="this.select()">
                </div>
            </aside>
        </div>
    </div>

    <div class="xl:hidden mt-3 grid grid-cols-2 gap-2" id="campaign-analytics-mobile">
        @foreach ($analyticsCards as $card)
            <div class="analytics-card rounded-xl border px-3 py-2 {{ $card['tone'] }} cursor-pointer"
                 role="button"
                 tabindex="0"
                 data-insight-filter="{{ $card['key'] === 'engaged' ? 'responded' : $card['key'] }}"
                 data-insight-label="{{ $card['label'] }}"
                 aria-pressed="false"
                 title="{{ __('chatbot.workspace.campaigns.tap_card_filter') }}">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $card['iconTone'] }}">
                        <x-workspace.icon :name="$card['icon']" class="h-3.5 w-3.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wide opacity-80 truncate">{{ $card['label'] }}</p>
                        <p class="text-lg font-semibold leading-tight" data-analytics="{{ $card['key'] }}">{{ $card['count'] }}</p>
                    </div>
                </div>
                <a
                    href="{{ route('ai-chatbot.workspace.campaigns.analytics.export', [$instance, $campaign, $card['export']]) }}"
                    class="analytics-download mt-2 inline-flex w-full items-center justify-center gap-1 rounded-lg bg-white/70 px-2 py-1 text-[10px] font-medium ring-1 ring-black/5"
                >
                    <x-workspace.icon name="arrow-down-tray" class="h-3 w-3" />
                    {{ __('chatbot.workspace.campaigns.download_phones') }}
                </a>
            </div>
        @endforeach
    </div>

    <div id="toast" class="fixed bottom-4 inset-x-0 mx-auto w-fit max-w-sm hidden z-50 rounded-xl bg-[#2b1e11] text-white text-sm px-4 py-2 shadow-lg" role="status"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-chat.js') }}?v={{ @filemtime(public_path('js/workspace-chat.js')) ?: time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.WorkspaceChat) return;
    const list = document.getElementById('workspace-list-pane');
    const chat = document.getElementById('workspace-chat');
    const page = document.getElementById('campaign-workspace');
    window.WorkspaceChat.startListPolling(list);
    if (chat) {
        window.WorkspaceChat.startConversation(chat);
    }
    window.WorkspaceChat.startAnalyticsPolling(page);
    window.WorkspaceChat.bindInsightFilters(page, list);
});
</script>
@endpush
