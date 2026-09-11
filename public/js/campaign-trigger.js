/**
 * Campaign Trigger modal — pick Excel contacts (all selected by default)
 * and optionally add a custom phone number.
 */
(function () {
    'use strict';

    const modal = document.getElementById('campaign-trigger-modal');
    if (!modal) return;

    const listEl = document.getElementById('campaign-trigger-list');
    const searchEl = document.getElementById('campaign-trigger-search');
    const formEl = document.getElementById('campaign-trigger-form');
    const idsEl = document.getElementById('campaign-trigger-ids');
    const countEl = document.getElementById('campaign-trigger-count');
    const errEl = document.getElementById('campaign-trigger-error');
    const submitBtn = document.getElementById('campaign-trigger-submit');
    const selectAllBtn = document.getElementById('campaign-trigger-select-all');
    const clearAllBtn = document.getElementById('campaign-trigger-clear-all');
    const customPhoneEl = document.getElementById('campaign-trigger-custom-phone');
    const customNameEl = document.getElementById('campaign-trigger-custom-name');
    const customAddBtn = document.getElementById('campaign-trigger-custom-add');
    const customMsgEl = document.getElementById('campaign-trigger-custom-msg');

    let contacts = [];
    let selected = new Set();
    let query = '';
    let storeContactUrl = '';

    const labels = {
        selected: modal.dataset.labelSelected || ':selected / :total selected',
        loading: modal.dataset.labelLoading || 'Loading…',
        empty: modal.dataset.labelEmpty || 'No contacts match.',
        needOne: modal.dataset.labelNeedOne || 'Select at least one number.',
        unnamed: modal.dataset.labelUnnamed || 'No name',
        customInvalid: modal.dataset.labelCustomInvalid || 'Enter a valid phone number.',
        customExists: modal.dataset.labelCustomExists || 'Number already in the list — selected.',
    };

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta?.content) return meta.content;
        const input = formEl?.querySelector('input[name="_token"]');
        return input?.value || '';
    }

    function open() {
        modal.hidden = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function close() {
        modal.hidden = true;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
        errEl?.classList.add('hidden');
        hideCustomMsg();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hideCustomMsg() {
        if (!customMsgEl) return;
        customMsgEl.classList.add('hidden');
        customMsgEl.textContent = '';
        customMsgEl.classList.remove('text-red-600', 'text-[#2f7d4a]');
        customMsgEl.classList.add('text-[#7c6a56]');
    }

    function showCustomMsg(text, kind) {
        if (!customMsgEl) return;
        customMsgEl.textContent = text;
        customMsgEl.classList.remove('hidden', 'text-red-600', 'text-[#2f7d4a]', 'text-[#7c6a56]');
        if (kind === 'error') customMsgEl.classList.add('text-red-600');
        else if (kind === 'ok') customMsgEl.classList.add('text-[#2f7d4a]');
        else customMsgEl.classList.add('text-[#7c6a56]');
    }

    function filtered() {
        const q = query.trim().toLowerCase();
        if (!q) return contacts;
        return contacts.filter((c) => {
            const name = String(c.name || '').toLowerCase();
            const phone = String(c.phone || '').toLowerCase();
            const city = String(c.city || '').toLowerCase();
            return name.includes(q) || phone.includes(q) || city.includes(q);
        });
    }

    function paintCount() {
        if (!countEl) return;
        countEl.textContent = labels.selected
            .replace(':selected', String(selected.size))
            .replace(':total', String(contacts.length));
    }

    function paintList() {
        if (!listEl) return;
        const rows = filtered();
        if (!rows.length) {
            listEl.innerHTML = `<p class="px-3 py-8 text-center text-xs text-[#a78a6c]">${escapeHtml(labels.empty)}</p>`;
            paintCount();
            return;
        }

        listEl.innerHTML = rows.map((c) => {
            const checked = selected.has(Number(c.id)) ? 'checked' : '';
            const name = String(c.name || '').trim();
            const phone = String(c.phone || '');
            const city = String(c.city || '').trim();
            const title = name !== '' ? name : labels.unnamed;
            const meta = [phone, city].filter(Boolean).join(' · ');
            return `
                <label class="flex cursor-pointer items-start gap-3 rounded-xl px-3 py-2.5 hover:bg-white/80">
                    <input type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-[#f47a2e]" data-contact-id="${c.id}" ${checked}>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-[#2b1e11] truncate">${escapeHtml(title)}</span>
                        <span class="block text-xs text-[#7c6a56] truncate" dir="ltr">${escapeHtml(meta)}</span>
                    </span>
                </label>`;
        }).join('');
        paintCount();
    }

    function syncHiddenInputs() {
        if (!idsEl) return;
        idsEl.innerHTML = Array.from(selected).map((id) =>
            `<input type="hidden" name="contact_ids[]" value="${id}">`
        ).join('');
    }

    function upsertContact(contact) {
        const id = Number(contact.id);
        const idx = contacts.findIndex((c) => Number(c.id) === id);
        if (idx >= 0) contacts[idx] = contact;
        else contacts.push(contact);
        selected.add(id);
        query = '';
        if (searchEl) searchEl.value = '';
        paintList();
        syncHiddenInputs();
    }

    async function loadAndOpen(btn) {
        const contactsUrl = btn.dataset.contactsUrl;
        const startUrl = btn.dataset.startUrl;
        storeContactUrl = btn.dataset.storeContactUrl || '';
        if (!contactsUrl || !startUrl || !formEl) return;

        formEl.action = startUrl;
        open();
        listEl.innerHTML = `<p class="px-3 py-8 text-center text-xs text-[#a78a6c]">${escapeHtml(labels.loading)}</p>`;
        if (searchEl) searchEl.value = '';
        if (customPhoneEl) customPhoneEl.value = '';
        if (customNameEl) customNameEl.value = '';
        query = '';
        contacts = [];
        selected = new Set();
        errEl?.classList.add('hidden');
        hideCustomMsg();

        try {
            const res = await fetch(contactsUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!res.ok) throw new Error('Failed to load contacts');
            const data = await res.json();
            contacts = Array.isArray(data.contacts) ? data.contacts : [];
            selected = new Set(contacts.map((c) => Number(c.id)));
            paintList();
            syncHiddenInputs();
            customPhoneEl?.focus();
        } catch (_) {
            listEl.innerHTML = `<p class="px-3 py-8 text-center text-xs text-red-600">${escapeHtml(labels.needOne)}</p>`;
        }
    }

    async function addCustomNumber() {
        hideCustomMsg();
        errEl?.classList.add('hidden');

        const phone = String(customPhoneEl?.value || '').trim();
        const name = String(customNameEl?.value || '').trim();
        if (!phone) {
            showCustomMsg(labels.customInvalid, 'error');
            customPhoneEl?.focus();
            return;
        }
        if (!storeContactUrl) {
            showCustomMsg(labels.customInvalid, 'error');
            return;
        }

        if (customAddBtn) customAddBtn.disabled = true;

        try {
            const res = await fetch(storeContactUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ phone, name: name || null }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.contact) {
                const msg = data.message
                    || (data.errors?.phone && data.errors.phone[0])
                    || labels.customInvalid;
                showCustomMsg(msg, 'error');
                return;
            }

            const already = contacts.some((c) => Number(c.id) === Number(data.contact.id));
            upsertContact(data.contact);
            if (customPhoneEl) customPhoneEl.value = '';
            if (customNameEl) customNameEl.value = '';
            showCustomMsg(
                already || data.created === false ? labels.customExists : `${data.contact.phone}`,
                'ok'
            );
            customPhoneEl?.focus();
        } catch (_) {
            showCustomMsg(labels.customInvalid, 'error');
        } finally {
            if (customAddBtn) customAddBtn.disabled = false;
        }
    }

    document.addEventListener('click', (e) => {
        const openBtn = e.target.closest?.('[data-campaign-trigger-open]');
        if (openBtn) {
            e.preventDefault();
            loadAndOpen(openBtn);
            return;
        }
        if (e.target.closest?.('[data-trigger-close]')) {
            e.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) {
            close();
        }
    });

    listEl?.addEventListener('change', (e) => {
        const input = e.target.closest?.('input[data-contact-id]');
        if (!input) return;
        const id = Number(input.dataset.contactId);
        if (input.checked) selected.add(id);
        else selected.delete(id);
        syncHiddenInputs();
        paintCount();
    });

    searchEl?.addEventListener('input', () => {
        query = searchEl.value || '';
        paintList();
    });

    selectAllBtn?.addEventListener('click', () => {
        filtered().forEach((c) => selected.add(Number(c.id)));
        paintList();
        syncHiddenInputs();
    });

    clearAllBtn?.addEventListener('click', () => {
        filtered().forEach((c) => selected.delete(Number(c.id)));
        paintList();
        syncHiddenInputs();
    });

    customAddBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        addCustomNumber();
    });

    customPhoneEl?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addCustomNumber();
        }
    });

    customNameEl?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addCustomNumber();
        }
    });

    formEl?.addEventListener('submit', (e) => {
        syncHiddenInputs();
        if (selected.size === 0) {
            e.preventDefault();
            if (errEl) {
                errEl.textContent = labels.needOne;
                errEl.classList.remove('hidden');
            }
            return;
        }
        if (submitBtn) submitBtn.disabled = true;
    });
})();
