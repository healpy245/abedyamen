@php
    $activeProject = 'ai-chatbot';
    $testChatI18n = [
        'empty' => __('chatbot.workspace.test_chat_empty'),
        'placeholder' => __('chatbot.workspace.test_chat_placeholder'),
        'send' => __('chatbot.workspace.send'),
        'new_chat' => __('chatbot.workspace.test_new_chat'),
        'mic' => __('chatbot.workspace.test_mic'),
        'mic_stop' => __('chatbot.workspace.test_mic_stop'),
        'call' => __('chatbot.workspace.test_call'),
        'call_end' => __('chatbot.workspace.test_call_end'),
        'listening' => __('chatbot.workspace.test_listening'),
        'speaking' => __('chatbot.workspace.test_speaking'),
        'processing' => __('voice.phone.processing'),
        'unsupported' => __('chatbot.workspace.test_voice_unsupported'),
        'error' => __('chatbot.workspace.test_send_error'),
        'attach' => __('chatbot.attach_image'),
        'attach_hint' => __('chatbot.attach_image_hint'),
        'attach_clear' => __('chatbot.attach_image_clear'),
        'attach_error' => __('chatbot.attach_error'),
        'attach_invalid' => __('chatbot.attach_invalid_type'),
        'attachment_file' => __('chatbot.attachment_file'),
    ];
@endphp
@extends('layouts.kaman')

@section('title', __('chatbot.workspace.campaigns.test').' — '.$campaign->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[900px] mx-auto px-3 sm:px-4 pb-3">
    @include('ai-chatbot.workspace.partials.nav')
    @include('ai-chatbot.workspace.campaigns.partials.subnav')

    <div id="test-panel"
         class="kaman-card overflow-hidden border border-[#eadfce] flex flex-col flex-1 min-h-[70vh]"
         data-url="{{ route('ai-chatbot.workspace.campaigns.test', [$instance, $campaign]) }}"
         data-image-url=""
         data-tts-url=""
         data-stream-url=""
         data-streaming="0"
         data-silence-ms="500"
         data-latency-overlay="0"
         data-csrf="{{ csrf_token() }}"
         data-i18n='@json($testChatI18n)'>
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-[#eadfce] bg-[#fffaf3]/80 shrink-0">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="kaman-chip text-xs bg-amber-100 text-amber-800 border-amber-200">{{ __('chatbot.workspace.simulation_only') }}</span>
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.campaigns.test_title') }}</h2>
                </div>
                <p class="mt-1 text-xs text-[#7c6a56]">{{ __('chatbot.workspace.campaigns.test_hint') }}</p>
            </div>
            <button type="button" id="test-new-chat" class="kaman-button-ghost kaman-button--sm shrink-0">
                {{ __('chatbot.workspace.test_new_chat') }}
            </button>
        </div>

        <div id="test-thread"
             class="flex-1 overflow-y-auto kaman-scroll px-4 py-4 space-y-3 bg-[linear-gradient(180deg,#f7efe3_0%,#fffaf3_45%,#f7efe3_100%)] min-h-[20rem]"
             role="log"
             aria-live="polite">
            <p id="test-empty" class="text-center text-sm text-[#a78a6c] py-16">{{ __('chatbot.workspace.test_chat_empty') }}</p>
        </div>

        <div class="border-t border-[#eadfce] bg-[#fffaf3]/90 px-4 py-3 space-y-2 shrink-0">
            <p id="test-status" class="hidden text-xs font-medium text-[#7c6a56]" aria-live="polite"></p>
            <p id="test-error" class="hidden text-xs text-red-600" role="alert"></p>
            <form id="test-form" class="flex items-end gap-2">
                <textarea id="test-input" rows="2" class="kaman-input flex-1 resize-none" placeholder="{{ __('chatbot.workspace.test_chat_placeholder') }}"></textarea>
                <button type="submit" id="test-send" class="kaman-button shrink-0">{{ __('chatbot.workspace.send') }}</button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/workspace-test-chat.js') }}?v={{ @filemtime(public_path('js/workspace-test-chat.js')) ?: time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.WorkspaceTestChat) {
        window.WorkspaceTestChat.mount(document.getElementById('test-panel'));
    }
});
</script>
@endpush
