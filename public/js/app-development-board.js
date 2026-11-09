(function () {
    const root = document.querySelector('[data-kb-ui]');
    if (!root) {
        return;
    }

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const i18n = {
        moved: root.getAttribute('data-moved') || '',
        failed: root.getAttribute('data-failed') || '',
        loading: root.getAttribute('data-loading') || '',
    };

    function toast(message, bad) {
        const host = root.querySelector('[data-kb-toasts]');
        if (!host || !message) {
            return;
        }
        const el = document.createElement('div');
        el.className = 'kb-toast' + (bad ? ' kb-toast--bad' : '');
        el.textContent = message;
        host.appendChild(el);
        window.requestAnimationFrame(function () {
            el.classList.add('is-in');
        });
        window.setTimeout(function () {
            el.classList.remove('is-in');
            window.setTimeout(function () { el.remove(); }, reduce ? 0 : 180);
        }, 2400);
    }

    document.querySelectorAll('[data-kb-pop]').forEach(function (pop) {
        const toggle = pop.querySelector('[data-kb-pop-toggle]');
        const panel = pop.querySelector('[data-kb-pop-panel]');
        if (!toggle || !panel) {
            return;
        }
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            const open = panel.hasAttribute('hidden');
            panel.hidden = !open;
            pop.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-kb-pop]')) {
            return;
        }
        document.querySelectorAll('[data-kb-pop-panel]').forEach(function (panel) {
            panel.hidden = true;
            panel.closest('[data-kb-pop]')?.classList.remove('is-open');
            panel.closest('[data-kb-pop]')?.querySelector('[data-kb-pop-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    });

    document.querySelectorAll('[data-kb-jump]').forEach(function (button) {
        button.addEventListener('click', function () {
            const status = button.getAttribute('data-kb-jump');
            const column = root.querySelector('[data-kb-column][data-status="' + status + '"]');
            column?.scrollIntoView({ inline: 'start', block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
        });
    });

    const drawer = root.querySelector('[data-kb-drawer]');
    const drawerBody = root.querySelector('[data-kb-drawer-body]');
    const drawerTitle = root.querySelector('[data-kb-drawer-title]');
    let drawerToken = 0;
    let drawerUrl = '';
    let lastFocus = null;
    let closingDrawer = false;

    function skeleton() {
        return '<div class="kb-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
    }

    function drawerIsOpen() {
        return !!(drawer && (drawer.open || drawer.classList.contains('is-open')));
    }

    function setDrawer(open) {
        if (!drawer) {
            return;
        }
        if (open) {
            closingDrawer = false;
            if (typeof drawer.showModal === 'function' && !drawer.open) {
                drawer.showModal();
            }
            window.requestAnimationFrame(function () {
                drawer.classList.add('is-open');
            });
            drawer.setAttribute('aria-hidden', 'false');
            return;
        }
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }

    function releaseDrawer() {
        if (drawer && drawer.open) {
            drawer.close();
        }
        const back = lastFocus;
        lastFocus = null;
        closingDrawer = false;
        if (back && typeof back.focus === 'function' && document.contains(back)) {
            back.focus({ preventScroll: true });
        }
    }

    async function openDrawer(url, opener) {
        if (!drawerBody || !url) {
            return;
        }
        lastFocus = opener || document.activeElement;
        drawerUrl = url;
        const token = ++drawerToken;
        if (drawerTitle) {
            drawerTitle.textContent = i18n.loading;
        }
        drawerBody.innerHTML = skeleton();
        setDrawer(true);
        try {
            const response = await fetch(url, {
                headers: {
                    'X-App-Dev-Modal': '1',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'text/html',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('load');
            }
            const html = await response.text();
            if (token !== drawerToken) {
                return;
            }
            drawerBody.innerHTML = html;
            const title = drawerBody.querySelector('[data-modal-title]')?.getAttribute('data-modal-title');
            if (title && drawerTitle) {
                drawerTitle.textContent = title;
            }
            if (window.AppDevVoice && typeof window.AppDevVoice.boot === 'function') {
                window.AppDevVoice.boot(drawerBody);
            }
            document.dispatchEvent(new CustomEvent('app-dev-modal:loaded'));
        } catch (error) {
            if (token === drawerToken) {
                window.location.href = url;
            }
        }
    }

    // Redraws the open task in place (after the timer starts or pauses), without the loading skeleton.
    async function refreshDrawer() {
        if (!drawerBody || !drawerUrl || !drawerIsOpen()) {
            return;
        }
        const token = drawerToken;
        const response = await fetch(drawerUrl, {
            headers: { 'X-App-Dev-Modal': '1', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        });
        if (!response.ok || token !== drawerToken) {
            return;
        }
        const scroller = drawerBody.scrollTop;
        drawerBody.innerHTML = await response.text();
        drawerBody.scrollTop = scroller;
        if (window.AppDevVoice && typeof window.AppDevVoice.boot === 'function') {
            window.AppDevVoice.boot(drawerBody);
        }
        // The live clock starts on this event.
        document.dispatchEvent(new CustomEvent('app-dev-modal:loaded'));
    }

    // Start / pause timer: sent in the background so the task stays open.
    if (drawerBody) {
        drawerBody.addEventListener('submit', async function (event) {
            const form = event.target.closest('form');
            if (!form || !/\/timer\/(start|pause)$/.test(new URL(form.action, window.location.href).pathname)) {
                return;
            }
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const body = await response.json().catch(function () { return {}; });
                if (!response.ok || body.ok === false) {
                    throw new Error(body.message || i18n.failed || 'Error');
                }
                const card = root.querySelector('[data-kb-card][data-show-url="' + drawerUrl + '"]');
                if (card && body.status) {
                    moveCard(card.getAttribute('data-status-url'), body.status);
                }
                toast(body.message || '', false);
                await refreshDrawer();
            } catch (error) {
                toast(error.message || i18n.failed, true);
                if (button) {
                    button.disabled = false;
                }
            }
        });
    }

    function closeDrawer() {
        if (!drawer || !drawerIsOpen() || closingDrawer) {
            return;
        }
        drawerToken += 1;
        closingDrawer = true;
        setDrawer(false);
        if (reduce) {
            releaseDrawer();
            return;
        }
        window.setTimeout(releaseDrawer, 240);
    }

    if (drawer) {
        drawer.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDrawer();
        });
        drawer.addEventListener('click', function (event) {
            if (event.target === drawer) {
                closeDrawer();
            }
        });
    }

    function moveCard(url, status) {
        const card = root.querySelector('[data-kb-card][data-status-url="' + url + '"]');
        if (!card) {
            return;
        }
        const list = root.querySelector('[data-kb-column][data-status="' + status + '"] [data-kb-list]');
        const from = card.parentElement;
        card.setAttribute('data-status', status);
        if (list && from && from !== list) {
            const empty = list.querySelector('[data-kb-empty]');
            if (empty) {
                list.insertBefore(card, empty);
            } else {
                list.appendChild(card);
            }
            syncColumn(from);
            syncColumn(list);
        }
    }

    root.addEventListener('change', function (event) {
        const select = event.target.closest('[data-kb-status-select]');
        if (!select) {
            return;
        }
        const url = select.getAttribute('data-status-url');
        const status = select.value;
        const previous = select.getAttribute('data-current') || '';
        if (!url || status === previous) {
            return;
        }
        fetch(url, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ status: status }),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(i18n.failed || 'Error');
            }
            return response.json();
        }).then(function (body) {
            select.setAttribute('data-current', status);
            moveCard(url, status);
            toast(body.message || i18n.moved, false);
        }).catch(function () {
            select.value = previous;
            toast(i18n.failed, true);
        });
    });

    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-kb-drawer-close]')) {
            event.preventDefault();
            closeDrawer();
            return;
        }
        const opener = event.target.closest('[data-kb-card], [data-kb-open]');
        if (!opener || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (opener.classList.contains('kb-card--suppress')) {
            event.preventDefault();
            return;
        }
        const url = opener.getAttribute('data-show-url') || opener.getAttribute('href');
        if (!url) {
            return;
        }
        event.preventDefault();
        openDrawer(url, opener);
    });

    root.addEventListener('keydown', function (event) {
        const card = event.target.closest('[data-kb-card]');
        if (!card || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        event.preventDefault();
        openDrawer(card.getAttribute('data-show-url'), card);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !drawerIsOpen()) {
            return;
        }
        const modal = document.getElementById('app-dev-modal');
        if (modal && modal.classList.contains('is-open')) {
            return;
        }
        closeDrawer();
    });

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-comment-notify-toggle]');
        if (!toggle || !drawerBody || !drawerBody.contains(toggle)) {
            return;
        }
        event.preventDefault();
        const panel = toggle.closest('[data-comment-notify]')?.querySelector('[data-comment-notify-panel]');
        if (!panel) {
            return;
        }
        const open = panel.hasAttribute('hidden');
        panel.hidden = !open;
        panel.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    function syncColumn(list) {
        if (!list) {
            return;
        }
        const count = list.querySelectorAll('[data-kb-card]').length;
        const column = list.closest('[data-kb-column]');
        const badge = column?.querySelector('[data-kb-count]');
        if (badge) {
            badge.textContent = String(count);
        }
        const empty = list.querySelector('[data-kb-empty]');
        if (empty) {
            empty.hidden = count > 0;
        }
    }

    const board = root.querySelector('[data-kb-board]');
    if (!board || typeof window.Sortable !== 'function') {
        return;
    }

    let suppressClick = false;

    board.querySelectorAll('[data-kb-list]').forEach(function (list) {
        window.Sortable.create(list, {
            group: 'kaman-board',
            animation: reduce ? 0 : 180,
            draggable: '[data-kb-draggable]',
            ghostClass: 'kb-card--ghost',
            chosenClass: 'kb-card--chosen',
            dragClass: 'kb-card--drag',
            filter: '[data-kb-empty]',
            preventOnFilter: false,
            sort: false,
            delay: 140,
            delayOnTouchOnly: true,
            touchStartThreshold: 4,
            fallbackTolerance: 5,
            forceFallback: false,
            scroll: true,
            bubbleScroll: true,
            scrollSensitivity: 56,
            scrollSpeed: 14,
            emptyInsertThreshold: 48,
            onStart: function (evt) {
                suppressClick = true;
                evt.item.classList.add('kb-card--suppress');
            },
            onMove: function (evt) {
                board.querySelectorAll('.kb-column.is-drop').forEach(function (column) {
                    column.classList.remove('is-drop');
                });
                evt.to.closest('[data-kb-column]')?.classList.add('is-drop');
            },
            onEnd: function (evt) {
                board.querySelectorAll('.kb-column.is-drop').forEach(function (column) {
                    column.classList.remove('is-drop');
                });
                window.setTimeout(function () {
                    suppressClick = false;
                    evt.item.classList.remove('kb-card--suppress');
                }, reduce ? 0 : 80);

                if (evt.from === evt.to) {
                    return;
                }

                const status = evt.to.closest('[data-kb-column]')?.getAttribute('data-status');
                const url = evt.item.getAttribute('data-status-url');
                syncColumn(evt.from);
                syncColumn(evt.to);

                if (!status || !url) {
                    restore(evt);
                    return;
                }

                const previous = evt.item.getAttribute('data-status');
                evt.item.setAttribute('data-status', status);

                fetch(url, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ status: status }),
                }).then(function (response) {
                    if (!response.ok) {
                        return response.json().catch(function () { return {}; }).then(function (body) {
                            const error = new Error(body.message || i18n.failed || 'Error');
                            throw error;
                        });
                    }
                    return response.json();
                }).then(function (body) {
                    toast(body.message || i18n.moved, false);
                }).catch(function (error) {
                    evt.item.setAttribute('data-status', previous || '');
                    restore(evt);
                    toast(error.message || i18n.failed, true);
                });
            },
        });
    });

    function restore(evt) {
        const next = evt.from.children[evt.oldIndex] || null;
        evt.from.insertBefore(evt.item, next);
        syncColumn(evt.from);
        syncColumn(evt.to);
    }

    root.addEventListener('click', function (event) {
        if (suppressClick && event.target.closest('[data-kb-card]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
})();
