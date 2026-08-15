@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotMessage[] $messages */
    use App\Enums\Voice\VoiceProfile;

    $activeConversationId = $activeConversation?->id ?? null;
    $activeProject = 'ai-chatbot';
    $speechLocale = match (app()->getLocale()) {
        'ar' => 'ar-SA',
        'he' => 'he-IL',
        default => 'en-US',
    };
    $voiceProfiles = collect(VoiceProfile::cases())->mapWithKeys(
        fn (VoiceProfile $profile) => [$profile->value => $profile->synthesis()]
    );
    $chatbotJsTranslations = array_merge(__('chatbot.js'), [
        'dictate' => __('chatbot.dictate'),
        'dictation_unsupported' => __('chatbot.dictation_unsupported'),
        'dictation_error' => __('chatbot.dictation_error'),
        'voice_unsupported' => __('chatbot.voice_unsupported'),
        'voice_error' => __('chatbot.voice_error'),
        'attach_error' => __('chatbot.attach_error'),
        'attach_invalid_type' => __('chatbot.attach_invalid_type'),
    ]);
    $voicePhoneStates = [
        'connecting' => __('voice.realtime.states.connecting'),
        'listening' => __('voice.realtime.states.listening'),
        'user_speaking' => __('voice.realtime.states.user_speaking'),
        'assistant_thinking' => __('voice.realtime.states.assistant_thinking'),
        'assistant_speaking' => __('voice.realtime.states.assistant_speaking'),
        'muted' => __('voice.realtime.states.muted'),
        'reconnecting' => __('voice.realtime.states.reconnecting'),
        'ended' => __('voice.realtime.states.ended'),
    ];
    $realtimeEnabled = (bool) config('voice.realtime.enabled', true);
@endphp

@extends('layouts.kaman')

@section('title', $instance->name . ' ' . __('chatbot.title_suffix'))
@section('tag', __('chatbot.tag'))

