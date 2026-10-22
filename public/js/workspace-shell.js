(function () {
    function move(nav) {
        if (!nav) return;
        var active = nav.querySelector('.is-selected, .is-on, [aria-current="page"]');
        var indicator = nav.querySelector('[data-ws-indicator]');
        if (!active || !indicator) return;
        var navBox = nav.getBoundingClientRect();
        var activeBox = active.getBoundingClientRect();
        indicator.style.width = activeBox.width + 'px';
        indicator.style.transform = 'translateX(' + (activeBox.left - navBox.left) + 'px)';
    }

    function moveAll() {
        document.querySelectorAll('[data-ws-float]').forEach(move);
    }

    var search = document.querySelector('[data-ws-search]');
    var board = document.querySelector('[data-sup-search]');
    if (search && board && search !== board) {
        search.addEventListener('input', function () {
            board.value = search.value;
            board.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    var kbSearch = document.querySelector('[data-kb-search]');
    if (search && kbSearch && search !== kbSearch) {
        if (kbSearch.value) search.value = kbSearch.value;
        search.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || !kbSearch.form) return;
            event.preventDefault();
            kbSearch.value = search.value;
            kbSearch.form.submit();
        });
    }

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            if (!search) return;
            event.preventDefault();
            search.focus();
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-ws-float]')) return;
        requestAnimationFrame(moveAll);
        window.setTimeout(moveAll, 40);
    });
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(moveAll);
    }
    requestAnimationFrame(moveAll);
    window.addEventListener('resize', moveAll);

    function closeDetails(details) {
        if (!details || !details.open || details.dataset.closing === '1') return;
        var menu = details.querySelector('.ws-lang__menu, .ws-bell__panel');
        if (!menu) { details.open = false; return; }
        details.dataset.closing = '1';
        menu.classList.add('is-leaving');
        window.setTimeout(function () {
            details.open = false;
            details.dataset.closing = '';
            menu.classList.remove('is-leaving');
        }, 170);
    }

    document.addEventListener('click', function (event) {
        var summary = event.target.closest('summary');
        if (summary) {
            var parent = summary.parentElement;
            if (parent && parent.open && parent.matches('details.ws-lang, details.ws-bell')) {
                event.preventDefault();
                closeDetails(parent);
                return;
            }
        }
        document.querySelectorAll('details.ws-lang[open], details.ws-bell[open]').forEach(function (details) {
            if (!details.contains(event.target)) closeDetails(details);
        });
    }, true);

    window.kamanCloseDialog = function (dialog) {
        if (!dialog || !dialog.open || dialog.dataset.closing === '1') return;
        dialog.dataset.closing = '1';
        dialog.classList.add('is-leaving');
        window.setTimeout(function () {
            dialog.close();
            dialog.classList.remove('is-leaving');
            dialog.dataset.closing = '';
        }, 170);
    };

    document.addEventListener('click', function (event) {
        if (event.target.matches && event.target.matches('dialog.tk-assure')) {
            event.preventDefault();
            event.stopPropagation();
            window.kamanCloseDialog(event.target);
        }
    }, true);

    document.addEventListener('cancel', function (event) {
        if (!event.target.matches || !event.target.matches('dialog.tk-assure')) return;
        event.preventDefault();
        window.kamanCloseDialog(event.target);
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (document.querySelector('dialog[open]')) return;
        var details = document.querySelector('details.ws-lang[open], details.ws-bell[open]');
        if (!details) return;
        event.preventDefault();
        closeDetails(details);
    });
})();
