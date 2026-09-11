@php
    /** @var \App\Models\Malan\MalanCampaign $campaign */
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $isChats = request()->routeIs('ai-chatbot.workspace.campaigns.show');
    $isTest = request()->routeIs('ai-chatbot.workspace.campaigns.test*');
    $isSettings = request()->routeIs('ai-chatbot.workspace.campaigns.settings*');
@endphp
<div class="mb-3 flex flex-wrap items-center justify-between gap-2">
    <div class="min-w-0">
        <a href="{{ route('ai-chatbot.workspace.campaigns', $instance) }}" class="text-xs text-[#a78a6c] hover:text-[#f16229]">← {{ __('chatbot.workspace.campaigns.back') }}</a>
        <h2 class="text-lg font-semibold text-[#2b1e11] truncate">{{ $campaign->name }}</h2>
        <p class="text-[11px] text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.leads_only_note') }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-1.5">
        <a href="{{ route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign]) }}"
           class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs {{ $isChats ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            {{ __('chatbot.workspace.campaigns.conversations') }}
        </a>
        <a href="{{ route('ai-chatbot.workspace.campaigns.test.page', [$instance, $campaign]) }}"
           class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs {{ $isTest ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            {{ __('chatbot.workspace.campaigns.test') }}
        </a>
        <a href="{{ route('ai-chatbot.workspace.campaigns.settings', [$instance, $campaign]) }}"
           class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs {{ $isSettings ? '!border-[#f47a2e]/40 !text-[#f16229]' : '' }}">
            {{ __('chatbot.workspace.campaigns.settings') }}
        </a>
        @if($canControlBot ?? false)
            @if($campaign->canStop())
                <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.stop', [$instance, $campaign]) }}">
                    @csrf
                    <button type="submit" class="kaman-button-ghost !min-h-0 !py-1.5 !px-3 text-xs">{{ __('chatbot.workspace.campaigns.stop') }}</button>
                </form>
            @elseif($campaign->canStart())
                <button type="button"
                        class="kaman-button !min-h-0 !py-1.5 !px-3 text-xs"
                        data-campaign-trigger-open
                        data-contacts-url="{{ route('ai-chatbot.workspace.campaigns.contacts', [$instance, $campaign]) }}"
                        data-store-contact-url="{{ route('ai-chatbot.workspace.campaigns.contacts.store', [$instance, $campaign]) }}"
                        data-start-url="{{ route('ai-chatbot.workspace.campaigns.start', [$instance, $campaign]) }}">
                    {{ __('chatbot.workspace.campaigns.start') }}
                </button>
            @endif
        @endif
    </div>
</div>

@once('campaign-trigger-modal')
    @include('ai-chatbot.workspace.campaigns.partials.trigger-modal')
@endonce
@pushOnce('scripts', 'campaign-trigger-js')
    <script src="{{ asset('js/campaign-trigger.js') }}?v={{ @filemtime(public_path('js/campaign-trigger.js')) ?: time() }}" defer></script>
@endPushOnce
