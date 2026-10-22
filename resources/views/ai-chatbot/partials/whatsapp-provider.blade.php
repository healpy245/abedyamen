@php
    $whapi = app(\App\Services\AiChatbot\WhapiCloudClient::class);
    $provider = old('whatsapp_provider', $whapi->provider());
    $canEdit = $canEdit ?? true;
    $isWhapi = $provider === 'whapi';
    $compact = $compact ?? false;
@endphp
<div data-whatsapp-switch class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
        <div class="flex flex-wrap gap-4 text-sm">
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="whatsapp_provider" value="greenapi" @checked(! $isWhapi) @disabled(! $canEdit)>
                <span>{{ __('chatbot.whapi_green') }}</span>
            </label>
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="whatsapp_provider" value="whapi" @checked($isWhapi) @disabled(! $canEdit)>
                <span>{{ __('chatbot.whapi_whapi') }}</span>
            </label>
        </div>
    </div>
    <div data-provider-panel="whapi" @unless($isWhapi) hidden @endunless class="grid grid-cols-1 lg:grid-cols-[minmax(0,0.7fr)_minmax(0,0.8fr)_minmax(0,2.2fr)] gap-4">
        <div class="space-y-2 min-w-0">
            <label class="kaman-label block" for="whapi_channel">{{ __('chatbot.whapi_channel') }}</label>
            <input id="whapi_channel" type="text" readonly value="{{ $whapi->channelId() }}" class="kaman-input w-full font-mono text-xs bg-[#faf6f0]" dir="ltr">
            @unless($compact)
            <p class="text-xs text-[#a78a6c] break-all">
                <a href="https://panel.whapi.cloud/channels/{{ $whapi->channelId() }}" class="underline" target="_blank" rel="noopener">https://panel.whapi.cloud/channels/{{ $whapi->channelId() }}</a>
            </p>
            @endunless
        </div>
        <div class="space-y-2 min-w-0">
            <label class="kaman-label block" for="whapi_token">{{ __('chatbot.whapi_token') }}</label>
            <input id="whapi_token" name="whapi_token" type="password" autocomplete="new-password" maxlength="2000" class="kaman-input w-full font-mono text-xs" dir="ltr" placeholder="{{ $whapi->token() !== '' ? '••••••••' : '' }}" @disabled(! $canEdit)>
            @unless($compact)
            <p class="text-xs text-[#a78a6c]">{{ __('chatbot.whapi_token_help') }}</p>
            @endunless
        </div>
        <div class="space-y-2 min-w-0">
            <label class="kaman-label block" for="whapi_webhook_url">{{ __('chatbot.whapi_webhook') }}</label>
            <input id="whapi_webhook_url" name="whapi_webhook_url" type="text" maxlength="2000" value="{{ old('whapi_webhook_url', $whapi->webhookUrl()) }}" class="kaman-input w-full font-mono text-xs" dir="ltr" @disabled(! $canEdit)>
            @unless($compact)
            <p class="text-xs text-[#a78a6c]">{{ __('chatbot.whapi_webhook_help') }}</p>
            @endunless
        </div>
    </div>
</div>
<script>
(function () {
    if (window.__kamanWaSwitchBound) return;
    window.__kamanWaSwitchBound = true;

    function applyWhatsappProvider() {
        document.querySelectorAll('[data-whatsapp-switch]').forEach(function (root) {
            var checked = root.querySelector('input[name="whatsapp_provider"]:checked');
            var value = checked ? checked.value : 'greenapi';
            var scope = root.closest('form') || document;
            scope.querySelectorAll('[data-provider-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-provider-panel') !== value;
            });
        });
    }

    function bindWhatsappProvider() {
        document.querySelectorAll('[data-whatsapp-switch] input[name="whatsapp_provider"]').forEach(function (input) {
            input.addEventListener('change', applyWhatsappProvider);
        });
        applyWhatsappProvider();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindWhatsappProvider);
    } else {
        bindWhatsappProvider();
    }
})();
</script>
