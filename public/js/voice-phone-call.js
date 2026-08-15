/**
 * Hands-free phone-call mode: continuous listen → respond → speak → listen.
 */
window.VoicePhoneCall = (function () {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    let activeAudio = null;

    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function stopActiveAudio() {
        if (activeAudio) {
            activeAudio.pause();
            activeAudio.src = '';
            activeAudio = null;
        }
    }

    function createRecognizer(locale, continuous = true) {
        if (!SpeechRecognition) {
            return null;
        }

        const recognition = new SpeechRecognition();
        recognition.continuous = continuous;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;
        recognition.lang = locale;

        return recognition;
    }

    function pickVoice(voices, profile, locale) {
        const prefer = profile?.prefer || [];
        const langPrefix = (locale || 'en').split('-')[0].toLowerCase();
        const localized = voices.filter((voice) =>
            (voice.lang || '').toLowerCase().startsWith(langPrefix)
        );
        const pool = localized.length > 0 ? localized : voices;

        for (const keyword of prefer) {
            const match = pool.find((voice) =>
                (voice.name || '').toLowerCase().includes(keyword.toLowerCase())
            );
            if (match) {
                return match;
            }
        }

        return pool[0] || voices[0] || null;
    }

    function speakWithBrowser(text, profile, locale) {
        return new Promise((resolve, reject) => {
            if (!window.speechSynthesis) {
                reject(new Error('speech_synthesis_unavailable'));
                return;
            }

            const utterance = new SpeechSynthesisUtterance(text);
            const voices = window.speechSynthesis.getVoices();
            const voice = pickVoice(voices, profile, locale);

            if (voice) {
                utterance.voice = voice;
                utterance.lang = voice.lang;
            } else {
                utterance.lang = locale;
            }

            utterance.pitch = profile?.pitch ?? 1;
            utterance.rate = profile?.rate ?? 1;
            utterance.onend = () => resolve();
            utterance.onerror = (event) => reject(event.error || new Error('tts_failed'));

            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utterance);
        });
    }

    async function speakWithServer(text, options) {
        const { ttsUrl, csrf, voiceProfile, locale } = options;

        const response = await fetch(ttsUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'audio/mpeg, application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ text, voice_profile: voiceProfile, locale }),
        });

        const contentType = response.headers.get('content-type') || '';

        if (contentType.includes('application/json')) {
            const data = await response.json().catch(() => ({}));
            if (data.use_browser) {
                return speakWithBrowser(text, options.profile, locale);
            }
            throw new Error(data.message || 'tts_failed');
        }

        if (!response.ok) {
            throw new Error('tts_failed');
        }

        const blob = await response.blob();
        if (!blob.size) {
            throw new Error('tts_empty');
        }

        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            activeAudio = audio;

            audio.onended = () => {
                URL.revokeObjectURL(url);
                if (activeAudio === audio) {
                    activeAudio = null;
                }
                resolve();
            };

            audio.onerror = () => {
                URL.revokeObjectURL(url);
                if (activeAudio === audio) {
                    activeAudio = null;
                }
                reject(new Error('tts_playback_failed'));
            };

            audio.play().catch(reject);
        });
    }

    async function speak(text, profile, locale, serverOptions = null) {
        const trimmed = (text || '').trim();
        if (!trimmed) {
            return;
        }

        stopActiveAudio();
        window.speechSynthesis?.cancel();

        if (serverOptions?.ttsUrl && serverOptions?.csrf) {
            return speakWithServer(trimmed, { ...serverOptions, profile, locale });
        }

        return speakWithBrowser(trimmed, profile, locale);
    }

    function listenOnce(locale, silenceMs, onInterim) {
        return new Promise((resolve, reject) => {
            const recognition = createRecognizer(locale, true);
            if (!recognition) {
                reject(new Error('speech_recognition_unavailable'));
                return;
            }

            let finalTranscript = '';
            let interimTranscript = '';
            let silenceTimer = null;
            let settled = false;

            const finish = (text) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(silenceTimer);
                try {
                    recognition.stop();
                } catch (error) {
                    // ignore
                }
                resolve(text);
            };

            const scheduleSilenceFlush = () => {
                clearTimeout(silenceTimer);
                silenceTimer = setTimeout(() => {
                    const text = (finalTranscript + ' ' + interimTranscript).trim();
                    if (text) {
                        finish(text);
                    }
                }, silenceMs);
            };

            recognition.onstart = () => {
                finalTranscript = '';
                interimTranscript = '';
            };

            recognition.onresult = (event) => {
                interimTranscript = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const piece = event.results[i][0]?.transcript || '';
                    if (event.results[i].isFinal) {
                        finalTranscript += piece;
                    } else {
                        interimTranscript += piece;
                    }
                }

                const live = (finalTranscript + ' ' + interimTranscript).trim();
                onInterim?.(live);
                scheduleSilenceFlush();
            };

            recognition.onerror = (event) => {
                if (event.error === 'no-speech' || event.error === 'aborted') {
                    finish('');
                    return;
                }
                if (!settled) {
                    settled = true;
                    clearTimeout(silenceTimer);
                    reject(new Error(event.error || 'recognition_error'));
                }
            };

            recognition.onend = () => {
                if (!settled) {
                    finish((finalTranscript + ' ' + interimTranscript).trim());
                }
            };

            try {
                recognition.start();
            } catch (error) {
                reject(new Error('recognition_start_failed'));
            }
        });
    }

    function mount(options) {
        const {
            micButton,
            statusEl,
            liveTranscriptEl,
            locale,
            profile,
            voiceProfile,
            ttsUrl,
            csrf,
            silenceMs = 500,
            autoStart = true,
            phoneStates = {},
            onTranscript,
            onError,
            onStateChange,
        } = options;

        const serverTtsOptions = ttsUrl && csrf
            ? { ttsUrl, csrf, voiceProfile: voiceProfile || 'woman' }
            : null;

        if (!SpeechRecognition) {
            onError?.('speech_recognition_unavailable');
            return { destroy() {}, stop() {}, setMuted() {} };
        }

        let destroyed = false;
        let muted = false;
        let busy = false;
        let sessionPromise = null;

        function setState(state, detail = '') {
            if (statusEl && phoneStates[state]) {
                statusEl.textContent = phoneStates[state];
            }
            onStateChange?.(state, detail);
        }

        function updateLiveTranscript(text) {
            if (!liveTranscriptEl) {
                return;
            }
            liveTranscriptEl.textContent = text;
            liveTranscriptEl.classList.toggle('hidden', !text);
        }

        function stop() {
            destroyed = true;
            busy = false;
            stopActiveAudio();
            window.speechSynthesis?.cancel();
            updateLiveTranscript('');
            setState('idle');
        }

        function destroy() {
            stop();
        }

        function setMuted(value) {
            muted = Boolean(value);
            micButton?.classList.toggle('opacity-40', muted);
            micButton?.classList.toggle('ring-4', !muted && !busy);
            micButton?.classList.toggle('ring-[#f47a2e]/25', !muted && !busy);

            if (muted) {
                setState('muted');
            }

            if (!muted && !busy && !destroyed && autoStart) {
                ensureSession();
            }
        }

        async function processTurn(transcript) {
            busy = true;
            setState('processing', transcript);

            try {
                const assistantText = await onTranscript(transcript);
                if (assistantText && !destroyed && !muted) {
                    setState('speaking');
                    await speak(assistantText, profile, locale, {
                        ...serverTtsOptions,
                        voiceProfile: voiceProfile || serverTtsOptions?.voiceProfile || 'woman',
                    });
                }
            } catch (error) {
                onError?.(error?.message || 'send_failed');
            } finally {
                busy = false;
                updateLiveTranscript('');
            }
        }

        async function runSession() {
            while (!destroyed) {
                if (muted || busy) {
                    await sleep(150);
                    continue;
                }

                setState('listening');
                micButton?.classList.add('ring-4', 'ring-[#f47a2e]/25');

                let transcript = '';
                try {
                    transcript = await listenOnce(locale, silenceMs, updateLiveTranscript);
                } catch (error) {
                    if (!destroyed && error.message !== 'aborted') {
                        onError?.(error.message || 'recognition_error');
                    }
                    await sleep(400);
                    continue;
                } finally {
                    micButton?.classList.remove('ring-4', 'ring-[#f47a2e]/25');
                }

                if (destroyed || muted) {
                    break;
                }

                if (transcript) {
                    await processTurn(transcript);
                } else {
                    await sleep(120);
                }
            }
        }

        function ensureSession() {
            if (destroyed || sessionPromise) {
                return;
            }
            sessionPromise = runSession().finally(() => {
                sessionPromise = null;
            });
        }

        micButton?.addEventListener('click', () => {
            setMuted(!muted);
        });

        if (window.speechSynthesis) {
            window.speechSynthesis.onvoiceschanged = () => {
                window.speechSynthesis.getVoices();
            };
        }

        if (autoStart) {
            ensureSession();
        }

        return { destroy, stop, setMuted, ensureSession };
    }

    return {
        isSupported() {
            return Boolean(SpeechRecognition);
        },
        mount,
        speak,
    };
})();
