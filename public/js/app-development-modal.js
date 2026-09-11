(function () {
    const modal = document.getElementById('app-dev-modal');
    if (!modal) {
        return;
    }

    const body = document.getElementById('app-dev-modal-body');
    const titleEl = document.getElementById('app-dev-modal-title');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function sleep(ms) {
        return reduceMotion ? Promise.resolve() : new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function isModalUrl(path) {
        return /\/app-development\/tickets\/create\/?$/.test(path)
            || /\/app-development\/tickets\/[^/]+\/edit\/?$/.test(path)
            || /\/app-development\/tickets\/[^/]+\/?$/.test(path)
            || /\/app-development\/releases\/create\/?$/.test(path)
            || /\/app-development\/releases\/[^/]+\/?$/.test(path);
    }

    function setOpen(open) {
        modal.classList.toggle('is-open', open);
        modal.hidden = !open;
        document.body.classList.toggle('app-dev-modal-lock', open);
        if (open) {
            modal.removeAttribute('hidden');
        }
    }

    function setLoading(on) {
        modal.classList.toggle('is-loading', on);
    }

    function setVariant(variant) {
        modal.classList.toggle('app-dev-modal--view', variant !== 'form');
        modal.classList.toggle('app-dev-modal--form', variant === 'form');
    }

    function syncTitle() {
        const source = body.querySelector('[data-modal-title]');
        if (source && titleEl) {
            titleEl.textContent = source.getAttribute('data-modal-title') || titleEl.textContent;
        }

        setVariant(body.querySelector('[data-modal-variant]')?.getAttribute('data-modal-variant'));
    }

    function pathOf(url) {
        return new URL(url, window.location.origin).pathname;
    }

    function variantForUrl(url) {
        return /\/(create|edit)\/?$/.test(pathOf(url)) ? 'form' : 'view';
    }

    function titleForUrl(url) {
        const path = pathOf(url).replace(/\/edit\/?$/, '').replace(/\/$/, '');
        const match = path.match(/\/app-development\/(?:tickets|releases)\/([^/]+)$/);

        return match && match[1] !== 'create' ? decodeURIComponent(match[1]) : '';
    }

    body.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-app-dev-tab]');
        if (!tab || !body.contains(tab)) {
            return;
        }

        const name = tab.getAttribute('data-app-dev-tab');
        body.querySelectorAll('[data-app-dev-tab]').forEach((item) => {
            item.classList.toggle('is-active', item === tab);
        });
        body.querySelectorAll('[data-app-dev-panel]').forEach((panel) => {
            panel.hidden = panel.getAttribute('data-app-dev-panel') !== name;
        });
    });

    async function load(url, { push = true } = {}) {
        setVariant(variantForUrl(url));
        if (titleEl) {
            titleEl.textContent = titleForUrl(url);
        }
        setOpen(true);
        setLoading(true);
        modal.classList.add('is-fading');
        await sleep(120);

        const response = await fetch(url, {
            headers: {
                'X-App-Dev-Modal': '1',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/html',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            window.location.href = url;
            return;
        }

        body.innerHTML = await response.text();
        modal.classList.remove('is-fading');
        setLoading(false);
        syncTitle();

        if (push) {
            history.pushState({ appDevModal: true }, '', url);
        }
    }

    function close() {
        if (modal.getAttribute('data-uploading') === '1') {
            return;
        }

        if (history.state && history.state.appDevModal) {
            history.back();
            return;
        }

        if (modal.getAttribute('data-standalone') === '1') {
            window.location.href = modal.getAttribute('data-fallback') || '/app-development/tickets';
            return;
        }

        setOpen(false);
        setLoading(false);
        modal.classList.remove('is-submitting');
    }

    document.addEventListener('click', (event) => {
        const closer = event.target.closest('[data-app-dev-modal-close]');
        if (closer) {
            event.preventDefault();
            close();
            return;
        }

        const link = event.target.closest('a[data-app-dev-modal]');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (link.target === '_blank') {
            return;
        }

        event.preventDefault();
        load(link.href).catch(() => {
            window.location.href = link.href;
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            const media = document.getElementById('app-dev-media-lightbox');
            if (media && media.classList.contains('is-open')) {
                return;
            }
            close();
        }
    });

    modal.addEventListener('submit', (event) => {
        if (!event.target.matches('form')) {
            return;
        }
        modal.classList.add('is-submitting');
        const button = event.target.querySelector('[type="submit"]');
        if (button) {
            button.disabled = true;
        }
    });

    window.addEventListener('popstate', () => {
        if (isModalUrl(window.location.pathname)) {
            load(window.location.href, { push: false }).catch(() => {
                window.location.reload();
            });
            return;
        }

        setOpen(false);
        setLoading(false);
        modal.classList.remove('is-submitting');
    });

    if (modal.classList.contains('is-open')) {
        document.body.classList.add('app-dev-modal-lock');
        syncTitle();
    }
})();
