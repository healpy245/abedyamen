(function () {
    const modal = document.getElementById('app-dev-modal');
    const cfg = window.__appDevUpload;
    if (!modal || !cfg) {
        return;
    }

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const i18n = cfg.i18n || {};
    let busy = false;
    let warnUnload = false;

    const ui = {
        label: () => modal.querySelector('[data-app-dev-upload-label]'),
        progress: () => modal.querySelector('[data-app-dev-upload-progress]'),
        bar: () => modal.querySelector('[data-app-dev-upload-bar]'),
        fill: () => modal.querySelector('[data-app-dev-upload-fill]'),
        percent: () => modal.querySelector('[data-app-dev-upload-percent]'),
        eta: () => modal.querySelector('[data-app-dev-upload-eta]'),
        hint: () => modal.querySelector('[data-app-dev-upload-hint]'),
    };

    function setProgress(percent, labelText, hintText, etaText) {
        const p = Math.max(0, Math.min(100, Math.round(percent || 0)));
        const progress = ui.progress();
        const fill = ui.fill();
        const bar = ui.bar();
        const percentEl = ui.percent();
        const etaEl = ui.eta();
        const hint = ui.hint();
        const label = ui.label();

        if (progress) {
            progress.hidden = false;
        }
        if (fill) {
            fill.style.width = p + '%';
        }
        if (bar) {
            bar.setAttribute('aria-valuenow', String(p));
        }
        if (percentEl) {
            percentEl.textContent = p + '%';
        }
        if (etaEl) {
            etaEl.textContent = etaText || '';
        }
        if (hint) {
            hint.textContent = hintText || '';
        }
        if (label && labelText) {
            label.textContent = labelText;
        }
    }

    function formatEta(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return '';
        }
        if (seconds < 5) {
            return i18n.almost || '';
        }
        const mins = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);
        const template = i18n.eta || ':time left';
        const time = mins > 0 ? (mins + 'm ' + secs + 's') : (secs + 's');
        return template.replace(':time', time);
    }

    function chunkUrl(uuid) {
        return String(cfg.chunkUrlTemplate || '').replace('__UUID__', encodeURIComponent(uuid));
    }

    function collectFiles(form) {
        const attachments = [];
        const voices = [];

        form.querySelectorAll('input[type="file"]').forEach((input) => {
            const list = Array.from(input.files || []);
            if (!list.length) {
                return;
            }
            const name = input.getAttribute('name') || '';
            const bucket = name.indexOf('voices') !== -1 ? voices : attachments;
            list.forEach((file) => bucket.push(file));
        });

        return { attachments, voices };
    }

    function postFormJson(url, body) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.timeout = 0;
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onload = function () {
                let data = {};
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(data);
                } else {
                    reject(new Error(data.message || i18n.failed || 'Upload failed'));
                }
            };
            xhr.onerror = function () {
                reject(new Error(i18n.failed || 'Upload failed'));
            };
            xhr.send(JSON.stringify(body));
        });
    }

    function uploadChunk(uuid, index, blob) {
        return new Promise((resolve, reject) => {
            const form = new FormData();
            form.append('index', String(index));
            form.append('chunk', blob, 'chunk.bin');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', chunkUrl(uuid), true);
            xhr.timeout = 0;
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function () {
                let data = {};
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(data);
                } else {
                    reject(new Error(data.message || i18n.failed || 'Upload failed'));
                }
            };
            xhr.onerror = function () {
                reject(new Error(i18n.failed || 'Upload failed'));
            };
            xhr.send(form);
        });
    }

    async function uploadFile(file, kind, onBytes) {
        const init = await postFormJson(cfg.initUrl, {
            name: file.name,
            size: file.size,
            mime: file.type || null,
            kind: kind,
        });

        const chunkSize = Number(init.chunk_size) || (1024 * 1024);
        const totalChunks = Number(init.total_chunks) || Math.ceil(file.size / chunkSize);
        let uploaded = 0;

        for (let index = 0; index < totalChunks; index++) {
            const start = index * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const blob = file.slice(start, end);
            let attempt = 0;
            let ok = false;
            while (!ok) {
                try {
                    await uploadChunk(init.uuid, index, blob);
                    ok = true;
                } catch (err) {
                    attempt += 1;
                    if (attempt >= 5) {
                        throw err;
                    }
                    await new Promise((r) => setTimeout(r, Math.min(8000, 400 * attempt * attempt)));
                }
            }
            uploaded = end;
            onBytes(uploaded, file.size);
        }

        return init.uuid;
    }

    function submitTicketForm(form, stagedAttachments, stagedVoices) {
        const data = new FormData(form);
        data.delete('attachments[]');
        data.delete('attachments');
        data.delete('voices[]');
        data.delete('voices');

        stagedAttachments.forEach((uuid) => data.append('staged_attachments[]', uuid));
        stagedVoices.forEach((uuid) => data.append('staged_voices[]', uuid));

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(form.method || 'POST', form.action, true);
            xhr.timeout = 0;
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'text/html,application/xhtml+xml');
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 400) {
                    const redirect = xhr.responseURL || form.action;
                    resolve(redirect);
                    return;
                }
                reject(new Error(i18n.failed || 'Submit failed'));
            };
            xhr.onerror = function () {
                reject(new Error(i18n.failed || 'Submit failed'));
            };
            // keepalive-like: ignore abort after send for small create payload
            xhr.send(data);
        });
    }

    async function handleSubmit(form) {
        const files = collectFiles(form);
        const all = files.attachments.concat(files.voices);
        if (!all.length) {
            return false;
        }

        busy = true;
        warnUnload = true;
        modal.setAttribute('data-uploading', '1');
        modal.classList.add('is-submitting');
        setProgress(0, i18n.uploading || 'Uploading…', i18n.keepOpen || '', '');

        const totalBytes = all.reduce((sum, file) => sum + (file.size || 0), 0) || 1;
        let doneBytes = 0;
        const started = Date.now();
        const stagedAttachments = [];
        const stagedVoices = [];

        const report = (fileDone, fileTotal) => {
            const current = doneBytes + fileDone;
            const percent = (current / totalBytes) * 100;
            const elapsed = (Date.now() - started) / 1000;
            const rate = current > 0 ? current / Math.max(elapsed, 0.2) : 0;
            const remaining = rate > 0 ? (totalBytes - current) / rate : NaN;
            setProgress(
                percent,
                i18n.uploading || 'Uploading…',
                i18n.keepOpen || '',
                formatEta(remaining),
            );
        };

        try {
            for (const file of files.attachments) {
                const uuid = await uploadFile(file, 'attachment', (loaded, total) => report(loaded, total));
                stagedAttachments.push(uuid);
                doneBytes += file.size || 0;
                report(0, 0);
            }
            for (const file of files.voices) {
                const uuid = await uploadFile(file, 'voice', (loaded, total) => report(loaded, total));
                stagedVoices.push(uuid);
                doneBytes += file.size || 0;
                report(0, 0);
            }

            warnUnload = false;
            setProgress(100, i18n.creating || 'Creating ticket…', i18n.canLeave || '', i18n.almost || '');

            const redirect = await submitTicketForm(form, stagedAttachments, stagedVoices);
            modal.removeAttribute('data-uploading');
            window.location.href = redirect;
            return true;
        } catch (error) {
            warnUnload = false;
            busy = false;
            modal.removeAttribute('data-uploading');
            modal.classList.remove('is-submitting');
            const progress = ui.progress();
            if (progress) {
                progress.hidden = true;
            }
            window.alert(error.message || i18n.failed || 'Upload failed');
            const button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = false;
            }
            return true;
        }
    }

    window.addEventListener('beforeunload', (event) => {
        if (!warnUnload) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    modal.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form || !form.matches || !form.matches('form')) {
            return;
        }
        if (busy) {
            event.preventDefault();
            return;
        }

        const files = collectFiles(form);
        if (!files.attachments.length && !files.voices.length) {
            return;
        }

        // Large media (or any attachment) → chunked upload with progress.
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        if (button) {
            button.disabled = true;
        }
        handleSubmit(form);
    }, true);
})();
