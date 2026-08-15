@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    /** @var \App\Models\Voice\VoiceCall|null $activeCall */
    /** @var \Illuminate\Support\Collection|\App\Models\Voice\VoiceCallMessage[] $messages */
    use App\Enums\Voice\VoiceInteractionMode;
    use App\Enums\Voice\VoiceProfile;

    $activeProject = 'ai-chatbot';
    $isActiveCall = $activeCall !== null;
    $callStatus = $activeCall?->statusEnum()->value ?? 'idle';
    $canSend = $activeCall?->isActive() ?? false;
    $callMetadata = $activeCall?->metadata ?? [];
    $initialMode = $callMetadata['interaction_mode'] ?? VoiceInteractionMode::Text->value;
    $initialVoiceProfile = $callMetadata['voice_profile'] ?? VoiceProfile::Woman->value;
    $voiceProfiles = VoiceProfile::cases();
    $speechLocale = match (app()->getLocale()) {
        'ar' => 'ar-SA',
        'he' => 'he-IL',
        default => 'en-US',
    };
    $phoneStates = [
        'idle' => __('voice.phone.idle'),
        'listening' => __('voice.phone.listening'),
        'processing' => __('voice.phone.processing'),
        'speaking' => __('voice.phone.speaking'),
    ];
    $phoneErrors = [
        'speech_recognition_unavailable' => __('voice.phone.errors.unsupported'),
        'recognition_start_failed' => __('voice.phone.errors.start_failed'),
        'send_failed' => __('voice.send_error'),
    ];
@endphp

@extends('layouts.kaman')

@section('title', $instance->name . ' — ' . __('voice.title'))
@section('tag', __('chatbot.tag'))

