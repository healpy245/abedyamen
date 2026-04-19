<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Login Assistant</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: radial-gradient(circle at top, #fef8f0 0%, #ffffff 45%, #fef2dd 100%);
            color: #1f2933;
            min-height: 100vh;
        }
        .chat-wrapper {
            max-width: 1120px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 2.5rem;
        }
        @media (min-width: 768px) {
            .chat-wrapper {
                padding: 2.5rem 1.5rem 3rem;
            }
        }
        .chat-card {
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
            border: 1px solid rgba(244, 201, 157, 0.5);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 7rem);
            max-height: 840px;
        }
        .chat-messages {
            padding: 1.5rem 1.5rem 1.25rem;
            overflow-y: auto;
            scroll-behavior: smooth;
        }
        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.9);
            border-radius: 999px;
        }
        .chat-input-area {
            border-top: 1px solid rgba(226, 232, 240, 0.9);
            padding: 0.85rem 1rem;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.9), rgba(248, 250, 252, 1));
        }
        .chat-input-inner {
            border-radius: 999px;
            border: 1px solid rgba(203, 213, 225, 0.9);
            background: #ffffff;
            padding: 0.35rem 0.4rem 0.35rem 1rem;
            display: flex;
            align-items: flex-end;
            gap: 0.5rem;
        }
        .chat-textarea {
            resize: none;
            border: none;
            outline: none;
            width: 100%;
            max-height: 120px;
            padding: 0.35rem 0;
            font-size: 0.9rem;
        }
        .chat-send-button {
            border-radius: 999px;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #ffffff;
            padding: 0.55rem 1.1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 14px 30px rgba(34, 197, 94, 0.35);
            transition: transform 0.08s ease, box-shadow 0.08s ease, filter 0.08s ease;
        }
        .chat-send-button:disabled {
            opacity: 0.7;
            box-shadow: none;
            cursor: not-allowed;
        }
        .chat-send-button:not(:disabled):hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
            box-shadow: 0 16px 36px rgba(34, 197, 94, 0.4);
        }
    </style>
</head>
<body class="antialiased">
    @include('partials.topbar', [
        'tagText' => 'AI Login Assistant',
    ])

    <main class="chat-wrapper">
        <div class="flex flex-col gap-4 mb-4">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-100 w-fit">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <p class="text-xs font-semibold tracking-[0.18em] uppercase text-emerald-700">AI chat · Kaman login</p>
            </div>
            <div>
                <h1 class="text-2xl md:text-3xl font-semibold text-slate-900 mb-1">
                    Chat-based login to Kaman
                </h1>
                <p class="text-sm md:text-base text-slate-500">
                    I’ll ask for your restaurant name and password and then try to log you in to Kaman. Once logged in, you can switch to the main form and run Full AI automation.
                </p>
            </div>
        </div>

        <section class="chat-card">
            <div id="chatMessages" class="chat-messages">
                <div class="flex items-start gap-3 mb-4">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500 text-white text-sm font-semibold">
                        AI
                    </div>
                    <div class="max-w-[80%] rounded-2xl rounded-tl-sm bg-slate-50 border border-slate-100 px-3 py-2.5 text-sm text-slate-800 leading-relaxed">
                        <p>
                            Hi, I’m your AI assistant for Kaman. First I’ll connect to your restaurant account.
                        </p>
                        <p class="mt-2">
                            What is your restaurant name as it appears in Kaman (the part before <span class="font-mono text-xs bg-emerald-50 px-1 rounded">.kaman.rest</span>)?
                        </p>
                    </div>
                </div>
            </div>

            <div class="chat-input-area">
                <form id="chatForm" class="chat-input-inner">
                    <textarea
                        id="chatInput"
                        class="chat-textarea text-slate-800 placeholder:text-slate-400"
                        rows="1"
                        placeholder="Type your answer and press Enter…"
                    ></textarea>
                    <button
                        id="chatSendButton"
                        type="submit"
                        class="chat-send-button"
                    >
                        <span>Send</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2.94 2.94a.75.75 0 0 1 .82-.17l13 5a.75.75 0 0 1 0 1.38l-13 5A.75.75 0 0 1 2 13.5v-3.88l7.27-.12a.38.38 0 0 0 0-.75L2 8.63V4.5a.75.75 0 0 1 .94-.56Z" />
                        </svg>
                    </button>
                </form>
                <p id="chatHint" class="mt-1.5 text-[11px] text-slate-400 text-right">
                    Your credentials are sent only to Kaman’s secure API. Webtimize does not store your password.
                </p>
            </div>
        </section>
    </main>

    <script>
        (function () {
            const chatForm = document.getElementById('chatForm');
            const chatInput = document.getElementById('chatInput');
            const chatMessages = document.getElementById('chatMessages');
            const chatSendButton = document.getElementById('chatSendButton');

            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            function appendMessage(role, text, options = {}) {
                const wrapper = document.createElement('div');
                wrapper.className = 'flex items-start gap-3 mb-3';
                if (role === 'user') {
                    wrapper.classList.add('justify-end');
                }

                const bubble = document.createElement('div');
                bubble.className = 'max-w-[80%] px-3 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap';

                if (role === 'user') {
                    bubble.classList.add('bg-emerald-500', 'text-white', 'rounded-tr-sm', 'shadow-md');
                } else {
                    const avatar = document.createElement('div');
                    avatar.className = 'flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500 text-white text-[11px] font-semibold flex-shrink-0';
                    avatar.textContent = 'AI';
                    wrapper.appendChild(avatar);

                    bubble.classList.add('bg-slate-50', 'text-slate-800', 'border', 'border-slate-100', 'rounded-tl-sm');
                    if (options.loginSuccess === true) {
                        bubble.classList.add('border-emerald-200', 'bg-emerald-50/70');
                    } else if (options.loginSuccess === false) {
                        bubble.classList.add('border-rose-200', 'bg-rose-50/80');
                    }
                }

                bubble.textContent = text;

                if (role === 'user') {
                    wrapper.appendChild(bubble);
                } else {
                    wrapper.appendChild(bubble);
                }

                chatMessages.appendChild(wrapper);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function setLoading(isLoading) {
                chatSendButton.disabled = isLoading;
                chatInput.disabled = isLoading;
            }

            chatForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const text = chatInput.value.trim();
                if (!text) return;

                appendMessage('user', text);
                chatInput.value = '';

                setLoading(true);

                fetch("{{ route('ai.login-chat.message') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ message: text }),
                })
                .then(async (response) => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const message = data.message || data.error || 'Something went wrong while talking to the assistant.';
                        appendMessage('assistant', message);
                        return;
                    }

                    const reply = data.reply || 'I could not generate a response.';
                    appendMessage('assistant', reply, {
                        loginSuccess: data.login_success,
                    });
                })
                .catch((error) => {
                    console.error(error);
                    appendMessage('assistant', 'Network error while contacting the assistant. Please try again.');
                })
                .finally(() => {
                    setLoading(false);
                    chatInput.focus();
                });
            });

            chatInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatForm.dispatchEvent(new Event('submit'));
                }
            });
        })();
    </script>
</body>
</html>

