{{-- Conversations list sidebar (RTL: sits on the inline-start / right side) --}}
@php
    $activeId = $activeId ?? null;
    $filter = $filter ?? 'all';
    $search = $search ?? '';
@endphp
<aside class="border-e border-[#eadfce] flex flex-col min-h-0 bg-[#fffaf3]/60 {{ $listPaneClass ?? '' }}"
       id="workspace-list-pane"
       data-poll-url="{{ route('ai-chatbot.workspace.conversations.poll', $instance) }}"
       data-filter="{{ $filter }}"
       data-q="{{ $search }}"
       data-list-labels="{{ e(json_encode([
           'mode_human' => __('chatbot.workspace.mode_human'),
           'mode_paused' => __('chatbot.workspace.mode_paused'),
           'needs_attention' => __('chatbot.workspace.needs_attention'),
       ], JSON_UNESCAPED_UNICODE)) }}">
    <div class="p-3 border-b border-[#eadfce] space-y-2">
        <form method="get" action="{{ route('ai-chatbot.workspace.conversations', $instance) }}" class="flex gap-2">
            <label class="sr-only" for="workspace-search">{{ __('chatbot.workspace.search') }}</label>
            <input id="workspace-search" name="q" value="{{ $search }}" type="search"
                   placeholder="{{ __('chatbot.workspace.search_placeholder') }}"
                   class="kaman-input !h-9 flex-1 text-sm">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <button type="submit" class="kaman-button kaman-button--sm">{{ __('chatbot.workspace.search') }}</button>
        </form>
        <div class="flex flex-wrap gap-1" role="tablist" aria-label="{{ __('chatbot.workspace.filters') }}">
            @foreach([
                'all' => __('chatbot.workspace.filter_all'),
                'unread' => __('chatbot.workspace.filter_unread'),
                'needs_attention' => __('chatbot.workspace.filter_needs_attention'),
                'human_takeover' => __('chatbot.workspace.filter_human'),
                'bot_active' => __('chatbot.workspace.filter_bot_active'),
            ] as $key => $label)
                <a href="{{ route('ai-chatbot.workspace.conversations', ['instance' => $instance, 'filter' => $key, 'q' => $search]) }}"
                   class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition
                   {{ $filter === $key ? 'border-[#f47a2e]/40 bg-[#f47a2e]/12 text-[#f16229]' : 'border-[#eadfce] text-[#7c6a56] hover:border-[#f47a2e]/30' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div id="conversation-list" class="flex-1 overflow-y-auto kaman-scroll" aria-live="polite">
        @forelse($conversations as $c)
            @include('ai-chatbot.workspace.partials.conversation-row', ['c' => $c, 'activeId' => $activeId])
        @empty
            <div class="p-6 text-center text-sm text-[#a78a6c]" id="list-empty">
                {{ __('chatbot.workspace.empty_conversations') }}
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <div class="p-2 border-t border-[#eadfce]">{{ $conversations->links() }}</div>
    @endif
</aside>
