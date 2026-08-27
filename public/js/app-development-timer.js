(function () {
    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function format(seconds) {
        const s = Math.max(0, Math.floor(seconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const r = s % 60;
        return pad(h) + ':' + pad(m) + ':' + pad(r);
    }

    function tick(root) {
        const started = root.getAttribute('data-started-at');
        const clock = root.querySelector('[data-timer-clock]');
        if (!started || !clock) {
            return;
        }
        const startMs = Date.parse(started);
        if (!Number.isFinite(startMs)) {
            return;
        }
        clock.textContent = format((Date.now() - startMs) / 1000);
    }

    function boot() {
        document.querySelectorAll('[data-app-dev-timer]').forEach(function (root) {
            if (root.dataset.timerBound) {
                return;
            }
            root.dataset.timerBound = '1';
            tick(root);
            setInterval(function () {
                tick(root);
            }, 1000);
        });
    }

    document.addEventListener('DOMContentLoaded', boot);
    document.addEventListener('app-dev-modal:loaded', boot);
})();
