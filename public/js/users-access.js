(function () {
    var root = document.querySelector('[data-ua]');
    if (!root) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    var toastEl = document.querySelector('[data-ua-toast]');
    var toastTimer = 0;

    function toast(message, bad) {
        if (!toastEl) return;
        window.clearTimeout(toastTimer);
        toastEl.hidden = true;
        toastEl.textContent = message;
        toastEl.classList.toggle('is-bad', !!bad);
        // Hiding and showing again restarts the entrance animation.
        void toastEl.offsetWidth;
        toastEl.hidden = false;
        toastTimer = window.setTimeout(function () { toastEl.hidden = true; }, 2200);
    }

    // Everything on the row comes back from the server after each save, so it always shows what was really stored.
    function paint(card, row) {
        card.classList.toggle('is-turned-off', !!row.turnedOff);
        var role = card.querySelector('[data-ua-role]');
        if (role) role.textContent = row.turnedOff ? root.getAttribute('data-turned-off') : row.role;
        Object.keys(row.doors).forEach(function (key) {
            var door = row.doors[key];
            var section = card.querySelector('[data-ua-door="' + key + '"]');
            var chip = card.querySelector('[data-ua-chip="' + key + '"]');
            var subKeys = Object.keys(door.subs);
            if (section) {
                section.classList.toggle('is-open', door.on);
                var main = section.querySelector('.ua-door__head input');
                if (main) {
                    main.checked = door.on;
                    main.disabled = door.fixed;
                    main.closest('label').classList.toggle('is-fixed', door.fixed);
                }
                subKeys.forEach(function (subKey) {
                    var input = section.querySelector('input[data-sub="' + subKey + '"]');
                    if (!input) return;
                    input.checked = door.subs[subKey].on;
                    input.disabled = door.subs[subKey].fixed || !door.on;
                    input.closest('label').classList.toggle('is-fixed', door.subs[subKey].fixed);
                });
            }
            if (chip) {
                var total = subKeys.length;
                var open = door.on ? door.openCount : 0;
                chip.classList.toggle('is-off', !door.on);
                chip.classList.toggle('is-part', door.on && total > 0 && open < total);
                chip.classList.toggle('is-on', door.on && !(total > 0 && open < total));
                var count = chip.querySelector('[data-ua-chip-count]');
                if (count) count.textContent = open + '/' + total;
                var names = subKeys.filter(function (subKey) { return door.subs[subKey].open; }).map(function (subKey) { return door.subs[subKey].label; });
                chip.title = door.label + (names.length ? ': ' + names.join('، ') : '');
            }
        });
    }

    function save(input) {
        var card = input.closest('[data-ua-user]');
        var label = input.closest('label');
        var on = input.checked;
        label.classList.add('is-saving');
        input.disabled = true;
        var account = input.hasAttribute('data-ua-account');
        var payload = account ? { on: on } : {
            door: input.getAttribute('data-door'),
            sub_door: input.getAttribute('data-sub') || null,
            on: on
        };

        fetch(card.getAttribute(account ? 'data-account-url' : 'data-url'), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().then(function (body) { return { ok: res.ok, body: body }; });
        }).then(function (result) {
            input.disabled = false;
            if (result.body && result.body.row) paint(card, result.body.row);
            if (!result.ok) throw new Error((result.body && result.body.message) || '');
            toast(result.body.message || root.getAttribute('data-saved'));
        }).catch(function (error) {
            input.disabled = false;
            input.checked = !on;
            toast((error && error.message) || root.getAttribute('data-failed'), true);
        }).then(function () {
            label.classList.remove('is-saving');
        });
    }

    root.addEventListener('change', function (event) {
        if (event.target.matches('[data-ua-toggle]')) save(event.target);
    });

    root.addEventListener('click', function (event) {
        var head = event.target.closest('[data-ua-expand]');
        if (!head) return;
        var card = head.closest('[data-ua-user]');
        var open = card.classList.toggle('is-expanded');
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    var search = document.querySelector('[data-ws-search]');
    var empty = root.querySelector('[data-ua-empty]');
    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            var shown = 0;
            root.querySelectorAll('[data-ua-user]').forEach(function (card) {
                var hit = !query || card.getAttribute('data-search').indexOf(query) !== -1;
                card.hidden = !hit;
                if (hit) shown++;
            });
            if (empty) empty.hidden = shown !== 0;
        });
    }
})();
