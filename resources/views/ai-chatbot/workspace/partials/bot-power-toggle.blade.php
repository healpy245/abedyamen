@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    $botActive = $instance->isBotGloballyActive();
    $canToggle = $canToggleBot ?? ($canManageSettings ?? $canControlBot ?? false);
    $compact = $compact ?? false;
@endphp

@if($compact)
    <div class="bot-power bot-power--compact {{ $botActive ? 'is-on' : 'is-off' }}" data-bot-power>
        @if($canToggle)
            <form method="post" action="{{ route('ai-chatbot.workspace.bot-active', $instance) }}" class="bot-power__form">
                @csrf
                <input type="hidden" name="is_active" value="{{ $botActive ? '0' : '1' }}">
                <button type="submit"
                        class="bot-power__pill"
                        aria-pressed="{{ $botActive ? 'true' : 'false' }}"
                        title="{{ $botActive ? __('chatbot.workspace.deactivate_bot') : __('chatbot.workspace.activate_bot') }}">
                    <span class="bot-power__dot" aria-hidden="true"></span>
                    <span class="bot-power__pill-label">
                        {{ $botActive ? __('chatbot.workspace.bot_on') : __('chatbot.workspace.bot_off') }}
                    </span>
                </button>
            </form>
        @else
            <span class="bot-power__pill bot-power__pill--readonly" aria-live="polite">
                <span class="bot-power__dot" aria-hidden="true"></span>
                <span class="bot-power__pill-label">
                    {{ $botActive ? __('chatbot.workspace.bot_on') : __('chatbot.workspace.bot_off') }}
                </span>
            </span>
        @endif
    </div>
@else
    <div class="bot-power bot-power--card {{ $botActive ? 'is-on' : 'is-off' }}" data-bot-power>
        <div class="bot-power__glow" aria-hidden="true"></div>
        <div class="bot-power__content">
            <div class="bot-power__copy">
                <p class="bot-power__eyebrow">{{ __('chatbot.workspace.global_bot') }}</p>
                <h2 class="bot-power__title">
                    {{ $botActive ? __('chatbot.workspace.bot_status_active_title') : __('chatbot.workspace.bot_status_inactive_title') }}
                </h2>
                <p class="bot-power__desc">
                    {{ $botActive ? __('chatbot.workspace.bot_status_active_desc') : __('chatbot.workspace.bot_status_inactive_desc') }}
                </p>
            </div>

            @if($canToggle)
                <div class="bot-power__switch" role="group" aria-label="{{ __('chatbot.workspace.global_bot') }}">
                    <form method="post" action="{{ route('ai-chatbot.workspace.bot-active', $instance) }}" class="bot-power__option-form">
                        @csrf
                        <input type="hidden" name="is_active" value="1">
                        <button type="submit"
                                class="bot-power__option bot-power__option--on {{ $botActive ? 'is-selected' : '' }}"
                                @disabled($botActive)
                                aria-pressed="{{ $botActive ? 'true' : 'false' }}">
                            <span class="bot-power__option-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" width="22" height="22"><path d="M12 3v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M8.2 6.4a7 7 0 1 0 7.6 0" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                            </span>
                            <span class="bot-power__option-text">
                                <strong>{{ __('chatbot.workspace.activate_bot') }}</strong>
                                <small>{{ __('chatbot.workspace.activate_bot_hint') }}</small>
                            </span>
                        </button>
                    </form>
                    <form method="post" action="{{ route('ai-chatbot.workspace.bot-active', $instance) }}" class="bot-power__option-form">
                        @csrf
                        <input type="hidden" name="is_active" value="0">
                        <button type="submit"
                                class="bot-power__option bot-power__option--off {{ ! $botActive ? 'is-selected' : '' }}"
                                @disabled(! $botActive)
                                aria-pressed="{{ ! $botActive ? 'true' : 'false' }}">
                            <span class="bot-power__option-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" width="22" height="22"><path d="M12 3v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M8.2 6.4a7 7 0 1 0 7.6 0" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                            </span>
                            <span class="bot-power__option-text">
                                <strong>{{ __('chatbot.workspace.deactivate_bot') }}</strong>
                                <small>{{ __('chatbot.workspace.deactivate_bot_hint') }}</small>
                            </span>
                        </button>
                    </form>
                </div>
            @else
                <div class="bot-power__readonly-badge">
                    {{ $botActive ? __('chatbot.workspace.bot_on') : __('chatbot.workspace.bot_off') }}
                </div>
            @endif
        </div>
    </div>
@endif
