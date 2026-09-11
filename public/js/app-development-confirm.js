(function () {
    const dialog = document.getElementById('app-dev-confirm');
    if (!dialog) {
        return;
    }

    const titleEl = dialog.querySelector('[data-app-dev-confirm-title]');
    const messageEl = dialog.querySelector('[data-app-dev-confirm-message]');
    const okBtn = dialog.querySelector('[data-app-dev-confirm-ok]');
    let pendingForm = null;

    function isOpen() {
        return dialog.classList.contains('is-open');
    }

    function open(form) {
        pendingForm = form;
        if (titleEl) {
            titleEl.textContent = form.getAttribute('data-confirm-title')
                || titleEl.getAttribute('data-default-title')
                || '';
        }
        if (messageEl) {
            messageEl.textContent = form.getAttribute('data-confirm-message') || '';
        }
        if (okBtn) {
            const action = form.getAttribute('data-confirm-action');
            if (action) {
                okBtn.textContent = action;
            }
        }
        dialog.hidden = false;
        dialog.classList.add('is-open');
        okBtn?.focus();
    }

    function close() {
        pendingForm = null;
        dialog.classList.remove('is-open');
        dialog.hidden = true;
    }

    function confirmPending() {
        const form = pendingForm;
        if (!form) {
            close();
            return;
        }
        close();
        form.dataset.appDevConfirmed = '1';
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-app-dev-confirm')) {
            return;
        }
        if (form.dataset.appDevConfirmed === '1') {
            delete form.dataset.appDevConfirmed;
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        open(form);
    }, true);

    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-app-dev-confirm-cancel]')) {
            event.preventDefault();
            close();
            return;
        }
        if (event.target.closest('[data-app-dev-confirm-ok]')) {
            event.preventDefault();
            confirmPending();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !isOpen()) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        close();
    }, true);
})();
