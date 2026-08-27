(function () {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    function closeAll(except) {
        document.querySelectorAll('[data-kaman-inline].is-open').forEach((field) => {
            if (field !== except) {
                setOpen(field, false);
            }
        });
    }

    function setOpen(field, open) {
        field.classList.toggle('is-open', open);
        const trigger = field.querySelector('.kaman-inline-field__trigger');
        const menu = field.querySelector('.kaman-inline-field__menu');
        if (trigger) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (menu) {
            menu.hidden = !open;
        }
    }

    function markSelected(field, value) {
        field.querySelectorAll('.kaman-inline-field__option').forEach((option) => {
            const selected = option.getAttribute('data-value') === value;
            option.classList.toggle('is-selected', selected);
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    }

    function syncCritical(field, critical) {
        const row = field.closest('tr, [data-ticket-row]');
        if (!row || field.getAttribute('data-field') !== 'priority') {
            return;
        }
        row.classList.toggle('is-critical', !!critical);
    }

    function updateStatusCounts(counts) {
        if (!counts || typeof counts !== 'object') {
            return;
        }
        Object.keys(counts).forEach((status) => {
            document.querySelectorAll(`[data-status-count="${status}"]`).forEach((el) => {
                el.textContent = String(counts[status] ?? 0);
            });
        });
    }

    function rowMatchesCurrentTab(status) {
        const filters = document.querySelector('[data-app-dev-status-filters]');
        const tab = filters?.getAttribute('data-current-tab') || 'all';
        if (!tab || tab === 'all') {
            return true;
        }
        return tab === status;
    }

    function removeTicketFromBoard(ticketId) {
        if (!ticketId) {
            return;
        }
        document.querySelectorAll(`[data-ticket-id="${ticketId}"]`).forEach((node) => {
            node.remove();
        });

        const tbody = document.querySelector('[data-app-dev-ticket-tbody]');
        if (tbody && !tbody.querySelector('[data-ticket-row]')) {
            if (!tbody.querySelector('[data-app-dev-empty-row]')) {
                const tr = document.createElement('tr');
                tr.setAttribute('data-app-dev-empty-row', '');
                tr.innerHTML = `<td colspan="8" class="kaman-table__empty">${tbody.dataset.emptyLabel || ''}</td>`;
                tbody.appendChild(tr);
            }
        }

        const mobile = document.querySelector('[data-app-dev-ticket-mobile]');
        if (mobile && !mobile.querySelector('[data-ticket-row]')) {
            if (!mobile.querySelector('[data-app-dev-empty-mobile]')) {
                const p = document.createElement('p');
                p.className = 'py-6 text-center text-sm text-[#7c6a56]';
                p.setAttribute('data-app-dev-empty-mobile', '');
                p.textContent = mobile.dataset.emptyLabel || '';
                mobile.appendChild(p);
            }
        }
    }

    function applyStatusBoardUpdate(field, previousStatus, nextStatus, ticketId, counts) {
        updateStatusCounts(counts);

        const row = field.closest('[data-ticket-row]');
        if (row) {
            row.setAttribute('data-ticket-status', nextStatus);
        }

        if (!rowMatchesCurrentTab(nextStatus) && previousStatus !== nextStatus) {
            removeTicketFromBoard(ticketId || row?.getAttribute('data-ticket-id'));
        }
    }

    async function choose(field, value) {
        if (field.getAttribute('data-value') === value || field.classList.contains('is-busy')) {
            setOpen(field, false);
            return;
        }

        const url = field.getAttribute('data-url');
        const key = field.getAttribute('data-field');
        if (!url || !key) {
            return;
        }

        const previousValue = field.getAttribute('data-value') || '';
        const row = field.closest('[data-ticket-row]');
        const ticketId = row?.getAttribute('data-ticket-id') || '';

        field.classList.add('is-busy');
        setOpen(field, false);

        try {
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ [key]: value }),
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Update failed');
            }

            const badge = field.querySelector('.kaman-inline-field__badge');
            if (badge && data.badge_html) {
                badge.innerHTML = data.badge_html;
            }

            field.setAttribute('data-value', data.value || value);
            markSelected(field, data.value || value);
            if (Object.prototype.hasOwnProperty.call(data, 'critical')) {
                syncCritical(field, data.critical);
            }
            const actor = field.closest('.kaman-inline-wrap')?.querySelector('[data-inline-actor]');
            if (actor && Object.prototype.hasOwnProperty.call(data, 'changed_by_name')) {
                const name = data.changed_by_name || '';
                actor.textContent = name;
                actor.hidden = !name;
            }

            if (key === 'status') {
                applyStatusBoardUpdate(
                    field,
                    previousValue,
                    data.value || value,
                    ticketId,
                    data.status_counts || null,
                );
            }
        } catch (error) {
            window.alert(error.message || 'Update failed');
        } finally {
            field.classList.remove('is-busy');
        }
    }

    async function applyAppTypes(field) {
        if (field.classList.contains('is-busy')) {
            return;
        }
        const url = field.getAttribute('data-url');
        if (!url) {
            return;
        }
        const selected = Array.from(field.querySelectorAll('input[type="checkbox"]:checked'))
            .map((input) => input.value)
            .filter(Boolean);
        if (!selected.length) {
            window.alert('Select at least one app type');
            return;
        }

        field.classList.add('is-busy');
        try {
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ app_types: selected }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Update failed');
            }
            const badge = field.querySelector('[data-app-types-badge]');
            if (badge && data.badge_html) {
                badge.innerHTML = data.badge_html;
            }
            field.setAttribute('data-value', (data.value || selected).join(','));
            field.querySelectorAll('.kaman-inline-field__check').forEach((label) => {
                const input = label.querySelector('input[type="checkbox"]');
                label.classList.toggle('is-selected', !!(input && input.checked));
            });
            if (data.app_type_counts) {
                Object.keys(data.app_type_counts).forEach((key) => {
                    document.querySelectorAll(`[data-app-type-count="${key}"]`).forEach((el) => {
                        el.textContent = String(data.app_type_counts[key] ?? 0);
                    });
                });
            }
            setOpen(field, false);
        } catch (error) {
            window.alert(error.message || 'Update failed');
        } finally {
            field.classList.remove('is-busy');
        }
    }

    document.addEventListener('click', (event) => {
        const applyBtn = event.target.closest('[data-app-types-apply]');
        if (applyBtn) {
            event.preventDefault();
            event.stopPropagation();
            const field = applyBtn.closest('[data-kaman-inline-multi]');
            if (field) {
                applyAppTypes(field);
            }
            return;
        }

        const option = event.target.closest('.kaman-inline-field__option');
        if (option) {
            event.preventDefault();
            event.stopPropagation();
            const field = option.closest('[data-kaman-inline]');
            if (field) {
                choose(field, option.getAttribute('data-value'));
            }
            return;
        }

        const trigger = event.target.closest('.kaman-inline-field__trigger');
        if (trigger) {
            event.preventDefault();
            event.stopPropagation();
            const field = trigger.closest('[data-kaman-inline]');
            if (!field || field.classList.contains('is-busy')) {
                return;
            }
            const willOpen = !field.classList.contains('is-open');
            closeAll(field);
            setOpen(field, willOpen);
            return;
        }

        if (!event.target.closest('[data-kaman-inline]')) {
            closeAll();
        }
    });

    document.addEventListener('change', (event) => {
        const check = event.target.closest('.kaman-inline-field__check input[type="checkbox"]');
        if (!check) {
            return;
        }
        const label = check.closest('.kaman-inline-field__check');
        if (label) {
            label.classList.toggle('is-selected', check.checked);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAll();
        }
    });
})();
