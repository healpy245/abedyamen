/*
 * Kaman design system behaviour.
 *  - select[data-ds-select]  -> styled dropdown; the native select stays in the form and keeps firing "change".
 *  - [data-ds-tabs]          -> segmented tabs with a sliding indicator; panels use [data-ds-panel].
 *  - window.ds.closeDialog() -> animated close for dialog.ds-dialog.
 * New markup (AJAX, templates) is picked up automatically.
 */
(function () {
    'use strict';

    var CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    var CHEVRON = '<svg class="ds-select__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    var uid = 0;
    var openSelect = null;

    /* ------------------------------------------------------------ select */

    function labelFor(select) {
        var option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : '';
    }

    function syncSelect(select) {
        var wrap = select.closest('.ds-select');
        if (!wrap) return;
        var value = wrap.querySelector('.ds-select__value');
        var option = select.options[select.selectedIndex];
        value.textContent = labelFor(select);
        value.classList.toggle('is-placeholder', !option || option.value === '');
        wrap.classList.toggle('is-disabled', select.disabled);
        wrap.querySelector('.ds-select__trigger').disabled = select.disabled;
    }

    function buildMenu(wrap) {
        var select = wrap.querySelector('select');
        var menu = document.createElement('div');
        menu.className = 'ds-menu';
        menu.setAttribute('role', 'listbox');
        menu.id = 'ds-menu-' + (++uid);
        Array.prototype.forEach.call(select.children, function (node) {
            if (node.tagName === 'OPTGROUP') {
                var group = document.createElement('div');
                group.className = 'ds-menu__group';
                group.textContent = node.label;
                menu.appendChild(group);
                Array.prototype.forEach.call(node.children, function (option) { menu.appendChild(itemFor(select, option)); });
            } else if (node.tagName === 'OPTION') {
                menu.appendChild(itemFor(select, node));
            }
        });
        return menu;
    }

    function itemFor(select, option) {
        var item = document.createElement('div');
        item.className = 'ds-menu__item';
        item.setAttribute('role', 'option');
        item.dataset.value = option.value;
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        if (option.disabled) item.setAttribute('aria-disabled', 'true');
        var text = document.createElement('span');
        text.textContent = option.textContent.trim();
        item.appendChild(text);
        item.insertAdjacentHTML('beforeend', CHECK);
        return item;
    }

    function place(wrap, menu) {
        var trigger = wrap.querySelector('.ds-select__trigger');
        var box = trigger.getBoundingClientRect();
        var below = window.innerHeight - box.bottom;
        var height = Math.min(menu.scrollHeight, 300);
        // Open upwards when there is no room below and more room above.
        var up = below < height + 12 && box.top > below;
        menu.classList.toggle('is-up', up);
        menu.style.minWidth = box.width + 'px';
        var rtl = getComputedStyle(trigger).direction === 'rtl';
        var left = rtl ? box.right - menu.offsetWidth : box.left;
        menu.style.left = Math.max(8, Math.min(left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        menu.style.top = (up ? box.top - height - 6 : box.bottom + 6) + 'px';
    }

    function setActive(menu, item) {
        menu.querySelectorAll('.ds-menu__item.is-active').forEach(function (el) { el.classList.remove('is-active'); });
        if (!item) return;
        item.classList.add('is-active');
        item.scrollIntoView({ block: 'nearest' });
    }

    function open(wrap) {
        if (openSelect && openSelect !== wrap) close(openSelect, false);
        var select = wrap.querySelector('select');
        if (select.disabled) return;
        var menu = buildMenu(wrap);
        // Keep the menu inside the same top layer as an open dialog, otherwise it would sit behind it.
        (wrap.closest('dialog[open]') || document.body).appendChild(menu);
        wrap._menu = menu;
        wrap.classList.add('is-open');
        var trigger = wrap.querySelector('.ds-select__trigger');
        trigger.setAttribute('aria-expanded', 'true');
        trigger.setAttribute('aria-controls', menu.id);
        addSearch(wrap, menu);
        place(wrap, menu);
        setActive(menu, menu.querySelector('[aria-selected="true"]') || menu.querySelector('.ds-menu__item:not([aria-disabled])'));
        openSelect = wrap;

        menu.addEventListener('mousedown', function (event) {
            if (!event.target.closest('.ds-menu__search')) event.preventDefault();
        });
        menu.addEventListener('mousemove', function (event) {
            var item = event.target.closest('.ds-menu__item');
            if (item && !item.classList.contains('is-active')) setActive(menu, item);
        });
        menu.addEventListener('click', function (event) {
            var item = event.target.closest('.ds-menu__item');
            if (!item || item.getAttribute('aria-disabled') === 'true') return;
            choose(wrap, item.dataset.value);
        });
    }

    function close(wrap, focus) {
        if (!wrap || !wrap._menu) return;
        var menu = wrap._menu;
        wrap._menu = null;
        wrap.classList.remove('is-open');
        var trigger = wrap.querySelector('.ds-select__trigger');
        trigger.setAttribute('aria-expanded', 'false');
        menu.classList.add('is-leaving');
        window.setTimeout(function () { menu.remove(); }, 140);
        if (openSelect === wrap) openSelect = null;
        if (focus) trigger.focus();
    }

    function choose(wrap, value) {
        var select = wrap.querySelector('select');
        var changed = select.value !== value;
        select.value = value;
        syncSelect(select);
        close(wrap, true);
        if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function visibleItems(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('.ds-menu__item:not([aria-disabled="true"]):not([hidden])'));
    }

    function navigate(wrap, event, fromSearch) {
        var menu = wrap._menu;
        if (!menu) return;
        var items = visibleItems(menu);
        var index = items.indexOf(menu.querySelector('.ds-menu__item.is-active'));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            var next = event.key === 'ArrowDown' ? Math.min(items.length - 1, index + 1) : Math.max(0, index - 1);
            setActive(menu, items[next]);
        } else if (event.key === 'Enter' || (event.key === ' ' && !fromSearch)) {
            event.preventDefault();
            if (items[index]) choose(wrap, items[index].dataset.value);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(wrap, true);
        } else if (event.key === 'Tab') {
            close(wrap, false);
        } else if (!fromSearch && event.key.length === 1) {
            var key = event.key.toLowerCase();
            var hit = items.find(function (item) { return item.textContent.trim().toLowerCase().indexOf(key) === 0; });
            if (hit) setActive(menu, hit);
        }
    }

    // Long lists (more than 8 options, or data-ds-search) get a search box at the top of the menu.
    function addSearch(wrap, menu) {
        var select = wrap.querySelector('select');
        var count = select.querySelectorAll('option').length;
        if (!(select.hasAttribute('data-ds-search') || count > 8)) return;
        var lang = (document.documentElement.lang || 'en').slice(0, 2);
        var placeholder = { ar: 'بحث…', he: 'חיפוש…' }[lang] || 'Search…';
        var empty = { ar: 'لا نتائج', he: 'אין תוצאות' }[lang] || 'No results';
        var box = document.createElement('div');
        box.className = 'ds-menu__search';
        box.innerHTML = '<input type="search" autocomplete="off" spellcheck="false">';
        var input = box.firstChild;
        input.placeholder = placeholder;
        input.setAttribute('aria-label', placeholder);
        menu.insertBefore(box, menu.firstChild);
        var none = document.createElement('p');
        none.className = 'ds-menu__empty';
        none.textContent = empty;
        none.hidden = true;
        menu.appendChild(none);

        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var shown = 0;
            menu.querySelectorAll('.ds-menu__item').forEach(function (item) {
                var hit = q === '' || item.textContent.toLowerCase().indexOf(q) !== -1;
                item.hidden = !hit;
                if (hit) shown++;
            });
            menu.querySelectorAll('.ds-menu__group').forEach(function (group) {
                var next = group.nextElementSibling;
                var any = false;
                while (next && next.classList.contains('ds-menu__item')) {
                    if (!next.hidden) any = true;
                    next = next.nextElementSibling;
                }
                group.hidden = !any;
            });
            none.hidden = shown > 0;
            setActive(menu, visibleItems(menu)[0]);
        });
        input.addEventListener('keydown', function (event) { navigate(wrap, event, true); });
        window.setTimeout(function () { input.focus(); }, 0);
    }

    function enhanceSelect(select) {
        if (select.dataset.dsReady === '1') return;
        select.dataset.dsReady = '1';
        var wrap = document.createElement('div');
        wrap.className = 'ds-select' + (select.classList.contains('is-invalid') ? ' is-invalid' : '') +
            (select.dataset.dsWrap ? ' ' + select.dataset.dsWrap : '');
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('ds-select__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'ds-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        var label = select.getAttribute('aria-label') || (select.labels && select.labels[0] ? select.labels[0].textContent.trim() : '');
        if (label) trigger.setAttribute('aria-label', label);
        trigger.innerHTML = '<span class="ds-select__value"></span>' + CHEVRON;
        wrap.appendChild(trigger);
        syncSelect(select);

        trigger.addEventListener('click', function () {
            if (wrap._menu) close(wrap, false); else open(wrap);
        });
        trigger.addEventListener('keydown', function (event) {
            if (!wrap._menu) {
                if (['ArrowDown', 'ArrowUp', 'Enter', ' '].indexOf(event.key) !== -1) {
                    event.preventDefault();
                    open(wrap);
                }
                return;
            }
            navigate(wrap, event, false);
        });
        select.addEventListener('change', function () { syncSelect(select); });
        if (select.form) {
            select.form.addEventListener('reset', function () { window.setTimeout(function () { syncSelect(select); }, 0); });
        }
    }

    document.addEventListener('mousedown', function (event) {
        if (openSelect && !openSelect.contains(event.target) && !(openSelect._menu && openSelect._menu.contains(event.target))) {
            close(openSelect, false);
        }
    });
    window.addEventListener('resize', function () { if (openSelect) close(openSelect, false); });
    document.addEventListener('scroll', function (event) {
        if (openSelect && openSelect._menu && !openSelect._menu.contains(event.target)) close(openSelect, false);
    }, true);
    // Escape inside an open menu must not also close the dialog around it.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openSelect) {
            event.preventDefault();
            event.stopPropagation();
            close(openSelect, true);
        }
    }, true);

    /* ------------------------------------------------------------ tabs */

    function moveIndicator(tabs) {
        var indicator = tabs.querySelector('.ds-tabs__indicator');
        var active = tabs.querySelector('.ds-tab[aria-selected="true"]');
        if (!indicator || !active) return;
        var box = tabs.getBoundingClientRect();
        var a = active.getBoundingClientRect();
        if (!box.width) return;
        indicator.style.width = a.width + 'px';
        indicator.style.transform = 'translateX(' + (a.left - box.left) + 'px)';
    }

    function selectTab(tabs, name) {
        tabs.querySelectorAll('.ds-tab').forEach(function (tab) {
            var on = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
        });
        var scope = tabs.closest('[data-ds-tabs-scope]') || document;
        scope.querySelectorAll('[data-ds-panel]').forEach(function (panel) {
            var on = panel.dataset.dsPanel === name;
            if (on && panel.hidden) {
                panel.hidden = false;
                panel.style.animation = 'none';
                void panel.offsetWidth;
                panel.style.animation = '';
            } else if (!on) {
                panel.hidden = true;
            }
        });
        scope.querySelectorAll('[data-ds-for]').forEach(function (el) {
            el.hidden = el.dataset.dsFor.split(' ').indexOf(name) === -1;
        });
        moveIndicator(tabs);
        tabs.dispatchEvent(new CustomEvent('ds:tab', { bubbles: true, detail: { tab: name } }));
    }

    function enhanceTabs(tabs) {
        if (tabs.dataset.dsReady === '1') return;
        tabs.dataset.dsReady = '1';
        if (!tabs.querySelector('.ds-tabs__indicator')) {
            var indicator = document.createElement('span');
            indicator.className = 'ds-tabs__indicator';
            indicator.setAttribute('aria-hidden', 'true');
            tabs.insertBefore(indicator, tabs.firstChild);
        }
        var current = tabs.querySelector('.ds-tab[aria-selected="true"]') || tabs.querySelector('.ds-tab');
        // Place the indicator without animating it in from the corner.
        var indicatorEl = tabs.querySelector('.ds-tabs__indicator');
        indicatorEl.style.transition = 'none';
        selectTab(tabs, current.dataset.tab);
        requestAnimationFrame(function () { moveIndicator(tabs); indicatorEl.style.transition = ''; });

        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('.ds-tab');
            if (tab) selectTab(tabs, tab.dataset.tab);
        });
        tabs.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
            var list = Array.prototype.slice.call(tabs.querySelectorAll('.ds-tab'));
            var index = list.indexOf(document.activeElement);
            if (index === -1) return;
            var rtl = getComputedStyle(tabs).direction === 'rtl';
            var step = (event.key === 'ArrowRight') !== rtl ? 1 : -1;
            var next = list[(index + step + list.length) % list.length];
            next.focus();
            selectTab(tabs, next.dataset.tab);
        });
    }

    window.addEventListener('resize', function () {
        document.querySelectorAll('[data-ds-tabs]').forEach(moveIndicator);
    });

    /* ------------------------------------------------------------ dialogs */

    function closeDialog(dialog) {
        if (!dialog || !dialog.open || dialog.classList.contains('is-leaving')) return;
        if (openSelect) close(openSelect, false);
        dialog.classList.add('is-leaving');
        window.setTimeout(function () {
            dialog.classList.remove('is-leaving');
            dialog.close();
        }, 180);
    }

    /* ------------------------------------------------------------ boot */

    function enhance(root) {
        (root || document).querySelectorAll('select[data-ds-select]').forEach(enhanceSelect);
        (root || document).querySelectorAll('[data-ds-tabs]').forEach(enhanceTabs);
    }

    new MutationObserver(function (records) {
        records.forEach(function (record) {
            record.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.matches('select[data-ds-select]')) enhanceSelect(node);
                if (node.matches('[data-ds-tabs]')) enhanceTabs(node);
                enhance(node);
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    window.ds = {
        enhance: enhance,
        sync: function (root) { (root || document).querySelectorAll('select[data-ds-select]').forEach(syncSelect); },
        selectTab: function (tabs, name) { selectTab(tabs, name); },
        refreshTabs: function (root) { (root || document).querySelectorAll('[data-ds-tabs]').forEach(moveIndicator); },
        closeDialog: closeDialog
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
})();
