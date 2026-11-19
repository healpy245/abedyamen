(function () {
    'use strict';

    var CLOSE_MS = 180;
    var boot = window.SALES_BOOT || { catalog: {}, editing: null };

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
        var closer = event.target.closest('[data-fin-sheet-close]');
        if (closer) closeDialog(closer.closest('dialog'));
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
    // Delegated, so controls inside a quote panel loaded later work too.
    document.addEventListener('change', function (event) {
        var el = event.target;
        if (el.matches && el.matches('[data-fin-autosubmit]') && el.form) el.form.requestSubmit();
    });
    document.querySelectorAll('[data-fin-search]').forEach(function (input) {
        var timer = null;
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { input.form.requestSubmit(); }, 450);
        });
    });

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
        ok.textContent = form.getAttribute('data-fin-confirm-ok') || ok.getAttribute('data-default');
        ok.classList.toggle('ds-btn--danger-solid', !primary);
        ok.classList.toggle('ds-btn--primary', primary);
        confirmDialog.querySelector('[data-fin-confirm-icon]').hidden = form.hasAttribute('data-fin-confirm-ok');
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

    /* ---------- quote panel ---------- */
    // Cards open their quote in the dialog without leaving the page; the address follows so a refresh reopens it.
    var detail = document.querySelector('[data-sq-detail]');
    if (detail) {
        var setOpenParam = function (href) {
            try { history.replaceState(history.state, '', href); } catch (e) {}
        };
        var withoutOpen = function () {
            var url = new URL(location.href);
            url.searchParams.delete('open');
            return url.pathname + (url.search ? url.search : '');
        };
        var request = 0;

        document.addEventListener('click', function (event) {
            var card = event.target.closest('[data-sq-card]');
            if (!card || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            var mine = ++request;
            detail.innerHTML = '<div class="sq-panel is-loading"><span class="sq-panel__spinner" aria-hidden="true"></span></div>';
            detail.setAttribute('aria-busy', 'true');
            openDialog(detail);
            fetch(card.getAttribute('data-sq-card'), { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' })
                .then(function (res) { if (!res.ok) throw new Error(res.status); return res.text(); })
                .then(function (html) {
                    if (mine !== request) return;
                    detail.innerHTML = html;
                    detail.removeAttribute('aria-busy');
                    if (window.ds) window.ds.enhance(detail);
                    setOpenParam(card.href);
                })
                .catch(function () { location.href = card.href; });
        });

        detail.addEventListener('close', function () { setOpenParam(withoutOpen()); });
    }

    /* ---------- toast ---------- */
    var showToast = function (text, bad) {
        var el = document.createElement('div');
        el.className = 'fin-toast ' + (bad ? 'is-bad' : 'is-ok');
        el.setAttribute('role', 'status');
        el.textContent = text;
        document.body.appendChild(el);
        window.setTimeout(function () { el.classList.add('is-leaving'); window.setTimeout(function () { el.remove(); }, 220); }, 4500);
    };

    var refreshPanel = function (panel) {
        var dialog = panel && panel.closest('dialog');
        if (!dialog) return;
        fetch(panel.getAttribute('data-panel-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.text() : null; })
            .then(function (html) {
                if (!html) return;
                dialog.innerHTML = html;
                if (window.ds) window.ds.enhance(dialog);
            });
    };

    /* ---------- PDF ---------- */
    // The quote document renders itself into a PDF inside a hidden frame: saved as a download, or returned
    // as a file when it is going out on WhatsApp.
    var pdfJob = null;
    window.addEventListener('message', function (event) {
        if (event.origin !== location.origin || !event.data || event.data.type !== 'sq-pdf' || !pdfJob) return;
        var job = pdfJob;
        pdfJob = null;
        window.clearTimeout(job.timer);
        job.frame.remove();
        if (event.data.ok) job.resolve(event.data.blob); else job.reject();
    });

    var makePdf = function (panel, lang, mode) {
        if (pdfJob) return Promise.reject();
        return new Promise(function (resolve, reject) {
            var url = new URL(panel.getAttribute('data-document-url'), location.href);
            url.searchParams.set('lang', lang);
            url.searchParams.set('pdf', mode === 'blob' ? 'blob' : '1');
            url.searchParams.set('name', panel.getAttribute('data-filename') || '');
            var frame = document.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            frame.style.cssText = 'position:fixed;width:900px;height:1200px;left:-10000px;top:0;border:0;visibility:hidden;';
            frame.src = url.toString();
            pdfJob = { frame: frame, resolve: resolve, reject: reject, timer: window.setTimeout(function () {
                if (pdfJob) { pdfJob.frame.remove(); pdfJob = null; }
                reject();
            }, 30000) };
            document.body.appendChild(frame);
        });
    };

    // A lead's quote: the PDF downloads in the language picked from the menu.
    var closePdfMenus = function (except) {
        document.querySelectorAll('[data-sq-pdf-menu]').forEach(function (menu) {
            if (menu === except) return;
            menu.hidden = true;
            var toggle = menu.parentNode.querySelector('[data-sq-pdf-toggle]');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    };
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-sq-pdf-toggle]');
        if (toggle) {
            var menu = toggle.parentNode.querySelector('[data-sq-pdf-menu]');
            closePdfMenus(menu);
            menu.hidden = !menu.hidden;
            toggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
            return;
        }
        var pick = event.target.closest('[data-sq-pdf-lang]');
        if (pick) {
            var panel = pick.closest('[data-sq-panel]');
            var button = panel.querySelector('[data-sq-pdf-toggle]');
            closePdfMenus(null);
            button.classList.add('is-loading');
            makePdf(panel, pick.getAttribute('data-sq-pdf-lang'), 'save')
                .catch(function () { showToast(document.documentElement.getAttribute('data-pdf-failed') || 'PDF failed', true); })
                .then(function () { button.classList.remove('is-loading'); });
            return;
        }
        if (!event.target.closest('[data-sq-pdf-menu]')) closePdfMenus(null);
    });

    // A client's contract is in Hebrew, so the PDF downloads straight away.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-sq-pdf]');
        if (!button) return;
        var panel = button.closest('[data-sq-panel]');
        button.classList.add('is-loading');
        makePdf(panel, 'he', 'save')
            .catch(function () { showToast(document.documentElement.getAttribute('data-pdf-failed') || 'PDF failed', true); })
            .then(function () { button.classList.remove('is-loading'); });
    });

    /* ---------- WhatsApp ---------- */
    var waDialog = document.querySelector('[data-sq-wa-dialog]');
    if (waDialog) {
        var waForm = waDialog.querySelector('[data-sq-wa-form]');
        var waMessage = waDialog.querySelector('[data-sq-wa-message]');
        var waError = waDialog.querySelector('[data-sq-wa-error]');
        var waSend = waDialog.querySelector('[data-sq-wa-send]');
        var waPanel = null;
        var waMessages = {};
        var waEdited = false;

        // A lead's quote goes out as the PDF; a client's as a link to sign the contract.
        var setMode = function (mode) {
            waDialog.setAttribute('data-mode', mode);
            waDialog.querySelectorAll('[data-link][data-pdf]').forEach(function (el) {
                el.textContent = el.getAttribute(mode === 'pdf' ? 'data-pdf' : 'data-link');
            });
        };
        var openWhatsapp = function (panel) {
            waPanel = panel;
            var pdfMode = panel.getAttribute('data-wa-mode') === 'pdf';
            setMode(pdfMode ? 'pdf' : 'link');
            // The contract (a client's quote) only goes out in Hebrew; a lead's PDF quote can be in any language.
            waDialog.querySelector('[data-sq-wa-langs]').hidden = !pdfMode;
            waMessages = JSON.parse(waPanel.getAttribute('data-messages') || '{}');
            waEdited = false;
            waError.hidden = true;
            waDialog.querySelector('[data-sq-wa-phone]').value = waPanel.getAttribute('data-phone') || '';
            var lang = pdfMode ? (waPanel.getAttribute('data-lang') || 'he') : 'he';
            var radio = waDialog.querySelector('[data-sq-wa-lang][value="' + lang + '"]');
            if (radio) radio.checked = true;
            waMessage.value = waMessages[lang] || '';
            openDialog(waDialog);
        };

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-sq-whatsapp]');
            if (btn) openWhatsapp(btn.closest('[data-sq-panel]'));
        });

        // After the agent cancels a signature to send again, the page comes back with the dialog open.
        var resend = document.querySelector('[data-sq-panel][data-resend]');
        if (resend) {
            try {
                var clean = new URL(location.href);
                clean.searchParams.delete('resend');
                history.replaceState(history.state, '', clean.pathname + clean.search);
            } catch (e) {}
            window.setTimeout(function () { openWhatsapp(resend); }, 50);
        }

        waMessage.addEventListener('input', function () { waEdited = true; });
        waDialog.addEventListener('change', function (event) {
            // Switching language rewrites the message, unless the agent already wrote their own.
            if (event.target.matches('[data-sq-wa-lang]') && !waEdited) waMessage.value = waMessages[event.target.value] || '';
        });

        waForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!waPanel) return;
            waError.hidden = true;
            waSend.classList.add('is-loading');
            var lang = (waDialog.querySelector('[data-sq-wa-lang]:checked') || {}).value || 'he';
            var post = function (data) {
                return fetch(waPanel.getAttribute('data-whatsapp-url'), {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json'
                    }
                });
            };
            var sending;
            if (waPanel.getAttribute('data-wa-mode') === 'pdf') {
                // The quote PDF is made here and sent as a file.
                sending = makePdf(waPanel, lang, 'blob').then(function (blob) {
                    var data = new FormData(waForm);
                    data.set('lang', lang);
                    data.set('pdf', new File([blob], (waPanel.getAttribute('data-filename') || 'quote') + '-' + lang + '.pdf', { type: 'application/pdf' }));
                    return post(data);
                });
            } else {
                // The server sends a link to the contract; the client signs it there.
                var data = new FormData(waForm);
                data.delete('lang');
                sending = post(data);
            }
            sending.then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (body) {
                    if (!res.ok) {
                        var first = body.errors ? Object.values(body.errors)[0] : null;
                        throw new Error((first && first[0]) || body.message || '');
                    }
                    return body;
                });
            }).then(function (body) {
                closeDialog(waDialog);
                showToast(body.message || '');
                refreshPanel(waPanel);
            }).catch(function (error) {
                waError.textContent = (error && error.message) || waError.getAttribute('data-default') || '';
                waError.hidden = waError.textContent === '';
            }).then(function () { waSend.classList.remove('is-loading'); });
        });
    }

    /* ---------- close-deal request ---------- */
    var requestDialog = document.querySelector('[data-sq-request-dialog]');
    if (requestDialog) {
        var requestForm = requestDialog.querySelector('[data-sq-request-form]');
        var filesInput = requestDialog.querySelector('[data-sq-files]');
        var thumbs = requestDialog.querySelector('[data-sq-thumbs]');

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-sq-request]');
            if (!btn) return;
            var panel = btn.closest('[data-sq-panel]');
            requestForm.action = panel.getAttribute('data-request-url');
            requestForm.reset();
            thumbs.innerHTML = '';
            requestDialog.querySelector('[data-sq-request-note]').value = panel.getAttribute('data-deal-note') || '';
            var hasFiles = panel.getAttribute('data-has-files') === '1';
            requestDialog.querySelector('[data-sq-has-files]').hidden = !hasFiles;
            filesInput.required = !hasFiles;
            closeDialog(panel.closest('dialog'));
            window.setTimeout(function () { openDialog(requestDialog); }, CLOSE_MS + 10);
        });

        filesInput.addEventListener('change', function () {
            thumbs.innerHTML = '';
            Array.prototype.forEach.call(filesInput.files, function (file) {
                var item = document.createElement('span');
                item.className = 'sq-thumb';
                if (file.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = file.name;
                    item.appendChild(img);
                } else {
                    item.classList.add('is-doc');
                    item.textContent = file.name;
                }
                thumbs.appendChild(item);
            });
        });
    }

    /* ---------- convert a lead ---------- */
    // A quote still for a lead cannot go to Finance, so its panel opens this instead of the close-deal request.
    var convertDialog = document.querySelector('[data-sq-convert-dialog]');
    if (convertDialog) {
        var convertForm = convertDialog.querySelector('[data-sq-convert-form]');
        var convertLead = convertDialog.querySelector('[data-sq-convert-lead]');

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-sq-convert]');
            if (!btn) return;
            var panel = btn.closest('[data-sq-panel]');
            convertForm.action = panel.getAttribute('data-convert-url');
            convertForm.reset();
            convertLead.textContent = convertLead.getAttribute('data-template').replace(':lead', panel.getAttribute('data-lead-name') || '');
            convertDialog.querySelector('[data-sq-convert-boarding]').href = panel.getAttribute('data-boarding-url') || '#';
            closeDialog(panel.closest('dialog'));
            window.setTimeout(function () { openDialog(convertDialog); }, CLOSE_MS + 10);
        });
    }

    /* ---------- quote builder ---------- */
    // Three parts: the monthly offer (base subscription plus ticked features), the devices offer (quantities per
    // device) and one-time services (ticked). The form only gets its line inputs when it is submitted.
    var builder = document.querySelector('[data-sq-builder]');
    if (builder) {
        var form = builder.querySelector('[data-sq-form]');
        var client = builder.querySelector('[data-sq-client]');
        var lead = builder.querySelector('[data-sq-lead]');
        var meta = builder.querySelector('[data-sq-client-meta]');
        var monthlyOn = builder.querySelector('[data-sq-monthly-on]');
        var monthlyBody = builder.querySelector('[data-sq-monthly-body]');
        var baseRow = builder.querySelector('[data-sq-base]');
        var catalog = boot.catalog || {};
        var extra = [];

        var byId = function (kind, id) {
            return (catalog[kind] || []).find(function (row) { return String(row.id) === String(id); });
        };

        var setQty = function (row, qty) {
            qty = Math.max(0, qty);
            row.querySelector('[data-qty]').value = qty;
            row.querySelector('[data-qty]').textContent = qty;
            row.classList.toggle('is-on', qty > 0);
        };

        var selected = function () {
            var lines = [];
            if (monthlyOn.checked) {
                if (baseRow) lines.push({ kind: 'base', ref_id: baseRow.getAttribute('data-sq-base') });
                builder.querySelectorAll('[data-sq-feature]:checked').forEach(function (box) {
                    lines.push({ kind: 'feature', ref_id: box.value });
                });
            }
            builder.querySelectorAll('[data-sq-device]').forEach(function (row) {
                var qty = Number(row.querySelector('[data-qty]').textContent) || 0;
                if (qty > 0) lines.push({ kind: 'equipment', ref_id: row.getAttribute('data-sq-device'), quantity: qty });
            });
            builder.querySelectorAll('[data-sq-service]:checked').forEach(function (box) {
                lines.push({ kind: 'service', ref_id: box.value });
            });
            return lines.concat(extra);
        };

        var refresh = function () {
            var sum = { monthly: 0, devices: 0, services: 0 };
            selected().forEach(function (line) {
                var ref = line.kind === 'custom' || line.kind === 'package' ? null : byId(line.kind, line.ref_id);
                var price = line.unit_price != null && line.unit_price !== '' ? Number(line.unit_price) : (ref ? ref.price : 0);
                var billing = line.billing || (ref ? ref.billing : 'monthly');
                var total = price * (line.kind === 'equipment' ? (Number(line.quantity) || 1) : 1);
                if (billing === 'monthly') sum.monthly += total;
                else if (line.kind === 'equipment') sum.devices += total;
                else sum.services += total;
            });
            builder.querySelector('[data-sq-total="monthly"]').textContent = money(sum.monthly);
            builder.querySelector('[data-sq-total="devices"]').textContent = money(sum.devices);
            builder.querySelector('[data-sq-total="services"]').textContent = money(sum.services);
            builder.querySelector('[data-sq-total="one_time"]').textContent = money(sum.devices + sum.services);
            monthlyBody.hidden = !monthlyOn.checked;
            builder.querySelector('[data-sq-monthly-off]').hidden = monthlyOn.checked;
            builder.querySelector('[data-sq-monthly]').classList.toggle('is-off', !monthlyOn.checked);
            builder.querySelector('[data-sq-empty]').hidden = selected().length > 0;
        };

        var showClient = function () {
            var picked = lead && !lead.disabled ? lead : client;
            var option = picked.selectedOptions[0];
            var parts = option && option.value ? [option.getAttribute('data-contact'), option.getAttribute('data-phone')].filter(Boolean) : [];
            meta.textContent = parts.join(' · ');
            meta.hidden = parts.length === 0;
        };

        // A quote is for a client or a lead: only the picked side is required and sent.
        var setFor = function (kind) {
            if (!lead) return;
            var forLead = kind === 'lead';
            builder.querySelector('[data-sq-for][value="' + kind + '"]').checked = true;
            builder.querySelector('[data-sq-for-pane="client"]').hidden = forLead;
            builder.querySelector('[data-sq-for-pane="lead"]').hidden = !forLead;
            client.disabled = forLead;
            client.required = !forLead;
            lead.disabled = !forLead;
            lead.required = forLead;
            if (window.ds) window.ds.sync(builder);
            showClient();
        };

        builder.addEventListener('change', function (event) {
            if (event.target.matches('[data-sq-for]')) setFor(event.target.value);
            if (event.target === client || event.target === lead) showClient();
            refresh();
        });

        builder.addEventListener('click', function (event) {
            var step = event.target.closest('[data-step]');
            if (!step) return;
            var row = step.closest('[data-sq-device]');
            setQty(row, (Number(row.querySelector('[data-qty]').textContent) || 0) + Number(step.getAttribute('data-step')));
            refresh();
        });

        form.addEventListener('submit', function (event) {
            var lines = selected();
            if (!lines.length) {
                event.preventDefault();
                builder.querySelector('[data-sq-empty]').classList.add('is-alert');
                return;
            }
            var holder = form.querySelector('[data-sq-inputs]');
            holder.innerHTML = '';
            lines.forEach(function (line, i) {
                Object.keys(line).forEach(function (key) {
                    if (line[key] === null || line[key] === undefined || line[key] === '') return;
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'lines[' + i + '][' + key + ']';
                    input.value = line[key];
                    holder.appendChild(input);
                });
            });
        });

        var load = function (data) {
            var store = form.getAttribute('data-store');
            var action = data && data.action ? data.action : store;
            var editing = action !== store;
            form.action = action;
            form.querySelector('[data-sq-action]').value = action;
            form.querySelector('[data-sq-method]').value = editing ? 'PUT' : 'POST';
            var title = builder.querySelector('#sq-builder-title');
            title.textContent = title.getAttribute(editing ? 'data-edit' : 'data-new');
            client.value = data && data.finance_client_id ? data.finance_client_id : '';
            client.dispatchEvent(new Event('change'));
            if (lead) {
                lead.value = data && data.agent_lead_id ? data.agent_lead_id : '';
                lead.dispatchEvent(new Event('change'));
                setFor(data && data.agent_lead_id ? 'lead' : 'client');
            }
            form.querySelector('[name="notes"]').value = data && data.notes ? data.notes : '';

            var lines = data && data.lines ? data.lines : [];
            var features = {};
            var devices = {};
            var services = {};
            extra = [];
            lines.forEach(function (line) {
                if (line.kind === 'feature') features[line.ref_id] = true;
                else if (line.kind === 'equipment') devices[line.ref_id] = Number(line.quantity) || 1;
                else if (line.kind === 'service') services[line.ref_id] = true;
                else if (line.kind !== 'base') extra.push(line);
            });
            monthlyOn.checked = !editing || lines.some(function (l) { return l.kind === 'base' || l.kind === 'feature'; });
            builder.querySelectorAll('[data-sq-feature]').forEach(function (box) { box.checked = !!features[box.value]; });
            builder.querySelectorAll('[data-sq-device]').forEach(function (row) { setQty(row, devices[row.getAttribute('data-sq-device')] || 0); });
            builder.querySelectorAll('[data-sq-service]').forEach(function (box) { box.checked = !!services[box.value]; });
            builder.querySelector('[data-sq-empty]').classList.remove('is-alert');
            showClient();
            refresh();
        };

        document.querySelectorAll('[data-sq-new]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                load(null);
                openDialog(builder);
            });
        });
        // The quote panel is loaded on demand, so its edit button is handled here and carries its own data.
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-sq-edit]');
            if (!btn) return;
            var panel = btn.closest('[data-sq-panel]');
            closeDialog(btn.closest('dialog'));
            load(JSON.parse(panel.getAttribute('data-editing') || 'null'));
            window.setTimeout(function () { openDialog(builder); }, CLOSE_MS + 10);
        });

        // ds.js upgrades the client select on DOMContentLoaded; load after it so the shown value is in sync.
        window.setTimeout(function () { load(boot.reopen ? boot.editing : null); }, 0);
    }

    document.querySelectorAll('dialog[data-open-on-load]').forEach(openDialog);
})();
