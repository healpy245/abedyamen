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

@section('title', __('chatbot.workspace.test_title').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
<div class="flex flex-col flex-1 min-h-0 w-full max-w-[900px] mx-auto px-3 sm:px-4 pb-3">
    @include('ai-chatbot.workspace.partials.nav')

    <div id="test-panel"
         class="kaman-card overflow-hidden border border-[#eadfce] flex flex-col flex-1 min-h-[70vh]"
         data-url="{{ route('ai-chatbot.workspace.test', $instance) }}"
         data-image-url="{{ route('ai-chatbot.workspace.test.image', $instance) }}"
         data-tts-url="{{ route('ai-chatbot.instances.voice.tts', $instance) }}"
         data-stream-url="{{ route('ai-chatbot.instances.voice.stream', $instance) }}"
         data-streaming="{{ config('voice.phone.streaming_enabled', true) ? '1' : '0' }}"
         data-silence-ms="{{ (int) config('voice.phone.silence_ms', 500) }}"
         data-latency-overlay="{{ config('voice.latency.overlay', false) ? '1' : '0' }}"
         data-csrf="{{ csrf_token() }}"
         data-i18n='@json($testChatI18n)'>
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-[#eadfce] bg-[#fffaf3]/80 shrink-0">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="kaman-chip text-xs bg-amber-100 text-amber-800 border-amber-200">{{ __('chatbot.workspace.simulation_only') }}</span>
                    <h2 class="text-sm font-semibold text-[#2b1e11]">{{ __('chatbot.workspace.test_title') }}</h2>
                </div>
                <p class="mt-1 text-xs text-[#7c6a56]">{{ __('chatbot.workspace.test_hint_simple') }}</p>
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

        <div id="test-latency"
             class="hidden fixed bottom-3 left-3 z-50 max-w-xs rounded-xl border border-[#eadfce] bg-white/95 p-3 text-[11px] leading-relaxed text-[#2b1e11] shadow-lg font-mono"
             aria-live="polite"></div>

        <div class="border-t border-[#eadfce] bg-[#fffaf3]/90 px-4 py-3 space-y-2 shrink-0">
            <p id="test-status" class="hidden text-xs font-medium text-[#7c6a56]" aria-live="polite"></p>
            <p id="test-error" class="hidden text-xs text-red-600" role="alert"></p>

            <div id="test-image-preview" class="hidden items-center gap-3 rounded-2xl border border-[#eadfce] bg-[#fffaf3] px-3 py-2">
                <img id="test-image-preview-thumb" src="" alt="" class="h-14 w-14 rounded-xl object-cover">
                <div class="min-w-0 flex-1">
                    <p id="test-image-preview-name" class="truncate text-xs font-medium text-[#2b1e11]"></p>
                    <p class="text-[11px] text-[#a78a6c]">{{ __('chatbot.attach_image_hint') }}</p>
                </div>
                <button type="button" id="test-image-preview-clear" class="rounded-full px-2 py-1 text-xs text-[#a78a6c] hover:bg-[#f47a2e]/8 hover:text-[#f16229]">
                    {{ __('chatbot.attach_image_clear') }}
                </button>
            </div>

            <form id="test-form" class="flex items-end gap-2">
                <input type="file"
                       id="test-image-input"
                       accept="image/jpeg,image/png,image/webp,application/pdf"
                       class="hidden">
                <button type="button"
                        id="test-attach"
                        class="kaman-button-ghost kaman-button--sm shrink-0 !px-2.5"
                        title="{{ __('chatbot.attach_image') }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M16.5 10.5V14.25C16.5 15.4926 15.4926 16.5 14.25 16.5H5.75C4.50736 16.5 3.5 15.4926 3.5 14.25V5.75C3.5 4.50736 4.50736 3.5 5.75 3.5H9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M12.5 3.5H16.5V7.5M16.25 3.75L10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="sr-only">{{ __('chatbot.attach_image') }}</span>
                </button>
                <button type="button"
                        id="test-mic"
                        class="kaman-button-ghost kaman-button--sm shrink-0"
                        title="{{ __('chatbot.workspace.test_mic') }}"
                        aria-pressed="false">
                    <span aria-hidden="true">🎤</span>
                    <span class="sr-only">{{ __('chatbot.workspace.test_mic') }}</span>
                </button>
                <button type="button"
                        id="test-call"
                        class="kaman-button-ghost kaman-button--sm shrink-0"
                        title="{{ __('chatbot.workspace.test_call') }}"
                        aria-pressed="false">
                    <span aria-hidden="true">📞</span>
                    <span class="sr-only">{{ __('chatbot.workspace.test_call') }}</span>
                </button>
                <label class="sr-only" for="test-input">{{ __('chatbot.workspace.test_chat_placeholder') }}</label>
                <textarea id="test-input"
                          rows="1"
                          maxlength="4000"
                          placeholder="{{ __('chatbot.workspace.test_chat_placeholder') }}"
                          class="kaman-input flex-1 resize-none text-sm min-h-[2.5rem] max-h-28"></textarea>
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
