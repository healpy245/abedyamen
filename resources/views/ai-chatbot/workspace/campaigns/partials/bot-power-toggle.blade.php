@php
    /** @var \App\Models\Malan\MalanCampaign $campaign */
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $botActive = $campaign->isBotActive();
    $canToggle = $canToggleBot ?? ($canManageSettings ?? $canControlBot ?? false);
    $compact = $compact ?? false;
@endphp

@if($compact)
    <div class="bot-power bot-power--compact {{ $botActive ? 'is-on' : 'is-off' }}" data-bot-power>
        @if($canToggle)
            <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.bot-active', [$instance, $campaign]) }}" class="bot-power__form">
                @csrf
                <input type="hidden" name="is_active" value="{{ $botActive ? '0' : '1' }}">
                <button type="submit"
                        class="bot-power__pill"
                        aria-pressed="{{ $botActive ? 'true' : 'false' }}"
                        title="{{ $botActive ? __('chatbot.workspace.campaigns.deactivate_bot') : __('chatbot.workspace.campaigns.activate_bot') }}">
                    <span class="bot-power__dot" aria-hidden="true"></span>
                    <span class="bot-power__pill-label">
                        {{ $botActive ? __('chatbot.workspace.campaigns.bot_on') : __('chatbot.workspace.campaigns.bot_off') }}
                    </span>
                </button>
            </form>
        @else
            <span class="bot-power__pill bot-power__pill--readonly" aria-live="polite">
                <span class="bot-power__dot" aria-hidden="true"></span>
                <span class="bot-power__pill-label">
                    {{ $botActive ? __('chatbot.workspace.campaigns.bot_on') : __('chatbot.workspace.campaigns.bot_off') }}
                </span>
            </span>
        @endif
    </div>
@endif
