@php
    /** @var array<string,mixed> $settings */
    $refChars = $settings['typing_reference_chars'] ?? 96;
    $refSeconds = $settings['typing_reference_seconds'] ?? 15;
@endphp

<div class="page-container">
    <div class="mx-auto w-full max-w-4xl">

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="kaman-eyebrow mb-2">{{ __('chatbot.studio') }}</p>
                <h1 class="text-2xl font-semibold text-[#2b1e11]">{{ __('chatbot.settings') }}</h1>
                <p class="mt-1 text-sm text-[#7c6a56]">
                    {{ __('chatbot.settings_desc') }}
                </p>
            </div>
            <a href="{{ route('ai-chatbot.index') }}" class="kaman-button-ghost kaman-button--sm">
                {{ __('chatbot.back_to_chat') }}
            </a>
        </div>

        @if(session('status'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form action="{{ route('ai-chatbot.admin.settings.update') }}" method="post" class="kaman-card kaman-card--pad space-y-5">
            @csrf

            <input type="hidden" name="action" id="aiChatbotSettingsAction" value="save">

            <div class="space-y-2">
                <label for="system_prompt" class="kaman-label block">{{ __('chatbot.system_prompt') }}</label>
                <p class="text-xs text-[#a78a6c]">
                    {{ __('chatbot.system_prompt_help') }}
                </p>
                <textarea id="system_prompt" name="system_prompt" rows="5"
                          class="kaman-input kaman-scroll w-full font-mono leading-relaxed">{{ old('system_prompt', $settings['system_prompt'] ?? '') }}</textarea>
                @error('system_prompt')
                <p class="text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="space-y-2">
                    <label for="chatbot_model" class="kaman-label block">{{ __('chatbot.model') }}</label>
                    <input id="chatbot_model" name="chatbot_model" type="text"
                           value="{{ old('chatbot_model', $settings['chatbot_model'] ?? 'gpt-4o-mini') }}"
                           class="kaman-input w-full">
                    @error('chatbot_model')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="temperature" class="kaman-label block">{{ __('chatbot.temperature') }}</label>
                    <input id="temperature" name="temperature" type="number" step="0.1" min="0" max="2"
                           value="{{ old('temperature', $settings['temperature'] ?? 0.7) }}"
                           class="kaman-input w-full">
                    @error('temperature')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="max_tokens" class="kaman-label block">{{ __('chatbot.max_tokens') }}</label>
                    <input id="max_tokens" name="max_tokens" type="number" min="1" max="8000"
                           value="{{ old('max_tokens', $settings['max_tokens'] ?? 2000) }}"
                           class="kaman-input w-full">
                    @error('max_tokens')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="space-y-4 border-t border-[#f1dfc5] pt-6">
                <div>
                    <h2 class="text-base font-semibold text-[#2b1e11]">{{ __('chatbot.typing_delay') }}</h2>
                    <p class="mt-1 text-xs text-[#a78a6c]">
                        {{ __('chatbot.typing_delay_help', ['chars' => $refChars, 'seconds' => $refSeconds]) }}
                    </p>
                </div>

                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-[#7c6a56]">
                    <input type="hidden" name="typing_delay_enabled" value="0">
                    <input type="checkbox" name="typing_delay_enabled" value="1"
                           class="h-4 w-4 rounded border-[#f1dfc5] text-[#f47a2e] focus:ring-[#f59f43]"
                           @checked(old('typing_delay_enabled', $settings['typing_delay_enabled'] ?? true))>
                    <span>{{ __('chatbot.enable_typing_delay') }}</span>
                </label>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="space-y-2">
                        <label for="typing_reference_chars" class="kaman-label block">{{ __('chatbot.reference_length') }}</label>
                        <input id="typing_reference_chars" name="typing_reference_chars" type="number" min="10" max="2000"
                               value="{{ old('typing_reference_chars', $settings['typing_reference_chars'] ?? 96) }}"
                               class="kaman-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label for="typing_reference_seconds" class="kaman-label block">{{ __('chatbot.reference_delay') }}</label>
                        <input id="typing_reference_seconds" name="typing_reference_seconds" type="number" min="1" max="120"
                               value="{{ old('typing_reference_seconds', $settings['typing_reference_seconds'] ?? 15) }}"
                               class="kaman-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label for="typing_min_seconds" class="kaman-label block">{{ __('chatbot.min_delay') }}</label>
                        <input id="typing_min_seconds" name="typing_min_seconds" type="number" min="0" max="120"
                               value="{{ old('typing_min_seconds', $settings['typing_min_seconds'] ?? 2) }}"
                               class="kaman-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label for="typing_max_seconds" class="kaman-label block">{{ __('chatbot.max_delay') }}</label>
                        <input id="typing_max_seconds" name="typing_max_seconds" type="number" min="1" max="300"
                               value="{{ old('typing_max_seconds', $settings['typing_max_seconds'] ?? 45) }}"
                               class="kaman-input w-full">
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#f1dfc5] pt-6">
                <p class="text-xs text-[#a78a6c] max-w-sm">
                    {{ __('chatbot.settings_footer') }}
                </p>
                <div class="flex items-center gap-2">
                    <button type="button"
                            onclick="if(confirm(@json(__('chatbot.reset_confirm')))) { document.getElementById('aiChatbotSettingsAction').value = 'reset'; this.form.submit(); }"
                            class="kaman-button-danger kaman-button--sm">
                        {{ __('chatbot.reset_defaults') }}
                    </button>
                    <button type="submit" class="kaman-button">
                        {{ __('chatbot.save_changes') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
