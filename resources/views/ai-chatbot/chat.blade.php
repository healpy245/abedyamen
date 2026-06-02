@php
    /** @var \Illuminate\Support\Collection|\App\Models\AiChatbot\ChatbotMessage[] $messages */
    $activeConversationId = $activeConversation?->id ?? null;
@endphp

@extends('ai-chatbot.layout')

@section('title', 'AI Chatbot Studio')

@section('content')
    <div class="flex-1 flex flex-col md:flex-row max-w-6xl mx-auto w-full">
        @include('ai-chatbot.partials.sidebar', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
        ])

        <section class="flex-1 flex flex-col bg-gradient-to-b from-slate-950 via-slate-950 to-slate-950/95">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-sm font-medium text-slate-50">
                        {{ $activeConversation?->title ?? 'New chat' }}
                    </h1>
                    <p class="text-xs text-slate-500">
                        Ask anything. The assistant remembers within this conversation.
                    </p>
                </div>
            </div>

            <div id="aiChatbotMessages"
                 class="flex-1 overflow-y-auto px-4 py-4 space-y-1">
                @if($messages->isEmpty())
                    <div class="mt-12 max-w-md text-sm text-slate-400">
                        <p class="mb-2">
                            Start by introducing your task or question.
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-xs text-slate-500">
                            <li>“Help me draft a WhatsApp reply to a customer.”</li>
                            <li>“Explain my menu in Arabic for Instagram.”</li>
                            <li>“Summarize this long text I paste.”</li>
                        </ul>
                    </div>
                @else
                    @foreach($messages as $message)
                        @include('ai-chatbot.partials.message', ['message' => $message])
                    @endforeach
                @endif
            </div>

            <div class="border-t border-slate-800 bg-slate-950/90">
                <form id="aiChatbotSendForm"
                      action="{{ route('ai-chatbot.send') }}"
                      method="post"
                      class="max-w-3xl mx-auto px-3 py-3 flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="conversation_id" id="aiChatbotConversationId" value="{{ $activeConversationId }}">
                    <div class="flex gap-2">
                        <div class="flex-1 relative">
                            <textarea
                                id="aiChatbotMessageInput"
                                name="message"
                                rows="1"
                                dir="auto"
                                class="w-full resize-none rounded-xl border border-slate-700/80 bg-slate-900/70 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                                placeholder="Write your message… (Shift+Enter for new line)"></textarea>
                        </div>
                        <button type="submit"
                                id="aiChatbotSendButton"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 text-slate-950 text-sm font-medium px-3.5 py-2.5 hover:bg-emerald-400 disabled:opacity-60 disabled:cursor-not-allowed transition">
                            <span id="aiChatbotSendLabel">Send</span>
                            <svg id="aiChatbotSendIcon" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M2.94448 2.94446C3.06716 2.82178 3.2325 2.75604 3.40387 2.76058C3.57524 2.76513 3.73684 2.83955 3.85358 2.9666L16.3536 16.4666C16.4931 16.616 16.5435 16.8303 16.4867 17.027C16.43 17.2238 16.275 17.3763 16.0799 17.4311L9.07985 19.4311C8.86896 19.4913 8.6429 19.4325 8.49261 19.2797C8.34233 19.1268 8.29306 18.9052 8.36337 18.7055L10.193 13.5L5.99998 10L2.79448 3.85354C2.70715 3.69443 2.7218 3.499 2.83252 3.35355L2.94448 2.94446Z"/>
                            </svg>
                        </button>
                    </div>
                    <p id="aiChatbotError" class="text-xs text-rose-400 hidden"></p>
                    <p class="text-[11px] text-slate-500">
                        AI answers may be imperfect. Double-check important details.
                    </p>
                </form>
            </div>
        </section>
    </div>

@endsection

@push('scripts')
    <script>
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
                if (isLoading) {
                    label.textContent = 'Thinking…';
                } else {
                    label.textContent = 'Send';
                }
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
                if (dir === 'rtl') {
                    textarea.setAttribute('dir', 'rtl');
                    textarea.style.textAlign = 'right';
                } else {
                    textarea.setAttribute('dir', 'ltr');
                    textarea.style.textAlign = 'left';
                }
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
                            let message = data.message || 'Something went wrong while sending your message.';
                            if (response.status === 503 && !data.message) {
                                message = 'The chatbot service is temporarily unavailable. Please try again in a few minutes.';
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
                        errorEl.textContent = 'Network error. Please try again.';
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

