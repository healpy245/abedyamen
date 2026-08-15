@php
    use App\Models\AiChatbot\ChatbotConversation;
    $activeProject = 'ai-chatbot';
    $isRtlMsg = fn ($text) => (bool) preg_match('/[\x{0600}-\x{06FF}\x{0590}-\x{05FF}]/u', (string) $text);
    $botModeLabels = [
        'active' => __('chatbot.workspace.mode_active'),
        'paused' => __('chatbot.workspace.mode_paused'),
        'human_takeover' => __('chatbot.workspace.mode_human_takeover'),
    ];
@endphp
@extends('layouts.kaman')

@section('title', $conversation->displayName().' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<style>
    /* Collapsible Live AI Instructor (desktop xl+) */
    @media (min-width: 1280px) {
        #workspace-chat[data-instructor-collapsed="1"] #workspace-chat-grid {
            grid-template-columns: 300px 1fr;
        }
        #workspace-chat[data-instructor-collapsed="1"] #instructor-panel {
            display: none !important;
        }
        #workspace-chat[data-instructor-collapsed="1"] #btn-instructor-expand {
            display: inline-flex;
        }
    }
</style>
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[1600px] mx-auto px-3 sm:px-4 pb-3"
     id="workspace-chat"
     data-instructor-collapsed="0"
     data-instance-id="{{ $instance->id }}"
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
     data-mode-labels="{{ e(json_encode($botModeLabels, JSON_UNESCAPED_UNICODE)) }}">

    @include('ai-chatbot.workspace.partials.nav')

    <div class="mb-2 md:hidden">
        <a href="{{ route('ai-chatbot.workspace.conversations', $instance) }}" class="kaman-button-ghost kaman-button--sm">← {{ __('chatbot.workspace.back_to_list') }}</a>
    </div>

    <div class="kaman-card overflow-hidden flex flex-col flex-1 min-h-[75vh]">
        {{-- list | chat | instructor (RTL: list on the right, like WhatsApp Web) --}}
        <div id="workspace-chat-grid"
             class="grid grid-cols-1 md:grid-cols-[280px_1fr] xl:grid-cols-[300px_1fr_280px] flex-1 min-h-0">
            @include('ai-chatbot.workspace.partials.conversation-list-pane', [
                'activeId' => $conversation->id,
                'listPaneClass' => 'hidden md:flex',
            ])

            {{-- Chat column --}}
            <section class="flex flex-col min-h-0 border-e border-[#eadfce]">
                {{-- Header --}}
                <header class="px-4 py-3 border-b border-[#eadfce] bg-[#fffaf3]/80 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <div class="w-10 h-10 rounded-full bg-[#f1dfc5] text-[#7c6a56] flex items-center justify-center text-sm font-bold shrink-0">{{ $conversation->initials() }}</div>
                            <div class="min-w-0">
                                <h2 class="text-base font-semibold text-[#2b1e11] truncate">{{ $conversation->displayName() }}</h2>
                                <p class="text-xs text-[#a78a6c] truncate" dir="ltr">{{ $conversation->contact_phone ?: $conversation->external_chat_id }}</p>
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
                        <button type="button" id="btn-instructor-mobile" class="xl:hidden kaman-button-ghost kaman-button--sm">{{ __('chatbot.workspace.instructor') }}</button>
                        <button type="button"
                                id="btn-instructor-expand"
                                class="hidden kaman-button-ghost kaman-button--sm"
                                aria-controls="instructor-panel"
                                aria-expanded="false"
                                title="{{ __('chatbot.workspace.instructor_expand') }}">
                            {{ __('chatbot.workspace.instructor') }}
                        </button>
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

                {{-- Messages --}}
                <div id="message-thread" class="flex-1 overflow-y-auto kaman-scroll p-4 space-y-3 bg-[linear-gradient(180deg,#f7efe3_0%,#fffaf3_40%,#f7efe3_100%)]" role="log" aria-live="polite">
                    @forelse($messages as $message)
                        @include('ai-chatbot.workspace.partials.message-bubble', ['message' => $message, 'instance' => $instance])
                    @empty
                        <p class="text-center text-sm text-[#a78a6c] py-12">{{ __('chatbot.workspace.no_messages') }}</p>
                    @endforelse
                </div>

                {{-- Composer: staff → customer (WhatsApp), never the AI bot --}}
                <footer class="border-t border-[#eadfce] p-3 bg-white">
                    @if($canReply)
                        <p class="mb-2 text-[11px] text-[#7c6a56]">
                            {{ $conversation->isWhatsApp()
                                ? __('chatbot.workspace.reply_hint_whatsapp')
                                : __('chatbot.workspace.reply_hint') }}
                        </p>
                        <form id="reply-form" class="flex gap-2 items-end">
                            <label class="sr-only" for="reply-input">{{ __('chatbot.workspace.reply_placeholder') }}</label>
                            <textarea id="reply-input" rows="2" required maxlength="4000"
                                      placeholder="{{ $conversation->isWhatsApp()
                                          ? __('chatbot.workspace.reply_placeholder_whatsapp')
                                          : __('chatbot.workspace.reply_placeholder') }}"
                                      class="kaman-input flex-1 resize-none text-sm"></textarea>
                            <button type="submit" id="reply-submit" class="kaman-button shrink-0">{{ __('chatbot.workspace.send') }}</button>
                        </form>
                        <p id="reply-error" class="hidden mt-2 text-xs text-red-600" role="alert"></p>
                    @else
                        <p class="text-sm text-[#a78a6c]">{{ __('chatbot.workspace.viewer_no_reply') }}</p>
                    @endif
                </footer>
            </section>

            {{-- Instructor panel (desktop xl+, collapsible) --}}
            <aside id="instructor-panel" class="hidden xl:flex flex-col min-h-0 bg-[#fffaf3]/50 border-s border-[#eadfce]">
                @include('ai-chatbot.workspace.partials.instructor-panel', ['collapsible' => true])
            </aside>
        </div>
    </div>

    {{-- Mobile / tablet instructor drawer --}}
    <div id="instructor-drawer" class="fixed inset-0 z-40 hidden" role="dialog" aria-modal="true" aria-label="{{ __('chatbot.workspace.instructor') }}">
        <div class="absolute inset-0 bg-black/40" data-close-drawer></div>
        <div class="absolute inset-y-0 end-0 w-full max-w-md bg-[#fffaf3] shadow-xl flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-[#eadfce]">
                <h3 class="font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.instructor') }}</h3>
                <button type="button" class="kaman-button-ghost kaman-button--sm" data-close-drawer>{{ __('chatbot.workspace.close') }}</button>
            </div>
            <div class="flex-1 overflow-y-auto">
                @include('ai-chatbot.workspace.partials.instructor-panel')
            </div>
        </div>
    </div>

    <div id="toast" class="fixed bottom-4 inset-x-0 mx-auto w-fit max-w-sm hidden z-50 rounded-xl bg-[#2b1e11] text-white text-sm px-4 py-2 shadow-lg" role="status"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-chat.js') }}?v={{ @filemtime(public_path('js/workspace-chat.js')) ?: time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.WorkspaceChat) {
        window.WorkspaceChat.startConversation(document.getElementById('workspace-chat'));
        window.WorkspaceChat.startListPolling(document.getElementById('workspace-list-pane'));
    }
});
</script>
@endpush