@section('content')
    <div class="flex-1 flex flex-col px-4 py-6 sm:px-6 sm:py-8">
        <div class="kaman-card mx-auto flex w-full max-w-6xl flex-1 flex-col overflow-hidden md:flex-row">
            @include('ai-chatbot.partials.sidebar', [
                'instance' => $instance,
                'instances' => $instances,
                'conversations' => $conversations,
                'activeConversation' => $activeConversation,
            ])

            <section class="flex flex-1 flex-col min-h-0 min-w-0">
                <div class="border-b border-[#f1dfc5] px-5 py-3.5">
                    <p class="text-[0.65rem] uppercase tracking-[0.18em] text-[#f47a2e] font-semibold mb-0.5">
                        {{ $instance->name }}
                    </p>
                    <h1 class="text-base font-semibold text-[#2b1e11]">
                        {{ __('voice.simulator_title') }}
                    </h1>
                    <p class="mt-0.5 text-xs text-[#a78a6c]">
                        {{ __('voice.simulator_hint') }}
                    </p>
                    @include('ai-chatbot.partials.instance-actions', ['instance' => $instance])
                </div>

                <div class="border-b border-[#f1dfc5] bg-[#fffaf3]/60 px-5 py-4 space-y-3">
                    @unless($isActiveCall)
                        <form id="voiceCallStartForm"
                              action="{{ route('ai-chatbot.instances.voice.start', $instance) }}"
                              method="post"
                              class="space-y-4">
                            @csrf

                            <div class="space-y-2">
                                <span class="block text-[11px] font-semibold uppercase tracking-wide text-[#a78a6c]">
                                    {{ __('voice.interaction_mode') }}
                                </span>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="interaction_mode" value="text" class="peer sr-only" checked>
                                        <span class="flex items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-xs font-semibold transition
                                            peer-checked:border-[#f47a2e]/40 peer-checked:bg-[#f47a2e]/10 peer-checked:text-[#f16229]
                                            border-[#f1dfc5] bg-white text-[#7c6a56] hover:border-[#f47a2e]/25">
                                            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 5H16M4 10H12M4 15H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                            {{ __('voice.modes.text') }}
                                        </span>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="interaction_mode" value="phone" class="peer sr-only">
                                        <span class="flex items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-xs font-semibold transition
                                            peer-checked:border-[#f47a2e]/40 peer-checked:bg-[#f47a2e]/10 peer-checked:text-[#f16229]
                                            border-[#f1dfc5] bg-white text-[#7c6a56] hover:border-[#f47a2e]/25">
                                            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 8.5V11.5C4 12.0523 4.44772 12.5 5 12.5H6.5L9 15V5L6.5 7.5H5C4.44772 7.5 4 7.94772 4 8.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12.5 8.5C13.3284 9.32843 13.3284 10.6716 12.5 11.5M14.5 6.5C16.1569 8.15685 16.1569 11.8431 14.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                            {{ __('voice.modes.phone') }}
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div id="voiceProfilePicker" class="hidden space-y-2">
                                <span class="block text-[11px] font-semibold uppercase tracking-wide text-[#a78a6c]">
                                    {{ __('voice.voice_profile') }}
                                </span>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    @foreach($voiceProfiles as $profile)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="voice_profile" value="{{ $profile->value }}"
                                                   class="peer sr-only" @checked($profile === VoiceProfile::Woman)>
                                            <span class="flex flex-col items-center justify-center rounded-xl border px-2 py-2.5 text-center text-[11px] font-semibold transition
                                                peer-checked:border-[#f47a2e]/40 peer-checked:bg-[#f47a2e]/10 peer-checked:text-[#f16229]
                                                border-[#f1dfc5] bg-white text-[#7c6a56] hover:border-[#f47a2e]/25">
                                                {{ $profile->label() }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="text-[11px] text-[#a78a6c]">{{ __('voice.voice_profile_hint') }}</p>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-end">
                                <div class="flex-1 w-full">
                                    <label for="callerNumber" class="block text-[11px] font-semibold uppercase tracking-wide text-[#a78a6c] mb-1">
                                        {{ __('voice.caller_number') }}
                                    </label>
                                    <input type="text" name="caller_number" id="callerNumber" maxlength="32"
                                           placeholder="{{ __('voice.caller_number_placeholder') }}"
                                           class="kaman-input w-full">
                                </div>
                                <button type="submit" class="kaman-button shrink-0">
                                    {{ __('voice.start_call') }}
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center gap-3 justify-between">
                            <div class="space-y-2">
                                <div class="text-xs text-[#7c6a56]">
                                    {{ __('voice.call_id', ['id' => $activeCall->id]) }}
                                    @if($activeCall->caller_number)
                                        · {{ __('voice.from_number', ['number' => $activeCall->caller_number]) }}
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold
                                        {{ $activeCall->isActive() ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-[#f1dfc5] bg-white text-[#7c6a56]' }}">
                                        <span class="h-2 w-2 rounded-full {{ $activeCall->isActive() ? 'bg-emerald-500 animate-pulse' : 'bg-[#c7b69d]' }}"></span>
                                        {{ __('voice.status.' . $callStatus) }}
                                    </div>
                                    <span class="inline-flex items-center rounded-full border border-[#f1dfc5] bg-white px-3 py-1 text-[11px] font-semibold text-[#7c6a56]">
                                        {{ __('voice.modes.' . $initialMode) }}
                                    </span>
                                    @if($initialMode === VoiceInteractionMode::Phone->value)
                                        <span class="inline-flex items-center rounded-full border border-[#f1dfc5] bg-white px-3 py-1 text-[11px] font-semibold text-[#7c6a56]">
                                            {{ __('voice.profiles.' . $initialVoiceProfile) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if($activeCall->isActive())
                                <form action="{{ route('ai-chatbot.instances.voice.end', ['instance' => $instance, 'voiceCall' => $activeCall]) }}" method="post">
                                    @csrf
                                    <button type="submit" class="kaman-button-ghost kaman-button--sm">
                                        {{ __('voice.end_call') }}
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if($canSend)
                            <div class="flex gap-2 pt-1">
                                <button type="button" id="modeTabText"
                                        class="voice-mode-tab rounded-lg px-3 py-1.5 text-xs font-semibold border transition
                                            {{ $initialMode === 'text' ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229]' : 'border-[#f1dfc5] bg-white text-[#7c6a56]' }}">
                                    {{ __('voice.modes.text') }}
                                </button>
                                <button type="button" id="modeTabPhone"
                                        class="voice-mode-tab rounded-lg px-3 py-1.5 text-xs font-semibold border transition
                                            {{ $initialMode === 'phone' ? 'border-[#f47a2e]/30 bg-[#f47a2e]/12 text-[#f16229]' : 'border-[#f1dfc5] bg-white text-[#7c6a56]' }}">
                                    {{ __('voice.modes.phone') }}
                                </button>
                            </div>
                        @endif
                    @endunless
                </div>

                <div id="voiceCallMessages" class="kaman-scroll flex-1 overflow-y-auto px-5 py-5 min-h-[20rem]">
                    @if($messages->isEmpty())
                        <div class="mt-8 max-w-md text-sm text-[#7c6a56]">
                            <p>{{ __('voice.empty_transcript') }}</p>
                        </div>
                    @else
                        @foreach($messages as $message)
                            @include('ai-chatbot.voice.partials.message', ['message' => $message])
                        @endforeach
                    @endif
                </div>

                @if($isActiveCall && $canSend)
                    <div id="voiceTextPanel" class="border-t border-[#f1dfc5] bg-[#fffaf3]/70 {{ $initialMode === 'phone' ? 'hidden' : '' }}">
                        <form id="voiceCallSendForm"
                              action="{{ route('ai-chatbot.instances.voice.message', ['instance' => $instance, 'voiceCall' => $activeCall]) }}"
                              method="post"
                              class="mx-auto flex max-w-3xl flex-col gap-2 px-4 py-4">
                            @csrf
                            <div class="flex gap-2">
                                <textarea id="voiceCallMessageInput"
                                          name="message"
                                          rows="1"
                                          dir="auto"
                                          class="kaman-input min-w-0 flex-1 resize-none"
                                          placeholder="{{ __('voice.message_placeholder') }}"></textarea>
                                <button type="submit" id="voiceCallSendButton" class="kaman-button shrink-0">
                                    <span id="voiceCallSendLabel">{{ __('voice.send') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div id="voicePhonePanel" class="border-t border-[#f1dfc5] bg-[#fffaf3]/70 {{ $initialMode === 'text' ? 'hidden' : '' }}">
                        <div class="mx-auto flex max-w-3xl flex-col items-center gap-3 px-4 py-6">
                            <p id="voicePhoneStatus" class="text-sm font-semibold text-[#7c6a56]">{{ __('voice.phone.idle') }}</p>
                            <p id="voicePhoneLiveTranscript" class="hidden text-sm text-[#2b1e11] text-center max-w-md" dir="auto"></p>
                            <button type="button" id="voicePhoneMicButton"
                                    class="relative flex h-24 w-24 items-center justify-center rounded-full border-4 border-[#f47a2e]/30 bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white shadow-lg transition hover:scale-105 active:scale-95">
                                <svg class="w-10 h-10" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 14C13.6569 14 15 12.6569 15 11V6C15 4.34315 13.6569 3 12 3C10.3431 3 9 4.34315 9 6V11C9 12.6569 10.3431 14 12 14Z" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M6 11C6 14.3137 8.68629 17 12 17C15.3137 17 18 14.3137 18 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <path d="M12 17V20M9 20H15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                                <span id="voicePhoneMicPulse" class="absolute inset-0 rounded-full border-4 border-[#f47a2e]/40 hidden animate-ping"></span>
                            </button>
                            <p class="text-xs text-[#a78a6c] text-center">{{ __('voice.phone.tap_hint') }}</p>
                        </div>
                    </div>

                    <p id="voiceCallError" class="hidden px-4 pb-4 text-xs text-red-500 text-center"></p>
                @elseif($isActiveCall)
                    <p class="px-4 py-4 text-xs text-[#a78a6c] text-center">{{ __('voice.call_ended_hint') }}</p>
                @endif
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/voice-phone-call.js') }}"></script>
    <script>
        (function () {
            const startForm = document.getElementById('voiceCallStartForm');
            const profilePicker = document.getElementById('voiceProfilePicker');
            const modeRadios = startForm?.querySelectorAll('input[name="interaction_mode"]');

            modeRadios?.forEach((radio) => {
                radio.addEventListener('change', () => {
                    const isPhone = startForm.querySelector('input[name="interaction_mode"]:checked')?.value === 'phone';
                    profilePicker?.classList.toggle('hidden', !isPhone);
                });
            });

            @if($isActiveCall && $canSend)
            const form = document.getElementById('voiceCallSendForm');
            const textarea = document.getElementById('voiceCallMessageInput');
            const messagesEl = document.getElementById('voiceCallMessages');
            const errorEl = document.getElementById('voiceCallError');
            const sendButton = document.getElementById('voiceCallSendButton');
            const sendLabel = document.getElementById('voiceCallSendLabel');
            const textPanel = document.getElementById('voiceTextPanel');
            const phonePanel = document.getElementById('voicePhonePanel');
            const modeTabText = document.getElementById('modeTabText');
            const modeTabPhone = document.getElementById('modeTabPhone');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const thinkingLabel = @json(__('voice.thinking'));
            const sendText = @json(__('voice.send'));
            const sendError = @json(__('voice.send_error'));
            const phoneStates = @json($phoneStates);
            const phoneErrors = @json($phoneErrors);
            const speechLocale = @json($speechLocale);
            const voiceProfile = @json($callMetadata['voice_synthesis'] ?? config('voice.profiles.woman'));
            const messageUrl = @json(route('ai-chatbot.instances.voice.message', ['instance' => $instance, 'voiceCall' => $activeCall]));
            let phoneController = null;
            let uiMode = @json($initialMode);

            function showError(message) {
                if (!errorEl) return;
                errorEl.textContent = message;
                errorEl.classList.remove('hidden');
            }

            function clearError() {
                errorEl?.classList.add('hidden');
            }

            function appendMessages(data) {
                if (data.caller_message_html) {
                    messagesEl.insertAdjacentHTML('beforeend', data.caller_message_html);
                }
                if (data.assistant_message_html) {
                    messagesEl.insertAdjacentHTML('beforeend', data.assistant_message_html);
                }
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            async function sendMessage(message) {
                const response = await fetch(messageUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ message }),
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.message || sendError);
                }

                appendMessages(data);
                return data.assistant_message?.content || '';
            }

            function setUiMode(mode) {
                uiMode = mode;
                textPanel?.classList.toggle('hidden', mode !== 'text');
                phonePanel?.classList.toggle('hidden', mode !== 'phone');
                modeTabText?.classList.toggle('border-[#f47a2e]/30', mode === 'text');
                modeTabText?.classList.toggle('bg-[#f47a2e]/12', mode === 'text');
                modeTabText?.classList.toggle('text-[#f16229]', mode === 'text');
                modeTabPhone?.classList.toggle('border-[#f47a2e]/30', mode === 'phone');
                modeTabPhone?.classList.toggle('bg-[#f47a2e]/12', mode === 'phone');
                modeTabPhone?.classList.toggle('text-[#f16229]', mode === 'phone');

                if (mode !== 'phone') {
                    phoneController?.stop();
                }
            }

            modeTabText?.addEventListener('click', () => setUiMode('text'));
            modeTabPhone?.addEventListener('click', () => {
                if (!window.VoicePhoneCall?.isSupported()) {
                    showError(phoneErrors.speech_recognition_unavailable);
                    return;
                }
                setUiMode('phone');
            });

            form?.addEventListener('submit', async function (event) {
                event.preventDefault();
                const message = textarea.value.trim();
                if (!message) return;

                clearError();
                sendButton.disabled = true;
                sendLabel.textContent = thinkingLabel;

                try {
                    await sendMessage(message);
                    textarea.value = '';
                } catch (error) {
                    showError(error.message || sendError);
                } finally {
                    sendButton.disabled = false;
                    sendLabel.textContent = sendText;
                }
            });

            if (window.VoicePhoneCall?.isSupported()) {
                const statusEl = document.getElementById('voicePhoneStatus');
                const liveTranscriptEl = document.getElementById('voicePhoneLiveTranscript');
                const micButton = document.getElementById('voicePhoneMicButton');
                const micPulse = document.getElementById('voicePhoneMicPulse');

                phoneController = window.VoicePhoneCall.mount({
                    micButton,
                    statusEl,
                    liveTranscriptEl,
                    locale: speechLocale,
                    profile: voiceProfile,
                    voiceProfile: @json($initialVoiceProfile),
                    ttsUrl: @json(route('ai-chatbot.instances.voice.tts', $instance)),
                    csrf,
                    onTranscript: async (transcript) => {
                        clearError();
                        return sendMessage(transcript);
                    },
                    onError: (code) => {
                        showError(phoneErrors[code] || code || sendError);
                    },
                    onStateChange: (state, detail) => {
                        statusEl.textContent = phoneStates[state] || state;
                        micPulse?.classList.toggle('hidden', state !== 'listening');
                        micButton?.classList.toggle('ring-4', state === 'listening');
                        micButton?.classList.toggle('ring-[#f47a2e]/30', state === 'listening');
                        if (state === 'processing' && detail) {
                            statusEl.textContent = phoneStates.processing;
                        }
                    },
                });
            } else if (uiMode === 'phone') {
                showError(phoneErrors.speech_recognition_unavailable);
            }
            @endif
        })();
    </script>
@endpush
