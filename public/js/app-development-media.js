(function () {
    function rootEl() {
        return document.getElementById('app-dev-media-lightbox');
    }

    function close() {
        const root = rootEl();
        if (!root) {
            return;
        }

        const bodyEl = root.querySelector('[data-app-dev-media-body]');
        const titleEl = root.querySelector('[data-app-dev-media-title]');
        const media = bodyEl?.querySelector('video, audio');
        if (media) {
            try {
                media.pause();
                media.removeAttribute('src');
                media.load();
            } catch (e) {}
        }
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        if (titleEl) {
            titleEl.textContent = '';
        }
        root.hidden = true;
        root.classList.remove('is-open');
        root.style.display = 'none';
        document.body.classList.remove('app-dev-media-lock');
    }

    function formatBytes(n) {
        if (!Number.isFinite(n) || n <= 0) {
            return '';
        }
        if (n < 1024 * 1024) {
            return (n / 1024).toFixed(0) + ' KB';
        }
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function wireVideoProgress(video, statusEl) {
        const update = function () {
            if (!statusEl) {
                return;
            }
            try {
                if (video.readyState >= 3 && !video.paused) {
                    statusEl.hidden = true;
                    return;
                }
                const buffered = video.buffered;
                if (buffered && buffered.length && video.duration) {
                    const end = buffered.end(buffered.length - 1);
                    const pct = Math.max(0, Math.min(100, Math.round((end / video.duration) * 100)));
                    statusEl.hidden = false;
                    statusEl.textContent = pct + '%';
                    if (pct >= 8 || video.readyState >= 2) {
                        // Enough to start — keep tiny label until playback begins.
                        if (video.readyState >= 3) {
                            statusEl.hidden = true;
                        }
                    }
                    return;
                }
                statusEl.hidden = false;
                statusEl.textContent = '…';
            } catch (e) {}
        };

        ['loadstart', 'progress', 'loadeddata', 'canplay', 'canplaythrough', 'playing', 'waiting', 'error'].forEach(function (evt) {
            video.addEventListener(evt, update);
        });

        video.addEventListener('error', function () {
            if (statusEl) {
                statusEl.hidden = false;
                statusEl.textContent = 'Error';
            }
        });

        update();
    }

    function open(trigger) {
        const root = rootEl();
        if (!root || !trigger) {
            return;
        }

        const titleEl = root.querySelector('[data-app-dev-media-title]');
        const bodyEl = root.querySelector('[data-app-dev-media-body]');
        if (!bodyEl) {
            return;
        }

        const type = trigger.getAttribute('data-media-type') || 'image';
        const url = trigger.getAttribute('data-media-url') || '';
        const name = trigger.getAttribute('data-media-name') || '';
        if (!url) {
            return;
        }

        if (titleEl) {
            titleEl.textContent = name;
        }

        bodyEl.innerHTML = '';

        const status = document.createElement('div');
        status.className = 'app-dev-media__status';
        status.textContent = '…';
        bodyEl.appendChild(status);

        // Cache-bust only if needed; prefer same URL so browser can reuse
        // the bytes already fetched for the thumbnail/preview.
        const mediaUrl = url;

        if (type === 'video') {
            const video = document.createElement('video');
            video.className = 'app-dev-media__video';
            video.controls = true;
            video.playsInline = true;
            video.preload = 'auto';
            video.setAttribute('controls', '');
            video.setAttribute('playsinline', '');
            video.setAttribute('preload', 'auto');
            // Start playback as soon as the first playable frames arrive.
            video.addEventListener('loadeddata', function () {
                status.hidden = true;
                video.play().catch(function () {});
            }, { once: true });
            video.addEventListener('canplay', function () {
                status.hidden = true;
                video.play().catch(function () {});
            }, { once: true });
            wireVideoProgress(video, status);
            video.src = mediaUrl;
            bodyEl.appendChild(video);
            video.load();
            video.play().catch(function () {});
        } else {
            const img = document.createElement('img');
            img.src = mediaUrl;
            img.alt = name;
            img.className = 'app-dev-media__image';
            img.onload = function () {
                status.hidden = true;
            };
            bodyEl.appendChild(img);
        }

        root.hidden = false;
        root.removeAttribute('hidden');
        root.classList.add('is-open');
        root.style.display = 'flex';
        document.body.classList.add('app-dev-media-lock');
    }

    function onActivate(event) {
        const closer = event.target.closest && event.target.closest('[data-app-dev-media-close]');
        if (closer) {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }

        const trigger = event.target.closest && event.target.closest('[data-app-dev-media]');
        if (!trigger) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        open(trigger);
    }

    document.addEventListener('click', onActivate, true);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const root = rootEl();
            if (root && root.classList.contains('is-open')) {
                event.preventDefault();
                event.stopPropagation();
                close();
            }
        }
    }, true);
})();
