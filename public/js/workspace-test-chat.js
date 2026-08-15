/**
 * Workspace sandbox chat — text, image, mic, and call. Never sends WhatsApp.
 */
(function () {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    function parseI18n(root) {
        try {
            let raw = root.getAttribute('data-i18n') || root.dataset.i18n || '{}';
            if (raw.includes('&quot;') || raw.includes('&#')) {
                const ta = document.createElement('textarea');
                ta.innerHTML = raw;
                raw = ta.value;
            }
            return JSON.parse(raw);
        } catch (_) {
            return {};
        }
    }

    function textDir(text) {
        return /[\u0600-\u06FF\u0590-\u05FF]/.test(text || '') ? 'rtl' : 'ltr';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.WorkspaceTestChat = {
        mount(root) {
            if (!root || root.dataset.testChatMounted === '1') return;
            root.dataset.testChatMounted = '1';

            const t = parseI18n(root);
            const form = root.querySelector('#test-form');
            const input = root.querySelector('#test-input');
            const thread = root.querySelector('#test-thread');
            const empty = root.querySelector('#test-empty');
            const err = root.querySelector('#test-error');
            const status = root.querySelector('#test-status');
            const sendBtn = root.querySelector('#test-send');
            const micBtn = root.querySelector('#test-mic');
            const callBtn = root.querySelector('#test-call');
            const newBtn = root.querySelector('#test-new-chat');
            const attachBtn = root.querySelector('#test-attach');
            const imageInput = root.querySelector('#test-image-input');
            const imagePreview = root.querySelector('#test-image-preview');
            const imagePreviewThumb = root.querySelector('#test-image-preview-thumb');
            const imagePreviewName = root.querySelector('#test-image-preview-name');
            const imagePreviewClear = root.querySelector('#test-image-preview-clear');

            let conversationId = null;
            let busy = false;
            let callActive = false;
            let recognition = null;
            let dictation = null;
            let audio = null;
            let selectedImageFile = null;
            let selectedImageObjectUrl = null;
            let activeTurnId = null;
            let streamAbort = null;
            let audioQueue = Promise.resolve();
            let silenceTimer = null;
            let interimTranscript = '';
            let listeningPaused = false;
            let turnInFlight = false;
            const silenceMs = Math.max(400, Math.min(700, parseInt(root.dataset.silenceMs || '500', 10) || 500));
            const streamingEnabled = root.dataset.streaming !== '0' && !!root.dataset.streamUrl;
            const latencyOverlay = root.dataset.latencyOverlay === '1';
            const latencyEl = root.querySelector('#test-latency');
            const timingState = {};

            const stopPlayback = () => {
                if (audio) {
                    try { audio.pause(); } catch (_) {}
                    try { URL.revokeObjectURL(audio.src); } catch (_) {}
                    audio = null;
                }
                if (window.speechSynthesis) {
                    try { window.speechSynthesis.cancel(); } catch (_) {}
                }
            };

            const interruptTurn = () => {
                activeTurnId = null;
                if (streamAbort) {
                    try { streamAbort.abort(); } catch (_) {}
                    streamAbort = null;
                }
                stopPlayback();
                audioQueue = Promise.resolve();
            };

            const updateLatencyOverlay = () => {
                if (!latencyOverlay || !latencyEl) return;
                const lines = Object.keys(timingState).map((k) => `${k}: ${timingState[k]} ms`);
                if (!lines.length) {
                    latencyEl.classList.add('hidden');
                    return;
                }
                latencyEl.innerHTML = lines.map((l) => escapeHtml(l)).join('<br>');
                latencyEl.classList.remove('hidden');
            };

            const setError = (msg) => {
                if (!err) return;
                if (!msg) {
                    err.classList.add('hidden');
                    err.textContent = '';
                    return;
                }
                err.textContent = msg;
                err.classList.remove('hidden');
            };

            const setStatus = (msg) => {
                if (!status) return;
                if (!msg) {
                    status.classList.add('hidden');
                    status.textContent = '';
                    return;
                }
                status.textContent = msg;
                status.classList.remove('hidden');
            };

            const scrollBottom = () => {
                thread.scrollTop = thread.scrollHeight;
            };

            const clearSelectedImage = () => {
                selectedImageFile = null;
                if (selectedImageObjectUrl) {
                    URL.revokeObjectURL(selectedImageObjectUrl);
                    selectedImageObjectUrl = null;
                }
                if (imageInput) imageInput.value = '';
                if (imagePreviewThumb) {
                    imagePreviewThumb.src = '';
                    imagePreviewThumb.classList.add('hidden');
                }
                if (imagePreviewName) imagePreviewName.textContent = '';
                imagePreview?.classList.add('hidden');
                imagePreview?.classList.remove('flex');
            };

            const setSelectedImage = (file) => {
                if (!file) {
                    clearSelectedImage();
                    return;
                }
                if (!ALLOWED_TYPES.includes(file.type)) {
                    setError(t.attach_invalid || 'Unsupported file type');
                    clearSelectedImage();
                    return;
                }

                selectedImageFile = file;
                if (selectedImageObjectUrl) {
                    URL.revokeObjectURL(selectedImageObjectUrl);
                    selectedImageObjectUrl = null;
                }

                if (file.type === 'application/pdf') {
                    if (imagePreviewThumb) {
                        imagePreviewThumb.src = '';
                        imagePreviewThumb.classList.add('hidden');
                    }
                } else {
                    selectedImageObjectUrl = URL.createObjectURL(file);
                    if (imagePreviewThumb) {
                        imagePreviewThumb.src = selectedImageObjectUrl;
                        imagePreviewThumb.classList.remove('hidden');
                    }
                }

                if (imagePreviewName) imagePreviewName.textContent = file.name;
                imagePreview?.classList.remove('hidden');
                imagePreview?.classList.add('flex');
            };

            const clearThread = () => {
                thread.querySelectorAll('.test-bubble').forEach((n) => n.remove());
                if (empty) {
                    empty.classList.remove('hidden');
                    empty.textContent = t.empty || 'Start chatting…';
                }
            };

            const appendBubble = (role, message, meta = {}) => {
                if (empty) empty.classList.add('hidden');
                const isUser = role === 'user';
                const wrap = document.createElement('div');
                wrap.className = `flex test-bubble ${isUser ? 'justify-end' : 'justify-start'}`;
                const bubble = document.createElement('div');
                bubble.className = `max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm shadow-sm break-words ${
                    isUser
                        ? 'bg-[#f47a2e] text-white rounded-se-md'
                        : 'bg-white text-[#2b1e11] rounded-ss-md'
                }`;

                let mediaHtml = '';
                if (meta.attachment_url && meta.is_image) {
                    mediaHtml = `<a href="${escapeHtml(meta.attachment_url)}" target="_blank" rel="noopener" class="mb-2 block overflow-hidden rounded-xl"><img src="${escapeHtml(meta.attachment_url)}" alt="" class="max-h-56 w-full object-cover"></a>`;
                } else if (meta.attachment_url && meta.is_pdf) {
                    mediaHtml = `<a href="${escapeHtml(meta.attachment_url)}" target="_blank" rel="noopener" class="mb-2 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm ${isUser ? 'bg-white/15' : 'bg-[#f7efe3]'}">📄 ${escapeHtml(t.attachment_file || 'PDF')}</a>`;
                } else if (meta.attachment_url) {
                    mediaHtml = `<a href="${escapeHtml(meta.attachment_url)}" target="_blank" rel="noopener" class="mb-2 inline-block text-sm underline">${escapeHtml(t.attachment_file || 'File')}</a>`;
                }

                const text = (message || '').trim();
                const textHtml = text
                    ? `<div class="whitespace-pre-wrap" dir="${textDir(text)}">${escapeHtml(text)}</div>`
                    : '';

                bubble.innerHTML = mediaHtml + textHtml;
                wrap.appendChild(bubble);
                thread.appendChild(wrap);
                scrollBottom();
            };

            const renderMessages = (messages) => {
                clearThread();
                (messages || []).forEach((m) => appendBubble(m.role, m.message, m));
            };

            const postJson = async (payload) => {
                const res = await fetch(root.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': root.dataset.csrf,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok && !data.conversation_id) {
                    throw new Error(data.error || t.error || 'Request failed');
                }
                return data;
            };

            const postImage = async (file, caption) => {
                const body = new FormData();
                body.append('image', file);
                if (caption) body.append('caption', caption);
                if (conversationId) body.append('conversation_id', String(conversationId));
                body.append('reset', conversationId ? '0' : '1');
                body.append('channel', 'test');

                const res = await fetch(root.dataset.imageUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': root.dataset.csrf,
                    },
                    body,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok && !data.conversation_id) {
                    throw new Error(data.error || t.attach_error || t.error || 'Upload failed');
                }
                return data;
            };

            const splitSpeakChunks = (text) => {
                const cleaned = String(text || '').replace(/\s+/g, ' ').trim();
                if (!cleaned) return [];
                if (cleaned.length <= 72) return [cleaned];

                const parts = cleaned
                    .split(/(?<=[.!?؟…])\s+|(?<=[،؛])\s+/u)
                    .map((p) => p.trim())
                    .filter(Boolean);

                if (parts.length <= 1) {
                    // Hard-wrap long single sentence so first audio starts sooner.
                    const chunks = [];
                    let rest = cleaned;
                    while (rest.length > 72) {
                        let cut = rest.lastIndexOf(' ', 72);
                        if (cut < 28) cut = 72;
                        chunks.push(rest.slice(0, cut).trim());
                        rest = rest.slice(cut).trim();
                    }
                    if (rest) chunks.push(rest);
                    return chunks;
                }

                const chunks = [];
                let buf = '';
                for (const part of parts) {
                    const next = buf ? `${buf} ${part}` : part;
                    // Keep the first spoken chunk short for faster time-to-first-audio.
                    const limit = chunks.length === 0 ? 64 : 110;
                    if (buf && next.length > limit) {
                        chunks.push(buf);
                        buf = part;
                    } else {
                        buf = next;
                    }
                }
                if (buf) chunks.push(buf);
                return chunks;
            };

            const fetchTtsBlob = async (text) => {
                const res = await fetch(root.dataset.ttsUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'audio/wav, audio/mpeg, application/json',
                        'X-CSRF-TOKEN': root.dataset.csrf,
                    },
                    body: JSON.stringify({ text, voice_profile: 'woman', locale: 'ar' }),
                });
                if (!res.ok) return null;
                const contentType = res.headers.get('Content-Type') || '';
                if (contentType.includes('application/json')) {
                    return { kind: 'json', data: await res.json().catch(() => ({})) };
                }
                return { kind: 'audio', blob: await res.blob() };
            };

            const playAudioBlob = async (blob, turnId) => {
                if (turnId && activeTurnId && turnId !== activeTurnId) return;
                const url = URL.createObjectURL(blob);
                if (audio) {
                    audio.pause();
                    try { URL.revokeObjectURL(audio.src); } catch (_) {}
                }
                audio = new Audio(url);
                await new Promise((resolve) => {
                    audio.onended = resolve;
                    audio.onerror = resolve;
                    audio.play().catch(() => resolve());
                });
                URL.revokeObjectURL(url);
            };

            const enqueueAudio = (blob, turnId) => {
                audioQueue = audioQueue.then(async () => {
                    if (turnId && activeTurnId && turnId !== activeTurnId) return;
                    setStatus(t.speaking || 'Speaking…');
                    await playAudioBlob(blob, turnId);
                }).catch(() => {});
                return audioQueue;
            };

            const b64ToBlob = (b64, contentType) => {
                const bin = atob(b64);
                const bytes = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                return new Blob([bytes], { type: contentType || 'audio/wav' });
            };

            const streamConverse = async (text) => {
                const turnStarted = performance.now();
                Object.keys(timingState).forEach((k) => delete timingState[k]);
                timingState.speech_to_request = 0;
                updateLatencyOverlay();

                streamAbort = new AbortController();
                const res = await fetch(root.dataset.streamUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'text/event-stream',
                        'X-CSRF-TOKEN': root.dataset.csrf,
                    },
                    body: JSON.stringify({
                        message: text,
                        conversation_id: conversationId,
                        voice_profile: 'woman',
                        locale: 'ar',
                        channel: 'test',
                    }),
                    signal: streamAbort.signal,
                });
                if (!res.ok || !res.body) {
                    const err = new Error(t.error || 'Stream failed');
                    err.status = res.status;
                    throw err;
                }

                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let fullText = '';
                let firstAudioMarked = false;
                let sawAssistant = false;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';
                    for (const part of parts) {
                        const line = part.split('\n').find((l) => l.startsWith('data:'));
                        if (!line) continue;
                        let event;
                        try { event = JSON.parse(line.slice(5).trim()); } catch (_) { continue; }
                        const type = event?.type;
                        if (type === 'turn_start') {
                            activeTurnId = event.turn_id;
                        } else if (type === 'conversation' && event.conversation_id) {
                            conversationId = event.conversation_id;
                        } else if (type === 'timing' && event.key) {
                            timingState[event.key] = event.ms;
                            updateLatencyOverlay();
                        } else if (type === 'assistant_phrase' && event.text) {
                            sawAssistant = true;
                            fullText = fullText ? `${fullText} ${event.text}` : event.text;
                        } else if (type === 'audio_chunk' && event.audio_base64) {
                            if (event.turn_id && activeTurnId && event.turn_id !== activeTurnId) continue;
                            if (!firstAudioMarked) {
                                firstAudioMarked = true;
                                timingState.browser_ttfa = Math.round(performance.now() - turnStarted);
                                updateLatencyOverlay();
                            }
                            const blob = b64ToBlob(event.audio_base64, event.content_type || 'audio/wav');
                            enqueueAudio(blob, event.turn_id || activeTurnId);
                        } else if (type === 'assistant_done') {
                            sawAssistant = true;
                            if (event.timing) {
                                Object.assign(timingState, event.timing);
                                updateLatencyOverlay();
                            }
                            if (event.text) fullText = event.text;
                        } else if (type === 'error') {
                            throw new Error(event.message || t.error || 'Error');
                        }
                    }
                }

                await audioQueue;
                if (fullText) {
                    appendBubble('assistant', fullText);
                } else if (!sawAssistant) {
                    throw new Error(t.error || 'Stream empty');
                }
            };

            const converseVoice = async (message) => {
                if (streamingEnabled) {
                    try {
                        await streamConverse(message);
                        return;
                    } catch (ex) {
                        // Intentional barge-in / navigation cancel — don't fall back.
                        if (ex?.name === 'AbortError') throw ex;
                        // Shared hosting / race / SSE drop → reliable non-stream path.
                        console.warn('voice stream failed, falling back', ex);
                    }
                }

                const data = await postJson({
                    message,
                    conversation_id: conversationId,
                    reset: !conversationId,
                    voice_mode: true,
                    channel: 'test',
                });
                await applyResponse(data, { speakReply: true });
            };

            const speak = async (text) => {
                if (!text || !root.dataset.ttsUrl) return;
                try {
                    setStatus(t.speaking || 'Speaking…');
                    const chunks = splitSpeakChunks(text);
                    if (!chunks.length) return;

                    // Prefetch next chunk while current audio plays → faster perceived replies.
                    let nextFetch = fetchTtsBlob(chunks[0]);
                    for (let i = 0; i < chunks.length; i++) {
                        if (!callActive && i > 0) break;
                        const payload = await nextFetch;
                        if (i + 1 < chunks.length) {
                            nextFetch = fetchTtsBlob(chunks[i + 1]);
                        }
                        if (!payload) continue;
                        if (payload.kind === 'json') {
                            if (!callActive && window.speechSynthesis && payload.data?.use_browser) {
                                await new Promise((resolve) => {
                                    const u = new SpeechSynthesisUtterance(chunks[i]);
                                    u.lang = 'ar-SA';
                                    u.onend = resolve;
                                    u.onerror = resolve;
                                    window.speechSynthesis.speak(u);
                                });
                            }
                            return;
                        }
                        if (payload.blob) {
                            await playAudioBlob(payload.blob, activeTurnId);
                        }
                    }
                } catch (_) {
                    // TTS optional
                } finally {
                    if (callActive) setStatus(t.listening || 'Listening…');
                    else setStatus('');
                }
            };

            const applyResponse = async (data, { speakReply = false } = {}) => {
                // Start TTS immediately (don't wait on DOM render) for lower reply latency.
                const speakPromise = (speakReply && data.assistant_response)
                    ? speak(data.assistant_response)
                    : null;

                if (data.conversation_id) conversationId = data.conversation_id;
                if (Array.isArray(data.messages) && data.messages.length) {
                    renderMessages(data.messages);
                } else if (data.assistant_response) {
                    appendBubble('assistant', data.assistant_response);
                }
                if (!data.ok) setError(data.error || t.error || 'Error');
                if (speakPromise) {
                    await speakPromise;
                }
            };

            const sendMessage = async (text, { voiceMode = false, speakReply = false } = {}) => {
                const message = (text || '').trim();
                const pendingFile = selectedImageFile || imageInput?.files?.[0] || null;
                if ((!message && !pendingFile) || busy) return;
                busy = true;
                setError('');
                sendBtn.disabled = true;
                if (attachBtn) attachBtn.disabled = true;
                if (voiceMode || speakReply) {
                    setStatus(t.processing || 'لحظة من فضلك…');
                }

                const localPreviewUrl = selectedImageObjectUrl
                    || (pendingFile && pendingFile.type !== 'application/pdf' ? URL.createObjectURL(pendingFile) : null);
                if (pendingFile) {
                    appendBubble('user', message, {
                        attachment_url: pendingFile.type === 'application/pdf' ? null : localPreviewUrl,
                        is_image: pendingFile.type !== 'application/pdf',
                        is_pdf: pendingFile.type === 'application/pdf',
                    });
                } else {
                    appendBubble('user', message);
                }
                input.value = '';

                try {
                    let data;
                    if (pendingFile) {
                        clearSelectedImage();
                        data = await postImage(pendingFile, message);
                        await applyResponse(data, { speakReply });
                    } else if (voiceMode) {
                        await converseVoice(message);
                    } else {
                        data = await postJson({
                            message,
                            conversation_id: conversationId,
                            reset: !conversationId,
                            voice_mode: false,
                            channel: 'test',
                        });
                        await applyResponse(data, { speakReply });
                    }
                } catch (ex) {
                    if (ex?.name !== 'AbortError') {
                        setError(ex.message || t.error || 'Error');
                    }
                    if (callActive) setStatus(t.listening || 'Listening…');
                    else setStatus('');
                } finally {
                    busy = false;
                    sendBtn.disabled = false;
                    if (attachBtn) attachBtn.disabled = false;
                    input.focus();
                }
            };

            const stopRecognition = (rec) => {
                try { rec?.stop(); } catch (_) {}
                try { rec?.abort(); } catch (_) {}
            };

            const startDictation = () => {
                if (!SpeechRecognition) {
                    setError(t.unsupported || 'Voice not supported in this browser');
                    return;
                }
                stopRecognition(dictation);
                dictation = new SpeechRecognition();
                dictation.lang = 'ar-IL';
                dictation.interimResults = true;
                dictation.continuous = false;
                micBtn.setAttribute('aria-pressed', 'true');
                micBtn.classList.add('ring-2', 'ring-[#f47a2e]');
                setStatus(t.listening || 'Listening…');
                dictation.onresult = (ev) => {
                    let finalText = '';
                    let interim = '';
                    for (let i = 0; i < ev.results.length; i++) {
                        const piece = ev.results[i][0]?.transcript || '';
                        if (ev.results[i].isFinal) finalText += piece;
                        else interim += piece;
                    }
                    const text = cleanSttText((finalText || interim).trim());
                    if (text) input.value = text;
                    if (finalText.trim()) {
                        input.value = cleanSttText(finalText.trim());
                        sendMessage(cleanSttText(finalText.trim()));
                    }
                };
                dictation.onerror = () => {
                    micBtn.setAttribute('aria-pressed', 'false');
                    micBtn.classList.remove('ring-2', 'ring-[#f47a2e]');
                    setStatus('');
                };
                dictation.onend = () => {
                    micBtn.setAttribute('aria-pressed', 'false');
                    micBtn.classList.remove('ring-2', 'ring-[#f47a2e]');
                    if (!callActive) setStatus('');
                };
                dictation.start();
            };

            const stopCall = () => {
                callActive = false;
                listeningPaused = false;
                turnInFlight = false;
                interruptTurn();
                if (silenceTimer) {
                    clearTimeout(silenceTimer);
                    silenceTimer = null;
                }
                stopRecognition(recognition);
                recognition = null;
                callBtn.setAttribute('aria-pressed', 'false');
                callBtn.classList.remove('ring-2', 'ring-emerald-500');
                setStatus('');
            };

            const NUMBER_WORDS = new Set([
                'صفر', 'زيرو', 'زرو', 'zero',
                'واحد', 'وحد', 'واحدة', 'one',
                'اتنين', 'اثنين', 'ثنين', 'اثنان', 'تنين', 'two',
                'ثلاثة', 'تلاتة', 'تلاته', 'ثلاث', 'تلات', 'three',
                'اربعة', 'أربعة', 'اربعه', 'أربعه', 'اربع', 'four',
                'خمسة', 'خمسه', 'خمس', 'five',
                'ستة', 'سته', 'ست', 'six',
                'سبعة', 'سبعه', 'سبع', 'seven',
                'ثمانية', 'ثمانيه', 'تمانية', 'تمانيه', 'ثمان', 'تمان', 'eight',
                'تسعة', 'تسعه', 'تسع', 'nine',
                'אפס', 'אחד', 'שתיים', 'שנים', 'שלוש', 'ארבע', 'חמש', 'שש', 'שבע', 'שמונה', 'תשע',
            ]);

            const isNumericToken = (w) => {
                if (/^\d+$/u.test(w)) return true;
                const lower = String(w || '').toLowerCase();
                if (NUMBER_WORDS.has(lower)) return true;
                // Prefix forms still count as numeric ("خمسين", "واحده")
                for (const word of NUMBER_WORDS) {
                    if (word.length >= 2 && (lower.startsWith(word) || word.startsWith(lower))) {
                        return true;
                    }
                }
                return false;
            };

            const cleanSttText = (text) => {
                let t = String(text || '').replace(/\s+/g, ' ').trim();
                if (!t) return '';

                const words = t.split(' ');
                const out = [];
                for (const raw of words) {
                    const w = raw.trim();
                    if (!w) continue;
                    const prev = out[out.length - 1];
                    if (!prev) {
                        out.push(w);
                        continue;
                    }
                    const prevNumeric = isNumericToken(prev);
                    const curNumeric = isNumericToken(w);

                    // Keep repeated digits / number-words: "5 5", "خمسة خمسة"
                    if (prev === w && (prevNumeric || curNumeric)) {
                        out.push(w);
                        continue;
                    }
                    // Collapse only non-numeric stutter: "انت انت"
                    if (prev === w) continue;

                    // Growing hypothesis only for normal words, never for numbers
                    // (otherwise "5"+"55" would collapse and lose dictation intent).
                    if (!prevNumeric && !curNumeric && w.startsWith(prev) && w.length > prev.length) {
                        out[out.length - 1] = w;
                        continue;
                    }
                    if (!prevNumeric && !curNumeric && prev.startsWith(w) && prev.length > w.length) {
                        continue;
                    }
                    out.push(w);
                }

                return out.join(' ').trim();
            };

            const readRecognitionText = (ev) => {
                // Rebuild from the full result list — never append interim fragments across events.
                let text = '';
                for (let i = 0; i < ev.results.length; i++) {
                    text += ev.results[i][0]?.transcript || '';
                }
                return cleanSttText(text);
            };

            const flushUtterance = async (text) => {
                text = cleanSttText(text);
                if (!callActive) return;
                if (!text) {
                    listeningPaused = false;
                    if (!busy && !turnInFlight) listenInCall();
                    return;
                }
                if (turnInFlight || busy) return;

                turnInFlight = true;
                listeningPaused = true;
                if (silenceTimer) {
                    clearTimeout(silenceTimer);
                    silenceTimer = null;
                }
                stopRecognition(recognition);
                // Stop leftover playback only — do NOT abort the stream we are about to start.
                stopPlayback();
                activeTurnId = null;
                interimTranscript = '';
                if (input) input.value = '';

                try {
                    await sendMessage(text, { voiceMode: true, speakReply: true });
                } finally {
                    turnInFlight = false;
                    listeningPaused = false;
                    if (callActive) listenInCall();
                }
            };

            const scheduleSilenceFlush = (rec) => {
                if (busy || turnInFlight || !callActive) return;
                if (silenceTimer) clearTimeout(silenceTimer);
                silenceTimer = setTimeout(async () => {
                    silenceTimer = null;
                    if (busy || turnInFlight || !callActive) return;
                    const text = interimTranscript.trim();
                    interimTranscript = '';
                    listeningPaused = true;
                    try { rec?.stop(); } catch (_) {}
                    await flushUtterance(text);
                }, silenceMs);
            };

            const listenInCall = () => {
                if (!callActive || !SpeechRecognition) return;
                if (busy || turnInFlight) return;
                stopRecognition(recognition);
                recognition = new SpeechRecognition();
                recognition.lang = 'ar-IL';
                recognition.interimResults = true;
                recognition.continuous = true;
                recognition.maxAlternatives = 1;
                interimTranscript = '';
                listeningPaused = false;
                setStatus(t.listening || 'Listening…');
                recognition.onresult = (ev) => {
                    if (busy || turnInFlight || listeningPaused) return;
                    interimTranscript = readRecognitionText(ev);
                    if (input) input.value = interimTranscript;
                    scheduleSilenceFlush(recognition);
                };
                recognition.onerror = (ev) => {
                    const soft = ['no-speech', 'aborted'].includes(ev?.error);
                    if (callActive && !listeningPaused && !busy && !turnInFlight) {
                        setTimeout(() => listenInCall(), soft ? 250 : 450);
                    }
                };
                recognition.onend = () => {
                    if (callActive && !listeningPaused && !silenceTimer && !busy && !turnInFlight) {
                        setTimeout(() => listenInCall(), 200);
                    }
                };
                try {
                    recognition.start();
                } catch (_) {
                    if (callActive && !busy && !turnInFlight) setTimeout(() => listenInCall(), 400);
                }
            };

            const startCall = async () => {
                if (!SpeechRecognition) {
                    setError(t.unsupported || 'Voice not supported in this browser');
                    return;
                }
                if (callActive) {
                    stopCall();
                    return;
                }
                callActive = true;
                callBtn.setAttribute('aria-pressed', 'true');
                callBtn.classList.add('ring-2', 'ring-emerald-500');
                stopRecognition(dictation);
                listenInCall();
            };

            form?.addEventListener('submit', (e) => {
                e.preventDefault();
                sendMessage(input.value);
            });

            input?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    form.requestSubmit();
                }
            });

            input?.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 112) + 'px';
            });

            const syncSelectedFromInput = () => {
                const file = imageInput?.files?.[0] || null;
                setError('');
                setSelectedImage(file);
            };

            attachBtn?.addEventListener('click', () => {
                imageInput?.click();
            });

            imageInput?.addEventListener('change', syncSelectedFromInput);
            imageInput?.addEventListener('input', syncSelectedFromInput);

            imagePreviewClear?.addEventListener('click', () => {
                clearSelectedImage();
            });

            micBtn?.addEventListener('click', () => {
                if (callActive) stopCall();
                if (micBtn.getAttribute('aria-pressed') === 'true') {
                    stopRecognition(dictation);
                    return;
                }
                startDictation();
            });

            callBtn?.addEventListener('click', () => {
                startCall();
            });

            newBtn?.addEventListener('click', async () => {
                stopCall();
                stopRecognition(dictation);
                clearSelectedImage();
                setError('');
                busy = true;
                try {
                    const data = await postJson({ reset: true, message: '' });
                    conversationId = data.conversation_id || null;
                    clearThread();
                } catch (ex) {
                    conversationId = null;
                    clearThread();
                    setError(ex.message || t.error || 'Error');
                } finally {
                    busy = false;
                }
            });
        },
    };
})();
