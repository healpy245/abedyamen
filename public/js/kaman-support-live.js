(function () {
    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function t(dict, key, fallback) {
        return (dict && dict[key]) || fallback || key;
    }

    function badge(text, tone) {
        var tones = {
            live: 'bg-emerald-50 text-emerald-800 border-emerald-200',
            off: 'bg-slate-50 text-slate-700 border-slate-200',
            warn: 'bg-amber-50 text-amber-800 border-amber-200',
            kind: 'bg-[#fff4ea] text-[#9a3412] border-[#f3d2b5]',
        };
        return '<span class="kaman-badge ' + (tones[tone] || tones.off) + '">' + escapeHtml(text) + '</span>';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function printerMac(value) {
        var hex = String(value || '').toLowerCase().replace(/[^0-9a-f]/g, '');
        if (hex.length < 12) return '';
        return hex.slice(0, 12).match(/.{2}/g).join(':');
    }

    function printerRaw(printer) {
        return String((printer && (printer.ip || printer.address || printer.host)) || '').trim();
    }

    function printerHost(printer) {
        var raw = printerRaw(printer);
        var match = raw.match(/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d+))?$/);
        if (match) return match[1];
        var colon = raw.lastIndexOf(':');
        if (colon > 0 && /^\d+$/.test(raw.slice(colon + 1))) return raw.slice(0, colon);
        return raw;
    }

    function printerPort(printer) {
        var raw = printerRaw(printer);
        var match = raw.match(/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/);
        if (match) return Number(match[2]) || 9100;
        return Number(printer && printer.port ? printer.port : 9100) || 9100;
    }

    function samePrinter(a, b) {
        if (!a || !b) return false;
        var aMac = printerMac(a.mac);
        var bMac = printerMac(b.mac);
        if (aMac && bMac && aMac === bMac) return true;
        var host = printerHost(a);
        return host !== '' && host === printerHost(b);
    }

    function isSavedRow(row) {
        if (!row) return false;
        if (row.saved === false || row.saved === 'false') {
            return !!(row.receipt || row.sticker || row.kitchen);
        }
        return true;
    }

    function uniquePrinters(rows) {
        var out = [];
        (rows || []).forEach(function (printer) {
            if (!printer || !(printer.ip || printer.address)) return;
            for (var i = 0; i < out.length; i++) {
                if (samePrinter(out[i], printer)) {
                    out[i] = Object.assign({}, out[i], printer, {
                        mac: printerMac(printer.mac) || printerMac(out[i].mac) || out[i].mac || '',
                        receipt: !!(out[i].receipt || printer.receipt),
                        sticker: !!(out[i].sticker || printer.sticker),
                        kitchen: !!(out[i].kitchen || printer.kitchen),
                        stations: uniqueStrings([].concat(out[i].stations || [], printer.stations || [])),
                        stationIds: uniqueStrings([].concat(out[i].stationIds || [], printer.stationIds || [])),
                        saved: out[i].saved !== false || printer.saved !== false,
                    });
                    return;
                }
            }
            out.push(printer);
        });
        return out;
    }

    function uniqueStrings(values) {
        var seen = {};
        return (values || []).filter(function (value) {
            var key = String(value || '').trim();
            if (!key || seen[key]) return false;
            seen[key] = true;
            return true;
        });
    }

    function splitLayout(device) {
        var saved = uniquePrinters((device.printers || []).filter(isSavedRow));
        var discovered = uniquePrinters((device.discovered || []).concat((device.printers || []).filter(function (row) {
            return row && !isSavedRow(row);
        }))).filter(function (printer) {
            return !saved.some(function (known) { return samePrinter(known, printer); });
        });
        return { printers: saved, discovered: discovered };
    }

    function printerKey(printer) {
        return [printer.ip || '', printerPort(printer), printerMac(printer.mac)].join('|');
    }

    function printerTitle(printer, saved, i18n) {
        if (!saved) return t(i18n, 'new_printer', 'New printer');
        var parts = [];
        if (printer.receipt) parts.push(t(i18n, 'role_receipt', 'Receipt'));
        if (printer.sticker) parts.push(t(i18n, 'role_sticker', 'Sticker'));
        if (printer.kitchen) {
            var stations = printer.stations || [];
            parts = parts.concat(stations.length ? stations : [t(i18n, 'role_kitchen', 'Kitchen')]);
        }
        return parts.length ? parts.join(' · ') : t(i18n, 'printer_untitled', 'Printer');
    }

    function snapshot(value) {
        try { return JSON.stringify(value); } catch (e) { return String(value); }
    }

    function postJson(url, csrf, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: res.ok };
            });
        });
    }

    function connectFeed(urlOrFn, onState) {
        var timer = null;
        var stopped = false;

        function currentUrl() {
            return typeof urlOrFn === 'function' ? urlOrFn() : urlOrFn;
        }

        function tick() {
            if (stopped) return;
            fetch(currentUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    onState(json);
                    var wait = Number((json.device && json.device.pollMs) || json.pollMs || 1000);
                    timer = setTimeout(tick, Math.max(700, Math.min(wait, 2000)));
                })
                .catch(function () {
                    timer = setTimeout(tick, 2000);
                });
        }

        tick();
        return {
            stop: function () {
                stopped = true;
                if (timer) clearTimeout(timer);
            },
        };
    }

    function logTone(level) {
        if (level === 'error') return { bg: 'bg-rose-50', text: 'text-rose-800', border: 'border-rose-200' };
        if (level === 'warn' || level === 'warning') return { bg: 'bg-amber-50', text: 'text-amber-800', border: 'border-amber-200' };
        if (level === 'debug') return { bg: 'bg-slate-50', text: 'text-slate-600', border: 'border-slate-200' };
        return { bg: 'bg-sky-50', text: 'text-sky-800', border: 'border-sky-200' };
    }

    function isFailedApi(log) {
        return !!(log && (log.failed || (log.level === 'error' && (log.source === 'api' || String(log.message || '').indexOf('API ') === 0))));
    }

    function renderLogRow(log, deviceUrl) {
        var tone = logTone(log.level);
        var failed = isFailedApi(log);
        var device = log.device;
        var context = log.context && typeof log.context === 'object' ? JSON.stringify(log.context, null, 2) : '';
        var deviceHtml = '';
        if (device && deviceUrl) {
            var href = deviceUrl.replace('__ID__', String(device.id));
            deviceHtml = '<a href="' + href + '" class="font-medium text-[#c2410c] hover:underline">' +
                escapeHtml(device.name || '') +
                (device.kind ? ' · ' + escapeHtml(device.kind) : '') +
                (device.cashier ? ' · ' + escapeHtml(device.cashier) : '') +
                '</a>';
        }
        return '<li class="rounded-md border ' + tone.border + ' ' + tone.bg + ' px-3 py-2 text-sm' +
            (failed ? ' ring-1 ring-rose-300' : '') + '"' + (failed ? ' data-failed-api' : '') + '>' +
            '<div class="flex flex-wrap items-center gap-2 text-xs ' + tone.text + '">' +
            '<span class="font-semibold uppercase">' + escapeHtml(log.level || '') + '</span>' +
            '<span>' + escapeHtml(log.source || '') + '</span>' +
            (failed ? '<span class="rounded bg-rose-100 px-1.5 py-0.5 font-semibold uppercase">API</span>' : '') +
            '<span>' + escapeHtml(log.at || '') + '</span>' +
            deviceHtml +
            '</div>' +
            '<pre class="mt-1 whitespace-pre-wrap break-words text-[#2b1e11]">' + escapeHtml(log.message || '') + '</pre>' +
            (context ? '<pre class="mt-1 whitespace-pre-wrap break-words text-xs text-[#7c6a56]">' + escapeHtml(context) + '</pre>' : '') +
            '</li>';
    }

    function withQuery(base, params) {
        var url = new URL(base, window.location.origin);
        Object.keys(params || {}).forEach(function (key) {
            var value = params[key];
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        });
        return url.toString();
    }

    function currentLogFilters(root) {
        var search = $('[data-log-search]', root);
        var level = $('[data-log-level]', root);
        var source = $('[data-log-source]', root);
        var restaurant = $('[data-failed-restaurant]', root);
        var type = $('[data-failed-type]', root);
        var from = $('[data-log-from]', root);
        var to = $('[data-log-to]', root);
        return {
            view: root.getAttribute('data-view') || '',
            q: search ? search.value.trim() : '',
            level: level ? level.value : '',
            source: source ? source.value : '',
            restaurant: restaurant ? restaurant.value : '',
            type: type ? type.value : '',
            from: from ? from.value : '',
            to: to ? to.value : '',
        };
    }

    function paintHints(root, hints, i18n) {
        var box = $('[data-log-hints]', root);
        if (!box) return;
        var failed = Number((hints && hints.failedApi) || 0);
        var tokens = (hints && hints.failedHints) || [];
        var chip = $('[data-failed-chip]', box);
        var count = $('[data-failed-count]', box);
        if (chip) chip.classList.toggle('hidden', failed < 1 && tokens.length < 1);
        if (count) count.textContent = String(failed || tokens.length);
        var existing = {};
        box.querySelectorAll('[data-hint-chip]').forEach(function (el) {
            existing[el.getAttribute('data-hint-chip')] = el;
        });
        tokens.forEach(function (token) {
            if (existing[token]) {
                delete existing[token];
                return;
            }
            var a = document.createElement('a');
            a.className = 'kaman-badge border-rose-200 bg-white text-rose-800';
            a.setAttribute('data-hint-chip', token);
            a.textContent = token;
            var url = new URL(window.location.href);
            if (!root.hasAttribute('data-failed-board')) {
                url.searchParams.set('view', 'api');
            }
            url.searchParams.set('q', token);
            a.href = url.toString();
            box.appendChild(a);
        });
        Object.keys(existing).forEach(function (token) {
            existing[token].remove();
        });
    }

    function paintLogs(root, logs, deviceUrl, i18n) {
        var list = $('[data-logs]', root);
        var empty = $('[data-logs-empty]', root);
        if (!list) return;
        var rows = logs || [];
        if (empty) empty.hidden = rows.length > 0;
        list.innerHTML = rows.map(function (log) {
            return renderLogRow(log, deviceUrl);
        }).join('');
        if (!rows.length && empty) {
            empty.textContent = t(i18n, 'no_logs', empty.textContent || 'No logs yet.');
        }
    }

    function renderBoard(root) {
        var feed = root.getAttribute('data-feed');
        var deviceUrl = root.getAttribute('data-device-url') || '';
        var i18n = JSON.parse(root.getAttribute('data-i18n') || '{}');
        var liveBox = $('[data-live-grid]', root);
        var recentBox = $('[data-recent-grid]', root);
        var liveCount = $('[data-live-count]', root);
        var liveEmpty = $('[data-live-empty]', root);
        var recentEmpty = $('[data-recent-empty]', root);

        function failedLabel(count) {
            return t(i18n, 'failed_on_device', ':count failed').replace(':count', String(count));
        }

        function card(device) {
            var href = deviceUrl.replace('__ID__', String(device.id));
            var failed = Number(device.failedCount || 0);
            return (
                '<a href="' + href + '" data-device-id="' + escapeHtml(device.id) + '" class="kaman-card kaman-card--compact block space-y-2 transition hover:bg-[#fff8f0] hover:shadow-sm">' +
                '<div class="flex flex-wrap items-start justify-between gap-2">' +
                '<div><div data-card-name class="font-semibold text-[#2b1e11]">' + escapeHtml(device.restaurantName || device.restaurant) + '</div>' +
                '<div data-card-cashier class="text-xs text-[#7c6a56]">' + escapeHtml(device.cashierName || '—') + (device.role ? ' · ' + escapeHtml(device.role) : '') + '</div></div>' +
                '<div class="flex flex-wrap gap-1">' +
                '<span data-online>' + badge(device.online ? t(i18n, 'online', 'Live') : t(i18n, 'offline', 'Offline'), device.online ? 'live' : 'off') + '</span>' +
                badge(t(i18n, 'kind_' + device.kind, device.kind), 'kind') +
                '</div></div>' +
                '<div data-card-host class="font-mono text-xs text-[#7c6a56] break-all">' + escapeHtml(device.hostname || device.deviceId) + '</div>' +
                '<div data-failed class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs text-rose-800"' + (failed ? '' : ' hidden') + '>' +
                '<div class="flex flex-wrap items-center gap-2">' +
                '<span data-failed-count class="font-semibold">' + escapeHtml(failedLabel(failed)) + '</span>' +
                '<span data-failed-source>' + escapeHtml(device.failedSource || '') + '</span>' +
                '<span data-failed-at>' + escapeHtml(device.failedAt || '') + '</span></div>' +
                '<div data-failed-message class="mt-0.5 line-clamp-2 break-words">' + escapeHtml(device.failedMessage || '') + '</div></div>' +
                '<div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[#7c6a56]">' +
                '<span data-card-meta>' + escapeHtml((device.platform || '—') + ' · ' + (device.appVersion || '—')) + '</span>' +
                '<span>' + escapeHtml((device.printers || []).length + ' ' + t(i18n, 'printers', 'printers')) +
                ' · <span data-last-clock>' + escapeHtml(device.lastSeenClock || '') + '</span> ' +
                '<span data-last-seen>' + escapeHtml(device.lastSeen || '') + '</span></span>' +
                '</div></a>'
            );
        }

        function setText(el, sel, value) {
            var node = el.querySelector(sel);
            if (node) node.textContent = value == null ? '' : String(value);
        }

        function fillCard(el, device) {
            setText(el, '[data-card-name]', device.restaurantName || device.restaurant);
            setText(el, '[data-card-cashier]', (device.cashierName || '—') + (device.role ? ' · ' + device.role : ''));
            setText(el, '[data-card-host]', device.hostname || device.deviceId);
            setText(el, '[data-card-meta]', (device.platform || '—') + ' · ' + (device.appVersion || '—'));
            setText(el, '[data-last-clock]', device.lastSeenClock || '');
            setText(el, '[data-last-seen]', device.lastSeen || '');
            var online = el.querySelector('[data-online]');
            if (online) {
                online.innerHTML = badge(device.online ? t(i18n, 'online', 'Live') : t(i18n, 'offline', 'Offline'), device.online ? 'live' : 'off');
            }
            var failed = Number(device.failedCount || 0);
            var failBox = el.querySelector('[data-failed]');
            if (failBox) {
                failBox.hidden = failed < 1;
                setText(el, '[data-failed-count]', failedLabel(failed));
                setText(el, '[data-failed-source]', device.failedSource || '');
                setText(el, '[data-failed-at]', device.failedAt || '');
                setText(el, '[data-failed-message]', device.failedMessage || '');
            }
        }

        function upsertCards(box, devices) {
            if (!box) return;
            var seen = {};
            devices.forEach(function (device) {
                var id = String(device.id);
                seen[id] = true;
                var el = box.querySelector('[data-device-id="' + id + '"]');
                if (el) {
                    fillCard(el, device);
                    return;
                }
                box.insertAdjacentHTML('beforeend', card(device));
            });
            box.querySelectorAll('[data-device-id]').forEach(function (el) {
                if (!seen[el.getAttribute('data-device-id')]) el.remove();
            });
        }

        var lastLogs = '';
        var lastHints = '';
        connectFeed(function () {
            return withQuery(feed, currentLogFilters(root));
        }, function (json) {
            var live = json.live || [];
            var recent = json.recent || [];
            if (liveCount) liveCount.textContent = String(live.length);
            upsertCards(liveBox, live);
            upsertCards(recentBox, recent);
            if (liveEmpty) liveEmpty.hidden = live.length > 0;
            if (recentEmpty) recentEmpty.hidden = recent.length > 0;
            var logsKey = snapshot(json.logs || []);
            if (logsKey !== lastLogs) {
                lastLogs = logsKey;
                paintLogs(root, json.logs || [], deviceUrl, i18n);
            }
            var hintsKey = snapshot(json.hints || {});
            if (hintsKey !== lastHints) {
                lastHints = hintsKey;
                paintHints(root, json.hints || {}, i18n);
            }
        });
    }

    function renderConsole(root) {
        var feed = root.getAttribute('data-feed');
        var commandUrl = root.getAttribute('data-command');
        var csrf = root.getAttribute('data-csrf');
        var i18n = JSON.parse(root.getAttribute('data-i18n') || '{}');
        var pending = {};
        var lastCommandId = '';

        function statusEl() { return $('[data-live-status]', root); }
        function printersEl() { return $('[data-printers]', root); }
        function discoveredEl() { return $('[data-discovered]', root); }
        function scanEl() { return $('[data-scan]', root); }
        function jobsEl() { return $('[data-jobs]', root); }
        function logsEl() { return $('[data-logs]', root); }

        function setStatus(text, tone) {
            var el = statusEl();
            if (!el) return;
            el.textContent = text;
            el.className = 'text-sm ' + (tone === 'bad' ? 'text-rose-700' : tone === 'ok' ? 'text-emerald-700' : 'text-[#7c6a56]');
        }

        function send(name, args) {
            var key = name + JSON.stringify(args || {});
            if (pending[key]) return;
            pending[key] = true;
            setStatus(t(i18n, 'sending', 'Sending to tablet…'));
            postJson(commandUrl, csrf, { name: name, args: args || {} })
                .then(function (json) {
                    pending[key] = false;
                    if (json && json.id) lastCommandId = json.id;
                    if (json && json.ok === false) {
                        setStatus(json.message || t(i18n, 'busy', 'Tablet is busy'), 'bad');
                        return;
                    }
                    setStatus(t(i18n, 'queued', 'Tablet is running it now'), 'ok');
                })
                .catch(function () {
                    pending[key] = false;
                    setStatus(t(i18n, 'send_failed', 'Could not reach workspace'), 'bad');
                });
        }

        function chip(label, selected, onClick) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kaman-button-ghost kaman-button--sm ' + (selected ? 'ring-1 ring-[#e8b086]' : '');
            btn.textContent = label;
            btn.addEventListener('click', onClick);
            return btn;
        }

        function printerCard(printer, kitchenTypes, saved) {
            var box = document.createElement('div');
            box.className = 'bg-white px-3 py-3 space-y-2';
            var issue = printer.issue && printer.issue !== 'none';
            var reachable = printer.reachable;
            var warn = issue || reachable === false;
            var status = '';
            if (reachable === true) {
                status = badge(t(i18n, 'connected', 'Connected'), 'live');
            } else if (reachable === false || issue) {
                status = badge(t(i18n, 'disconnected', 'Not connected'), 'warn');
            }
            box.innerHTML =
                '<div class="flex flex-wrap items-start justify-between gap-2">' +
                '<div><div class="font-semibold text-[#2b1e11]">' + escapeHtml(printerTitle(printer, saved, i18n)) + '</div>' +
                '<div class="text-xs font-mono text-[#7c6a56]">' + escapeHtml(printer.ip || printer.address || '') + '</div></div>' +
                status +
                '</div>';
            var actions = document.createElement('div');
            actions.className = 'flex flex-wrap gap-2';
            if (saved) {
                actions.appendChild(chip(t(i18n, 'role_receipt', 'Receipt'), !!printer.receipt, function () {
                    send('set_role', { ip: printer.ip, port: printer.port, mac: printer.mac || '', role: 'receipt', enabled: !printer.receipt });
                }));
                actions.appendChild(chip(t(i18n, 'role_sticker', 'Sticker'), !!printer.sticker, function () {
                    send('set_role', { ip: printer.ip, port: printer.port, mac: printer.mac || '', role: 'sticker', enabled: !printer.sticker });
                }));
                actions.appendChild(chip(t(i18n, 'role_kitchen', 'Kitchen'), !!printer.kitchen, function () {
                    send('set_role', { ip: printer.ip, port: printer.port, mac: printer.mac || '', role: 'kitchen', enabled: !printer.kitchen });
                }));
                var test = document.createElement('button');
                test.type = 'button';
                test.className = 'kaman-button kaman-button--sm';
                test.textContent = t(i18n, 'print_test', 'Test print');
                test.addEventListener('click', function () {
                    send('test_print', { ip: printer.ip, port: printer.port, printer: printer.ip, mac: printer.mac || '' });
                });
                actions.appendChild(test);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'kaman-button-ghost kaman-button--sm';
                remove.textContent = t(i18n, 'remove', 'Remove');
                remove.addEventListener('click', function () {
                    send('forget_printer', { ip: printer.ip, port: printer.port, mac: printer.mac || '' });
                });
                actions.appendChild(remove);
            } else {
                var add = document.createElement('button');
                add.type = 'button';
                add.className = 'kaman-button kaman-button--sm';
                add.textContent = t(i18n, 'add', 'Add');
                add.addEventListener('click', function () {
                    send('remember_printer', { ip: printer.ip, port: printer.port, mac: printer.mac || '' });
                });
                actions.appendChild(add);
            }
            box.appendChild(actions);
            if (saved && printer.kitchen && kitchenTypes && kitchenTypes.length) {
                var wrap = document.createElement('div');
                wrap.className = 'rounded-lg border border-[#eadfce] bg-[#fff8f0] px-3 py-2 space-y-2';
                wrap.innerHTML = '<div class="text-xs font-semibold text-[#7c6a56]">' + escapeHtml(t(i18n, 'choose_kitchen', 'Which kitchen?')) + '</div>';
                var types = document.createElement('div');
                types.className = 'flex flex-wrap gap-2';
                kitchenTypes.forEach(function (station) {
                    var selected = (printer.stations || []).indexOf(station.name) !== -1 || (printer.stationIds || []).indexOf(station.id) !== -1;
                    types.appendChild(chip(station.name, selected, function () {
                        send('set_kitchen_type', {
                            ip: printer.ip,
                            port: printer.port,
                            mac: printer.mac || '',
                            station: station.name,
                            stationId: station.id,
                            selected: !selected,
                        });
                    }));
                });
                wrap.appendChild(types);
                box.appendChild(wrap);
            }
            if (warn) {
                var hint = document.createElement('p');
                hint.className = 'text-xs font-semibold text-amber-800';
                hint.textContent = t(i18n, 'not_detected', 'This printer is not answering on the restaurant LAN.');
                box.appendChild(hint);
            }
            box.dataset.key = printerKey(printer);
            return box;
        }

        function paintTable(el, printers, kitchenTypes, saved) {
            if (!el) return;
            el.innerHTML = '';
            printers.forEach(function (printer, index) {
                var card = printerCard(printer, kitchenTypes, saved);
                if (index) card.classList.add('border-t', 'border-[#eadfce]');
                el.appendChild(card);
            });
        }

        var lastLayout = '';
        var lastLan = '';
        var lanSelected = '';
        var lastJobs = '';
        var lastLogs = '';
        var lastHints = '';

        function paintLan(device) {
            var host = $('[data-lan-map]', root);
            if (!host) return;
            var map = device.lan || {};
            var nodes = Array.isArray(map.nodes) ? map.nodes : [];
            var links = Array.isArray(map.links) ? map.links : [];
            var scan = device.lanScan || {};
            var bar = $('[data-lan-scan]', root);
            if (bar) {
                bar.hidden = !scan.running;
                if (scan.running && scan.total) {
                    bar.value = scan.done;
                    bar.max = scan.total;
                }
            }
            var facts = $('[data-lan-facts]', root);
            if (facts) {
                var parts = [];
                if (map.subnet) parts.push(t(i18n, 'lan_subnet', 'Subnet') + ' ' + map.subnet);
                if (map.gateway) parts.push(t(i18n, 'lan_gateway', 'Gateway') + ' ' + map.gateway);
                if (nodes.length) parts.push(t(i18n, 'lan_devices', 'Devices') + ' ' + nodes.length);
                if (map.method) parts.push(map.method);
                facts.textContent = parts.join(' · ');
            }
            var key = snapshot({ nodes: nodes, links: links, selected: lanSelected, scan: scan });
            if (key === lastLan) return;
            lastLan = key;
            if (!nodes.length) {
                host.innerHTML = '<p class="px-4 py-8 text-center text-sm text-[#7c6a56]">' + escapeHtml(t(i18n, 'lan_empty', 'No map yet.')) + '</p>';
                var emptyDetail = $('[data-lan-detail]', root);
                if (emptyDetail) emptyDetail.innerHTML = '';
                return;
            }
            var cardW = 132;
            var cardH = 64;
            var gapX = 18;
            var gapY = 36;
            var cols = Math.max(1, Math.min(4, nodes.filter(function (node) { return node.role !== 'tablet' && node.role !== 'gateway'; }).length || 1));
            var width = Math.max(360, cols * (cardW + gapX) + 24);
            var points = {};
            var y = 16;
            function placeRow(row) {
                var rowW = row.length * cardW + (row.length - 1) * gapX;
                var x = Math.max(12, (width - rowW) / 2);
                row.forEach(function (node) {
                    points[node.id || node.ip] = { x: x, y: y };
                    x += cardW + gapX;
                });
                y += cardH + gapY;
            }
            placeRow(nodes.filter(function (node) { return node.role === 'gateway'; }));
            placeRow(nodes.filter(function (node) { return node.role === 'tablet'; }));
            var rest = nodes.filter(function (node) { return node.role !== 'gateway' && node.role !== 'tablet'; });
            for (var i = 0; i < rest.length; i += cols) placeRow(rest.slice(i, i + cols));
            var height = y;
            var colors = {
                tablet: '#f47a2e',
                gateway: '#0369a1',
                printer: '#059669',
                camera: '#d97706',
                computer: '#7c6a56',
                web: '#c2410c',
                other: '#a78a6c'
            };
            function center(id) {
                var point = points[id];
                if (!point) return null;
                return { x: point.x + cardW / 2, y: point.y + cardH / 2 };
            }
            var svg = '<svg width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">';
            links.forEach(function (link) {
                var a = center(link.from);
                var b = center(link.to);
                if (!a || !b) return;
                var dash = link.kind === 'missing' ? ' stroke-dasharray="5 4"' : '';
                svg += '<line x1="' + a.x + '" y1="' + a.y + '" x2="' + b.x + '" y2="' + b.y + '" stroke="#e8b086" stroke-width="2"' + dash + '></line>';
            });
            svg += '</svg>';
            host.innerHTML = svg;
            host.style.position = 'relative';
            nodes.forEach(function (node) {
                var id = node.id || node.ip;
                var point = points[id];
                if (!point) return;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.style.position = 'absolute';
                btn.style.left = point.x + 'px';
                btn.style.top = point.y + 'px';
                btn.style.width = cardW + 'px';
                btn.style.height = cardH + 'px';
                var selected = lanSelected === id;
                btn.className = 'rounded-xl border bg-white px-2 py-1 text-left ' + (selected ? 'border-[#f47a2e] ring-2 ring-[#f47a2e]' : 'border-[#eadfce]');
                var role = t(i18n, 'lan_role_' + (node.role || 'other'), node.role || 'Device');
                var offline = node.online === false ? ' · ' + t(i18n, 'lan_offline', 'Not answering') : '';
                btn.innerHTML = '<div class="truncate text-[11px] font-semibold" style="color:' + (colors[node.role] || colors.other) + '">' + escapeHtml(role) + escapeHtml(offline) + '</div>' +
                    '<div class="truncate font-mono text-xs text-[#2b1e11]">' + escapeHtml(node.ip || '') + '</div>' +
                    '<div class="truncate text-[10px] text-[#7c6a56]">' + escapeHtml(node.name || node.vendor || node.mac || '') + '</div>';
                btn.addEventListener('click', function () {
                    lanSelected = id;
                    lastLan = '';
                    paintLan(device);
                });
                host.appendChild(btn);
            });
            var detail = $('[data-lan-detail]', root);
            var chosen = nodes.find(function (node) { return (node.id || node.ip) === lanSelected; }) || nodes[0];
            if (!detail || !chosen) return;
            var portText = (chosen.ports || []).map(function (port) {
                return (port.port || '') + (port.name ? ' ' + port.name : '');
            }).join(', ');
            var printer = chosen.printer || {};
            var jobs = [];
            if (printer.receipt) jobs.push('receipt');
            if (printer.sticker) jobs.push('sticker');
            if (printer.kitchen) jobs.push('kitchen');
            detail.innerHTML = '<div class="rounded-xl border border-[#eadfce] bg-white px-3 py-2">' +
                '<div class="font-semibold">' + escapeHtml(chosen.ip || '') + '</div>' +
                '<div class="mt-1 text-xs text-[#7c6a56]">' +
                escapeHtml([chosen.mac, chosen.vendor, chosen.seen, chosen.iface, portText, jobs.join(' · ')].filter(Boolean).join(' · ')) +
                '</div></div>';
        }

        function paint(json) {
            var device = json.device || {};
            var online = $('[data-online]', root);
            if (online) {
                online.innerHTML = badge(device.online ? t(i18n, 'online', 'Live') : t(i18n, 'offline', 'Offline'), device.online ? 'live' : 'off');
            }
            var seen = $('[data-last-seen]', root);
            if (seen) seen.textContent = device.lastSeen || '—';
            var scan = device.scan || {};
            var bar = scanEl();
            if (bar) {
                bar.hidden = !scan.running;
                if (scan.running && scan.total) {
                    bar.value = scan.done;
                    bar.max = scan.total;
                }
            }
            var layout = splitLayout(device);
            var printers = layout.printers;
            var discovered = layout.discovered;
            var layoutKey = snapshot({ printers: printers, discovered: discovered, types: device.kitchenTypes || [], scan: scan });
            var list = printersEl();
            var found = discoveredEl();
            var savedBlock = $('[data-saved-block]', root);
            var foundBlock = $('[data-found-block]', root);
            var empty = $('[data-printers-empty]', root);
            var scanned = !scan.running && Number(scan.total || 0) > 0;
            if (layoutKey !== lastLayout) {
                lastLayout = layoutKey;
                if (empty) {
                    empty.hidden = printers.length > 0 || discovered.length > 0;
                    if (!empty.hidden) {
                        empty.textContent = scanned
                            ? t(i18n, 'no_printers_found', 'No printers found')
                            : t(i18n, 'no_printers', 'No printers on this tablet yet.');
                    }
                }
                if (savedBlock) savedBlock.hidden = printers.length === 0;
                if (list) paintTable(list, printers, device.kitchenTypes || [], true);
                if (foundBlock) foundBlock.hidden = discovered.length === 0;
                if (found) paintTable(found, discovered, [], false);
            }
            paintLan(device);
            var jobsKey = snapshot(json.commands || []);
            var jobs = jobsEl();
            if (jobs && jobsKey !== lastJobs) {
                lastJobs = jobsKey;
                jobs.innerHTML = (json.commands || []).map(function (command) {
                    var summary = command.result && (command.result.summary || command.result.message);
                    return '<li class="rounded-md border border-[#eadfce] px-3 py-2 text-sm">' +
                        '<div class="flex flex-wrap gap-2 text-xs text-[#a78a6c]">' +
                        '<span class="font-semibold text-[#c2410c]">' + escapeHtml(command.label || command.name) + '</span>' +
                        '<span>' + escapeHtml(command.status || '') + '</span></div>' +
                        (summary ? '<div class="mt-1 text-[#2b1e11]">' + escapeHtml(summary) + '</div>' : '') +
                        '</li>';
                }).join('');
            }
            var logsKey = snapshot(json.logs || []);
            if (logsEl() && logsKey !== lastLogs) {
                lastLogs = logsKey;
                paintLogs(root, json.logs || [], null, i18n);
            }
            var hintsKey = snapshot(json.hints || {});
            if (hintsKey !== lastHints) {
                lastHints = hintsKey;
                paintHints(root, json.hints || {}, i18n);
            }
            if (lastCommandId) {
                var match = (json.commands || []).find(function (command) { return command.id === lastCommandId; });
                if (match && match.status && match.status !== 'queued' && match.status !== 'sent') {
                    var ok = match.status === 'done';
                    setStatus((match.result && match.result.summary) || match.status, ok ? 'ok' : 'bad');
                    lastCommandId = '';
                }
            }
        }

        $('[data-scan-lan]', root)?.addEventListener('click', function () { send('scan_lan', {}); });
        $('[data-map-lan]', root)?.addEventListener('click', function () { send('scan_lan', { map: true }); });
        $('[data-sync-api]', root)?.addEventListener('click', function () { send('sync_kitchen_api', {}); });
        $('[data-retry]', root)?.addEventListener('click', function () { send('retry_failed_prints', {}); });

        connectFeed(function () {
            return withQuery(feed, currentLogFilters(root));
        }, paint);
    }

    function renderFailedBoard(root) {
        var feed = root.getAttribute('data-feed');
        var deviceUrl = root.getAttribute('data-device-url') || '';
        var i18n = JSON.parse(root.getAttribute('data-i18n') || '{}');
        var lastLogs = '';
        var lastHints = '';

        connectFeed(function () {
            return withQuery(feed, currentLogFilters(root));
        }, function (json) {
            var total = $('[data-failed-total]', root);
            if (total) total.textContent = String(json.count || (json.logs || []).length);
            var logsKey = snapshot(json.logs || []);
            if (logsKey !== lastLogs) {
                lastLogs = logsKey;
                paintLogs(root, json.logs || [], deviceUrl, i18n);
            }
            var hintsKey = snapshot(json.hints || {});
            if (hintsKey !== lastHints) {
                lastHints = hintsKey;
                paintHints(root, json.hints || {}, i18n);
            }
        });
    }

    document.querySelectorAll('[data-live-board]').forEach(renderBoard);
    document.querySelectorAll('[data-live-console]').forEach(renderConsole);
    document.querySelectorAll('[data-failed-board]').forEach(renderFailedBoard);
})();
