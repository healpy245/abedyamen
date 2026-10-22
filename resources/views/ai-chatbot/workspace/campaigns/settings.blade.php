@php
    $activeProject = 'ai-chatbot';
    $botOnly = auth()->user()?->isChatbotOnly() ?? false;
    $isWhapi = old('whatsapp_provider', app(\App\Services\AiChatbot\WhapiCloudClient::class)->provider()) === 'whapi';
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.campaigns.settings').' — '.$campaign->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="w-full {{ $botOnly ? 'max-w-6xl' : 'max-w-3xl' }} mx-auto px-3 sm:px-4 pb-10">
    @include('ai-chatbot.workspace.partials.nav')
    @include('ai-chatbot.workspace.campaigns.partials.subnav')

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">{{ $errors->first() }}</div>
    @endif

    @if($botOnly)
        <div class="mb-4 flex flex-row flex-wrap items-center gap-3">
            @include('ai-chatbot.workspace.partials.whatsapp-line-card', [
                'line' => $campaignLine ?? ['name' => $campaign->name, 'phone' => '', 'avatar' => '', 'id' => ''],
                'fallback' => $campaign->name,
            ])
            @include('ai-chatbot.workspace.campaigns.partials.bot-power-toggle', [
                'instance' => $instance,
                'campaign' => $campaign,
                'canToggleBot' => $canControlBot ?? false,
                'compact' => true,
            ])
        </div>
    @else
        <div class="mb-4">
            @include('ai-chatbot.workspace.campaigns.partials.bot-power-toggle', [
                'instance' => $instance,
                'campaign' => $campaign,
                'canToggleBot' => $canControlBot ?? false,
                'compact' => true,
            ])
            <p class="mt-2 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.bot_independent_help') }}</p>
        </div>

        <div class="kaman-card kaman-card--pad mb-4 space-y-2">
            <p class="text-xs text-[#7c6a56]">{{ __('chatbot.workspace.campaigns.separate_greenapi_note') }}</p>
        </div>
    @endif

    <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.settings.update', [$instance, $campaign]) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')

        @if($botOnly)
            <div class="kaman-card kaman-card--pad space-y-4">
                @include('ai-chatbot.partials.whatsapp-provider', ['canEdit' => $canEdit, 'compact' => true])
                <div class="grid grid-cols-1 items-end gap-3 lg:grid-cols-3">
                    <div class="min-w-0">
                        <label class="kaman-label block" for="name">{{ __('chatbot.workspace.campaigns.name') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $campaign->name) }}" required maxlength="160" class="kaman-input w-full" @disabled(! $canEdit)>
                    </div>
                    <div data-provider-panel="greenapi" @if($isWhapi) hidden @endif class="min-w-0 lg:col-span-2 grid grid-cols-1 items-end gap-3 lg:grid-cols-2">
                        <div class="min-w-0">
                            <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.workspace.campaigns.greenapi_url') }}</label>
                            <input id="greenapi_url" name="greenapi_url" type="url" value="{{ old('greenapi_url', $campaign->greenapi_url) }}" class="kaman-input w-full font-mono text-xs" dir="ltr" @disabled(! $canEdit)>
                        </div>
                        <div class="min-w-0">
                            <label class="kaman-label block" for="campaign-webhook-url">{{ __('chatbot.workspace.campaigns.webhook_url') }}</label>
                            <div class="flex gap-2">
                                <input id="campaign-webhook-url" name="greenapi_webhook_url" type="text" maxlength="2000" value="{{ old('greenapi_webhook_url', $webhookUrl) }}" class="kaman-input min-w-0 flex-1 font-mono text-xs" dir="ltr" @disabled(! $canEdit)>
                                <button type="button" class="kaman-button-ghost kaman-button--sm shrink-0" onclick="navigator.clipboard.writeText(document.getElementById('campaign-webhook-url').value)">{{ __('chatbot.copy') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kaman-card kaman-card--pad">
                <label class="kaman-label block" for="opening_message">{{ __('chatbot.workspace.campaigns.opening_message') }}</label>
                <textarea id="opening_message" name="opening_message" rows="3" required class="kaman-input w-full" @disabled(! $canEdit)>{{ old('opening_message', $campaign->opening_message) }}</textarea>
            </div>
            <div class="kaman-card kaman-card--pad">
                <label class="kaman-label mb-2 block text-base" for="system_prompt">{{ __('chatbot.workspace.campaigns.system_prompt') }}</label>
                <textarea id="system_prompt" name="system_prompt" data-prompt-fit required class="kaman-input w-full" style="min-height:72vh;font-size:1.0625rem;line-height:1.85;padding:1.25rem 1.35rem;overflow-anchor:none;" @disabled(! $canEdit)>{{ old('system_prompt', $campaign->system_prompt ?: $campaign->resolvedSystemPrompt()) }}</textarea>
            </div>
            <div class="kaman-card kaman-card--pad">
                <label class="kaman-label block" for="excel">{{ __('chatbot.workspace.campaigns.excel_replace') }}</label>
                @if($campaign->excel_original_name)
                    <p class="mb-1 text-xs text-[#7c6a56]">{{ $campaign->excel_original_name }} ({{ $campaign->contacts_count }})</p>
                @endif
                <input id="excel" name="excel" type="file" accept=".xlsx,.csv,.txt" class="kaman-input w-full" @disabled(! $canEdit)>
            </div>
        @else
            <div class="kaman-card kaman-card--pad space-y-3">
                <div>
                    <label class="kaman-label block" for="name">{{ __('chatbot.workspace.campaigns.name') }}</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $campaign->name) }}" required maxlength="160" class="kaman-input w-full" @disabled(! $canEdit)>
                </div>
                <div>
                    <label class="kaman-label block" for="campaign-webhook-url">{{ __('chatbot.workspace.campaigns.webhook_url') }}</label>
                    <div class="flex gap-2">
                        <input id="campaign-webhook-url" name="greenapi_webhook_url" type="text" maxlength="2000" value="{{ old('greenapi_webhook_url', $webhookUrl) }}" class="kaman-input w-full font-mono text-xs" dir="ltr" @disabled(! $canEdit)>
                        <button type="button" class="kaman-button-ghost !min-h-0 shrink-0" onclick="navigator.clipboard.writeText(document.getElementById('campaign-webhook-url').value)">{{ __('chatbot.copy') }}</button>
                    </div>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.webhook_help') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.workspace.campaigns.greenapi_url') }}</label>
                    <input id="greenapi_url" name="greenapi_url" type="url" value="{{ old('greenapi_url', $campaign->greenapi_url) }}" class="kaman-input w-full font-mono text-xs" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.greenapi_url_help') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="opening_message">{{ __('chatbot.workspace.campaigns.opening_message') }}</label>
                    <textarea id="opening_message" name="opening_message" rows="4" required class="kaman-input w-full" @disabled(! $canEdit)>{{ old('opening_message', $campaign->opening_message) }}</textarea>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.opening_message_help') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="system_prompt">{{ __('chatbot.workspace.campaigns.system_prompt') }}</label>
                    <textarea id="system_prompt" name="system_prompt" rows="12" required class="kaman-input w-full font-mono text-xs leading-relaxed" @disabled(! $canEdit)>{{ old('system_prompt', $campaign->system_prompt ?: $campaign->resolvedSystemPrompt()) }}</textarea>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.system_prompt_help') }}</p>
                </div>
                <div>
                    <label class="kaman-label block" for="excel">{{ __('chatbot.workspace.campaigns.excel_replace') }}</label>
                    @if($campaign->excel_original_name)
                        <p class="mb-1 text-xs text-[#7c6a56]">{{ __('chatbot.workspace.campaigns.file_current') }}: {{ $campaign->excel_original_name }} ({{ $campaign->contacts_count }})</p>
                    @endif
                    <input id="excel" name="excel" type="file" accept=".xlsx,.csv,.txt" class="kaman-input w-full" @disabled(! $canEdit)>
                    <p class="mt-1 text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.excel_help') }}</p>
                </div>
            </div>
        @endif

        @if($canEdit)
            <div class="flex justify-end">
                <button type="submit" class="kaman-button">{{ __('chatbot.workspace.campaigns.save') }}</button>
            </div>
        @else
            <p class="text-sm text-amber-700">{{ __('chatbot.workspace.campaigns.stop') }} — {{ $campaign->status }}</p>
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
@endsection
