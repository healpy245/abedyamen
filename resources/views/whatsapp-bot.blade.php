<<<<<<< HEAD
@extends('layouts.kaman')
=======
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp Bot - KAMAN POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
>>>>>>> parent of cd712ea (First)

@section('title', __('whatsapp.title'))
@section('tag', __('whatsapp.tag'))

@php $activeProject = 'whatsapp-bot'; @endphp

@section('content')
    <div class="page-container">
        <div class="mx-auto w-full max-w-6xl space-y-6">

            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="kaman-eyebrow mb-2">{{ __('whatsapp.eyebrow') }}</p>
                    <h1 class="text-3xl font-semibold text-[#2b1e11]">{{ __('whatsapp.heading') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-[#7c6a56]">
                        {{ __('whatsapp.intro') }}
                    </p>
                </div>
            </div>

            {{-- Webhook --}}
            <section class="kaman-card kaman-card--pad">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.webhook') }}</h2>
                    <span id="webhookActiveBadge" class="kaman-chip {{ $webhookActive ? 'kaman-chip--success' : 'kaman-chip--muted' }}">
                        {{ $webhookActive ? __('app.active') : __('app.inactive') }}
                    </span>
                </div>

                <div class="kaman-well p-4">
                    <p class="text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-2">
                        {{ __('whatsapp.webhook_url_label') }}
                    </p>
                    <code class="block break-all text-xs sm:text-sm text-[#2b1e11] font-mono">{{ $webhookUrl }}</code>
                </div>

                <div class="mt-4">
                    <button id="toggleWebhookBtn" type="button"
                            class="{{ $webhookActive ? 'kaman-button-danger' : 'kaman-button' }}">
                        {{ $webhookActive ? __('whatsapp.deactivate_webhook') : __('whatsapp.activate_webhook') }}
                    </button>
                </div>
            </section>

            {{-- Staff agent test --}}
            <section class="kaman-card kaman-card--pad">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.staff_agent') }}</h2>
                        <p class="mt-1 max-w-xl text-sm text-[#7c6a56]">
                            {{ __('whatsapp.staff_agent_desc') }}
                        </p>
                    </div>
                    <button id="resetStaffChatBtn" type="button" class="kaman-button-ghost kaman-button--sm">
                        {{ __('whatsapp.reset_staff_chat') }}
                    </button>
                </div>

                <div class="mb-4 flex flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 text-sm text-[#2b1e11]">
                        <input type="radio" name="staffRole" value="ceo" checked class="text-[#f47a2e]">
                        {{ __('whatsapp.ceo_role') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-[#2b1e11]">
                        <input type="radio" name="staffRole" value="coworker" class="text-[#f47a2e]">
                        {{ __('whatsapp.coworker_role') }}
                    </label>
                </div>

                <div id="staffChatMessages" class="kaman-well kaman-scroll space-y-3 max-h-[360px] overflow-y-auto p-4 mb-4">
                    <p class="text-sm text-[#a78a6c]">{{ __('whatsapp.staff_empty') }}</p>
                </div>

                <form id="staffChatForm" class="flex flex-wrap gap-2">
                    <input id="staffChatInput" type="text" required maxlength="4000"
                           placeholder="{{ __('whatsapp.staff_placeholder') }}"
                           class="kaman-input min-w-0 flex-1">
                    <button id="staffChatSendBtn" type="submit" class="kaman-button">
                        {{ __('app.send') }}
                    </button>
                </form>
                <p id="staffChatStatus" class="mt-2 text-xs text-[#a78a6c]"></p>
            </section>

            {{-- Test bot --}}
            <section class="kaman-card kaman-card--pad">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.test_bot') }}</h2>
                        <p class="mt-1 max-w-xl text-sm text-[#7c6a56]">
                            {{ __('whatsapp.test_bot_desc') }}
                        </p>
                    </div>
                    <button id="resetTestChatBtn" type="button" class="kaman-button-ghost kaman-button--sm">
                        {{ __('whatsapp.start_fresh') }}
                    </button>
                </div>

                <div id="testChatMessages" class="kaman-well kaman-scroll space-y-3 max-h-[360px] overflow-y-auto p-4 mb-4">
                    <p class="text-sm text-[#a78a6c]">{{ __('whatsapp.test_empty') }}</p>
                </div>

                <form id="testChatForm" class="flex flex-wrap gap-2">
                    <input id="testChatInput" type="text" required maxlength="4000"
                           placeholder="{{ __('whatsapp.test_placeholder') }}"
                           class="kaman-input min-w-0 flex-1">
                    <button id="testChatSendBtn" type="submit" class="kaman-button">
                        {{ __('app.send') }}
                    </button>
                </form>
                <p id="testChatStatus" class="mt-2 text-xs text-[#a78a6c]"></p>
            </section>

            {{-- Manual send --}}
            <section class="kaman-card kaman-card--pad">
                <h2 class="text-lg font-semibold text-[#2b1e11] mb-5">{{ __('whatsapp.manual_send') }}</h2>

                <form method="POST" action="{{ route('whatsapp.bot.test-send') }}" class="space-y-4">
                    @csrf
                    <div class="space-y-2">
                        <label for="chat_id" class="kaman-label block">{{ __('whatsapp.chat_id') }} <span class="text-[#a78a6c] font-normal">{{ __('whatsapp.chat_id_example') }}</span></label>
                        <input id="chat_id" name="chat_id" type="text" required value="{{ old('chat_id') }}"
                               class="kaman-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label for="message" class="kaman-label block">{{ __('whatsapp.incoming_lead') }}</label>
                        <textarea id="message" name="message" rows="4" required
                                  class="kaman-input w-full">{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="kaman-button">
                        {{ __('whatsapp.generate_reply') }}
                    </button>
                </form>

                @if($errors->any())
                    <div class="mt-4 rounded-xl border border-red-200 bg-red-50/70 px-4 py-3 text-sm text-red-600">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if(!empty($lastResult))
                    <div class="kaman-well mt-6 p-4">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-3">
                            {{ __('whatsapp.last_result') }}
                        </p>
                        <dl class="space-y-2 text-sm text-[#2b1e11]">
                            <div class="flex gap-2">
                                <dt class="font-semibold text-[#7c6a56] shrink-0">{{ __('whatsapp.chat_id_label') }}</dt>
                                <dd class="break-all">{{ $lastResult['chat_id'] ?? '' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="font-semibold text-[#7c6a56] shrink-0">{{ __('whatsapp.incoming_label') }}</dt>
                                <dd>{{ $lastResult['incoming'] ?? '' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="font-semibold text-[#7c6a56] shrink-0">{{ __('whatsapp.reply_label') }}</dt>
                                <dd>{{ $lastResult['reply'] ?? '' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="font-semibold text-[#7c6a56] shrink-0">{{ __('whatsapp.green_api_status') }}</dt>
                                <dd>{{ $lastResult['green_api_status'] ?? '' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </section>

            {{-- Prompt --}}
            <section class="kaman-card kaman-card--pad">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                    <h2 class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.prompt') }}</h2>
                    <span id="promptSaveStatus" class="kaman-chip kaman-chip--muted">{{ __('whatsapp.not_saved') }}</span>
                </div>
                <p class="text-sm text-[#7c6a56] mb-4">
                    {{ __('whatsapp.prompt_desc') }}
                </p>

                <textarea id="chatbotPromptInput"
                          rows="9"
                          class="kaman-input kaman-scroll w-full font-mono leading-relaxed">{{ $chatbotPrompt }}</textarea>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button id="savePromptBtn" type="button" class="kaman-button">
                        {{ __('whatsapp.save_prompt') }}
                    </button>
                    <button id="resetPromptBtn" type="button" class="kaman-button-ghost">
                        {{ __('whatsapp.reset_default') }}
                    </button>
                </div>
            </section>

            {{-- Live activity --}}
            <section class="kaman-card kaman-card--pad">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <h2 class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.live_activity') }}</h2>
                    <div class="flex items-center gap-2">
                        <button id="clearWebhookEventsBtn" type="button" class="kaman-button-ghost kaman-button--sm">
                            {{ __('whatsapp.clear_events') }}
                        </button>
                        <span id="webhookLiveStatus" class="kaman-chip kaman-chip--success">{{ __('whatsapp.live') }}</span>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-3 mb-5">
                    <div class="metric-card">
                        <p class="text-[0.6rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-1">{{ __('whatsapp.total_events') }}</p>
                        <p id="webhookTotalEvents" class="text-2xl font-semibold text-[#2b1e11]">0</p>
                    </div>
                    <div class="metric-card">
                        <p class="text-[0.6rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-1">{{ __('whatsapp.last_update') }}</p>
                        <p id="webhookLastUpdate" class="text-lg font-semibold text-[#2b1e11]">-</p>
                    </div>
                    <div class="metric-card">
                        <p class="text-[0.6rem] font-semibold uppercase tracking-[0.18em] text-[#a78a6c] mb-1">{{ __('whatsapp.polling_interval') }}</p>
                        <p class="text-lg font-semibold text-[#2b1e11]">{{ __('whatsapp.every_3_seconds') }}</p>
                    </div>
                </div>

                <div id="webhookEvents" class="kaman-scroll space-y-3 max-h-[460px] overflow-y-auto pr-1">
                    <div class="kaman-well p-4 text-sm text-[#a78a6c]">
                        {{ __('whatsapp.waiting_events') }}
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const __whatsappT = @json(__('whatsapp.js'));

        (function () {
            const eventsContainer = document.getElementById('webhookEvents');
            const totalEventsElement = document.getElementById('webhookTotalEvents');
            const lastUpdateElement = document.getElementById('webhookLastUpdate');
            const liveStatusElement = document.getElementById('webhookLiveStatus');
            const clearEventsButton = document.getElementById('clearWebhookEventsBtn');
            const toggleWebhookButton = document.getElementById('toggleWebhookBtn');
            const webhookActiveBadge = document.getElementById('webhookActiveBadge');
            const promptInput = document.getElementById('chatbotPromptInput');
            const savePromptButton = document.getElementById('savePromptBtn');
            const resetPromptButton = document.getElementById('resetPromptBtn');
            const promptSaveStatus = document.getElementById('promptSaveStatus');
            const eventsUrl = "{{ route('whatsapp.bot.events') }}";
            const clearEventsUrl = "{{ route('whatsapp.bot.events.clear') }}";
            const savePromptUrl = "{{ route('whatsapp.bot.prompt.save') }}";
            const resetPromptUrl = "{{ route('whatsapp.bot.prompt.reset') }}";
            const toggleWebhookUrl = "{{ route('whatsapp.bot.toggle') }}";
            const testChatUrl = "{{ route('whatsapp.bot.test-chat') }}";
            const resetTestChatUrl = "{{ route('whatsapp.bot.test-chat.reset') }}";
            const testStaffChatUrl = "{{ route('whatsapp.bot.test-staff-chat') }}";
            const resetStaffChatUrl = "{{ route('whatsapp.bot.test-staff-chat.reset') }}";
            const csrfToken = "{{ csrf_token() }}";
            const testChatForm = document.getElementById('testChatForm');
            const testChatInput = document.getElementById('testChatInput');
            const testChatSendBtn = document.getElementById('testChatSendBtn');
            const testChatMessages = document.getElementById('testChatMessages');
            const testChatStatus = document.getElementById('testChatStatus');
            const resetTestChatBtn = document.getElementById('resetTestChatBtn');
            const staffChatForm = document.getElementById('staffChatForm');
            const staffChatInput = document.getElementById('staffChatInput');
            const staffChatSendBtn = document.getElementById('staffChatSendBtn');
            const staffChatMessages = document.getElementById('staffChatMessages');
            const staffChatStatus = document.getElementById('staffChatStatus');
            const resetStaffChatBtn = document.getElementById('resetStaffChatBtn');

            // Kaman chip variants, so JS-rendered status matches the server-rendered page.
            const CHIP = {
                base: 'kaman-chip',
                success: 'kaman-chip kaman-chip--success',
                muted: 'kaman-chip kaman-chip--muted',
                accent: 'kaman-chip kaman-chip--accent',
                danger: 'kaman-chip kaman-chip--danger',
            };

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function statusBadge(status) {
                if (status === 'processed') {
                    return `<span class="${CHIP.success}">processed</span>`;
                }
                if (status === 'staff_processed') {
                    return `<span class="${CHIP.success}">staff agent</span>`;
                }
                if (status === 'failed') {
                    return `<span class="${CHIP.danger}">failed</span>`;
                }
                if (status === 'fallback') {
                    return `<span class="${CHIP.accent}">fallback</span>`;
                }
                if (status === 'deactivated') {
                    return `<span class="${CHIP.muted}">deactivated</span>`;
                }
                return `<span class="${CHIP.accent}">ignored</span>`;
            }

            function updateWebhookToggleUi(active) {
                webhookActiveBadge.textContent = active ? 'Active' : 'Inactive';
                webhookActiveBadge.className = active ? CHIP.success : CHIP.muted;

                toggleWebhookButton.textContent = active ? 'Deactivate Webhook' : 'Activate Webhook';
                toggleWebhookButton.className = active
                    ? 'kaman-button-danger'
                    : 'kaman-button';
            }

            function renderEvents(events) {
                if (!events.length) {
                    eventsContainer.innerHTML = `
                        <div class="kaman-well p-4 text-sm text-[#a78a6c]">
                            {{ __('whatsapp.waiting_events') }}
                        </div>
                    `;
                    return;
                }

                eventsContainer.innerHTML = events.slice().reverse().map((event) => `
                    <div class="rounded-2xl border border-[#f1dfc5] bg-white p-4">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <p class="text-xs text-[#a78a6c]">${escapeHtml(event.time || '-')}</p>
                            ${statusBadge(event.status)}
                        </div>
                        <dl class="space-y-1.5 text-sm text-[#2b1e11]">
                            <div class="flex gap-2"><dt class="font-semibold text-[#7c6a56] shrink-0">chatId:</dt><dd class="break-all">${escapeHtml(event.chat_id || '-')}</dd></div>
                            <div class="flex gap-2"><dt class="font-semibold text-[#7c6a56] shrink-0">Incoming:</dt><dd>${escapeHtml(event.incoming || '-')}</dd></div>
                            <div class="flex gap-2"><dt class="font-semibold text-[#7c6a56] shrink-0">Reply:</dt><dd>${escapeHtml(event.reply || '-')}</dd></div>
                            <div class="flex gap-2"><dt class="font-semibold text-[#7c6a56] shrink-0">Green API status:</dt><dd>${escapeHtml(event.green_api_status ?? '-')}</dd></div>
                            ${event.reason ? `<div class="flex gap-2"><dt class="font-semibold text-[#7c6a56] shrink-0">Reason:</dt><dd>${escapeHtml(event.reason)}</dd></div>` : ''}
                        </dl>
                    </div>
                `).join('');
            }

            async function loadEvents() {
                try {
                    const response = await fetch(eventsUrl, { headers: { 'Accept': 'application/json' } });
                    if (!response.ok) {
                        throw new Error('Failed to load events');
                    }

                    const data = await response.json();
                    const events = Array.isArray(data.events) ? data.events : [];

                    totalEventsElement.textContent = String(data.count ?? events.length);
                    lastUpdateElement.textContent = new Date().toLocaleTimeString();
                    liveStatusElement.textContent = 'Live';
                    liveStatusElement.className = CHIP.success;
                    updateWebhookToggleUi(Boolean(data.webhook_active));
                    renderEvents(events);
                } catch (error) {
                    liveStatusElement.textContent = 'Disconnected';
                    liveStatusElement.className = CHIP.danger;
                }
            }

            loadEvents();
            setInterval(loadEvents, 3000);

            savePromptButton.addEventListener('click', async function () {
                savePromptButton.disabled = true;
                promptSaveStatus.textContent = 'Saving...';
                promptSaveStatus.className = CHIP.accent;
                try {
                    const response = await fetch(savePromptUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ prompt: promptInput.value }),
                    });
                    if (!response.ok) {
                        throw new Error('Failed to save prompt');
                    }
                    promptSaveStatus.textContent = 'Saved';
                    promptSaveStatus.className = CHIP.success;
                } catch (error) {
                    console.error(error);
                    promptSaveStatus.textContent = 'Save failed';
                    promptSaveStatus.className = CHIP.danger;
                } finally {
                    savePromptButton.disabled = false;
                }
            });

            resetPromptButton.addEventListener('click', async function () {
                resetPromptButton.disabled = true;
                try {
                    const response = await fetch(resetPromptUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    if (!response.ok) {
                        throw new Error('Failed to reset prompt');
                    }
                    const data = await response.json();
                    if (typeof data.prompt === 'string') {
                        promptInput.value = data.prompt;
                    }
                    promptSaveStatus.textContent = 'Reset to default';
                    promptSaveStatus.className = CHIP.muted;
                } catch (error) {
                    console.error(error);
                    promptSaveStatus.textContent = 'Reset failed';
                    promptSaveStatus.className = CHIP.danger;
                } finally {
                    resetPromptButton.disabled = false;
                }
            });

            clearEventsButton.addEventListener('click', async function () {
                clearEventsButton.disabled = true;
                try {
                    const response = await fetch(clearEventsUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    if (!response.ok) {
                        throw new Error('Failed to clear events');
                    }
                    await loadEvents();
                } catch (error) {
                    console.error(error);
                } finally {
                    clearEventsButton.disabled = false;
                }
            });

            function renderTestChatHistory(history) {
                if (!Array.isArray(history) || !history.length) {
                    testChatMessages.innerHTML = '<p class="text-sm text-[#a78a6c]">' + __whatsappT.test_empty + '</p>';
                    return;
                }

                testChatMessages.innerHTML = history.map((entry) => {
                    const isUser = entry.role === 'user';
                    const bubbleClass = isUser
                        ? 'bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white ml-8'
                        : 'bg-white border border-[#f1dfc5] text-[#2b1e11] mr-8';
                    const label = isUser ? __whatsappT.you : __whatsappT.kaman_assistant;

                    return `
                        <div class="rounded-2xl px-4 py-3 text-sm ${bubbleClass}">
                            <p class="text-[11px] font-semibold opacity-80 mb-1">${label}</p>
                            <p class="whitespace-pre-wrap">${escapeHtml(entry.content || '')}</p>
                        </div>
                    `;
                }).join('');

                testChatMessages.scrollTop = testChatMessages.scrollHeight;
            }

            function renderStaffChatHistory(history) {
                if (!Array.isArray(history) || !history.length) {
                    staffChatMessages.innerHTML = '<p class="text-sm text-[#a78a6c]">' + __whatsappT.staff_empty + '</p>';
                    return;
                }

                staffChatMessages.innerHTML = history.map((entry) => {
                    const isUser = entry.role === 'user';
                    const bubbleClass = isUser
                        ? 'bg-gradient-to-br from-[#f59f43] to-[#f47a2e] text-white ml-8'
                        : 'bg-white border border-[#f1dfc5] text-[#2b1e11] mr-8';
                    const label = isUser ? __whatsappT.you : __whatsappT.staff_agent_label;

                    return `
                        <div class="rounded-2xl px-4 py-3 text-sm ${bubbleClass}">
                            <p class="text-[11px] font-semibold opacity-80 mb-1">${label}</p>
                            <p class="whitespace-pre-wrap">${escapeHtml(entry.content || '')}</p>
                        </div>
                    `;
                }).join('');

                staffChatMessages.scrollTop = staffChatMessages.scrollHeight;
            }

            function selectedStaffRole() {
                const checked = document.querySelector('input[name="staffRole"]:checked');
                return checked ? checked.value : 'ceo';
            }

            testChatForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                const message = testChatInput.value.trim();
                if (!message) {
                    return;
                }

                testChatSendBtn.disabled = true;
                testChatStatus.textContent = __whatsappT.generating_reply;
                testChatStatus.className = 'mt-2 text-xs text-[#a78a6c]';

                try {
                    const response = await fetch(testChatUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ message }),
                    });

                    const data = await response.json();

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Failed to generate reply');
                    }

                    renderTestChatHistory(data.history || []);
                    testChatInput.value = '';

                    if (data.team_notify?.notified) {
                        testChatStatus.textContent = __whatsappT.reply_team_notify;
                        testChatStatus.className = 'mt-2 text-xs text-emerald-700';
                    } else {
                        testChatStatus.textContent = __whatsappT.reply_generated;
                        testChatStatus.className = 'mt-2 text-xs text-[#a78a6c]';
                    }
                } catch (error) {
                    console.error(error);
                    testChatStatus.textContent = error.message || 'Failed to generate reply.';
                    testChatStatus.className = 'mt-2 text-xs text-red-600';
                } finally {
                    testChatSendBtn.disabled = false;
                    testChatInput.focus();
                }
            });

            staffChatForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                const message = staffChatInput.value.trim();
                if (!message) {
                    return;
                }

                staffChatSendBtn.disabled = true;
                staffChatStatus.textContent = 'Processing staff command...';
                staffChatStatus.className = 'mt-2 text-xs text-[#a78a6c]';

                try {
                    const response = await fetch(testStaffChatUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ message, role: selectedStaffRole() }),
                    });

                    const data = await response.json();

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Failed to process staff command');
                    }

                    renderStaffChatHistory(data.history || []);
                    staffChatInput.value = '';
                    staffChatStatus.textContent = 'Staff agent replied.';
                    staffChatStatus.className = 'mt-2 text-xs text-emerald-700';
                } catch (error) {
                    console.error(error);
                    staffChatStatus.textContent = error.message || 'Failed to process staff command.';
                    staffChatStatus.className = 'mt-2 text-xs text-red-600';
                } finally {
                    staffChatSendBtn.disabled = false;
                    staffChatInput.focus();
                }
            });

            resetTestChatBtn.addEventListener('click', async function () {
                resetTestChatBtn.disabled = true;
                testChatStatus.textContent = 'Resetting conversation...';

                try {
                    const response = await fetch(resetTestChatUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Failed to reset conversation');
                    }

                    renderTestChatHistory([]);
                    testChatInput.value = '';
                    testChatStatus.textContent = 'Conversation reset. Send a new message to start fresh.';
                } catch (error) {
                    console.error(error);
                    testChatStatus.textContent = 'Failed to reset conversation.';
                } finally {
                    resetTestChatBtn.disabled = false;
                    testChatInput.focus();
                }
            });

            resetStaffChatBtn.addEventListener('click', async function () {
                resetStaffChatBtn.disabled = true;
                staffChatStatus.textContent = 'Resetting staff conversation...';

                try {
                    const response = await fetch(resetStaffChatUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ role: selectedStaffRole() }),
                    });

                    if (!response.ok) {
                        throw new Error('Failed to reset staff conversation');
                    }

                    renderStaffChatHistory([]);
                    staffChatInput.value = '';
                    staffChatStatus.textContent = 'Staff conversation reset.';
                } catch (error) {
                    console.error(error);
                    staffChatStatus.textContent = 'Failed to reset staff conversation.';
                } finally {
                    resetStaffChatBtn.disabled = false;
                    staffChatInput.focus();
                }
            });

            toggleWebhookButton.addEventListener('click', async function () {
                toggleWebhookButton.disabled = true;
                try {
                    const response = await fetch(toggleWebhookUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    if (!response.ok) {
                        throw new Error('Failed to toggle webhook');
                    }
                    const data = await response.json();
                    updateWebhookToggleUi(Boolean(data.webhook_active));
                    await loadEvents();
                } catch (error) {
                    console.error(error);
                } finally {
                    toggleWebhookButton.disabled = false;
                }
            });
        })();
    </script>
@endpush
