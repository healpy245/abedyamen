/**
 * ChatGPT-style composer: text, voice dictation, full voice call overlay.
 */
window.ChatComposer = (function () {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

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

    function createDictationRecognizer(locale, onInterim, onFinal, onError) {
        if (!SpeechRecognition) {
            return null;
        }

        const recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = true;
        recognition.lang = locale;

        recognition.onresult = (event) => {
            let interim = '';
            let finalText = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                const piece = event.results[i][0]?.transcript || '';
                if (event.results[i].isFinal) {
                    finalText += piece;
                } else {
                    interim += piece;
                }
            }
            onInterim((finalText || interim).trim());
            if (finalText.trim()) {
                onFinal(finalText.trim());
            }
        };

        recognition.onerror = (event) => onError(event.error || 'recognition_error');
        return recognition;
    }

    function mount(config) {
        const {
            form,
            textarea,
            messagesEl,
            conversationIdInput,
            errorEl,
            sendButton,
            sendLabel,
            dictateButton,
            voiceCallButton,
            attachButton,
            imageInput,
            imagePreview,
            imagePreviewThumb,
            imagePreviewName,
            imagePreviewClear,
            overlay,
            overlayClose,
            overlayMic,
            overlayStatus,
            overlayEnd,
            overlayStart,
            overlayPreCall,
            overlayInCall,
            overlayPulse,
            diagnosticsEl,
            csrf,
            sendUrl,
            uploadImageUrl,
            realtimeSessionUrl,
            realtimeEnabled,
            ttsUrl,
            speechLocale,
            phoneStates,
            translations,
        } = config;

        let dictation = null;
        let dictating = false;
        let phoneController = null;
        let overlayAnchor = null;
        let selectedImageFile = null;
        let selectedImageObjectUrl = null;

        function mountVoiceOverlay() {
            if (!overlay || overlay.parentElement === document.body) {
                return;
            }

            overlayAnchor = document.createComment('aiChatbotVoiceOverlay');
            overlay.parentElement.insertBefore(overlayAnchor, overlay);
            document.body.appendChild(overlay);
        }

        function showError(message) {
            if (!errorEl) return;
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }

        function clearError() {
            errorEl?.classList.add('hidden');
        }

        function scrollToBottom() {
            if (!messagesEl) return;
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function appendMessages(data) {
            if (data.user_message_html) {
                messagesEl.insertAdjacentHTML('beforeend', data.user_message_html);
            }
            if (data.assistant_message_html) {
                messagesEl.insertAdjacentHTML('beforeend', data.assistant_message_html);
            }
            if (data.conversation?.id) {
                conversationIdInput.value = data.conversation.id;
            }
            scrollToBottom();
        }

        async function sendTextMessage(text) {
            const payload = {
                message: text,
                conversation_id: conversationIdInput.value || null,
            };

            const data = await postJson(sendUrl, csrf, payload);
            appendMessages(data);
            return data;
        }

        function clearSelectedImage() {
            selectedImageFile = null;
            if (selectedImageObjectUrl) {
                URL.revokeObjectURL(selectedImageObjectUrl);
                selectedImageObjectUrl = null;
            }
            if (imageInput) {
                imageInput.value = '';
            }
            imagePreview?.classList.add('hidden');
            imagePreview?.classList.remove('flex');
            if (imagePreviewThumb) {
                imagePreviewThumb.src = '';
            }
            if (imagePreviewName) {
                imagePreviewName.textContent = '';
            }
        }

        function setSelectedImage(file) {
            if (!file) {
                clearSelectedImage();
                return;
            }

            const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if (!allowed.includes(file.type)) {
                showError(translations.attach_invalid_type || translations.send_error);
                clearSelectedImage();
                return;
            }

            selectedImageFile = file;
            if (selectedImageObjectUrl) {
                URL.revokeObjectURL(selectedImageObjectUrl);
            }

            if (file.type === 'application/pdf') {
                selectedImageObjectUrl = null;
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

            if (imagePreviewName) {
                imagePreviewName.textContent = file.name;
            }
            imagePreview?.classList.remove('hidden');
            imagePreview?.classList.add('flex');
        }

        async function sendImageMessage(file, caption) {
            const body = new FormData();
            body.append('image', file);
            if (caption) {
                body.append('caption', caption);
            }
            if (conversationIdInput.value) {
                body.append('conversation_id', conversationIdInput.value);
            }

            const response = await fetch(uploadImageUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body,
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || translations.attach_error || translations.send_error);
            }

            appendMessages(data);
            return data;
        }

        function setLoading(isLoading) {
            sendButton.disabled = isLoading;
            if (attachButton) {
                attachButton.disabled = isLoading;
            }
            sendLabel.textContent = isLoading ? translations.thinking : translations.send;
        }

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const text = textarea.value.trim();
            if (!selectedImageFile && !text) {
                return;
            }

            clearError();
            setLoading(true);
            try {
                if (selectedImageFile) {
                    await sendImageMessage(selectedImageFile, text);
                    clearSelectedImage();
                    textarea.value = '';
                } else {
                    await sendTextMessage(text);
                    textarea.value = '';
                }
            } catch (error) {
                showError(error.message || translations.send_error);
            } finally {
                setLoading(false);
                textarea.focus();
            }
        });

        attachButton?.addEventListener('click', () => {
            imageInput?.click();
        });

        imageInput?.addEventListener('change', () => {
            const file = imageInput.files?.[0] || null;
            clearError();
            setSelectedImage(file);
        });

        imagePreviewClear?.addEventListener('click', () => {
            clearSelectedImage();
        });

        dictateButton?.addEventListener('click', () => {
            if (!SpeechRecognition) {
                showError(translations.dictation_unsupported);
                return;
            }

            if (dictating) {
                dictation?.stop();
                dictating = false;
                dictateButton.classList.remove('ring-2', 'ring-[#f47a2e]/40');
                return;
            }

            dictation = createDictationRecognizer(
                speechLocale,
                (text) => {
                    if (text) textarea.value = text;
                },
                async (text) => {
                    dictating = false;
                    dictateButton.classList.remove('ring-2', 'ring-[#f47a2e]/40');
                    textarea.value = text;
                    clearError();
                    setLoading(true);
                    try {
                        await sendTextMessage(text);
                        textarea.value = '';
                    } catch (error) {
                        showError(error.message || translations.send_error);
                    } finally {
                        setLoading(false);
                    }
                },
                (code) => {
                    if (code !== 'no-speech') {
                        showError(translations.dictation_error);
                    }
                    dictating = false;
                    dictateButton.classList.remove('ring-2', 'ring-[#f47a2e]/40');
                },
            );

            if (!dictation) return;

            dictating = true;
            dictateButton.classList.add('ring-2', 'ring-[#f47a2e]/40');
            try {
                dictation.start();
            } catch (error) {
                dictating = false;
                dictateButton.classList.remove('ring-2', 'ring-[#f47a2e]/40');
                showError(translations.dictation_error);
            }
        });

        function appendVoiceMessages(messages) {
            if (!messagesEl || !messages?.length) {
                return;
            }

            messages.forEach((message) => {
                if (message?.html) {
                    messagesEl.insertAdjacentHTML('beforeend', message.html);
                }
            });
            scrollToBottom();
        }

        async function openVoiceOverlay() {
            if (!window.RealtimeCall?.isSupported()) {
                showError(translations.voice_unsupported);
                return;
            }

            clearError();
            mountVoiceOverlay();
            overlay?.classList.remove('hidden');
            messagesEl?.classList.add('pb-44');
            overlayPreCall?.classList.remove('hidden');
            overlayInCall?.classList.add('hidden');

            if (phoneController) {
                await phoneController.end().catch(() => {});
                phoneController = null;
            }

            phoneController = window.RealtimeCall.mount({
                startButton: overlayStart,
                endButton: overlayEnd,
                muteButton: overlayMic,
                statusEl: overlayStatus,
                diagnosticsEl,
                sessionUrl: realtimeSessionUrl,
                csrf,
                conversationId: conversationIdInput.value ? Number(conversationIdInput.value) : null,
                phoneStates,
                onConversationId: (id) => {
                    conversationIdInput.value = id;
                },
                onMessageHtml: (message) => {
                    appendVoiceMessages([message]);
                },
                onError: () => showError(translations.voice_error),
                onStateChange: (state) => {
                    if (state === 'listening' || state === 'user_speaking' || state === 'assistant_speaking') {
                        overlayPreCall?.classList.add('hidden');
                        overlayInCall?.classList.remove('hidden');
                    }
                    overlayPulse?.classList.toggle('hidden', state !== 'listening' && state !== 'user_speaking');
                    overlayMic?.classList.toggle('ring-4', state === 'listening');
                    overlayMic?.classList.toggle('ring-[#f47a2e]/25', state === 'listening');
                },
            });
        }

        async function closeVoiceOverlay() {
            if (phoneController) {
                await phoneController.end().catch(() => {});
                phoneController = null;
            }

            overlay?.classList.add('hidden');
            messagesEl?.classList.remove('pb-44');
            overlayPreCall?.classList.remove('hidden');
            overlayInCall?.classList.add('hidden');
        }

        voiceCallButton?.addEventListener('click', openVoiceOverlay);
        overlayClose?.addEventListener('click', () => closeVoiceOverlay());
        overlayEnd?.addEventListener('click', () => closeVoiceOverlay());

        return {
            closeVoiceOverlay,
        };
    }

    return { mount, isDictationSupported: () => Boolean(SpeechRecognition) };
})();
