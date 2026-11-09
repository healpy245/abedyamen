(function () {
    var root = document.querySelector('[data-inv]');
    if (!root) return;

    var issues = [];
    var labels = {};
    try {
        issues = JSON.parse((document.getElementById('sup-inv-data') || {}).textContent || '[]');
        labels = JSON.parse((document.getElementById('sup-inv-labels') || {}).textContent || '{}');
    } catch (error) {
        issues = [];
    }

    var byKey = {};
    issues.forEach(function (issue) { byKey[issue.key] = issue; });

    var form = document.getElementById('sup-inv-form');
    var list = root.querySelector('[data-stream]');
    var detail = root.querySelector('[data-detail]');
    var countEl = root.querySelector('[data-visible-count]');
    var noMatch = root.querySelector('[data-no-match]');
    var clientChips = root.querySelector('[data-client-chips]');
    var badge = root.querySelector('[data-filter-badge]');
    var deviceInput = root.querySelector('[data-device-input]');
    var headEl = null;
    var tabsEl = null;
    var paneEl = null;

    var state = {
        severity: field('severity'),
        category: field('category'),
        integration: field('integration'),
        device: field('device'),
        http: field('http'),
        tab: 'details',
        occurrence: null,
        key: null,
        q: ''
    };

    var hash = (location.hash || '').replace(/^#i=/, '');
    if (hash && byKey[hash]) {
        state.key = hash;
    } else {
        var preset = list && list.querySelector('.sup-inv__issue.is-selected:not([hidden])');
        state.key = preset ? preset.getAttribute('data-key') : null;
    }

    function field(name) {
        var input = form && form.querySelector('[data-client="' + name + '"]');
        return input ? input.value : '';
    }

    function setField(name, value) {
        var input = form.querySelector('[data-client="' + name + '"]');
        if (input) input.value = value;
        state[name] = value;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function accepts(issue) {
        var level = issue.level || '';
        if (state.severity && level !== state.severity && !(state.severity === 'warn' && level === 'warning')) return false;
        if (state.category === 'network' && !issue.network) return false;
        if (state.category && state.category !== 'network' && issue.logKind !== state.category) return false;
        if (state.integration && issue.service !== state.integration) return false;
        if (state.http && String(issue.code || '') !== state.http) return false;
        if (state.device) {
            var names = [issue.device || ''].concat(issue.devices || []);
            if (names.join(' ').toLowerCase().indexOf(state.device.toLowerCase()) === -1) return false;
        }
        if (state.q) {
            var haystack = [
                issue.title, issue.restaurant, issue.device, issue.message,
                issue.endpoint, issue.code, issue.sourceLabel, issue.service
            ].concat(issue.devices || []).join(' ').toLowerCase();
            if (haystack.indexOf(state.q) === -1) return false;
        }
        return true;
    }

    function motionOn() {
        return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function setIssueVisible(button, show) {
        var token = String((Number(button.getAttribute('data-motion')) || 0) + 1);
        button.setAttribute('data-motion', token);
        if (show) {
            button.classList.remove('is-out');
            if (button.hidden) {
                button.hidden = false;
                if (motionOn()) {
                    button.classList.add('is-in');
                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () { button.classList.remove('is-in'); });
                    });
                }
            }
            return;
        }
        if (button.hidden || button.classList.contains('is-out')) return;
        if (!motionOn()) {
            button.hidden = true;
            return;
        }
        button.classList.add('is-out');
        window.setTimeout(function () {
            if (button.getAttribute('data-motion') !== token) return;
            button.hidden = true;
            button.classList.remove('is-out');
        }, 200);
    }

    function shutMenu(menu, btn) {
        if (!menu) return;
        if (btn) btn.setAttribute('aria-expanded', 'false');
        if (menu.hidden) return;
        menu.classList.remove('is-open');
        var token = String((Number(menu.getAttribute('data-shut')) || 0) + 1);
        menu.setAttribute('data-shut', token);
        window.setTimeout(function () {
            if (menu.getAttribute('data-shut') !== token || menu.classList.contains('is-open')) return;
            menu.hidden = true;
        }, 190);
    }

    function openMenu(pop) {
        var menu = pop.querySelector('[data-pop-menu]');
        var btn = pop.querySelector('[data-pop-btn]');
        if (!menu) return;
        place(pop);
        menu.setAttribute('data-shut', '');
        requestAnimationFrame(function () { menu.classList.add('is-open'); });
        if (btn) btn.setAttribute('aria-expanded', 'true');
        var search = menu.querySelector('[data-pop-search], [data-device-input]');
        if (search) search.focus();
    }

    function occurrenceOf(issue) {
        var rows = issue.occurrences || [];
        if (!rows.length) return null;
        if (state.occurrence != null) {
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].id === state.occurrence) return rows[i];
            }
        }
        return rows[0];
    }

    function fill(text) {
        return text ? String(text) : (labels.unavailable || '');
    }

    function affected(issue) {
        var names = issue.devices || [];
        if (!names.length) return labels.pending || '';
        if (names.length === 1) return names[0];
        return String(labels.devices || ':count').replace(':count', String(names.length));
    }

    function markSelected() {
        if (!list) return;
        list.querySelectorAll('.sup-inv__issue').forEach(function (button) {
            var on = button.getAttribute('data-key') === state.key;
            button.classList.toggle('is-selected', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function syncUrl() {
        var params = new URLSearchParams(location.search);
        ['severity', 'category', 'integration', 'device', 'http'].forEach(function (key) {
            if (state[key]) params.set(key, state[key]);
            else params.delete(key);
        });
        var hashPart = state.key ? '#i=' + state.key : '';
        history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params.toString() : '') + hashPart);
    }

    function renderChips() {
        if (!clientChips) return;
        clientChips.replaceChildren();
        var rows = [
            ['severity', labels.levels && labels.levels[state.severity]],
            ['category', labels.categories && labels.categories[state.category]],
            ['integration', labels.integrations && labels.integrations[state.integration]],
            ['device', state.device],
            ['http', state.http ? 'HTTP ' + state.http : '']
        ];
        rows.forEach(function (row) {
            if (!state[row[0]] || !row[1]) return;
            var button = el('button', 'sup-inv__chip');
            button.type = 'button';
            button.setAttribute('data-clear', row[0]);
            var span = el('span', '', row[1]);
            span.dir = 'auto';
            button.appendChild(span);
            var mark = el('i', '', '×');
            mark.setAttribute('aria-hidden', 'true');
            button.appendChild(mark);
            clientChips.appendChild(button);
        });
    }

    function renderBadge() {
        if (!badge) return;
        var keys = [];
        root.querySelectorAll('[data-pick]').forEach(function (group) {
            keys.push(group.getAttribute('data-pick'));
        });
        if (deviceInput) keys.push('device');
        var count = keys.filter(function (key) { return !!state[key]; }).length;
        badge.hidden = count === 0;
        badge.textContent = count ? String(count) : '';
        if (badge.parentElement) badge.parentElement.classList.toggle('is-set', count > 0);
    }

    function syncPicks() {
        root.querySelectorAll('[data-pick]').forEach(function (group) {
            var name = group.getAttribute('data-pick');
            group.querySelectorAll('[data-value]').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-value') === state[name]);
            });
        });
        if (deviceInput && document.activeElement !== deviceInput) deviceInput.value = state.device;
        var query = state.device.toLowerCase();
        root.querySelectorAll('[data-device-option]').forEach(function (button) {
            var name = button.getAttribute('data-device-option') || '';
            button.hidden = query !== '' && name.toLowerCase().indexOf(query) === -1;
            button.classList.toggle('is-active', name === state.device);
        });
    }

    function ensureShell() {
        if (headEl && detail.contains(headEl)) return;
        detail.replaceChildren();
        headEl = el('div', 'sup-inv__head');
        paneEl = el('div', 'sup-inv__pane');
        detail.appendChild(headEl);
        detail.appendChild(paneEl);
    }

    function paintEmpty() {
        headEl = null;
        tabsEl = null;
        paneEl = null;
        detail.replaceChildren();
        detail.appendChild(el('div', 'sup-inv__placeholder', labels.select || ''));
    }

    var icons = {
        error: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
        warn: '<svg viewBox="0 0 24 24"><path d="M12 3 2.5 20h19z"/><path d="M12 9v4M12 17h.01"/></svg>',
        info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
        ok: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
        clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        device: '<svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>',
        http: '<svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>',
        copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        chevron: '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>'
    };

    function icon(name, extra) {
        var node = el('span', 'sup-inv__mark' + (extra ? ' ' + extra : ''));
        node.innerHTML = icons[name] || icons.info;
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    function httpBand(code) {
        var text = String(code || '');
        return /^\d{3}$/.test(text) ? text.charAt(0) : '';
    }

    function httpBadge(code) {
        var band = httpBand(code);
        if (!band) return null;
        var node = el('span', 'sup-inv__http is-' + band, String(code));
        node.dir = 'ltr';
        return node;
    }

    function paintHeader(issue) {
        ensureShell();
        headEl.replaceChildren();
        // The title, restaurant, product, status and count are on the selected row in the list.
        if (issue.sourceLabel) {
            var sub = el('p', 'sup-inv__sub');
            var source = el('span', 'sup-inv__iso', issue.sourceLabel);
            source.dir = 'ltr';
            sub.appendChild(source);
            headEl.appendChild(sub);
        }

        // One device is already named on its occurrence row.
        if ((issue.devices || []).length > 1) {
            var stats = el('div', 'sup-inv__stats');
            stats.appendChild(stat(labels.affected, affected(issue), false, 'device'));
            headEl.appendChild(stats);
        }
    }

    function paintLogo(slot, url) {
        if (!slot || !url || slot.querySelector('img')) return;
        var img = document.createElement('img');
        img.alt = '';
        img.addEventListener('error', function () { img.remove(); });
        img.src = url;
        slot.appendChild(img);
    }

    function loadLogos() {
        var endpoint = root.getAttribute('data-logos');
        if (!endpoint) return;
        var missing = {};
        issues.forEach(function (issue) {
            if (issue.restaurantId && !issue.logo) missing[issue.restaurantId] = true;
        });
        var ids = Object.keys(missing).slice(0, 80);
        if (!ids.length) return;
        var query = ids.map(function (id) { return 'restaurants[]=' + encodeURIComponent(id); }).join('&');
        fetch(endpoint + '?' + query, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                var logos = (payload && payload.logos) || {};
                issues.forEach(function (issue) {
                    if (logos[issue.restaurantId]) issue.logo = logos[issue.restaurantId];
                });
                root.querySelectorAll('[data-logo]').forEach(function (slot) {
                    paintLogo(slot, logos[slot.getAttribute('data-logo')]);
                });
            })
            .catch(function () {});
    }

    function stat(label, value, ltr, iconName) {
        var wrap = el('div', 'sup-inv__stat');
        var caption = el('span', '');
        if (iconName) caption.appendChild(icon(iconName));
        caption.appendChild(document.createTextNode(label || ''));
        wrap.appendChild(caption);
        var strong = el('b');
        var iso = el('span', 'sup-inv__iso', String(value));
        iso.dir = ltr ? 'ltr' : 'auto';
        strong.appendChild(iso);
        wrap.appendChild(strong);
        return wrap;
    }

    function paintPane(issue, animate) {
        if (!paneEl) return;
        paneEl.classList.remove('is-in');
        paneEl.replaceChildren();
        if (animate) {
            void paneEl.offsetWidth;
            paneEl.classList.add('is-in');
        }
        paintOccurrences(issue);
    }

    function whenStamp(value, clock) {
        var match = String(value || '').match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/);
        if (match) return match[3] + '/' + match[2] + ' ' + match[4];
        return clock || value || '';
    }

    function pre(text, dir) {
        var node = el('pre', 'sup-inv__pre', text);
        if ((dir || 'ltr') === 'auto') node.style.direction = 'auto';
        else node.dir = 'ltr';
        return node;
    }

    function shortText(issue, occ) {
        var title = String(issue.title || '').trim();
        var explanation = String(issue.explanation || '').trim();
        if (explanation && explanation !== title && explanation.length <= 180) return explanation;
        var line = String((occ && occ.message) || '').split('\n')[0].trim();
        if (!line || line === title || line.length > 160) return '';
        if (line.indexOf('http://') === 0 || line.indexOf('https://') === 0 || line.charAt(0) === '{') return '';
        if (title && line.indexOf(title) !== -1 && line.length > title.length + 24) return '';
        return line;
    }

    function endpointLine(occ) {
        if (!occ) return '';
        return ((occ.method || '') + ' ' + (occ.endpoint || '')).trim();
    }

    function endpointNode(call) {
        if (!call) return null;
        var path = el('div', 'sup-inv__path');
        var text = el('span', 'sup-inv__iso', call);
        text.dir = 'ltr';
        path.appendChild(text);
        return path;
    }

    function paintDetails(issue, occ) {
        occ = occ || {};
        var text = shortText(issue, occ);
        if (text) {
            var lead = el('p', 'sup-inv__lead');
            var leadText = el('span', 'sup-inv__iso', text);
            leadText.dir = 'auto';
            lead.appendChild(leadText);
            paneEl.appendChild(lead);
        }
        var context = ((occ.platform || '') + ' ' + (occ.version || '')).trim();
        if (context) {
            var note = el('p', 'sup-inv__context');
            var noteText = el('span', 'sup-inv__iso', context);
            noteText.dir = 'ltr';
            note.appendChild(noteText);
            paneEl.appendChild(note);
        }
        var raw = rawDetails(occ);
        if (raw) paneEl.appendChild(raw);
    }

    function paintOccurrences(issue) {
        (issue.occurrences || []).forEach(function (occ) {
            var wrap = el('div', 'sup-inv__occ-wrap');
            var button = el('button', 'sup-inv__occ' + (state.occurrence === occ.id ? ' is-selected' : ''));
            button.type = 'button';
            button.setAttribute('data-occ', String(occ.id));
            var time = el('time');
            time.dateTime = occ.at || '';
            var clock = el('span', 'sup-inv__iso', whenStamp(occ.at, occ.clock));
            clock.dir = 'ltr';
            time.appendChild(clock);
            button.appendChild(time);
            var device = el('span');
            var deviceText = el('span', 'sup-inv__iso', occ.device || labels.pending || '');
            deviceText.dir = 'auto';
            device.appendChild(deviceText);
            button.appendChild(device);
            // The issue's status is on its row in the list; only a different one is news here.
            var status = String(occ.code || '') !== String(issue.code || '') ? httpBadge(occ.code) : null;
            if (status) button.appendChild(status);
            else button.appendChild(el('span', 'sup-inv__http is-empty', ''));
            button.addEventListener('click', function () {
                var opening = state.occurrence !== occ.id;
                state.occurrence = opening ? occ.id : null;
                paneEl.querySelectorAll('.sup-inv__occ-wrap').forEach(function (row) {
                    var on = row.getAttribute('data-occ-wrap') === String(state.occurrence);
                    row.classList.toggle('is-open', on);
                    var rowButton = row.querySelector('.sup-inv__occ');
                    if (rowButton) {
                        rowButton.classList.toggle('is-selected', on);
                        rowButton.setAttribute('aria-expanded', on ? 'true' : 'false');
                    }
                });
            });
            button.setAttribute('aria-expanded', state.occurrence === occ.id ? 'true' : 'false');
            wrap.appendChild(button);
            wrap.setAttribute('data-occ-wrap', String(occ.id));
            var extra = inspect(issue, occ);
            if (extra) {
                wrap.appendChild(extra);
                if (state.occurrence === occ.id) wrap.classList.add('is-open');
            }
            paneEl.appendChild(wrap);
        });
    }

    function inspect(issue, occ) {
        var body = el('div', 'sup-inv__occ-more-body');
        var text = shortText(issue, occ);
        if (text) {
            var line = el('p');
            var lineText = el('span', 'sup-inv__iso', text);
            lineText.dir = 'auto';
            line.appendChild(lineText);
            body.appendChild(line);
        }
        var raw = rawDetails(occ);
        if (raw) body.appendChild(raw);
        if (!body.childNodes.length) return null;
        var more = el('div', 'sup-inv__occ-more');
        var clip = el('div', 'sup-inv__occ-more-clip');
        clip.appendChild(body);
        more.appendChild(clip);
        return more;
    }

    function rawDetails(occ) {
        occ = occ || {};
        var body = el('div', 'sup-inv__raw-body');
        if (occ.endpoint) body.appendChild(labeledPre(labels.endpoint, occ.endpoint, 'ltr'));
        if (occ.requestBody) body.appendChild(labeledPre(labels.body || labels.exchange, occ.requestBody, 'ltr'));
        if (occ.message) body.appendChild(labeledPre(labels.message, occ.message, 'auto'));
        if (occ.stack) body.appendChild(labeledPre(labels.stack, occ.stack, 'ltr'));
        if (occ.exchange) body.appendChild(labeledPre(labels.exchange, occ.exchange, 'ltr'));
        if (occ.meta) body.appendChild(labeledPre(labels.meta, occ.meta, 'ltr'));
        if (occ.raw) body.appendChild(labeledPre('', occ.raw, 'ltr'));
        if (!body.childNodes.length) return null;
        var details = el('details', 'sup-inv__raw');
        var summary = el('summary');
        summary.appendChild(icon('chevron', 'sup-inv__chev'));
        summary.appendChild(el('span', '', labels.technical || ''));
        var copy = el('button', 'sup-inv__copy');
        copy.type = 'button';
        copy.appendChild(icon('copy'));
        copy.appendChild(document.createTextNode(labels.copy || ''));
        copy.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            copyJson(occ.raw || occ.message || '', copy);
        });
        summary.appendChild(copy);
        details.appendChild(summary);
        details.appendChild(body);
        details.open = true;
        return details;
    }

    function labeledPre(title, text, dir) {
        var wrap = el('div', 'sup-inv__raw-part');
        if (title) wrap.appendChild(el('h3', '', title));
        wrap.appendChild(pre(text, dir));
        return wrap;
    }

    function copyJson(text, button) {
        var done = function () {
            var previous = button.textContent;
            button.textContent = '';
            button.appendChild(icon('copy'));
            button.appendChild(document.createTextNode(labels.copied || previous));
            button.classList.add('is-done');
            window.setTimeout(function () {
                button.textContent = '';
                button.appendChild(icon('copy'));
                button.appendChild(document.createTextNode(previous));
                button.classList.remove('is-done');
            }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
            return;
        }
        done();
    }

    function show(issue, animate) {
        if (!issue) {
            paintEmpty();
            return;
        }
        var fresh = !headEl || !detail.contains(headEl);
        paintHeader(issue);
        paintPane(issue, animate || fresh);
        if (paneEl && (animate || fresh)) paneEl.scrollTop = 0;
    }

    function applyFilters() {
        var previous = state.key;
        var visible = 0;
        var first = null;
        if (list) {
            list.querySelectorAll('.sup-inv__issue').forEach(function (button) {
                var issue = byKey[button.getAttribute('data-key')];
                var ok = !!(issue && accepts(issue));
                setIssueVisible(button, ok);
                if (ok) {
                    visible += 1;
                    if (!first) first = button;
                }
            });
        }
        if (countEl && countEl.textContent !== String(visible)) {
            countEl.textContent = String(visible);
            if (motionOn()) {
                countEl.classList.remove('is-tick');
                void countEl.offsetWidth;
                countEl.classList.add('is-tick');
            }
        }
        if (noMatch) noMatch.hidden = !(visible === 0 && issues.length > 0);
        var selected = state.key && list ? list.querySelector('[data-key="' + state.key + '"]') : null;
        if (!selected || selected.hidden || selected.classList.contains('is-out')) {
            state.key = first ? first.getAttribute('data-key') : null;
            state.occurrence = null;
            state.tab = 'details';
        }
        markSelected();
        var changed = state.key !== previous || !headEl;
        if (changed && detail) show(state.key ? byKey[state.key] : null, true);
        syncUrl();
        renderChips();
        renderBadge();
        syncPicks();
    }

    function select(key) {
        if (!byKey[key] || state.key === key) {
            var button = list.querySelector('[data-key="' + key + '"]');
            if (button) button.scrollIntoView({ block: 'nearest' });
            return;
        }
        state.key = key;
        state.occurrence = null;
        state.tab = 'details';
        markSelected();
        var button = list.querySelector('[data-key="' + key + '"]');
        if (button) button.scrollIntoView({ block: 'nearest' });
        show(byKey[key], true);
        syncUrl();
    }

    function closePops(except) {
        root.querySelectorAll('[data-pop]').forEach(function (pop) {
            if (pop === except) return;
            shutMenu(pop.querySelector('[data-pop-menu]'), pop.querySelector('[data-pop-btn]'));
        });
    }

    function place(pop) {
        var btn = pop.querySelector('[data-pop-btn]');
        var menu = pop.querySelector('[data-pop-menu]');
        if (!btn || !menu) return;
        menu.hidden = false;
        var rect = btn.getBoundingClientRect();
        var width = menu.offsetWidth;
        var height = menu.offsetHeight;
        var rtl = document.documentElement.dir === 'rtl';
        var left = rtl ? rect.right - width : rect.left;
        var top = rect.bottom + 6;
        if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
        if (left < 8) left = 8;
        if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 6);
        menu.style.top = top + 'px';
        menu.style.left = left + 'px';
    }

    root.querySelectorAll('[data-pop]').forEach(function (pop) {
        var btn = pop.querySelector('[data-pop-btn]');
        var menu = pop.querySelector('[data-pop-menu]');
        if (!btn || !menu) return;
        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            var shouldOpen = menu.hidden || !menu.classList.contains('is-open');
            closePops(shouldOpen ? pop : null);
            if (!shouldOpen) {
                shutMenu(menu, btn);
                return;
            }
            openMenu(pop);
        });
        menu.querySelectorAll('.sup-inv__opt').forEach(function (label) {
            label.addEventListener('keydown', function (event) {
                if (event.key !== ' ' && event.key !== 'Enter') return;
                event.preventDefault();
                var input = label.querySelector('input');
                if (input) input.checked = !input.checked;
            });
        });
        var search = menu.querySelector('[data-pop-search]');
        if (search) {
            search.addEventListener('input', function () {
                var query = search.value.trim().toLowerCase();
                menu.querySelectorAll('[data-label]').forEach(function (label) {
                    label.hidden = query !== '' && (label.getAttribute('data-label') || '').indexOf(query) === -1;
                });
            });
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') event.preventDefault();
                else event.stopPropagation();
            });
        }
    });

    root.querySelectorAll('[data-pick]').forEach(function (group) {
        var name = group.getAttribute('data-pick');
        group.querySelectorAll('[data-value]').forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-value') || '';
                setField(name, state[name] === value ? '' : value);
                applyFilters();
            });
        });
    });

    root.querySelectorAll('[data-device-option]').forEach(function (button) {
        button.addEventListener('click', function () {
            setField('device', button.getAttribute('data-device-option') || '');
            applyFilters();
        });
    });

    if (deviceInput) {
        deviceInput.addEventListener('input', function () {
            setField('device', deviceInput.value.trim());
            applyFilters();
        });
        deviceInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') event.preventDefault();
        });
    }

    var clearFilters = root.querySelector('[data-clear-filters]');
    if (clearFilters) {
        clearFilters.addEventListener('click', function () {
            ['severity', 'category', 'integration', 'device', 'http'].forEach(function (key) { setField(key, ''); });
            applyFilters();
            closePops(null);
        });
    }

    var chips = root.querySelector('[data-chips]');
    if (chips) {
        chips.addEventListener('click', function (event) {
            var button = event.target.closest('[data-clear]');
            if (!button) return;
            setField(button.getAttribute('data-clear'), '');
            applyFilters();
        });
    }

    if (list) {
        list.addEventListener('click', function (event) {
            var button = event.target.closest('.sup-inv__issue');
            if (!button || button.hidden) return;
            select(button.getAttribute('data-key'));
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-pop]')) closePops(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closePops(null);
            return;
        }
        var menu = root.querySelector('[data-pop-menu]:not([hidden])');
        if (menu && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            var items = Array.prototype.filter.call(menu.querySelectorAll('[data-menu-item], input, button'), function (item) {
                return !item.hidden && !item.closest('[hidden]');
            });
            if (!items.length) return;
            event.preventDefault();
            var index = items.indexOf(document.activeElement);
            var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
            if (next < 0) next = items.length - 1;
            if (next >= items.length) next = 0;
            items[next].focus();
            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        if (event.altKey || event.metaKey || event.ctrlKey) return;
        var tag = event.target && event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (!list) return;
        var buttons = Array.prototype.filter.call(list.querySelectorAll('.sup-inv__issue'), function (button) {
            return !button.hidden;
        });
        if (!buttons.length) return;
        event.preventDefault();
        var current = -1;
        buttons.forEach(function (button, index) {
            if (button.getAttribute('data-key') === state.key) current = index;
        });
        var move = event.key === 'ArrowDown' ? current + 1 : current - 1;
        if (move < 0) move = buttons.length - 1;
        if (move >= buttons.length) move = 0;
        select(buttons[move].getAttribute('data-key'));
        buttons[move].focus({ preventScroll: true });
    });

    function reposition() {
        root.querySelectorAll('[data-pop]').forEach(function (pop) {
            var menu = pop.querySelector('[data-pop-menu]');
            if (menu && !menu.hidden) place(pop);
        });
    }
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);

    function whenPiece(which, end) {
        var date = form.querySelector('[data-when-date="' + which + '"]');
        var hour = form.querySelector('[data-when-hour="' + which + '"]');
        var day = date ? date.value : '';
        var clock = hour ? hour.value : '';
        if (!day) return '';
        if (!clock) return day;
        return day + ' ' + clock + (end ? ':59' : ':00');
    }

    function composeWhen() {
        var fromDate = form.querySelector('[data-when-date="from"]');
        var fromHour = form.querySelector('[data-when-hour="from"]');
        var toDate = form.querySelector('[data-when-date="to"]');
        var toHour = form.querySelector('[data-when-hour="to"]');
        if (fromHour && fromHour.value && fromDate && toDate && !toDate.value) toDate.value = fromDate.value;
        if (fromHour && fromHour.value && toHour && !toHour.value) toHour.value = fromHour.value;
        var from = form.querySelector('[data-when-value="from"]');
        var to = form.querySelector('[data-when-value="to"]');
        if (from) from.value = whenPiece('from', false);
        if (to) to.value = whenPiece('to', true);
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && submitter.getAttribute('name') === 'range' && submitter.value) return;
            composeWhen();
        });
    }

    root.querySelectorAll('[data-clear-range]').forEach(function (button) {
        button.addEventListener('click', function () {
            var range = form.querySelector('[data-range-field]');
            if (range) range.disabled = true;
            composeWhen();
        });
    });

    var searchInput = form && form.querySelector('.sup-inv__search');
    if (searchInput) {
        state.q = searchInput.value.trim().toLowerCase();
        var searchTimer = 0;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.q = searchInput.value.trim().toLowerCase();
                root.classList.toggle('is-searching', state.q !== '');
                applyFilters();
            }, 70);
        });
    }

    var refresh = root.querySelector('[data-refresh]');
    if (refresh) {
        refresh.addEventListener('click', function () {
            root.classList.add('is-loading');
            window.location.reload();
        });
    }

    if (!list || !detail) return;

    loadLogos();
    applyFilters();
})();
