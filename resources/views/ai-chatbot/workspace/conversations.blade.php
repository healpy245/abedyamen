@php
    $activeProject = 'ai-chatbot';
@endphp
@extends($instance->hasMansourIntegration() ? 'layouts.farz' : 'layouts.kaman')

@section('title', __('chatbot.workspace.conversations_title').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
@php
    $showKamanAnalytics = is_array($analytics ?? null) && ! $instance->hasMansourIntegration();
    $showInboxAnalytics = is_array($analytics ?? null);
@endphp
<div class="flex flex-col flex-1 min-h-0 w-full {{ $showInboxAnalytics ? 'max-w-[1600px]' : 'max-w-[1400px]' }} mx-auto px-3 sm:px-4 pb-3"
     id="workspace-list"
     data-csrf="{{ csrf_token() }}"
     @if($showInboxAnalytics) data-analytics-url="{{ route('ai-chatbot.workspace.analytics', array_filter(['instance' => $instance, 'line' => is_array($line ?? null) ? $line['id'] : null])) }}" @endif>

    @include('ai-chatbot.workspace.partials.nav')

    <div class="kaman-card overflow-hidden flex flex-col flex-1 min-h-[70vh] md:min-h-[75vh]">
        <div class="grid grid-cols-1 md:grid-cols-[420px_minmax(0,1fr)] {{ $showInboxAnalytics ? 'xl:grid-cols-[440px_minmax(0,1fr)_250px]' : '' }} flex-1 min-h-0">
            @include('ai-chatbot.workspace.partials.conversation-list-pane', [
                'activeId' => null,
                'listPaneClass' => '',
                'showInsight' => $showInboxAnalytics,
                'insightConversationIds' => $analytics['conversation_ids'] ?? [],
            ])

            {{-- Empty detail on list page --}}
            <section id="workspace-chat-slot" class="hidden md:flex flex-col items-center justify-center p-8 text-center bg-[#f7efe3]/40">
                @if($instance->hasMansourIntegration())
                    <div class="farz-empty-chat" aria-hidden="true">{{ \App\Support\FarzUi::svg('chat') }}</div>
                @else
                    <div class="w-16 h-16 rounded-full bg-[#f1dfc5]/80 flex items-center justify-center mb-4 text-2xl text-[#a78a6c]" aria-hidden="true">💬</div>
                @endif
                <p class="text-sm font-medium text-[#2b1e11]">{{ __('chatbot.workspace.select_conversation') }}</p>
                @unless($instance->hasMansourIntegration())
                    <p class="mt-1 text-xs text-[#a78a6c] max-w-xs">{{ __('chatbot.workspace.select_conversation_hint') }}</p>
                @endunless
            </section>

            @if($instance->hasMansourIntegration())
                @include('ai-chatbot.workspace.mansour.analytics', ['placement' => 'desktop'])
            @elseif($showKamanAnalytics)
                @include('ai-chatbot.workspace.partials.kaman-analytics', ['placement' => 'desktop'])
            @endif
        </div>
    </div>

    @if($instance->hasMansourIntegration())
        @include('ai-chatbot.workspace.mansour.analytics', ['placement' => 'mobile'])
        @include('ai-chatbot.workspace.mansour.trigger-modal')
    @elseif($showKamanAnalytics)
        @include('ai-chatbot.workspace.partials.kaman-analytics', ['placement' => 'mobile'])
    @endif

    <div id="toast" class="fixed bottom-4 inset-x-0 mx-auto w-fit max-w-sm hidden z-50 rounded-xl bg-[#2b1e11] text-white text-sm px-4 py-2 shadow-lg" role="status"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-chat.js') }}?v={{ @filemtime(public_path('js/workspace-chat.js')) ?: time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.WorkspaceChat) return;
    const page = document.getElementById('workspace-list');
    const list = document.getElementById('workspace-list-pane');
    window.WorkspaceChat.startListPolling(list);
    if (page?.dataset.analyticsUrl) {
        window.WorkspaceChat.startAnalyticsPolling(page);
        window.WorkspaceChat.bindInsightFilters(page, list);
    }
});
</script>
@endpush
