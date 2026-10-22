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
@extends($instance->hasMansourIntegration() ? 'layouts.farz' : 'layouts.kaman')

@section('title', $conversation->displayName().' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<style>
    /* Collapsible Live AI Instructor (desktop xl+) */
    @media (min-width: 1280px) {
        #workspace-chat[data-instructor-collapsed="1"] #workspace-chat-grid {
            grid-template-columns: 420px minmax(0, 1fr);
        }
        #workspace-chat[data-kaman-analytics="1"][data-instructor-collapsed="1"] #workspace-chat-grid {
            grid-template-columns: 440px minmax(0, 1fr) 250px;
        }
        #workspace-chat[data-instructor-collapsed="1"] #instructor-panel {
            display: none !important;
        }
        #workspace-chat[data-instructor-collapsed="1"] #btn-instructor-expand {
            display: inline-flex;
        }
    }
</style>
@php
    $showKamanAnalytics = is_array($analytics ?? null) && ! $instance->hasMansourIntegration();
    $showMansourAnalytics = is_array($analytics ?? null) && $instance->hasMansourIntegration();
    $hideInstructor = auth()->user()?->isChatbotOnly() ?? false;
    $chatGrid = match (true) {
        $showMansourAnalytics => 'xl:grid-cols-[440px_minmax(0,1fr)_250px]',
        $showKamanAnalytics && ! $hideInstructor => 'xl:grid-cols-[440px_minmax(0,1fr)_220px_260px]',
        $showKamanAnalytics => 'xl:grid-cols-[440px_minmax(0,1fr)_250px]',
        ! $hideInstructor => 'xl:grid-cols-[420px_minmax(0,1fr)_280px]',
        default => 'xl:grid-cols-[420px_minmax(0,1fr)]',
    };
@endphp
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[1600px] mx-auto px-3 sm:px-4 pb-3"
     id="workspace-chat"
     data-instructor-collapsed="0"
     @if($showKamanAnalytics || $showMansourAnalytics)
         data-kaman-analytics="1"
         data-analytics-url="{{ route('ai-chatbot.workspace.analytics', array_filter(['instance' => $instance, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}"
     @endif
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
        <a href="{{ route('ai-chatbot.workspace.conversations', array_filter(['instance' => $instance, 'line' => is_array($line ?? null) ? $line['id'] : null, 'filter' => ($filter ?? 'all') !== 'all' ? $filter : null, 'q' => ($search ?? '') !== '' ? $search : null])) }}" class="kaman-button-ghost kaman-button--sm">
            @if($instance->hasMansourIntegration())
                {{ \App\Support\FarzUi::svg('back', 'h-4 w-4') }}
            @else
                ←
            @endif
            {{ __('chatbot.workspace.back_to_list') }}
        </a>
    </div>

    <div class="kaman-card wa-chat-shell flex flex-col flex-1 min-h-0">
        {{-- list | chat | instructor (RTL: list on the right, like WhatsApp Web) --}}
        <div id="workspace-chat-grid"
             class="grid grid-cols-1 md:grid-cols-[420px_minmax(0,1fr)] {{ $chatGrid }} flex-1 min-h-0 h-full overflow-hidden">
            @include('ai-chatbot.workspace.partials.conversation-list-pane', [
                'activeId' => $conversation->id,
                'listPaneClass' => 'hidden md:flex',
                'showInsight' => $showKamanAnalytics || $showMansourAnalytics,
                'insightConversationIds' => $analytics['conversation_ids'] ?? [],
            ])

            @include('ai-chatbot.workspace.partials.conversation-pane')

            @if($showMansourAnalytics)
                @include('ai-chatbot.workspace.mansour.analytics', ['placement' => 'desktop'])
            @elseif($showKamanAnalytics)
                @include('ai-chatbot.workspace.partials.kaman-analytics', ['placement' => 'desktop'])
            @endif

            {{-- Instructor panel (desktop xl+, collapsible) --}}
            @if(! $showMansourAnalytics && ! $hideInstructor)
            <aside id="instructor-panel" class="hidden xl:flex flex-col min-h-0 bg-[#fffaf3]/50 border-s border-[#eadfce]">
                @include('ai-chatbot.workspace.partials.instructor-panel', ['collapsible' => true])
            </aside>
            @endif
        </div>
    </div>

    @if($showMansourAnalytics)
        @include('ai-chatbot.workspace.mansour.analytics', ['placement' => 'mobile'])
        @include('ai-chatbot.workspace.mansour.trigger-modal')
    @elseif($showKamanAnalytics)
        @include('ai-chatbot.workspace.partials.kaman-analytics', ['placement' => 'mobile'])
    @endif

    @unless($showMansourAnalytics || $hideInstructor)
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
    @endunless

    <div id="toast" class="fixed bottom-4 inset-x-0 mx-auto w-fit max-w-sm hidden z-50 rounded-xl bg-[#2b1e11] text-white text-sm px-4 py-2 shadow-lg" role="status"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-chat.js') }}?v={{ @filemtime(public_path('js/workspace-chat.js')) ?: time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.WorkspaceChat) return;
    const page = document.getElementById('workspace-chat');
    const list = document.getElementById('workspace-list-pane');
    window.WorkspaceChat.startConversation(page);
    window.WorkspaceChat.startListPolling(list);
    if (page?.dataset.analyticsUrl) {
        window.WorkspaceChat.startAnalyticsPolling(page);
        window.WorkspaceChat.bindInsightFilters(page, list);
    }
});
</script>
@endpush
