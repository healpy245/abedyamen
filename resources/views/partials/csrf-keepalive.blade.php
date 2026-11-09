<script>
(function () {
    var url = @json(route('csrf.token'));

    function applyToken(token) {
        if (!token) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
        document.querySelectorAll('input[name="_token"]').forEach(function (input) {
            input.value = token;
        });
    }

    function refreshCsrf() {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (!response.ok) return null;
            return response.json();
        }).then(function (data) {
            if (data && data.token) applyToken(data.token);
        }).catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refreshCsrf();
    });

    setInterval(function () {
        if (document.visibilityState === 'visible') refreshCsrf();
    }, 10 * 60 * 1000);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.csrfReady === '1') return;
        // Forms that a page script sends in the background itself (data-ajax-submit) are left to it.
        if (form.hasAttribute('data-ajax-submit')) return;
        if ((form.method || 'get').toLowerCase() === 'get') return;
        if (!form.querySelector('input[name="_token"]')) return;

        event.preventDefault();
        refreshCsrf().finally(function () {
            form.dataset.csrfReady = '1';
            HTMLFormElement.prototype.submit.call(form);
        });
    }, true);

    function reloadExpiredLivewire() {
        if (!window.Livewire || window.__kamanExpiredHook) return;
        window.__kamanExpiredHook = true;
        Livewire.hook('request', function (hooks) {
            hooks.fail(function (payload) {
                if (payload.status !== 419) return;
                payload.preventDefault();
                window.location.reload();
            });
        });
    }

    document.addEventListener('livewire:init', reloadExpiredLivewire);
    reloadExpiredLivewire();
})();
</script>
