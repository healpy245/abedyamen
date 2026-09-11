@php
    $activeProject = 'ai-chatbot';
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.campaigns.settings').' — '.$campaign->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="w-full max-w-3xl mx-auto px-3 sm:px-4 pb-10">
    @include('ai-chatbot.workspace.partials.nav')
    @include('ai-chatbot.workspace.campaigns.partials.subnav')

    @if(session('status'))
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50/70 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">{{ $errors->first() }}</div>
    @endif

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
        <label class="kaman-label block">{{ __('chatbot.workspace.campaigns.webhook_url') }}</label>
        <div class="flex gap-2">
            <input id="campaign-webhook-url" type="text" readonly value="{{ $webhookUrl }}" class="kaman-input w-full font-mono text-xs">
            <button type="button" class="kaman-button-ghost !min-h-0 shrink-0" onclick="navigator.clipboard.writeText(document.getElementById('campaign-webhook-url').value)">{{ __('chatbot.copy') }}</button>
        </div>
        <p class="text-xs text-[#a78a6c]">{{ __('chatbot.workspace.campaigns.webhook_help') }}</p>
        <p class="text-xs text-[#7c6a56]">{{ __('chatbot.workspace.campaigns.separate_greenapi_note') }}</p>
    </div>

    <form method="post" action="{{ route('ai-chatbot.workspace.campaigns.settings.update', [$instance, $campaign]) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')

        <div class="kaman-card kaman-card--pad space-y-3">
            <div>
                <label class="kaman-label block" for="name">{{ __('chatbot.workspace.campaigns.name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name', $campaign->name) }}" required maxlength="160" class="kaman-input w-full" @disabled(! $canEdit)>
            </div>
            <div>
                <label class="kaman-label block" for="greenapi_url">{{ __('chatbot.workspace.campaigns.greenapi_url') }}</label>
                <input id="greenapi_url" name="greenapi_url" type="url" value="{{ old('greenapi_url', $campaign->greenapi_url) }}" required class="kaman-input w-full font-mono text-xs" @disabled(! $canEdit)>
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

        @if($canEdit)
            <button type="submit" class="kaman-button">{{ __('chatbot.workspace.campaigns.save') }}</button>
        @else
            <p class="text-sm text-amber-700">{{ __('chatbot.workspace.campaigns.stop') }} — {{ $campaign->status }}</p>
        @endif
    </form>
</div>
@endsection
