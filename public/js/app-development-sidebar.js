(function () {
    'use strict';

    var STORAGE_KEY = 'app-dev-sidebar-collapsed';
    var shell = document.querySelector('.app-dev-shell');
    var sidebar = document.querySelector('[data-app-dev-sidebar]');
    var backdrop = document.querySelector('[data-app-dev-sidebar-backdrop]');
    if (!shell || !sidebar) {
        return;
    }

    var toggleBtn = document.querySelector('[data-app-dev-sidebar-toggle]');
    var openBtn = document.querySelector('[data-app-dev-sidebar-open]');
    var closeBtn = document.querySelector('[data-app-dev-sidebar-close]');
    var mq = window.matchMedia('(max-width: 900px)');

    function isMobile() {
        return mq.matches;
    }

    function setCollapsed(collapsed) {
        shell.classList.toggle('is-collapsed', collapsed);
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            var label = collapsed
                ? (toggleBtn.getAttribute('data-label-expand') || toggleBtn.getAttribute('aria-label') || 'Expand')
                : (toggleBtn.getAttribute('data-label-collapse') || toggleBtn.getAttribute('aria-label') || 'Collapse');
            toggleBtn.setAttribute('aria-label', label);
            toggleBtn.setAttribute('title', label);
        }
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            /* ignore quota / private mode */
        }
    }

    function readCollapsed() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function setMobileOpen(open) {
        shell.classList.toggle('is-mobile-open', open);
        if (backdrop) {
            if (open) {
                backdrop.hidden = false;
            } else {
                backdrop.hidden = true;
            }
        }
        document.body.classList.toggle('app-dev-sidebar-lock', open);
    }

    function syncMode() {
        if (isMobile()) {
            shell.classList.remove('is-collapsed');
            setMobileOpen(false);
        } else {
            setMobileOpen(false);
            setCollapsed(readCollapsed());
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (isMobile()) {
                return;
            }
            setCollapsed(!shell.classList.contains('is-collapsed'));
        });
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            setMobileOpen(true);
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            setMobileOpen(false);
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setMobileOpen(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && shell.classList.contains('is-mobile-open')) {
            setMobileOpen(false);
        }
    });

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', syncMode);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(syncMode);
    }

    syncMode();
})();
