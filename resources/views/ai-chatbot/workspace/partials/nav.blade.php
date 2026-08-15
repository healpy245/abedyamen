@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $canToggleBot = $canManageSettings ?? $canControlBot ?? false;
    $isConversations = request()->routeIs('ai-chatbot.workspace.conversations*');
    $isSettings = request()->routeIs('ai-chatbot.workspace.settings');
    $isTest = request()->routeIs('ai-chatbot.workspace.test*');
@endphp
<div class="mb-3 flex flex-wrap items-center justify-between gap-3 pt-3">
    <div class="flex flex-wrap items-center gap-2 min-w-0">
        <h1 class="text-lg font-semibold text-[#2b1e11] truncate">{{ $instance->name }}</h1>
        @include('ai-chatbot.workspace.partials.bot-power-toggle', [
            'instance' => $instance,
            'canToggleBot' => $canToggleBot,
            'compact' => true,
        ])
    </div>
    <nav class="flex flex-wrap items-center gap-1.5" aria-label="{{ __('chatbot.workspace.nav') }}">
        <a href="{{ route('ai-chatbot.workspace.conversations', $instance) }}"
           title="{{ __('chatbot.workspace.nav_conversations') }}"
           aria-label="{{ __('chatbot.workspace.nav_conversations') }}"
           class="kaman-button-ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !min-h-0 !p-0 rounded-full {{ $isConversations ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            <x-workspace.icon name="chat-bubble-left-right" class="h-5 w-5" />
        </a>
        <a href="{{ route('ai-chatbot.workspace.test.page', $instance) }}"
           title="{{ __('chatbot.workspace.nav_test') }}"
           aria-label="{{ __('chatbot.workspace.nav_test') }}"
           class="kaman-button-ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !min-h-0 !p-0 rounded-full {{ $isTest ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            <x-workspace.icon name="beaker" class="h-5 w-5" />
        </a>
        <a href="{{ route('ai-chatbot.workspace.settings', $instance) }}"
           title="{{ __('chatbot.workspace.nav_settings') }}"
           aria-label="{{ __('chatbot.workspace.nav_settings') }}"
           class="kaman-button-ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !min-h-0 !p-0 rounded-full {{ $isSettings ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            <x-workspace.icon name="cog-6-tooth" class="h-5 w-5" />
        </a>
        @if(($showInstanceSwitcher ?? false) && isset($instances))
            <select onchange="if(this.value) window.location=this.value" class="kaman-input !h-8 !text-xs !py-0 max-w-[10rem]" aria-label="{{ __('chatbot.instances') }}">
                @foreach($instances as $opt)
                    <option value="{{ route('ai-chatbot.workspace.conversations', $opt) }}" @selected($opt->id === $instance->id)>{{ $opt->name }}</option>
                @endforeach
            </select>
        @endif
    </nav>
</div>
