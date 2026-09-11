(function () {
    const i18n = window.__appDevVoiceI18n || {};

    function extForMime(mime) {
        if (!mime) return 'webm';
        if (mime.includes('mp4') || mime.includes('m4a') || mime.includes('aac')) return 'm4a';
        if (mime.includes('ogg')) return 'ogg';
        if (mime.includes('mpeg') || mime.includes('mp3')) return 'mp3';
        if (mime.includes('wav')) return 'wav';
        return 'webm';
    }

    function pickMime() {
        if (!window.MediaRecorder) return '';
        const candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/mp4',
            'audio/ogg;codecs=opus',
            'audio/ogg',
        ];
        for (const type of candidates) {
            if (MediaRecorder.isTypeSupported(type)) return type;
        }
        return '';
    }

    function appendFiles(input, files) {
        const dt = new DataTransfer();
        Array.from(input.files || []).forEach((f) => dt.items.add(f));
        files.forEach((f) => dt.items.add(f));
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function formatSec(total) {
        total = Math.max(0, Math.floor(total || 0));
        const m = Math.floor(total / 60);
        const s = String(total % 60).padStart(2, '0');
        return `${m}:${s}`;
    }

    function bindScrubber(root, audio) {
        const playBtn = root.querySelector('[data-voice-play]');
        const seek = root.querySelector('[data-voice-seek]');
        const cur = root.querySelector('[data-voice-current]');
        const dur = root.querySelector('[data-voice-duration]');
        let seeking = false;

        function sync() {
            if (!seeking && seek && Number.isFinite(audio.duration) && audio.duration > 0) {
                seek.max = String(Math.floor(audio.duration * 100));
                seek.value = String(Math.floor(audio.currentTime * 100));
            }
            if (cur) cur.textContent = formatSec(audio.currentTime);
            if (dur && Number.isFinite(audio.duration)) dur.textContent = formatSec(audio.duration);
            root.classList.toggle('is-playing', !audio.paused);
        }

        playBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            if (audio.paused) {
                document.querySelectorAll('audio[data-voice-audio]').forEach((other) => {
                    if (other !== audio) other.pause();
                });
                audio.play().catch(() => {});
            } else {
                audio.pause();
            }
        });

        function seekTo(value) {
            const next = Number(value) / 100;
            if (!Number.isFinite(next)) return;
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
                audio.currentTime = Math.min(Math.max(0, next), audio.duration);
            } else {
                audio.currentTime = Math.max(0, next);
            }
            if (cur) cur.textContent = formatSec(audio.currentTime);
        }

        seek?.addEventListener('pointerdown', () => { seeking = true; });
        seek?.addEventListener('pointerup', () => {
            if (seek) seekTo(seek.value);
            seeking = false;
            sync();
        });
        seek?.addEventListener('change', () => {
            seekTo(seek.value);
            seeking = false;
            sync();
        });
        seek?.addEventListener('input', () => {
            const next = Number(seek.value) / 100;
            if (cur) cur.textContent = formatSec(next);
            if (seeking) seekTo(seek.value);
        });

        audio.addEventListener('timeupdate', sync);
        audio.addEventListener('loadedmetadata', sync);
        audio.addEventListener('play', sync);
        audio.addEventListener('pause', sync);
        audio.addEventListener('ended', () => {
            audio.currentTime = 0;
            sync();
        });
        sync();
    }

    function mountPlayer(el) {
        if (!el || el.dataset.voicePlayerReady === '1') return;
        const audio = el.querySelector('audio[data-voice-audio]');
        if (!audio) return;
        el.dataset.voicePlayerReady = '1';
        bindScrubber(el, audio);
    }

    function createClipUi(url) {
        const item = document.createElement('div');
        item.className = 'wa-voice';
        item.innerHTML = `
            <button type="button" class="wa-voice__play" data-voice-play aria-label="Play">
                <svg class="kaman-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l12-7z"/></svg>
            </button>
            <div class="wa-voice__wave">
                <input type="range" class="wa-voice__seek" data-voice-seek min="0" max="100" value="0" step="1" aria-label="Seek">
            </div>
            <span class="wa-voice__time"><span data-voice-current>0:00</span>/<span data-voice-duration>0:00</span></span>
            <button type="button" class="wa-voice__remove" data-voice-remove aria-label="${i18n.remove || 'Remove'}">×</button>
            <audio data-voice-audio preload="metadata" src="${url}"></audio>
        `;
        return item;
    }

    function mount(root) {
        if (!root || root.dataset.voiceReady === '1') return;
        root.dataset.voiceReady = '1';

        const inputName = root.getAttribute('data-voice-input') || 'voices[]';
        const form = root.closest('form');
        let input = form?.querySelector(`input[type="file"][name="${inputName}"]`);
        if (!input && form) {
            input = document.createElement('input');
            input.type = 'file';
            input.name = inputName;
            input.multiple = true;
            input.accept = 'audio/*,.webm,.ogg,.mp3,.m4a,.wav,.aac';
            input.hidden = true;
            input.setAttribute('data-voice-file-input', '');
            form.appendChild(input);
        }
        if (!input) return;

        const toggleBtn = root.querySelector('[data-voice-toggle]');
        const timerEl = root.querySelector('[data-voice-timer]');
        const listEl = root.querySelector('[data-voice-list]');
        const statusEl = root.querySelector('[data-voice-status]');

        let recorder = null;
        let chunks = [];
        let stream = null;
        let startedAt = 0;
        let tick = null;
        let recording = false;

        function setRecording(on) {
            recording = on;
            root.classList.toggle('is-recording', on);
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                toggleBtn.title = on
                    ? (i18n.stop || 'Stop recording')
                    : (i18n.record || toggleBtn.getAttribute('data-record-label') || 'Record voice');
                toggleBtn.setAttribute(
                    'aria-label',
                    on ? (i18n.stop || 'Stop recording') : (i18n.record || 'Record voice'),
                );
            }
            if (timerEl) {
                timerEl.hidden = !on;
                if (!on) timerEl.textContent = '0:00';
            }
        }

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }

        function addClip(file, url) {
            const item = createClipUi(url);
            const audio = item.querySelector('audio');
            item.querySelector('[data-voice-remove]')?.addEventListener('click', () => {
                const dt = new DataTransfer();
                Array.from(input.files || []).forEach((f) => {
                    if (f !== file) dt.items.add(f);
                });
                input.files = dt.files;
                URL.revokeObjectURL(url);
                item.remove();
            });
            listEl?.appendChild(item);
            if (audio) bindScrubber(item, audio);
        }

        async function start() {
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                setStatus(i18n.unsupported || 'Voice recording is not supported in this browser.');
                return;
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mime = pickMime();
                chunks = [];
                recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
                recorder.addEventListener('dataavailable', (event) => {
                    if (event.data && event.data.size > 0) chunks.push(event.data);
                });
                recorder.addEventListener('stop', () => {
                    const type = recorder?.mimeType || mime || 'audio/webm';
                    const blob = new Blob(chunks, { type });
                    const file = new File([blob], `voice-${Date.now()}.${extForMime(type)}`, { type });
                    appendFiles(input, [file]);
                    addClip(file, URL.createObjectURL(blob));
                    setStatus('');
                    stream?.getTracks().forEach((t) => t.stop());
                    stream = null;
                    recorder = null;
                });
                recorder.start();
                startedAt = Date.now();
                if (timerEl) timerEl.textContent = '0:00';
                tick = window.setInterval(() => {
                    if (timerEl) timerEl.textContent = formatSec((Date.now() - startedAt) / 1000);
                }, 250);
                setRecording(true);
            } catch (error) {
                setStatus(i18n.denied || 'Microphone permission denied.');
                stream?.getTracks().forEach((t) => t.stop());
                stream = null;
                setRecording(false);
            }
        }

        function stop() {
            if (tick) {
                window.clearInterval(tick);
                tick = null;
            }
            setRecording(false);
            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
            } else {
                stream?.getTracks().forEach((t) => t.stop());
                stream = null;
            }
        }

        if (toggleBtn && !toggleBtn.dataset.recordLabel) {
            toggleBtn.dataset.recordLabel = toggleBtn.getAttribute('aria-label') || 'Record voice';
        }

        toggleBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            if (recording) {
                stop();
            } else {
                start();
            }
        });
        setRecording(false);
    }

    function boot(scope) {
        const root = scope || document;
        root.querySelectorAll('[data-voice-recorder]').forEach(mount);
        root.querySelectorAll('[data-wa-voice-player]').forEach(mountPlayer);
    }

    document.addEventListener('DOMContentLoaded', () => boot(document));
    document.addEventListener('app-dev-modal:loaded', (event) => {
        boot(event.target instanceof Element ? event.target : document);
    });

    const modalBody = document.getElementById('app-dev-modal-body');
    if (modalBody && window.MutationObserver) {
        const observer = new MutationObserver(() => boot(modalBody));
        observer.observe(modalBody, { childList: true, subtree: false });
    }

    window.AppDevVoice = { boot };
})();
