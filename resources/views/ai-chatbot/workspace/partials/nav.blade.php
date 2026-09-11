@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    /** @var \App\Models\Malan\MalanCampaign|null $campaign */
    $canToggleBot = $canManageSettings ?? $canControlBot ?? false;
    $isConversations = request()->routeIs('ai-chatbot.workspace.conversations*');
    $isCampaigns = request()->routeIs('ai-chatbot.workspace.campaigns*');
    $isSettings = request()->routeIs('ai-chatbot.workspace.settings');
    $isTest = request()->routeIs('ai-chatbot.workspace.test*');
    $hideGlobalBotToggle = (bool) ($hideGlobalBotToggle ?? false);
    $campaign = $campaign ?? null;
@endphp
<div class="mb-3 flex flex-wrap items-center justify-between gap-3 pt-3">
    <div class="flex flex-wrap items-center gap-2 min-w-0">
        <h1 class="text-lg font-semibold text-[#2b1e11] truncate">{{ $instance->name }}</h1>
        @if($campaign)
            @include('ai-chatbot.workspace.campaigns.partials.bot-power-toggle', [
                'instance' => $instance,
                'campaign' => $campaign,
                'canToggleBot' => $canToggleBot,
                'compact' => true,
            ])
        @elseif(! $hideGlobalBotToggle)
            @include('ai-chatbot.workspace.partials.bot-power-toggle', [
                'instance' => $instance,
                'canToggleBot' => $canToggleBot,
                'compact' => true,
            ])
        @endif
    </div>
    <nav class="flex flex-wrap items-center gap-1.5" aria-label="{{ __('chatbot.workspace.nav') }}">
        <a href="{{ route('ai-chatbot.workspace.conversations', $instance) }}"
           title="{{ __('chatbot.workspace.nav_conversations') }}"
           aria-label="{{ __('chatbot.workspace.nav_conversations') }}"
           class="kaman-button-ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !min-h-0 !p-0 rounded-full {{ $isConversations ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            <x-workspace.icon name="chat-bubble-left-right" class="h-5 w-5" />
        </a>
        @if($instance->hasMalanIntegration())
            <a href="{{ route('ai-chatbot.workspace.campaigns', $instance) }}"
               title="{{ __('chatbot.workspace.nav_campaigns') }}"
               aria-label="{{ __('chatbot.workspace.nav_campaigns') }}"
               class="kaman-button-ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !min-h-0 !p-0 rounded-full {{ $isCampaigns ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
                <x-workspace.icon name="megaphone" class="h-5 w-5" />
            </a>
        @endif
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
        @if($showInstanceSwitcher ?? false)
            <a href="{{ route('ai-chatbot.index') }}"
               title="{{ __('chatbot.choose.switch') }}"
               aria-label="{{ __('chatbot.choose.switch') }}"
               class="kaman-button-ghost kaman-button--sm !min-h-0 h-10 px-3">
                {{ __('chatbot.choose.switch') }}
            </a>
        @endif
    </nav>
</div>
