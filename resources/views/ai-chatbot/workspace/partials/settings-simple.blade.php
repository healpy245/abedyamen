@php
    $lines = $whatsappLines ?? [];
    $canEdit = ($canManageSettings ?? false) || ($canManageIntegration ?? false);
    $isWhapi = old('whatsapp_provider', app(\App\Services\AiChatbot\WhapiCloudClient::class)->provider()) === 'whapi';
    $promptText = old('system_prompt', $compiledPreview ?? $instance->system_prompt);
@endphp

<div class="flex flex-col gap-4">
    <div class="flex flex-row flex-wrap items-stretch gap-3">
        @forelse($lines as $line)
            @include('ai-chatbot.workspace.partials.whatsapp-line-card', ['line' => $line, 'fallback' => $instance->name])
        @empty
            @include('ai-chatbot.workspace.partials.whatsapp-line-card', ['line' => ['name' => $instance->name, 'phone' => '', 'avatar' => '', 'id' => ''], 'fallback' => $instance->name])
        @endforelse

        <div class="flex items-center">
            @include('ai-chatbot.workspace.partials.bot-power-toggle', [
                'instance' => $instance,
                'canToggleBot' => $canManageSettings || ($canControlBot ?? false),
                'compact' => true,
            ])
        </div>
    </div>

    <form method="post" action="{{ route('ai-chatbot.workspace.settings.update', $instance) }}" class="flex flex-col gap-4">
        @csrf
        @method('PUT')

        <div class="kaman-card kaman-card--pad space-y-4">
            @include('ai-chatbot.partials.whatsapp-provider', ['canEdit' => $canEdit, 'compact' => true])
            <div class="grid grid-cols-1 items-end gap-3 lg:grid-cols-3">
                <div class="min-w-0">
                    <label class="kaman-label block" for="name">{{ __('chatbot.instance_name') }}</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $instance->name) }}" class="kaman-input w-full" maxlength="120" @disabled(! $canEdit)>
                </div>
                <div data-provider-panel="greenapi" @if($isWhapi) hidden @endif class="min-w-0 lg:col-span-2 grid grid-cols-1 items-end gap-3 lg:grid-cols-2">
                <div class="min-w-0">
                    <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.greenapi_send_url') }}</label>
                    <input id="greenapi_url"
                           name="greenapi_url"
                           type="url"
                           maxlength="2000"
                           value="{{ old('greenapi_url', $instance->greenapi_url) }}"
                           placeholder="{{ __('chatbot.greenapi_send_url_placeholder') }}"
                           class="kaman-input w-full font-mono text-xs"
                           dir="ltr"
                           @disabled(! $canEdit)>
                </div>
                <div class="min-w-0">
                    <label class="kaman-label block" for="greenapi_webhook_url">{{ __('chatbot.greenapi_webhook_url') }}</label>
                    <div class="flex gap-2">
                        <input id="greenapi_webhook_url"
                               name="greenapi_webhook_url"
                               type="text"
                               maxlength="2000"
                               value="{{ old('greenapi_webhook_url', $greenapiWebhookUrl) }}"
                               class="kaman-input min-w-0 flex-1 font-mono text-xs"
                               dir="ltr"
                               @disabled(! $canEdit)>
                        <button type="button"
                                class="kaman-button-ghost kaman-button--sm shrink-0"
                                onclick="navigator.clipboard.writeText(document.getElementById('greenapi_webhook_url').value)">
                            {{ __('chatbot.copy') }}
                        </button>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <section class="kaman-card kaman-card--pad">
            <label class="kaman-label mb-2 block text-base" for="prompt_description">{{ __('chatbot.system_prompt') }}</label>
            <textarea id="prompt_description"
                      name="system_prompt"
                      data-prompt-fit
                      class="kaman-input w-full"
                      style="min-height:72vh;font-size:1.0625rem;line-height:1.85;padding:1.25rem 1.35rem;overflow-anchor:none;"
                      @disabled(! $canManageSettings)>{{ $promptText }}</textarea>
        </section>

        @if($canEdit)
            <div class="flex justify-end">
                <button type="submit" class="kaman-button">{{ __('chatbot.workspace.save_settings') }}</button>
            </div>
        @endif
    </form>
</div>
@once('prompt-fit')
<script>
(function () {
    function fitOnce(el) {
        var pageY = window.scrollY;
        var pageX = window.scrollX;
        el.style.height = 'auto';
        var min = Math.round(window.innerHeight * 0.72);
        el.style.height = Math.max(el.scrollHeight + 24, min) + 'px';
        el.style.overflowY = 'auto';
        window.scrollTo(pageX, pageY);
    }
    document.querySelectorAll('[data-prompt-fit]').forEach(function (el) {
        var lock = null;
        var restoring = false;
        function keep() {
            if (!lock || restoring || Date.now() > lock.until) return;
            restoring = true;
            if (el.scrollTop !== lock.top) el.scrollTop = lock.top;
            if (window.scrollY !== lock.pageY || window.scrollX !== lock.pageX) window.scrollTo(lock.pageX, lock.pageY);
            restoring = false;
        }
        el.addEventListener('scroll', keep);
        window.addEventListener('scroll', keep);
        el.addEventListener('beforeinput', function () {
            lock = { top: el.scrollTop, pageY: window.scrollY, pageX: window.scrollX, until: Date.now() + 300 };
        });
        el.addEventListener('input', function () {
            requestAnimationFrame(keep);
        });
    });
    function fitAll() {
        document.querySelectorAll('[data-prompt-fit]').forEach(fitOnce);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fitAll);
    } else {
        fitAll();
    }
})();
</script>
@endonce