@section('content')
    <div class="flex min-h-0 flex-1 flex-col px-4 py-6 sm:px-6 sm:py-8">
        <div class="kaman-card mx-auto flex min-h-0 w-full max-w-6xl flex-1 flex-col overflow-hidden md:flex-row">
            @include('ai-chatbot.partials.sidebar', [
                'instance' => $instance,
                'instances' => $instances,
                'conversations' => $conversations,
                'activeConversation' => $activeConversation,
            ])

            <section class="flex flex-1 flex-col min-h-0 min-w-0 relative">
                <div class="border-b border-[#f1dfc5] px-5 py-3.5">
                    <p class="text-[0.65rem] uppercase tracking-[0.18em] text-[#f47a2e] font-semibold mb-0.5">
                        {{ $instance->name }}
                    </p>
                    <h1 class="text-base font-semibold text-[#2b1e11]">
                        {{ $activeConversation?->title ?? __('chatbot.new_chat') }}
                    </h1>
                    <p class="mt-0.5 text-xs text-[#a78a6c]">
                        {{ __('chatbot.composer_hint') }}
                    </p>
                    @include('ai-chatbot.partials.instance-actions', ['instance' => $instance])
                </div>

                <div id="aiChatbotMessages" class="kaman-scroll flex-1 overflow-y-auto px-5 py-5 min-h-[24rem]">
                    @if($messages->isEmpty())
                        <div class="mt-16 max-w-lg mx-auto text-center text-sm text-[#7c6a56]">
                            <p class="mb-2 text-lg font-medium text-[#2b1e11]">{{ __('chatbot.composer_empty_title') }}</p>
                            <p>{{ __('chatbot.composer_empty_body') }}</p>
                        </div>
                    @else
                        @foreach($messages as $message)
                            @include('ai-chatbot.partials.message', ['message' => $message, 'instance' => $instance])
                        @endforeach
                    @endif
                </div>

                <div class="border-t border-[#f1dfc5] bg-[#fffaf3]/80 px-4 py-4">
                    <form id="aiChatbotSendForm"
                          action="{{ route('ai-chatbot.instances.send', $instance) }}"
                          method="post"
                          class="mx-auto max-w-3xl">
                        @csrf
                        <input type="hidden" name="conversation_id" id="aiChatbotConversationId" value="{{ $activeConversationId }}">

                        <div class="flex items-end gap-2 rounded-[1.75rem] border border-[#eadfce] bg-white px-3 py-2 shadow-sm focus-within:border-[#f47a2e]/40 focus-within:ring-2 focus-within:ring-[#f47a2e]/10">
                            <input type="file"
                                   id="aiChatbotImageInput"
                                   accept="image/jpeg,image/png,image/webp,application/pdf"
                                   class="hidden">

                            <button type="button" id="aiChatbotAttachButton"
                                    title="{{ __('chatbot.attach_image') }}"
                                    class="mb-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[#7c6a56] transition hover:bg-[#f47a2e]/8 hover:text-[#f16229]">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M16.5 10.5V14.25C16.5 15.4926 15.4926 16.5 14.25 16.5H5.75C4.50736 16.5 3.5 15.4926 3.5 14.25V5.75C3.5 4.50736 4.50736 3.5 5.75 3.5H9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <path d="M12.5 3.5H16.5V7.5M16.25 3.75L10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>

                            <textarea id="aiChatbotMessageInput"
                                      name="message"
                                      rows="1"
                                      dir="auto"
                                      class="min-h-[2.5rem] max-h-40 min-w-0 flex-1 resize-none border-0 bg-transparent px-2 py-2 text-sm text-[#2b1e11] outline-none placeholder:text-[#b8a48c]"
                                      placeholder="{{ __('chatbot.composer_placeholder') }}"></textarea>

                            <div class="flex shrink-0 items-center gap-1 pb-1">
                                <button type="button" id="aiChatbotDictateButton"
                                        title="{{ __('chatbot.dictate') }}"
                                        class="flex h-9 w-9 items-center justify-center rounded-full text-[#7c6a56] transition hover:bg-[#f47a2e]/8 hover:text-[#f16229]">
                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                        <path d="M10 12.5C11.3807 12.5 12.5 11.3807 12.5 10V6.5C12.5 5.11929 11.3807 4 10 4C8.61929 4 7.5 5.11929 7.5 6.5V10C7.5 11.3807 8.61929 12.5 10 12.5Z" stroke="currentColor" stroke-width="1.5"/>
                                        <path d="M5 10C5 12.7614 7.23858 15 10 15C12.7614 15 15 12.7614 15 10M10 15V17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                </button>

                                <button type="button" id="aiChatbotVoiceCallButton"
                                        title="{{ __('chatbot.voice_call') }}"
                                        class="flex h-9 w-9 items-center justify-center rounded-full bg-[#2b1e11] text-white transition hover:bg-[#1a120a]">
                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                        <path d="M3 10H5M7 10H9M11 10H13M15 10H17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                </button>

                                <button type="submit" id="aiChatbotSendButton"
                                        class="kaman-button !rounded-full !px-3 !py-2 shrink-0">
                                    <span id="aiChatbotSendLabel">{{ __('chatbot.js.send') }}</span>
                                </button>
                            </div>
                        </div>

                        <div id="aiChatbotImagePreview" class="mx-auto mt-2 hidden max-w-3xl items-center gap-3 rounded-2xl border border-[#eadfce] bg-white px-3 py-2">
                            <img id="aiChatbotImagePreviewThumb" src="" alt="" class="h-14 w-14 rounded-xl object-cover">
                            <div class="min-w-0 flex-1">
                                <p id="aiChatbotImagePreviewName" class="truncate text-xs font-medium text-[#2b1e11]"></p>
                                <p class="text-[11px] text-[#a78a6c]">{{ __('chatbot.attach_image_hint') }}</p>
                            </div>
                            <button type="button" id="aiChatbotImagePreviewClear" class="rounded-full px-2 py-1 text-xs text-[#a78a6c] hover:bg-[#f47a2e]/8 hover:text-[#f16229]">
                                {{ __('chatbot.attach_image_clear') }}
                            </button>
                        </div>

                        <p id="aiChatbotError" class="hidden mt-2 text-center text-xs text-red-500"></p>
                        <p class="mt-2 text-center text-[11px] text-[#a78a6c]">{{ __('chatbot.disclaimer') }}</p>
                    </form>
                </div>

                {{-- Voice call dock: floats over chat without hiding the conversation --}}
                <div id="aiChatbotVoiceOverlay" class="hidden fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-4 pointer-events-none">
                    <div class="pointer-events-auto w-full max-w-lg rounded-3xl border border-[#f1dfc5] bg-white/95 p-4 shadow-2xl backdrop-blur-sm">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[0.65rem] uppercase tracking-[0.18em] text-[#f47a2e] font-semibold">{{ __('chatbot.voice_call') }}</p>
                                <p id="aiChatbotVoiceOverlayStatus" class="truncate text-sm font-medium text-[#7c6a56]">{{ __('voice.realtime.ready') }}</p>
                            </div>
                            <button type="button" id="aiChatbotVoiceOverlayClose" class="shrink-0 rounded-full p-2 text-[#a78a6c] hover:bg-[#f47a2e]/8 hover:text-[#f16229]">
                                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none"><path d="M5 5L15 15M15 5L5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            </button>
                        </div>

                        @if(config('app.debug'))
                            <div id="aiChatbotVoiceDiagnostics" class="mb-3 rounded-xl border border-dashed border-[#f1dfc5] bg-[#fffaf3] p-3"></div>
                        @endif

                        <div id="aiChatbotVoicePreCall" class="flex flex-col items-center gap-2 py-1">
                            <button type="button" id="aiChatbotVoiceStartCall" class="kaman-button">
                                {{ __('voice.realtime.start_call') }}
                            </button>
                            <p class="text-center text-xs text-[#a78a6c]">{{ __('voice.realtime.start_hint') }}</p>
                        </div>

                        <div id="aiChatbotVoiceInCall" class="hidden flex items-center justify-between gap-4 py-1">
                            <div class="relative shrink-0">
                                <span id="aiChatbotVoiceOverlayPulse" class="absolute inset-0 rounded-full bg-[#f47a2e]/15 animate-ping hidden"></span>
                                <button type="button" id="aiChatbotVoiceOverlayMic"
                                        title="{{ __('voice.phone.mute_toggle') }}"
                                        class="relative flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white shadow-lg transition">
                                    <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 14C13.6569 14 15 12.6569 15 11V6C15 4.34315 13.6569 3 12 3C10.3431 3 9 4.34315 9 6V11C9 12.6569 10.3431 14 12 14Z" stroke="currentColor" stroke-width="1.8"/>
                                        <path d="M6 11C6 14.3137 8.68629 17 12 17C15.3137 17 18 14.3137 18 11M12 17V20M9 20H15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-[#a78a6c]">{{ __('voice.phone.handsfree_hint') }}</p>
                            </div>
                            <button type="button" id="aiChatbotVoiceOverlayEnd" class="kaman-button-ghost kaman-button--sm shrink-0">
                                {{ __('voice.end_call') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/realtime-call.js') }}?v={{ @filemtime(public_path('js/realtime-call.js')) }}"></script>
    <script src="{{ asset('js/chat-composer.js') }}?v={{ @filemtime(public_path('js/chat-composer.js')) }}"></script>
    <script>
        const __chatbotT = @json($chatbotJsTranslations);

        (function () {
            const textarea = document.getElementById('aiChatbotMessageInput');
            const messagesEl = document.getElementById('aiChatbotMessages');
            const newConversationForm = document.getElementById('aiChatbotNewConversationForm');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            function detectDirection(text) {
                return /[\u0600-\u06FF]/.test(text) ? 'rtl' : 'ltr';
            }

            textarea?.addEventListener('input', function () {
                const dir = detectDirection(textarea.value);
                textarea.setAttribute('dir', dir);
                textarea.style.textAlign = dir === 'rtl' ? 'right' : 'left';
            });

            textarea?.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    document.getElementById('aiChatbotSendForm')?.requestSubmit();
                }
            });

            window.ChatComposer.mount({
                form: document.getElementById('aiChatbotSendForm'),
                textarea,
                messagesEl,
                conversationIdInput: document.getElementById('aiChatbotConversationId'),
                errorEl: document.getElementById('aiChatbotError'),
                sendButton: document.getElementById('aiChatbotSendButton'),
                sendLabel: document.getElementById('aiChatbotSendLabel'),
                dictateButton: document.getElementById('aiChatbotDictateButton'),
                voiceCallButton: document.getElementById('aiChatbotVoiceCallButton'),
                attachButton: document.getElementById('aiChatbotAttachButton'),
                imageInput: document.getElementById('aiChatbotImageInput'),
                imagePreview: document.getElementById('aiChatbotImagePreview'),
                imagePreviewThumb: document.getElementById('aiChatbotImagePreviewThumb'),
                imagePreviewName: document.getElementById('aiChatbotImagePreviewName'),
                imagePreviewClear: document.getElementById('aiChatbotImagePreviewClear'),
                overlay: document.getElementById('aiChatbotVoiceOverlay'),
                overlayClose: document.getElementById('aiChatbotVoiceOverlayClose'),
                overlayMic: document.getElementById('aiChatbotVoiceOverlayMic'),
                overlayStatus: document.getElementById('aiChatbotVoiceOverlayStatus'),
                overlayEnd: document.getElementById('aiChatbotVoiceOverlayEnd'),
                overlayStart: document.getElementById('aiChatbotVoiceStartCall'),
                overlayPreCall: document.getElementById('aiChatbotVoicePreCall'),
                overlayInCall: document.getElementById('aiChatbotVoiceInCall'),
                overlayPulse: document.getElementById('aiChatbotVoiceOverlayPulse'),
                diagnosticsEl: document.getElementById('aiChatbotVoiceDiagnostics'),
                csrf: csrfToken,
                sendUrl: @json(route('ai-chatbot.instances.send', $instance)),
                uploadImageUrl: @json(route('ai-chatbot.instances.upload-image', $instance)),
                realtimeSessionUrl: @json(route('ai-chatbot.instances.voice.realtime.session', $instance)),
                realtimeEnabled: @json($realtimeEnabled),
                ttsUrl: @json(route('ai-chatbot.instances.voice.tts', $instance)),
                speechLocale: @json($speechLocale),
                phoneStates: @json($voicePhoneStates),
                translations: __chatbotT,
            });

            if (newConversationForm) {
                newConversationForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    fetch(newConversationForm.getAttribute('action'), {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            window.location.href = data.conversation?.redirect_url || window.location.href;
                        })
                        .catch(() => window.location.reload());
                });
            }

            if (messagesEl) {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }
        })();
    </script>
@endpush
