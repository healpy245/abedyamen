/**
 * OpenAI Realtime speech-to-speech via WebRTC (GA unified /realtime/calls flow).
 * Browser sends SDP to Laravel; Laravel proxies to OpenAI with server API key.
 */
window.RealtimeCall = (function () {
    const CALL_METRIC_KEYS = [
        'connection_start',
        'connection_ready',
        'greeting_started',
        'greeting_completed',
    ];

    function nowMs() {
        return performance.now();
    }

    function postJson(url, csrf, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body ?? {}),
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'request_failed');
            }
            return data;
        });
    }

    function mount(options) {
        const {
            startButton,
            endButton,
            muteButton,
            statusEl,
            diagnosticsEl,
            sessionUrl,
            csrf,
            conversationId,
            phoneStates = {},
            onError,
            onStateChange,
            onTranscript,
            onMessageHtml,
        } = options;

        let pc = null;
        let dc = null;
        let localStream = null;
        let remoteAudio = null;
        let sessionData = null;
        let active = false;
        let muted = false;
        let greetingSent = false;
        let greetingPlaying = false;
        let interruptionCount = 0;
        let currentResponseId = null;
        let currentAssistantItemId = null;
        let lastConnectError = '';
        let turnState = 'idle';
        let turnIndex = 0;
        let currentTurn = null;
        let interruptionInProgress = false;
        let lastInterruptionAt = 0;
        let pendingToolResponse = false;
        let assistantSpeaking = false;

        const callMetrics = Object.fromEntries(CALL_METRIC_KEYS.map((k) => [k, null]));
        const turns = [];
        const processedToolCallIds = new Set();
        const recordedAssistantTranscripts = new Set();
        const debugLog = [];

        function logDebug(message, extra = null) {
            if (!sessionData?.diagnostics_enabled) {
                return;
            }
            const entry = { at: nowMs(), message, ...(extra || {}) };
            debugLog.push(entry);
            console.info('[RealtimeCall]', message, extra || '');
        }

        function setState(state, detail = '') {
            if (state !== 'muted') {
                turnState = state;
            }
            if (statusEl && phoneStates[state]) {
                statusEl.textContent = phoneStates[state];
            }
            onStateChange?.(state, detail);
            renderDiagnostics();
        }

        function markCallMetric(key, extra = null) {
            if (!(key in callMetrics) || callMetrics[key] === null) {
                callMetrics[key] = nowMs();
            }
            queueMetrics({ [key]: { at: callMetrics[key], ...(extra || {}) } });
            renderDiagnostics();
        }

        function beginTurn() {
            turnIndex += 1;
            currentTurn = {
                index: turnIndex,
                speech_started: null,
                speech_stopped: null,
                response_created: null,
                first_audio_received: null,
                first_audio_played: null,
                response_done: null,
                interrupted: false,
                cancelled: false,
            };
            turns.push(currentTurn);
            return currentTurn;
        }

        function markTurnMetric(key, extra = null) {
            if (!currentTurn) {
                beginTurn();
            }
            if (currentTurn[key] === null || currentTurn[key] === false) {
                currentTurn[key] = key === 'interrupted' || key === 'cancelled' ? true : nowMs();
            }
            queueMetrics({
                [`turn_${currentTurn.index}_${key}`]: {
                    at: typeof currentTurn[key] === 'number' ? currentTurn[key] : nowMs(),
                    ...(extra || {}),
                },
            });
            renderDiagnostics();
        }

        let metricsFlushTimer = null;
        const pendingMetrics = {};

        function queueMetrics(batch) {
            Object.assign(pendingMetrics, batch);
            if (!sessionData?.metrics_url) {
                return;
            }
            clearTimeout(metricsFlushTimer);
            metricsFlushTimer = setTimeout(flushMetrics, 400);
        }

        function flushMetrics() {
            if (!sessionData?.metrics_url || !Object.keys(pendingMetrics).length) {
                return;
            }
            const payload = { ...pendingMetrics };
            Object.keys(pendingMetrics).forEach((key) => delete pendingMetrics[key]);
            postJson(sessionData.metrics_url, csrf, { metrics: payload }).catch(() => {});
        }

        function renderDiagnostics() {
            if (!diagnosticsEl) return;

            const turn = currentTurn;
            const speechStop = turn?.speech_stopped;
            const firstAudio = turn?.first_audio_played;
            const latency = speechStop && firstAudio ? Math.round(firstAudio - speechStop) : '—';

            diagnosticsEl.innerHTML = `
                <div class="grid grid-cols-2 gap-2 text-[10px] text-left">
                    <div>Turn latency: <strong>${latency} ms</strong></div>
                    <div>State: <strong>${turnState}</strong></div>
                    <div>ICE: <strong>${pc?.iceConnectionState || '—'}</strong></div>
                    <div>Voice: <strong>${sessionData?.voice || '—'}</strong></div>
                    <div>Response: <strong>${currentResponseId || '—'}</strong></div>
                    <div>Interruptions: <strong>${interruptionCount}</strong></div>
                    ${lastConnectError ? `<div class="col-span-2 text-red-600 break-all"><strong>Error:</strong> ${lastConnectError}</div>` : ''}
                </div>`;
        }

        function waitForIceGatheringComplete(peerConnection, timeoutMs = 15000) {
            if (peerConnection.iceGatheringState === 'complete') {
                return Promise.resolve();
            }

            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    peerConnection.removeEventListener('icegatheringstatechange', onChange);
                    reject(new Error('ice_gathering_timeout'));
                }, timeoutMs);

                function onChange() {
                    if (peerConnection.iceGatheringState === 'complete') {
                        clearTimeout(timer);
                        peerConnection.removeEventListener('icegatheringstatechange', onChange);
                        resolve();
                    }
                }

                peerConnection.addEventListener('icegatheringstatechange', onChange);
            });
        }

        function validateOfferSdp(sdp) {
            if (typeof sdp !== 'string') return false;
            const trimmed = sdp.trim();
            return trimmed.startsWith('v=0')
                && /\nm=audio /m.test(trimmed)
                && /\na=fingerprint:/m.test(trimmed)
                && /\na=ice-ufrag:/m.test(trimmed)
                && trimmed.length >= 200;
        }

        function sendDc(payload) {
            if (!dc || dc.readyState !== 'open') return false;
            dc.send(JSON.stringify(payload));
            return true;
        }

        async function flushEvents(events) {
            if (!sessionData?.events_url || !events.length) {
                return null;
            }

            const data = await postJson(sessionData.events_url, csrf, { events });

            if (data.conversation_id) {
                options.onConversationId?.(data.conversation_id);
            }

            data.messages?.forEach((message) => {
                if (message?.html) {
                    options.onMessageHtml?.(message);
                }
            });

            return data;
        }

        function stopAssistantPlayback() {
            if (!remoteAudio) {
                return 0;
            }
            const playedMs = Math.max(0, Math.floor(remoteAudio.currentTime * 1000));
            remoteAudio.pause();
            remoteAudio.currentTime = 0;
            return playedMs;
        }

        function handleInterruption(source = 'speech_started') {
            const now = nowMs();
            if (interruptionInProgress || (now - lastInterruptionAt) < 250) {
                return;
            }
            if (!assistantSpeaking && !greetingPlaying && turnState !== 'assistant_speaking' && turnState !== 'waiting_for_response') {
                return;
            }

            interruptionInProgress = true;
            lastInterruptionAt = now;
            const startedAt = now;

            interruptionCount += 1;
            markTurnMetric('interrupted', { source });
            queueMetrics({ interruption_to_audio_stop_ms: { at: nowMs(), ms: 0 } });

            const playedMs = stopAssistantPlayback();

            sendDc({ type: 'response.cancel' });
            sendDc({ type: 'output_audio_buffer.clear' });

            if (currentAssistantItemId && playedMs > 0) {
                sendDc({
                    type: 'conversation.item.truncate',
                    item_id: currentAssistantItemId,
                    content_index: 0,
                    audio_end_ms: playedMs,
                });
            }

            logDebug('interruption handled', {
                source,
                response_id: currentResponseId,
                item_id: currentAssistantItemId,
                played_ms: playedMs,
                sequence: interruptionCount,
            });

            assistantSpeaking = false;
            greetingPlaying = false;
            pendingToolResponse = false;
            currentResponseId = null;
            setState('interrupted');

            flushEvents([{ type: 'interruption' }]).catch(() => {});

            queueMetrics({
                interruption_to_audio_stop_ms: { at: nowMs(), ms: Math.round(nowMs() - startedAt) },
            });

            setTimeout(() => {
                interruptionInProgress = false;
                if (turnState === 'interrupted') {
                    setState('user_speaking');
                }
            }, 200);
        }

        function recordAssistantTranscript(text) {
            const key = `${currentResponseId || 'none'}:${text.trim()}`;
            if (!text.trim() || recordedAssistantTranscripts.has(key)) {
                queueMetrics({ duplicate_response_prevented: { at: nowMs(), key } });
                return;
            }
            recordedAssistantTranscripts.add(key);
            onTranscript?.('assistant', text);
            flushEvents([{ type: 'transcript', role: 'assistant', content: text }]).catch(() => {});
        }

        function handleServerEvent(event) {
            const type = event.type || '';

            if (type === 'session.created' || type === 'session.updated') {
                markCallMetric('connection_ready');
                setState('listening');
            }

            if (type === 'input_audio_buffer.speech_started') {
                if (!currentTurn || currentTurn.response_done) {
                    beginTurn();
                }
                markTurnMetric('speech_started');
                setState('user_speaking');

                if (assistantSpeaking || greetingPlaying || turnState === 'assistant_speaking' || turnState === 'waiting_for_response') {
                    handleInterruption('speech_started');
                }
            }

            if (type === 'input_audio_buffer.speech_stopped') {
                markTurnMetric('speech_stopped');
                setState('waiting_for_response');
            }

            if (type === 'conversation.item.input_audio_transcription.completed') {
                const text = event.transcript || '';
                if (text.trim()) {
                    onTranscript?.('user', text);
                    flushEvents([{ type: 'transcript', role: 'user', content: text }]).catch(() => {});
                }
            }

            if (type === 'response.created') {
                currentResponseId = event.response?.id || null;
                pendingToolResponse = false;
                markTurnMetric('response_created');
                setState('waiting_for_response');
                logDebug('response.created', { response_id: currentResponseId });
            }

            if (type === 'response.output_item.added') {
                const item = event.item || {};
                if (item.id) {
                    currentAssistantItemId = item.id;
                }
            }

            if (type === 'response.audio_transcript.done' || type === 'response.output_audio_transcript.done') {
                recordAssistantTranscript(event.transcript || '');
            }

            if (type === 'output_audio_buffer.started' || type === 'response.audio.delta') {
                if (!currentTurn?.first_audio_received) {
                    markTurnMetric('first_audio_received');
                }
                assistantSpeaking = true;
                setState('assistant_speaking');
                if (remoteAudio && remoteAudio.paused) {
                    remoteAudio.play().catch(() => {});
                    if (!currentTurn?.first_audio_played) {
                        markTurnMetric('first_audio_played');
                    }
                }
            }

            if (type === 'response.done' || type === 'response.audio.done' || type === 'output_audio_buffer.stopped') {
                assistantSpeaking = false;
                if (greetingPlaying) {
                    greetingPlaying = false;
                    markCallMetric('greeting_completed');
                    queueMetrics({
                        greeting_duration_ms: {
                            at: nowMs(),
                            ms: callMetrics.greeting_started && callMetrics.greeting_completed
                                ? Math.round(callMetrics.greeting_completed - callMetrics.greeting_started)
                                : null,
                        },
                    });
                }
                markTurnMetric('response_done');
                currentResponseId = null;
                currentAssistantItemId = null;
                pendingToolResponse = false;
                setState('listening');
            }

            if (type === 'response.cancelled') {
                assistantSpeaking = false;
                greetingPlaying = false;
                markTurnMetric('cancelled');
                currentResponseId = null;
                pendingToolResponse = false;
                setState('listening');
                logDebug('response.cancelled', { response_id: event.response?.id || null });
            }

            if (type === 'response.function_call_arguments.done') {
                handleToolCall(event);
            }

            if (type === 'response.output_item.done') {
                const item = event.item || {};
                if (item.type === 'function_call') {
                    handleToolCall({ ...event, ...item });
                }
            }

            if (type === 'error') {
                onError?.(event.error?.message || 'realtime_error');
            }
        }

        async function handleToolCall(event) {
            const toolName = event.name || event.item?.name;
            const callId = event.call_id || event.item?.call_id;
            if (!toolName || !callId || processedToolCallIds.has(callId)) {
                return;
            }
            processedToolCallIds.add(callId);

            let args = {};
            try {
                args = JSON.parse(event.arguments || event.item?.arguments || '{}');
            } catch (e) {
                args = {};
            }

            if (!sessionData?.tools_url) {
                return;
            }

            setState('tool_running');
            const toolStarted = nowMs();

            try {
                const result = await postJson(sessionData.tools_url, csrf, {
                    call_id: String(sessionData.voice_call_id),
                    tool_name: toolName,
                    arguments: args,
                    call_id_openai: callId,
                });

                sendDc({
                    type: 'conversation.item.create',
                    item: {
                        type: 'function_call_output',
                        call_id: callId,
                        output: JSON.stringify(result.result),
                    },
                });

                if (!pendingToolResponse) {
                    pendingToolResponse = true;
                    sendDc({ type: 'response.create' });
                }

                queueMetrics({
                    tool_duration_ms: {
                        at: nowMs(),
                        tool: toolName,
                        ms: Math.round(nowMs() - toolStarted),
                    },
                });
            } catch (error) {
                sendDc({
                    type: 'conversation.item.create',
                    item: {
                        type: 'function_call_output',
                        call_id: callId,
                        output: JSON.stringify({ success: false, message: error.message }),
                    },
                });
                if (!pendingToolResponse) {
                    pendingToolResponse = true;
                    sendDc({ type: 'response.create' });
                }
            }
        }

        async function playOpeningGreeting() {
            if (!sessionData?.play_greeting || greetingSent) {
                return;
            }
            greetingSent = true;
            greetingPlaying = true;
            markCallMetric('greeting_started');

            const instructions = sessionData.opening_greeting_instructions
                || `ابدئي المكالمة بهذه التحية مرة واحدة فقط، بصوت طبيعي ودافئ، دون أي كلام بعدها: «${sessionData.opening_greeting}»`;

            sendDc({
                type: 'response.create',
                response: { instructions },
            });

            await flushEvents([{ type: 'greeting_played' }]);
        }

        async function connectWebRTC(data) {
            sessionData = data;
            markCallMetric('connection_start');

            pc = new RTCPeerConnection();
            remoteAudio = document.createElement('audio');
            remoteAudio.autoplay = true;

            pc.ontrack = (event) => {
                remoteAudio.srcObject = event.streams[0];
                remoteAudio.play().catch(() => {});
            };

            pc.oniceconnectionstatechange = () => renderDiagnostics();
            pc.onconnectionstatechange = () => {
                renderDiagnostics();
                if (pc?.connectionState === 'failed') {
                    setState('reconnecting');
                }
            };

            const audioConstraints = {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
                channelCount: 1,
            };

            try {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints });
            } catch (error) {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }

            const track = localStream.getAudioTracks()[0];
            if (track && sessionData.diagnostics_enabled) {
                logDebug('microphone settings', track.getSettings?.() || {});
            }

            localStream.getTracks().forEach((streamTrack) => pc.addTrack(streamTrack, localStream));

            dc = pc.createDataChannel('oai-events');
            dc.addEventListener('open', () => {
                playOpeningGreeting();
            });
            dc.addEventListener('message', (message) => {
                try {
                    handleServerEvent(JSON.parse(message.data));
                } catch (e) {
                    // ignore malformed events
                }
            });

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            await waitForIceGatheringComplete(pc);

            const localSdp = pc.localDescription?.sdp || offer.sdp || '';
            if (!validateOfferSdp(localSdp)) {
                throw new Error('invalid_sdp');
            }

            if (sessionData.diagnostics_enabled) {
                console.info('Realtime SDP trace A_browser_before_fetch', {
                    length: localSdp.length,
                    startsWithV0: localSdp.startsWith('v=0'),
                });
            }

            const sdpResponse = await fetch(data.webrtc_url, {
                method: 'POST',
                body: JSON.stringify({ sdp: localSdp }),
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/sdp, application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
            });

            const responseBody = await sdpResponse.text();

            if (!sdpResponse.ok) {
                console.error('Realtime connect failed', sdpResponse.status, responseBody);
                let message = 'webrtc_failed';
                try {
                    const parsed = JSON.parse(responseBody);
                    message = parsed.message || message;
                    if (sessionData?.diagnostics_enabled) {
                        const parts = [parsed.message];
                        if (parsed.upstream_status) parts.push(`upstream=${parsed.upstream_status}`);
                        if (parsed.upstream_error) parts.push(parsed.upstream_error);
                        if (parsed.errors?.sdp) parts.push(parsed.errors.sdp.join(', '));
                        lastConnectError = parts.filter(Boolean).join(' | ');
                    } else {
                        lastConnectError = message;
                    }
                } catch (e) {
                    lastConnectError = responseBody.slice(0, 300) || `HTTP ${sdpResponse.status}`;
                }
                renderDiagnostics();
                throw new Error(lastConnectError || message);
            }

            if (!responseBody.trim().startsWith('v=0')) {
                lastConnectError = 'Invalid SDP answer from server';
                renderDiagnostics();
                throw new Error(lastConnectError);
            }

            lastConnectError = '';
            await pc.setRemoteDescription({ type: 'answer', sdp: responseBody });

            active = true;
            setState('listening');
        }

        async function start() {
            setState('connecting');
            markCallMetric('connection_start');

            const payload = await postJson(sessionUrl, csrf, {
                conversation_id: conversationId || null,
                reconnect: false,
            });

            if (payload.conversation_id) {
                options.onConversationId?.(payload.conversation_id);
            }

            await connectWebRTC(payload);
        }

        async function reconnect() {
            if (!active) return;
            setState('reconnecting');

            await teardown(false);

            const payload = await postJson(sessionUrl, csrf, {
                conversation_id: conversationId || sessionData?.conversation_id || null,
                reconnect: true,
            });

            await connectWebRTC(payload);
        }

        function setMuted(value) {
            muted = Boolean(value);
            localStream?.getAudioTracks().forEach((track) => {
                track.enabled = !muted;
            });
            setState(muted ? 'muted' : 'listening');
        }

        async function teardown(sendEnd = true) {
            active = false;
            assistantSpeaking = false;
            greetingPlaying = false;
            turnState = 'ended';
            flushMetrics();

            localStream?.getTracks().forEach((track) => track.stop());
            localStream = null;

            if (remoteAudio) {
                remoteAudio.pause();
                remoteAudio.srcObject = null;
                remoteAudio = null;
            }

            if (dc) {
                try { dc.close(); } catch (e) {}
                dc = null;
            }

            if (pc) {
                try { pc.close(); } catch (e) {}
                pc = null;
            }

            if (sendEnd && sessionData?.end_url) {
                try {
                    await postJson(sessionData.end_url, csrf, {});
                } catch (e) {
                    // ignore
                }
            }
        }

        async function end() {
            setState('ended');
            await teardown(true);
            sessionData = null;
        }

        startButton?.addEventListener('click', () => {
            start().catch((error) => onError?.(error.message || 'start_failed'));
        });

        endButton?.addEventListener('click', () => {
            end().catch(() => {});
        });

        muteButton?.addEventListener('click', () => {
            if (!active) return;
            setMuted(!muted);
        });

        window.addEventListener('beforeunload', () => {
            if (active) {
                teardown(true);
            }
        });

        return {
            start,
            end,
            reconnect,
            setMuted,
            isActive: () => active,
        };
    }

    return {
        mount,
        isSupported() {
            return Boolean(window.RTCPeerConnection && navigator.mediaDevices?.getUserMedia);
        },
    };
})();
