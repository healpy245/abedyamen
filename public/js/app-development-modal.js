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
        bindPicks(body);
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

    function readJson(node) {
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '');
        } catch (error) {
            return null;
        }
    }

    function bindPicks(root) {
        root.querySelectorAll('[data-kpick]').forEach((pick) => {
            if (pick.dataset.bound === '1') return;
            pick.dataset.bound = '1';

            const kind = pick.getAttribute('data-kpick-kind') || 'person';
            const query = pick.querySelector('[data-kpick-query]');
            const hidden = pick.querySelector('[data-kpick-value]');
            const list = pick.querySelector('[data-kpick-list]');
            const choices = readJson(pick.querySelector('[data-kpick-choices]')) || [];
            const logos = readJson(pick.querySelector('[data-kpick-logos]')) || {};
            const logosUrl = pick.querySelector('[data-kpick-logos-url]')?.getAttribute('data-kpick-logos-url') || '';
            if (!query || !hidden || !list) return;

            function paint(items) {
                list.replaceChildren();
                if (!items.length) {
                    const note = document.createElement('p');
                    note.className = 'kpick__empty';
                    note.textContent = pick.getAttribute('data-kpick-empty') || '';
                    list.append(note);
                    list.hidden = false;
                    return;
                }
                items.forEach((item, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'kpick__opt';
                    button.style.animationDelay = (index * 28) + 'ms';
                    const mark = document.createElement('span');
                    mark.className = 'kpick__mark';
                    if (item.slug) mark.dataset.slug = item.slug;
                    mark.textContent = (item.label || '?').trim().charAt(0) || '?';
                    const src = item.slug ? logos[item.slug] : '';
                    if (src) {
                        const img = document.createElement('img');
                        img.alt = '';
                        img.src = src;
                        mark.append(img);
                    }
                    const name = document.createElement('span');
                    name.textContent = item.label || '';
                    button.append(mark, name);
                    button.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        hidden.value = String(item.value ?? '');
                        query.value = item.label || '';
                        pick.classList.remove('is-invalid');
                        list.hidden = true;
                    });
                    list.append(button);
                });
                list.hidden = false;
                if (kind === 'restaurant' && logosUrl) {
                    const missing = items.map((item) => item.slug).filter((slug) => slug && !logos[slug]);
                    if (missing.length) {
                        fetch(logosUrl + '?' + missing.map((slug) => 'restaurants[]=' + encodeURIComponent(slug)).join('&'), {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        }).then((response) => response.ok ? response.json() : null).then((payload) => {
                            const found = payload && payload.logos;
                            if (!found) return;
                            Object.keys(found).forEach((slug) => {
                                if (found[slug]) logos[slug] = found[slug];
                            });
                            list.querySelectorAll('[data-slug]').forEach((mark) => {
                                const next = found[mark.getAttribute('data-slug')];
                                if (!next || mark.querySelector('img')) return;
                                const img = document.createElement('img');
                                img.alt = '';
                                img.src = next;
                                mark.append(img);
                            });
                        }).catch(() => {});
                    }
                }
            }

            function filter() {
                const typed = query.value.trim().toLocaleLowerCase();
                const exact = choices.find((item) => (item.label || '').trim().toLocaleLowerCase() === typed);
                hidden.value = exact ? String(exact.value ?? '') : '';
                if (kind === 'restaurant' && typed.length < 1) {
                    list.hidden = true;
                    list.replaceChildren();
                    return;
                }
                const items = (typed === '' ? choices : choices.filter((item) => {
                    return ((item.label || '') + ' ' + (item.slug || '')).toLocaleLowerCase().includes(typed);
                })).slice(0, 8);
                paint(items);
            }

            query.addEventListener('input', filter);
            query.addEventListener('focus', filter);
            query.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !list.hidden) {
                    event.stopPropagation();
                    list.hidden = true;
                }
            });
            query.addEventListener('blur', () => {
                window.setTimeout(() => { list.hidden = true; }, 140);
            });

            const form = pick.closest('form');
            if (form && pick.hasAttribute('data-kpick-required')) {
                form.addEventListener('submit', (event) => {
                    if (hidden.value) {
                        pick.classList.remove('is-invalid');
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    pick.classList.add('is-invalid');
                    query.focus();
                }, true);
            }
        });
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
        bindPicks(body);
    }
})();

// Task modal: title/description/assignee/priority/due edit in place; Save appears once something changes.
(function () {
    function editFormOf(target) {
        const form = target && target.form;
        return form && form.matches('[data-ti-edit]') ? form : null;
    }
    function markEdited(event) {
        const form = editFormOf(event.target);
        const bar = form && form.querySelector('[data-ti-edit-bar]');
        if (bar) {
            bar.hidden = false;
        }
    }
    document.addEventListener('input', markEdited);
    document.addEventListener('change', markEdited);
    document.addEventListener('click', function (event) {
        const reset = event.target.closest('[data-ti-edit-reset]');
        if (!reset) {
            return;
        }
        const form = reset.closest('[data-ti-edit]');
        form.reset();
        if (window.ds && window.ds.sync) {
            window.ds.sync(form.closest('.ti') || form);
        }
        form.querySelector('[data-ti-edit-bar]').hidden = true;
    });
})();
