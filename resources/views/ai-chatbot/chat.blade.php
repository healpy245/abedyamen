@php
    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotMessage[] $messages */
    $activeConversationId = $activeConversation?->id ?? null;
    $activeProject = 'ai-chatbot';
@endphp

@extends('layouts.kaman')

@section('title', $instance->name . ' ' . __('chatbot.title_suffix'))
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
                        {{ $activeConversation?->title ?? __('chatbot.new_chat') }}
                    </h1>
                    <p class="mt-0.5 text-xs text-[#a78a6c]">
                        {{ __('chatbot.instance_prompt_hint') }}
                    </p>
                </div>

                <div id="aiChatbotMessages" class="kaman-scroll flex-1 overflow-y-auto px-5 py-5 min-h-[24rem]">
                    @if($messages->isEmpty())
                        <div class="mt-10 max-w-md text-sm text-[#7c6a56]">
                            <p class="mb-3 font-medium text-[#2b1e11]">
                                {{ __('chatbot.empty_intro') }}
                            </p>
                            <ul class="space-y-1.5 text-xs text-[#a78a6c]">
                                <li class="flex gap-2"><span class="text-[#f47a2e]">•</span> “{{ __('chatbot.example_1') }}”</li>
                                <li class="flex gap-2"><span class="text-[#f47a2e]">•</span> “{{ __('chatbot.example_2') }}”</li>
                                <li class="flex gap-2"><span class="text-[#f47a2e]">•</span> “{{ __('chatbot.example_3') }}”</li>
                            </ul>
                        </div>
                    @else
                        @foreach($messages as $message)
                            @include('ai-chatbot.partials.message', ['message' => $message])
                        @endforeach
                    @endif
                </div>

                <div class="border-t border-[#f1dfc5] bg-[#fffaf3]/70">
                    <form id="aiChatbotSendForm"
                          action="{{ route('ai-chatbot.instances.send', $instance) }}"
                          method="post"
                          class="mx-auto flex max-w-3xl flex-col gap-2 px-4 py-4">
                        @csrf
                        <input type="hidden" name="conversation_id" id="aiChatbotConversationId" value="{{ $activeConversationId }}">

                        <div class="flex gap-2">
                            <textarea id="aiChatbotMessageInput"
                                      name="message"
                                      rows="1"
                                      dir="auto"
                                      class="kaman-input min-w-0 flex-1 resize-none"
                                      placeholder="{{ __('chatbot.message_placeholder') }}"></textarea>
                            <button type="submit" id="aiChatbotSendButton"
                                    class="kaman-button shrink-0">
                                <span id="aiChatbotSendLabel">{{ __('chatbot.js.send') }}</span>
                                <svg id="aiChatbotSendIcon" class="w-4 h-4 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor"
                                     xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M2.94448 2.94446C3.06716 2.82178 3.2325 2.75604 3.40387 2.76058C3.57524 2.76513 3.73684 2.83955 3.85358 2.9666L16.3536 16.4666C16.4931 16.616 16.5435 16.8303 16.4867 17.027C16.43 17.2238 16.275 17.3763 16.0799 17.4311L9.07985 19.4311C8.86896 19.4913 8.6429 19.4325 8.49261 19.2797C8.34233 19.1268 8.29306 18.9052 8.36337 18.7055L10.193 13.5L5.99998 10L2.79448 3.85354C2.70715 3.69443 2.7218 3.499 2.83252 3.35355L2.94448 2.94446Z"/>
                                </svg>
                            </button>
                        </div>

                        <p id="aiChatbotError" class="hidden text-xs text-red-500"></p>
                        <p class="text-[11px] text-[#a78a6c]">
                            {{ __('chatbot.disclaimer') }}
                        </p>
                    </form>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const __chatbotT = @json(__('chatbot.js'));

        (function () {
            const form = document.getElementById('aiChatbotSendForm');
            const textarea = document.getElementById('aiChatbotMessageInput');
            const button = document.getElementById('aiChatbotSendButton');
            const label = document.getElementById('aiChatbotSendLabel');
            const messagesEl = document.getElementById('aiChatbotMessages');
            const errorEl = document.getElementById('aiChatbotError');
            const conversationIdInput = document.getElementById('aiChatbotConversationId');
            const newConversationForm = document.getElementById('aiChatbotNewConversationForm');

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            function setLoading(isLoading) {
                button.disabled = isLoading;
                label.textContent = isLoading ? __chatbotT.thinking : __chatbotT.send;
            }

            function scrollToBottom() {
                if (!messagesEl) return;
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function detectDirection(text) {
                return /[\u0600-\u06FF]/.test(text) ? 'rtl' : 'ltr';
            }

            textarea.addEventListener('input', function () {
                const dir = detectDirection(textarea.value);
                textarea.setAttribute('dir', dir);
                textarea.style.textAlign = dir === 'rtl' ? 'right' : 'left';
            });

            textarea.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    form.requestSubmit();
                }
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const text = textarea.value.trim();
                if (!text) {
                    return;
                }

                errorEl.classList.add('hidden');
                errorEl.textContent = '';

                setLoading(true);

                const payload = {
                    message: text,
                    conversation_id: conversationIdInput.value || null,
                };

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                })
                    .then(async (response) => {
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            let message = data.message || __chatbotT.send_error;
                            if (response.status === 503 && !data.message) {
                                message = __chatbotT.service_unavailable;
                            }
                            errorEl.textContent = message;
                            errorEl.classList.remove('hidden');
                            return;
                        }

                        if (data.conversation && data.conversation.id) {
                            conversationIdInput.value = data.conversation.id;
                        }

                        if (data.user_message_html) {
                            messagesEl.insertAdjacentHTML('beforeend', data.user_message_html);
                        }
                        if (data.assistant_message_html) {
                            messagesEl.insertAdjacentHTML('beforeend', data.assistant_message_html);
                        }

                        textarea.value = '';
                        textarea.dispatchEvent(new Event('input'));
                        scrollToBottom();
                    })
                    .catch((error) => {
                        console.error(error);
                        errorEl.textContent = __chatbotT.network_error;
                        errorEl.classList.remove('hidden');
                    })
                    .finally(() => {
                        setLoading(false);
                        textarea.focus();
                    });
            });

            if (newConversationForm) {
                newConversationForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    fetch(newConversationForm.getAttribute('action'), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok || !data.conversation || !data.conversation.redirect_url) {
                                window.location.reload();
                                return;
                            }

                            window.location.href = data.conversation.redirect_url;
                        })
                        .catch(() => {
                            window.location.reload();
                        });
                });
            }

            scrollToBottom();
        })();
    </script>
@endpush
