(function () {
    'use strict';

    var CLOSE_MS = 180;

    function money(value) {
        var n = Math.round((Number(value) || 0) * 100) / 100;
        var whole = n % 1 === 0;
        return (n < 0 ? '-' : '') + '₪' + Math.abs(n).toLocaleString('en-US', {
            minimumFractionDigits: whole ? 0 : 2,
            maximumFractionDigits: 2
        });
    }

    function openDialog(dialog) {
        if (!dialog) return;
        dialog.classList.remove('is-leaving');
        if (!dialog.open) dialog.showModal();
    }

    function closeDialog(dialog) {
        if (!dialog || !dialog.open || dialog.classList.contains('is-leaving')) return;
        dialog.classList.add('is-leaving');
        window.setTimeout(function () {
            dialog.classList.remove('is-leaving');
            dialog.close();
        }, CLOSE_MS);
    }

    // Clicking the dark area around a dialog closes it.
    document.addEventListener('click', function (event) {
        var dialog = event.target;
        if (dialog instanceof HTMLDialogElement && dialog.open && !dialog.hasAttribute('data-fin-confirm-dialog')) {
            closeDialog(dialog);
        }
    });

    document.querySelectorAll('dialog').forEach(function (dialog) {
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog(dialog);
        });
    });

    /* ---------- toast ---------- */
    var toast = document.querySelector('[data-fin-toast]');
    if (toast) {
        var hide = function () { toast.classList.add('is-leaving'); window.setTimeout(function () { toast.remove(); }, 220); };
        toast.querySelector('[data-fin-toast-close]').addEventListener('click', hide);
        window.setTimeout(hide, 5000);
    }

    /* ---------- filters ---------- */
    document.querySelectorAll('[data-fin-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form && el.form.requestSubmit(); });
    });
    document.querySelectorAll('[data-fin-search]').forEach(function (input) {
        var timer = null;
        if (!input.form.hasAttribute('data-fin-clients-filters')) {
            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () { input.form.requestSubmit(); }, 450);
            });
        }
        if (input.value) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    });

    /* ---------- clients: search and filter in place ---------- */
    // Every client is already on the page, so nothing reloads: rows that stop matching fade out,
    // then the rest slide to their new places.
    (function (form) {
        if (!form) return;
        var FADE_MS = 90;
        var MOVE_MS = 200;
        var EASE = 'cubic-bezier(.2, 0, 0, 1)';
        var body = document.querySelector('[data-fin-clients]');
        var wrap = body.closest('.ds-table-wrap');
        var rows = Array.prototype.slice.call(body.querySelectorAll('[data-fin-row]'));
        var empty = body.querySelector('[data-fin-empty]');
        var count = form.querySelector('[data-fin-count]');
        var clear = form.querySelector('[data-fin-clear]');
        var timer = null;
        var shown = [];

        function motionOn() {
            return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        }

        function stop(node) {
            node.getAnimations().forEach(function (animation) { animation.cancel(); });
        }

        function commit() {
            var before = new Map();
            var targets = rows.concat(empty);
            var fromHeight = wrap.offsetHeight;
            targets.forEach(function (node) {
                if (!node.hidden) before.set(node, node.getBoundingClientRect().top);
                stop(node);
            });
            stop(wrap);
            rows.forEach(function (row) { row.hidden = shown.indexOf(row) === -1; });
            empty.hidden = shown.length > 0;
            if (!motionOn()) return;

            var toHeight = wrap.offsetHeight;
            if (toHeight !== fromHeight) {
                wrap.animate([
                    { height: fromHeight + 'px', overflow: 'hidden' },
                    { height: toHeight + 'px', overflow: 'hidden' }
                ], { duration: MOVE_MS, easing: EASE });
            }
            targets.forEach(function (node) {
                if (node.hidden) return;
                if (!before.has(node)) {
                    node.animate([
                        { opacity: 0, transform: 'translateY(6px)' },
                        { opacity: 1, transform: 'none' }
                    ], { duration: MOVE_MS, easing: EASE });
                    return;
                }
                var shift = before.get(node) - node.getBoundingClientRect().top;
                if (Math.abs(shift) > 0.5) {
                    node.animate([
                        { transform: 'translateY(' + shift + 'px)' },
                        { transform: 'none' }
                    ], { duration: MOVE_MS, easing: EASE });
                }
            });
        }

        function refresh() {
            var query = form.elements.q.value.trim().toLowerCase();
            var payment = form.elements.payment.value;
            var service = form.elements.service.value;
            shown = rows.filter(function (row) {
                return (!query || row.getAttribute('data-search').indexOf(query) !== -1)
                    && (!payment || row.getAttribute('data-payment') === payment)
                    && (!service || row.getAttribute('data-service') === service);
            });
            count.textContent = String(shown.length);
            clear.hidden = !query && !payment && !service;

            // The address keeps the filters, so a refresh or a save comes back to the same list.
            var url = new URL(window.location.href);
            [['q', form.elements.q.value.trim()], ['payment', payment], ['service', service]].forEach(function (pair) {
                if (pair[1]) url.searchParams.set(pair[0], pair[1]); else url.searchParams.delete(pair[0]);
            });
            window.history.replaceState(window.history.state, '', url.pathname + url.search);

            window.clearTimeout(timer);
            var leaving = rows.filter(function (row) { return !row.hidden && shown.indexOf(row) === -1; });
            if (!empty.hidden && shown.length > 0) leaving.push(empty);
            if (!leaving.length || !motionOn()) {
                commit();
                return;
            }
            leaving.forEach(function (node) {
                node.animate([{ opacity: 1 }, { opacity: 0 }], { duration: FADE_MS, easing: 'linear', fill: 'forwards' });
            });
            timer = window.setTimeout(commit, FADE_MS);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            refresh();
        });
        form.elements.q.addEventListener('input', refresh);
        clear.addEventListener('click', function (event) {
            event.preventDefault();
            form.elements.q.value = '';
            form.elements.payment.value = '';
            form.elements.service.value = '';
            if (window.ds) window.ds.sync(form);
            refresh();
            form.elements.q.focus();
        });
    })(document.querySelector('[data-fin-clients-filters]'));

    /* ---------- confirm ---------- */
    var confirmDialog = document.querySelector('[data-fin-confirm-dialog]');
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-fin-confirm') || form.dataset.confirmed === '1') return;
        event.preventDefault();
        if (!confirmDialog) { if (window.confirm(form.getAttribute('data-fin-confirm'))) { form.dataset.confirmed = '1'; form.submit(); } return; }
        confirmDialog.querySelector('[data-fin-confirm-text]').textContent = form.getAttribute('data-fin-confirm');
        var ok = confirmDialog.querySelector('[data-fin-confirm-ok]');
        var primary = form.getAttribute('data-fin-confirm-tone') === 'primary';
        if (ok) {
            ok.textContent = form.getAttribute('data-fin-confirm-ok') || ok.getAttribute('data-default');
            ok.classList.toggle('ds-btn--danger-solid', !primary);
            ok.classList.toggle('ds-btn--primary', primary);
        }
        var icon = confirmDialog.querySelector('[data-fin-confirm-icon]');
        if (icon) icon.hidden = form.hasAttribute('data-fin-confirm-ok');
        confirmDialog.returnValue = '';
        confirmDialog.showModal();
        confirmDialog.addEventListener('close', function onClose() {
            confirmDialog.removeEventListener('close', onClose);
            if (confirmDialog.returnValue === 'ok') {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
    }, true);

    /* ---------- WhatsApp language menu ---------- */
    function closeWaMenus(except) {
        document.querySelectorAll('[data-fin-wa]').forEach(function (root) {
            if (root === except) return;
            var menu = root.querySelector('[data-fin-wa-menu]');
            var btn = root.querySelector('[data-fin-wa-toggle]');
            if (menu && !menu.hidden) {
                menu.classList.add('is-leaving');
                btn.setAttribute('aria-expanded', 'false');
                window.setTimeout(function () { menu.hidden = true; menu.classList.remove('is-leaving'); }, 150);
            }
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-fin-wa-toggle]');
        var inMenu = event.target.closest('[data-fin-wa-menu]');
        if (toggle) {
            event.stopPropagation();
            var root = toggle.closest('[data-fin-wa]');
            var menu = root.querySelector('[data-fin-wa-menu]');
            closeWaMenus(root);
            menu.hidden = !menu.hidden;
            toggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
            return;
        }
        if (inMenu) {
            event.stopPropagation();
            var sent = event.target.closest('button[type="submit"]');
            if (sent) inMenu.classList.add('is-sending');
            return;
        }
        closeWaMenus(null);
    });

    /* ---------- client modal ---------- */
    var modal = document.querySelector('[data-fin-modal]');
    var modalBody = modal ? modal.querySelector('[data-fin-modal-body]') : null;

    function settle(root, tab) {
        if (!root) return;
        var tabs = root.querySelector('[data-ds-tabs]');
        if (window.ds) window.ds.enhance(root);
        if (tabs && window.ds) {
            if (tab) window.ds.selectTab(tabs, tab);
            window.ds.refreshTabs(root);
        }
        setupBuilder(root);
        setupDirty(root);
        setupPay(root);
        setupDetails(root);
        // The service switch returns to whichever tab is open.
        var current = root.querySelector('[data-fcm-current-tab]');
        if (current && tabs) {
            tabs.addEventListener('click', function (event) {
                var tabBtn = event.target.closest('[data-tab]');
                if (tabBtn) current.value = tabBtn.getAttribute('data-tab');
            });
        }
    }

    // The restaurant modal (agents-workspace.js) embeds a client's Finance sections and sets them up with this.
    window.FinanceWorkspace = { settle: settle };

    function loadClient(url, tab) {
        if (!modal) return;
        modalBody.innerHTML =
            '<div class="fcm2-skeleton" aria-busy="true">' +
            '<div class="fcm2-skeleton__head"><i class="ds-skeleton"></i><span><i class="ds-skeleton"></i><i class="ds-skeleton"></i></span></div>' +
            '<i class="ds-skeleton fcm2-skeleton__bar"></i><i class="ds-skeleton fcm2-skeleton__block"></i></div>';
        openDialog(modal);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.text(); })
            .then(function (html) {
                modalBody.innerHTML = html;
                settle(modalBody.querySelector('[data-fcm]'), tab);
            })
            .catch(function () { closeDialog(modal); });
    }

    document.addEventListener('click', function (event) {
        var row = event.target.closest('[data-fin-open]');
        if (row && !event.target.closest('[data-fin-wa], a, button, form')) {
            loadClient(row.getAttribute('data-fin-open'), 'details');
            return;
        }
        if (event.target.closest('[data-fin-close]')) {
            closeDialog(modal);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var row = event.target.closest && event.target.closest('[data-fin-open]');
        if (row && event.target === row) {
            event.preventDefault();
            loadClient(row.getAttribute('data-fin-open'), 'details');
        }
    });

    // The page rendered the modal itself (after a save or a validation error): just show it.
    if (modal && modalBody.querySelector('[data-fcm]')) {
        openDialog(modal);
        settle(modalBody.querySelector('[data-fcm]'), null);
        if (window.history && window.history.replaceState) {
            var clean = new URL(window.location.href);
            clean.searchParams.delete('open');
            clean.searchParams.delete('tab');
            window.history.replaceState(null, '', clean.toString());
        }
    }

    function setupDirty(root) {
        var form = root.querySelector('[data-fcm-details]');
        var note = root.querySelector('[data-fcm-dirty]');
        if (!form || !note) return;
        var mark = function () { note.hidden = false; };
        form.addEventListener('input', mark);
        form.addEventListener('change', mark);
    }

    /* ---------- record / pay a payment ---------- */
    function setupPay(root) {
        var dialog = root.querySelector('[data-fcm-pay]');
        if (!dialog || dialog.dataset.ready === '1') return;
        dialog.dataset.ready = '1';
        var form = dialog.querySelector('[data-fcm-pay-form]');
        var record = form.querySelector('[data-fcm-pay-record]');
        var submit = form.querySelector('[data-fcm-pay-submit]');
        var title = dialog.querySelector('[data-fcm-pay-title]');
        var files = form.querySelector('[data-fcm-files]');
        var picked = form.querySelector('[data-fcm-picked]');
        var drop = form.querySelector('[data-fcm-drop]');

        function setMode(mode, opener) {
            var settling = mode === 'settle';
            form.querySelector('[data-fcm-pay-mode]').value = mode;
            form.querySelector('[data-fcm-pay-target]').value = settling && opener ? opener.getAttribute('data-target') : '';
            if (opener) {
                form.action = opener.getAttribute('data-action');
                title.textContent = opener.getAttribute('data-title');
            }
            // A disabled fieldset is not submitted: settling keeps the month and amount it already has.
            record.disabled = settling;
            record.hidden = settling;
            dialog.querySelector('[data-fcm-pay-amount]').textContent = settling && opener ? opener.getAttribute('data-amount') : '';
            submit.textContent = submit.getAttribute(settling ? 'data-settle' : 'data-record');
        }

        function showPicked() {
            picked.innerHTML = '';
            Array.prototype.forEach.call(files.files || [], function (file) {
                var chip = document.createElement('span');
                chip.className = 'fcm2-picked__item';
                if (file.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.alt = '';
                    img.src = URL.createObjectURL(file);
                    chip.appendChild(img);
                }
                var name = document.createElement('span');
                name.textContent = file.name;
                chip.appendChild(name);
                picked.appendChild(chip);
            });
            picked.hidden = !files.files || files.files.length === 0;
        }

        files.addEventListener('change', showPicked);
        ['dragenter', 'dragover'].forEach(function (type) {
            drop.addEventListener(type, function (event) { event.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            drop.addEventListener(type, function () { drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (event) {
            event.preventDefault();
            if (event.dataTransfer && event.dataTransfer.files.length) {
                files.files = event.dataTransfer.files;
                showPicked();
            }
        });

        root.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-fcm-pay-open]');
            if (!opener) return;
            form.reset();
            showPicked();
            setMode(opener.getAttribute('data-fcm-pay-open'), opener);
            if (window.ds) window.ds.sync(form);
            openDialog(dialog);
        });

        // Validation failed: show the same dialog again with what was typed.
        var reopen = dialog.getAttribute('data-reopen');
        if (reopen) {
            var target = form.querySelector('[data-fcm-pay-target]').value;
            var opener = reopen === 'settle'
                ? root.querySelector('[data-fcm-pay-open="settle"][data-target="' + target + '"]')
                : root.querySelector('[data-fcm-pay-open="record"]');
            setMode(reopen, opener);
            openDialog(dialog);
        }
    }

    /* ---------- payment details ---------- */
    function setupDetails(root) {
        var dialog = root.querySelector('[data-fcm-detail-dialog]');
        if (!dialog || dialog.dataset.ready === '1') return;
        dialog.dataset.ready = '1';
        var body = dialog.querySelector('[data-fcm-detail-body]');
        root.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-fcm-detail-open]');
            if (!opener) return;
            var template = root.querySelector('template[data-fcm-detail="' + opener.getAttribute('data-fcm-detail-open') + '"]');
            if (!template) return;
            body.innerHTML = '';
            body.appendChild(template.content.cloneNode(true));
            openDialog(dialog);
        });
    }

    // Close buttons inside the client modal's own sub-dialogs.
    document.addEventListener('click', function (event) {
        var close = event.target.closest('[data-fcm] [data-fin-sheet-close]');
        if (close) closeDialog(close.closest('dialog'));
    });

    /* ---------- invoice builder ---------- */
    function setupBuilder(root) {
        var form = root.querySelector('[data-fcm-builder]');
        if (!form || form.dataset.ready === '1') return;
        form.dataset.ready = '1';
        var lines = form.querySelector('[data-fcm-lines]');
        var template = form.querySelector('[data-fcm-line-template]');
        if (!lines || !template) return;
        var vatRate = Number(form.getAttribute('data-vat')) || 0;
        var counter = 0;

        function hasVat() {
            var checked = form.querySelector('input[name="type"]:checked');
            return !checked || checked.getAttribute('data-has-vat') === '1';
        }

        function recalc() {
            var subtotal = 0;
            lines.querySelectorAll('[data-fcm-line]').forEach(function (line) {
                var qty = Number(line.querySelector('[data-fcm-qty]').value) || 0;
                var price = Number(line.querySelector('[data-fcm-price]').value) || 0;
                var total = Math.round(qty * price * 100) / 100;
                subtotal += total;
                line.querySelector('[data-fcm-line-total]').textContent = money(total);
            });
            var vat = hasVat() ? Math.round(subtotal * vatRate) / 100 : 0;
            form.querySelector('[data-fcm-subtotal]').textContent = money(subtotal);
            form.querySelector('[data-fcm-vat]').textContent = money(vat);
            form.querySelector('[data-fcm-vat-row]').hidden = !hasVat();
            form.querySelector('[data-fcm-total]').textContent = money(subtotal + vat);
        }

        function addLine() {
            var wrap = document.createElement('div');
            wrap.innerHTML = template.innerHTML.replace(/__i__/g, String(counter++)).trim();
            var line = wrap.firstElementChild;
            lines.appendChild(line);
            if (window.ds) window.ds.enhance(line);
            var trigger = line.querySelector('.ds-select__trigger');
            if (trigger) trigger.focus();
            recalc();
        }

        function setOpen(open) {
            root.dataset.builder = open ? 'open' : 'closed';
            if (open && !lines.querySelector('[data-fcm-line]')) addLine();
            if (open) form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        lines.addEventListener('change', function (event) {
            var select = event.target.closest('select[data-fcm-item]');
            if (select) {
                var option = select.selectedOptions[0];
                var line = select.closest('[data-fcm-line]');
                if (option && option.value) {
                    line.querySelector('[data-fcm-price]').value = option.getAttribute('data-price') || 0;
                    var stock = option.getAttribute('data-stock');
                    var qty = line.querySelector('[data-fcm-qty]');
                    if (stock !== '' && stock !== null) qty.max = stock; else qty.removeAttribute('max');
                }
            }
            recalc();
        });
        lines.addEventListener('input', recalc);
        lines.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-fcm-remove]');
            if (!remove) return;
            var line = remove.closest('[data-fcm-line]');
            line.classList.add('is-leaving');
            window.setTimeout(function () { line.remove(); recalc(); }, 160);
        });
        form.addEventListener('change', function (event) {
            if (event.target.name === 'type') recalc();
        });
        form.querySelector('[data-fcm-add]').addEventListener('click', addLine);
        root.querySelectorAll('[data-fcm-builder-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () { setOpen(root.dataset.builder !== 'open'); });
        });

        if (root.dataset.builder === 'open') addLine();
        recalc();
    }

    /* ---------- storage dialogs ---------- */
    var itemDialog = document.querySelector('[data-fin-item-dialog]');
    var adjustDialog = document.querySelector('[data-fin-adjust-dialog]');

    function fillItemForm(action, data, category) {
        var form = itemDialog.querySelector('[data-fin-item-form]');
        var title = itemDialog.querySelector('#fin-item-title');
        form.reset();
        form.action = action;
        form.querySelector('[data-fin-item-method]').value = data ? 'PATCH' : 'POST';
        title.textContent = data ? title.getAttribute('data-edit') : title.getAttribute('data-new');
        itemDialog.querySelector('[data-fin-opening]').hidden = !!data;
        if (data) {
            form.elements.name.value = data.name || '';
            form.elements.name_ar.value = data.name_ar || '';
            form.elements.name_en.value = data.name_en || '';
            form.elements.sku.value = data.sku || '';
            form.elements.category.value = data.category || 'hardware';
            form.elements.unit_price.value = data.unit_price || 0;
            form.elements.low_stock.value = data.low_stock || 0;
            form.elements.track_stock.checked = !!data.track_stock;
        } else if (category) {
            // New services start as services, without stock.
            form.elements.category.value = category;
            form.elements.track_stock.checked = category !== 'service';
        }
        if (window.ds) window.ds.sync(form);
        openDialog(itemDialog);
        window.setTimeout(function () { form.elements.name.focus(); }, 60);
    }

    document.addEventListener('click', function (event) {
        if (!itemDialog) return;
        var newBtn = event.target.closest('[data-fin-item-new]');
        if (newBtn) {
            fillItemForm(itemDialog.querySelector('[data-fin-item-form]').getAttribute('data-store-action'), null, newBtn.getAttribute('data-category'));
            return;
        }
        var editBtn = event.target.closest('[data-fin-item-edit]');
        if (editBtn) {
            fillItemForm(editBtn.getAttribute('data-fin-item-edit'), JSON.parse(editBtn.getAttribute('data-item') || '{}'));
            return;
        }
        var adjustBtn = event.target.closest('[data-fin-adjust]');
        if (adjustBtn && adjustDialog) {
            var form = adjustDialog.querySelector('[data-fin-adjust-form]');
            form.reset();
            form.action = adjustBtn.getAttribute('data-fin-adjust');
            adjustDialog.querySelector('[data-fin-adjust-name]').textContent = adjustBtn.getAttribute('data-name');
            form.dataset.stock = adjustBtn.getAttribute('data-stock') || '0';
            if (window.ds) window.ds.sync(form);
            showAdjustResult();
            openDialog(adjustDialog);
            return;
        }
        var step = event.target.closest('[data-fin-step]');
        if (step) {
            var input = step.parentElement.querySelector('input');
            var delta = Number(step.getAttribute('data-fin-step'));
            var next = (Number(input.value) || 0) + delta;
            // Zero is not a stock change, so step over it.
            input.value = next === 0 ? next + delta : next;
            input.classList.remove('is-bump');
            void input.offsetWidth;
            input.classList.add('is-bump');
            showAdjustResult();
            return;
        }
        var close = event.target.closest('[data-fin-sheet-close]');
        if (close) closeDialog(close.closest('dialog'));
    });

    // "In stock: 4 → 6" under the stepper, so the result is clear before saving.
    function showAdjustResult() {
        if (!adjustDialog) return;
        var form = adjustDialog.querySelector('[data-fin-adjust-form]');
        var out = adjustDialog.querySelector('[data-fin-adjust-result]');
        var from = Number(form.dataset.stock) || 0;
        var to = from + (Number(form.elements.change.value) || 0);
        out.innerHTML = '';
        out.append(out.getAttribute('data-label') + ': ');
        var a = document.createElement('b'); a.textContent = String(from);
        var b = document.createElement('b'); b.textContent = String(to);
        if (to < 0) b.style.color = 'var(--ds-red)';
        var arrow = document.createElement('span'); arrow.textContent = ' → '; arrow.dir = 'ltr';
        var wrap = document.createElement('bdi'); wrap.dir = 'ltr';
        wrap.append(a, arrow, b);
        out.append(wrap);
    }
    if (adjustDialog) {
        adjustDialog.querySelector('[data-fin-adjust-form]').elements.change.addEventListener('input', showAdjustResult);
    }

    /* ---------- monthly subscription and features ---------- */
    var featureDialog = document.querySelector('[data-fin-feature-dialog]');
    if (featureDialog) {
        var featureForm = featureDialog.querySelector('[data-fin-feature-form]');
        var featureStore = featureForm.action;
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-fin-feature-new], [data-fin-feature-edit]');
            if (!btn) return;
            var data = btn.hasAttribute('data-fin-feature-edit') ? JSON.parse(btn.getAttribute('data-feature') || '{}') : null;
            var title = featureDialog.querySelector('#fin-feature-title');
            featureForm.reset();
            featureForm.action = data ? btn.getAttribute('data-fin-feature-edit') : featureStore;
            featureForm.querySelector('[data-fin-feature-method]').value = data ? 'PATCH' : 'POST';
            title.textContent = title.getAttribute(data ? 'data-edit' : 'data-new');
            if (data) {
                ['name', 'name_ar', 'name_en', 'price', 'note', 'bullets'].forEach(function (key) {
                    featureForm.elements[key].value = data[key] == null ? '' : data[key];
                });
                featureForm.elements.billing.value = data.billing || 'monthly';
                featureForm.elements.popular.checked = !!data.popular;
            }
            // The base subscription is always monthly.
            featureDialog.querySelector('[data-fin-feature-billing]').hidden = !!(data && data.kind === 'base');
            if (window.ds) window.ds.sync(featureForm);
            openDialog(featureDialog);
            window.setTimeout(function () { featureForm.elements.name.focus(); }, 60);
        });
    }

    // Remember the create URL; editing an item points the same form elsewhere.
    if (itemDialog) {
        var itemForm = itemDialog.querySelector('[data-fin-item-form]');
        itemForm.setAttribute('data-store-action', itemForm.action);
    }

    /* ---------- client's card ---------- */
    // The full number is formatted and checked here only: the form submits brand, last 4, expiry and holder.
    var cardBrand = function (digits) {
        if (/^4/.test(digits)) return 'visa';
        if (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(digits)) return 'mastercard';
        if (/^3[47]/.test(digits)) return 'amex';
        if (/^3(0[0-5]|[68])/.test(digits)) return 'diners';
        if (digits.length > 0 && digits.length <= 9) return 'isracard';
        return 'card';
    };
    var luhn = function (digits) {
        var sum = 0;
        for (var i = 0; i < digits.length; i++) {
            var d = Number(digits.charAt(digits.length - 1 - i));
            if (i % 2 === 1) { d *= 2; if (d > 9) d -= 9; }
            sum += d;
        }
        return sum % 10 === 0;
    };
    var cardValid = function (digits) {
        if (digits.length >= 8 && digits.length <= 9) return true; // Isracard numbers are short and have no Luhn digit.
        return digits.length >= 13 && digits.length <= 19 && luhn(digits);
    };
    document.addEventListener('input', function (event) {
        var number = event.target.closest && event.target.closest('[data-card-number]');
        if (number) {
            var digits = number.value.replace(/\D/g, '').slice(0, 19);
            number.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
            var chip = number.parentNode.querySelector('[data-card-brand-chip]');
            var brand = cardBrand(digits);
            chip.hidden = digits.length < 2;
            chip.setAttribute('data-brand', brand);
            chip.textContent = brand === 'card' ? '' : brand.toUpperCase();
            number.form.querySelector('[data-card-error]').hidden = true;
            return;
        }
        var expiry = event.target.closest && event.target.closest('[data-card-expiry]');
        if (expiry) {
            var e = expiry.value.replace(/\D/g, '').slice(0, 4);
            expiry.value = e.length > 2 ? e.slice(0, 2) + '/' + e.slice(2) : e;
        }
    });
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-fcm-card-form')) return;
        var digits = form.querySelector('[data-card-number]').value.replace(/\D/g, '');
        var error = form.querySelector('[data-card-error]');
        if (!cardValid(digits)) {
            event.preventDefault();
            error.textContent = error.getAttribute('data-invalid');
            error.hidden = false;
            return;
        }
        form.querySelector('[data-card-brand]').value = cardBrand(digits);
        form.querySelector('[data-card-last4]').value = digits.slice(-4);
    }, true);
    document.addEventListener('click', function (event) {
        var edit = event.target.closest('[data-fcm-card-edit]');
        if (!edit) return;
        var form = edit.closest('.fcm2-cardbox').querySelector('[data-fcm-card-form]');
        form.hidden = !form.hidden;
        if (!form.hidden) form.querySelector('[data-card-number]').focus();
    });

    /* ---------- deal notifications bell ---------- */
    var bell = document.querySelector('[data-fin-bell]');
    if (bell) {
        var bellPanel = bell.querySelector('[data-fin-bell-panel]');
        var bellToggle = bell.querySelector('[data-fin-bell-toggle]');
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-fin-bell-toggle]')) {
                bellPanel.hidden = !bellPanel.hidden;
                bellToggle.setAttribute('aria-expanded', bellPanel.hidden ? 'false' : 'true');
            } else if (!event.target.closest('[data-fin-bell-panel]')) {
                bellPanel.hidden = true;
                bellToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ---------- close-deal requests ---------- */
    // Each row opens its deal in a modal; the address follows so a refresh or a notification reopens it.
    var setDealParam = function (id) {
        try {
            var url = new URL(location.href);
            if (id) url.searchParams.set('open', id); else url.searchParams.delete('open');
            history.replaceState(history.state, '', url.pathname + url.search);
        } catch (e) {}
    };
    document.querySelectorAll('[data-fd-modal]').forEach(function (modal) {
        modal.addEventListener('close', function () { if (!document.querySelector('[data-fd-modal][open]')) setDealParam(null); });
    });
    document.querySelectorAll('[data-fd-modal][data-open-on-load]').forEach(openDialog);

    // Device payment in the confirm dialog: paid in full, or partly paid with what is still left to pay.
    var syncDevices = function (box) {
        var total = Number(box.getAttribute('data-total')) || 0;
        var partial = (box.querySelector('[data-fd-pay-mode]:checked') || {}).value === 'partial';
        var left = box.querySelector('[data-fd-left]');
        var line = box.querySelector('[data-fd-invoice-line]');
        box.querySelector('[data-fd-partial]').hidden = !partial;
        left.disabled = !partial;
        left.required = partial;
        if (!partial) {
            line.textContent = line.getAttribute('data-full');
            return;
        }
        var owed = Math.min(total, Math.max(0, Number(left.value) || 0));
        var paid = Math.round((total - owed) * 100) / 100;
        line.textContent = paid > 0
            ? line.getAttribute('data-partial').replace(':paid', money(paid)).replace(':left', money(owed))
            : line.getAttribute('data-none').replace(':left', money(owed));
    };
    document.querySelectorAll('[data-fd-devices]').forEach(function (box) {
        box.addEventListener('change', function () { syncDevices(box); });
        box.addEventListener('input', function () { syncDevices(box); });
    });

    var lightbox = document.querySelector('[data-fd-lightbox]');
    var returnDialog = document.querySelector('[data-fd-return-dialog]');
    document.addEventListener('click', function (event) {
        var row = event.target.closest('[data-fd-open]');
        if (row) {
            var id = row.getAttribute('data-fd-open');
            openDialog(document.querySelector('[data-fd-modal="' + id + '"]'));
            setDealParam(id);
            return;
        }
        var image = event.target.closest('[data-fd-image]');
        if (image && lightbox) {
            var img = lightbox.querySelector('[data-fd-lightbox-img]');
            img.src = image.getAttribute('data-fd-image');
            img.alt = image.getAttribute('data-name') || '';
            lightbox.querySelector('[data-fd-lightbox-link]').href = image.getAttribute('data-fd-image');
            openDialog(lightbox);
            return;
        }
        var accept = event.target.closest('[data-fd-confirm]');
        if (accept) {
            openDialog(document.querySelector('[data-fd-confirm-dialog="' + accept.getAttribute('data-fd-confirm') + '"]'));
            return;
        }
        var back = event.target.closest('[data-fd-return]');
        if (back && returnDialog) {
            returnDialog.querySelector('[data-fd-return-form]').action = back.getAttribute('data-fd-return');
            openDialog(returnDialog);
        }
    });
})();
