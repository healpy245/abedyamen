@php
    use App\Models\AiChatbot\ChatbotConversation;
    $showMansourAnalytics = is_array($analytics ?? null) && $instance->hasMansourIntegration();
    $hideInstructor = auth()->user()?->isChatbotOnly() ?? false;
@endphp
<section class="wa-chat-pane border-e border-[#eadfce]"
         id="workspace-chat-live"
         data-conversation-id="{{ $conversation->id }}"
         data-messages-url="{{ route('ai-chatbot.workspace.conversations.messages', [$instance, $conversation]) }}"
         data-reply-url="{{ route('ai-chatbot.workspace.conversations.reply', [$instance, $conversation]) }}"
         data-bot-mode-url="{{ route('ai-chatbot.workspace.conversations.bot-mode', [$instance, $conversation]) }}"
         data-read-url="{{ route('ai-chatbot.workspace.conversations.read', [$instance, $conversation]) }}"
         data-instructions-url="{{ route('ai-chatbot.workspace.conversations.instructions.index', [$instance, $conversation]) }}"
         data-instructions-store-url="{{ route('ai-chatbot.workspace.conversations.instructions.store', [$instance, $conversation]) }}"
         data-csrf="{{ csrf_token() }}"
         data-can-reply="{{ $canReply ? '1' : '0' }}"
         data-can-control="{{ $canControlBot ? '1' : '0' }}"
         data-can-instruct="{{ $canManageInstructions ? '1' : '0' }}"
         data-last-message-id="{{ $messages->last()?->id ?? 0 }}"
         data-mode-labels="{{ e(json_encode([
             'active' => __('chatbot.workspace.mode_active'),
             'paused' => __('chatbot.workspace.mode_paused'),
             'human_takeover' => __('chatbot.workspace.mode_human_takeover'),
         ], JSON_UNESCAPED_UNICODE)) }}">
    <header class="wa-chat-header px-4 py-3 border-b border-[#eadfce] flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-sm font-bold shrink-0">{{ $conversation->initials() }}</div>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-[#2b1e11] truncate">{{ $conversation->displayName() }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs text-[#a78a6c] truncate" dir="ltr">{{ $conversation->contact_phone ?: $conversation->external_chat_id }}</p>
                        @if($instance->isAppsBot() && in_array((int) $conversation->id, array_map('intval', (array) data_get($analytics, 'conversation_ids.waiting_account', [])), true))
                            <button type="button"
                                    class="mark-user-created rounded-full border border-[#f47a2e]/40 bg-[#f47a2e]/10 px-2 py-0.5 text-[10px] font-semibold text-[#f16229] hover:bg-[#f47a2e]/20"
                                    data-url="{{ route('ai-chatbot.workspace.conversations.user-created', [$instance, $conversation]) }}"
                                    data-id="{{ $conversation->id }}">
                                {{ __('chatbot.workspace.mark_user_created') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
            @if($contextSummary)
                <p class="mt-2 text-[11px] text-[#7c6a56] bg-white/70 border border-[#eadfce] rounded-lg px-2 py-1.5 max-w-xl">
                    {{ __('chatbot.workspace.malan_context') }}:
                    {{ $contextSummary['verified_customer_name'] ?? '—' }}
                    @if(!empty($contextSummary['customer_status']))
                        · {{ $contextSummary['customer_status'] }}
                    @endif
                </p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <span id="bot-mode-badge"
                  class="kaman-chip text-xs {{ $conversation->bot_mode === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($conversation->bot_mode === 'paused' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-sky-50 text-sky-700 border-sky-200') }}"
                  data-mode="{{ $conversation->bot_mode }}">
                {{ __('chatbot.workspace.mode_'.$conversation->bot_mode) }}
            </span>
            <span id="live-sync-dot" class="inline-flex items-center gap-1 rounded-full border border-[#eadfce] bg-white px-2 py-0.5 text-[10px] text-[#a78a6c]" title="{{ __('chatbot.workspace.live_sync') }}">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true"></span>
                {{ __('chatbot.workspace.live') }}
            </span>
            @unless($showMansourAnalytics || $hideInstructor)
            <button type="button" id="btn-instructor-mobile" class="xl:hidden kaman-button-ghost kaman-button--sm">{{ __('chatbot.workspace.instructor') }}</button>
            <button type="button"
                    id="btn-instructor-expand"
                    class="hidden kaman-button-ghost kaman-button--sm"
                    aria-controls="instructor-panel"
                    aria-expanded="false"
                    title="{{ __('chatbot.workspace.instructor_expand') }}">
                {{ __('chatbot.workspace.instructor') }}
            </button>
            @endunless
            @if($canControlBot)
                <button type="button"
                        id="chat-bot-toggle"
                        class="kaman-button-ghost kaman-button--sm"
                        data-mode-on="{{ ChatbotConversation::BOT_MODE_ACTIVE }}"
                        data-mode-off="{{ ChatbotConversation::BOT_MODE_PAUSED }}"
                        data-on-label="{{ __('chatbot.workspace.chat_bot_on') }}"
                        data-off-label="{{ __('chatbot.workspace.chat_bot_off') }}"
                        aria-pressed="{{ $conversation->bot_mode === ChatbotConversation::BOT_MODE_ACTIVE ? 'true' : 'false' }}">
                    {{ $conversation->bot_mode === ChatbotConversation::BOT_MODE_ACTIVE
                        ? __('chatbot.workspace.chat_bot_on')
                        : __('chatbot.workspace.chat_bot_off') }}
                </button>
                <button type="button" class="bot-mode-btn kaman-button kaman-button--sm" data-mode="{{ ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER }}">{{ __('chatbot.workspace.human_takeover') }}</button>
            @endif
        </div>
    </header>

    <div id="message-thread" class="wa-chat-thread kaman-scroll p-4 space-y-3 bg-[linear-gradient(180deg,#f7efe3_0%,#fffaf3_40%,#f7efe3_100%)]" role="log" aria-live="polite">
        @forelse($messages as $message)
            @include('ai-chatbot.workspace.partials.message-bubble', ['message' => $message, 'instance' => $instance])
        @empty
            <p class="text-center text-sm text-[#a78a6c] py-12">{{ __('chatbot.workspace.no_messages') }}</p>
        @endforelse
        <div id="thread-bottom-anchor" class="h-px w-full shrink-0" aria-hidden="true"></div>
    </div>

    <footer class="wa-chat-composer p-2.5 sm:p-3">
        @if($canReply)
            <form id="reply-form" class="flex gap-2 items-end">
                <label class="sr-only" for="reply-input">{{ __('chatbot.workspace.reply_placeholder') }}</label>
                <textarea id="reply-input" rows="1" required maxlength="4000"
                          placeholder="{{ $conversation->isWhatsApp()
                              ? __('chatbot.workspace.reply_placeholder_whatsapp')
                              : __('chatbot.workspace.reply_placeholder') }}"
                          class="kaman-input wa-reply-input flex-1 text-sm"></textarea>
                <button type="submit" id="reply-submit" class="kaman-button shrink-0" title="{{ __('chatbot.workspace.send') }}">
                    @if($instance->hasMansourIntegration())
                        {{ \App\Support\FarzUi::svg('send', 'h-5 w-5') }}<span class="sr-only">{{ __('chatbot.workspace.send') }}</span>
                    @else
                        {{ __('chatbot.workspace.send') }}
                    @endif
                </button>
            </form>
            <p id="reply-error" class="hidden mt-1.5 text-xs text-red-600" role="alert"></p>
        @else
            <p class="text-sm text-[#a78a6c]">{{ __('chatbot.workspace.viewer_no_reply') }}</p>
        @endif
    </footer>
</section>
