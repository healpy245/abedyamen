(function () {
    var boot = window.SUP_BOARD || {};
    var dataNode = document.getElementById('sup-restaurants');
    var grid = document.querySelector('[data-sup-grid]');
    if (!dataNode || !grid) return;

    var restaurants = [];
    try { restaurants = JSON.parse(dataNode.textContent || '[]'); } catch (e) { restaurants = []; }
    var byId = {};
    restaurants.forEach(function (row) { byId[row.id] = row; });

    var search = document.querySelector('[data-sup-search]');
    var sort = document.querySelector('[data-sup-sort]');
    var statusFilter = 'recent';
    var modal = document.getElementById('sup-modal');
    var title = document.getElementById('sup-modal-title');
    var body = document.getElementById('sup-modal-body');
    var current = null;
    var tab = 'overview';

    var searchLaidOut = false;

    function apply() {
        var q = (search && search.value || '').trim().toLowerCase();
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.sup-card'));
        var rank = { warning: 0, offline: 1, unknown: 2, healthy: 3 };
        var before = new Map();
        if (searchLaidOut) {
            cards.forEach(function (card) {
                if (!card.hidden) before.set(card, card.getBoundingClientRect());
            });
        }
        cards.sort(function (a, b) {
            var login = (a.getAttribute('data-open') === '_pending' ? 1 : 0) - (b.getAttribute('data-open') === '_pending' ? 1 : 0);
            if (login) return login;
            return (rank[a.getAttribute('data-status')] || 9) - (rank[b.getAttribute('data-status')] || 9);
        });
        cards.forEach(function (card) {
            var loginCard = card.getAttribute('data-open') === '_pending';
            var statusOk = loginCard
                || statusFilter === 'all'
                || (statusFilter === 'recent' && card.getAttribute('data-recent') === '1')
                || card.getAttribute('data-status') === statusFilter;
            var show = statusOk && (!q || (card.getAttribute('data-name') || '').toLowerCase().indexOf(q) !== -1);
            var wasHidden = card.hidden;
            card.classList.toggle('is-hidden', !show);
            card.hidden = !show;
            if (searchLaidOut && show && wasHidden) {
                card.classList.remove('is-arriving');
                void card.offsetWidth;
                card.classList.add('is-arriving');
            }
            grid.appendChild(card);
        });
        if (!searchLaidOut) {
            searchLaidOut = true;
            return;
        }
        cards.forEach(function (card) {
            if (card.hidden) return;
            var start = before.get(card);
            if (!start) return;
            var end = card.getBoundingClientRect();
            var dx = start.left - end.left;
            var dy = start.top - end.top;
            if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
            card.style.transition = 'none';
            card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
            window.requestAnimationFrame(function () {
                card.style.transition = 'transform .45s cubic-bezier(.2,.8,.2,1)';
                card.style.transform = '';
                window.setTimeout(function () { card.style.transition = ''; }, 480);
            });
        });
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function productLabel(key) {
        return key ? (boot.labels[key] || '') : '';
    }

    function productLine(devices) {
        var online = devices.filter(function (device) { return device.online || device.status === 'warning'; });
        var source = online.length ? online : devices;
        var keys = {};
        source.forEach(function (device) {
            if (device.product) keys[device.product] = true;
        });
        return ['mall', 'lite'].filter(function (key) { return keys[key]; }).map(function (key) {
            return boot.labels[key] || '';
        }).filter(Boolean).join(' · ');
    }

    function deviceLine(device) {
        var product = productLabel(device.product);
        return '<div class="sup-device"><div><strong>' + esc(device.name) + '</strong><div class="sup-meta">'
            + esc(device.kind) + ' · ' + esc(device.platform)
            + (product ? ' · ' + esc(product) : '')
            + ' · <span dir="ltr">' + esc(device.version || device.appVersion || '') + '</span><br>'
            + esc(device.deviceId) + ' · ' + esc(device.lastSeen || boot.labels.never) + '</div></div><span class="sup-badge is-' + esc(device.status) + '">'
            + esc(boot.labels[device.status] || device.status) + '</span></div>';
    }

    function paintLogo(slot, url) {
        if (!slot || !url || slot.querySelector('img')) return;
        var img = document.createElement('img');
        img.alt = '';
        img.addEventListener('error', function () { img.remove(); });
        img.src = url;
        slot.appendChild(img);
    }

    function httpFact(log) {
        var ctx = log.context || {};
        var code = ctx.status != null && ctx.status !== '' ? String(ctx.status) : '';
        var method = typeof ctx.method === 'string' ? ctx.method.toUpperCase() : '';
        var endpoint = '';
        if (typeof ctx.path === 'string') endpoint = ctx.path;
        else if (typeof ctx.url === 'string') endpoint = ctx.url;
        else if (typeof ctx.endpoint === 'string') endpoint = ctx.endpoint;
        var message = '';
        if (typeof ctx.error === 'string') message = ctx.error;
        else if (ctx.error && typeof ctx.error.message === 'string') message = ctx.error.message;
        var raw = String(log.message || '');
        var lead = raw.match(/^(GET|POST|PUT|PATCH|DELETE|HEAD)\s+(\S+)/i);
        if (lead) {
            if (!method) method = lead[1].toUpperCase();
            if (!endpoint) endpoint = lead[2];
        }
        var match = raw.match(/->\s*(\d{3})\s*([\s\S]*)$/);
        if (!code && match) code = match[1];
        if (!message && match) message = match[2];
        if (!message && !lead) message = raw;
        message = String(message || '').replace(/^\d{3}\s*/, '').replace(/\s+/g, ' ').trim();
        if (message.length > 140) message = message.slice(0, 137) + '…';
        var call = [method, endpoint].filter(Boolean).join(' ');
        return { code: code, message: message, endpoint: call };
    }

    function logWhen(log) {
        var at = String(log.at || '');
        var match = at.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
        return match ? match[2] + ' · ' + match[1] : at;
    }

    function recentEnough(log, hours) {
        var at = String(log.at || '').replace(' ', 'T');
        var time = Date.parse(at);
        if (!time) return true;
        return Date.now() - time <= hours * 60 * 60 * 1000;
    }

    function logVersion(log) {
        var version = log.device && log.device.version ? String(log.device.version) : '';
        var product = productLabel(log.device && log.device.product);
        return [product, version].filter(Boolean).join(' · ');
    }

    function logRow(log) {
        var http = httpFact(log);
        var when = logWhen(log);
        var fact = '<div class="sup-log__fact" dir="ltr">'
            + (when ? '<time>' + esc(when) + '</time>' : '')
            + (http.code ? '<b class="sup-code">' + esc(http.code) + '</b>' : '')
            + (http.endpoint ? '<span class="sup-endpoint" title="' + esc(http.endpoint) + '">' + esc(http.endpoint) + '</span>' : '')
            + '</div>';
        var message = http.message && http.message !== http.endpoint
            ? '<strong dir="auto">' + esc(http.message) + '</strong>'
            : '';
        return '<div class="sup-log is-' + esc(log.level) + '"><i></i><div class="sup-log__body">' + fact + message + '</div></div>';
    }

    function focusDevices(row) {
        var cutoff = Date.now() - 20 * 60 * 1000;
        var live = (row.devices || []).filter(function (device) {
            return device.online || device.status === 'online' || device.status === 'warning'
                || (device.lastSeenAt && Date.parse(device.lastSeenAt) >= cutoff);
        });
        return live.length ? live : (row.devices || []).slice(0, 6);
    }

    function render() {
        if (!current) return;
        title.textContent = current.name;
        var modalLogo = document.getElementById('sup-modal-logo');
        if (modalLogo) {
            modalLogo.innerHTML = '';
            if (current.logo) {
                modalLogo.hidden = false;
                paintLogo(modalLogo, current.logo);
            } else {
                modalLogo.hidden = true;
            }
        }
        if (tab === 'devices') {
            body.innerHTML = '<div class="sup-list">' + current.devices.map(deviceLine).join('') + '</div>';
            return;
        }
        if (tab === 'tickets') {
            var tickets = current.tickets || [];
            body.innerHTML = tickets.length ? '<div class="sup-list">' + tickets.map(function (ticket) {
                return '<a class="sup-ticket" href="' + esc(ticket.href) + '"><strong>' + esc(ticket.id) + '</strong><span>'
                    + esc(ticket.summary) + '</span><span class="sup-meta">' + esc(ticket.label) + ' · ' + esc(ticket.at) + '</span></a>';
            }).join('') + '</div>' : '<p class="sup-empty">' + esc(boot.labels.empty) + '</p>';
            return;
        }
        if (tab === 'logs') {
            var allLogs = '<a class="sup-logs-all" href="' + esc(boot.logs) + '?restaurants[]=' + encodeURIComponent(current.id) + '">'
                + '<span class="sup-logs-all__mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></span>'
                + '<span>' + esc(boot.labels.viewAllLogs || boot.labels.logs) + '</span>'
                + '<svg class="sup-logs-all__go" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>';
            body.innerHTML = allLogs + '<div class="sup-list" data-log-slot><div class="sup-skel"></div><div class="sup-skel"></div><div class="sup-skel"></div></div>';
            var slot = body.querySelector('[data-log-slot]');
            fetch(boot.feed + '?restaurant=' + encodeURIComponent(current.id) + '&view=failed&hours=48&limit=200', { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    var logs = (payload && payload.logs) || [];
                    slot.innerHTML = logs.length
                        ? logs.map(logRow).join('')
                        : '<p class="sup-empty">' + esc(boot.labels.empty) + '</p>';
                })
                .catch(function () {
                    slot.innerHTML = '<p class="sup-empty">' + esc(boot.labels.empty) + '</p>';
                });
            return;
        }
        var devices = focusDevices(current);
        var products = typeof current.products === 'string'
            ? current.products
            : (current.products || []).map(function (key) { return boot.labels[key] || ''; }).filter(Boolean).join(' · ');
        body.innerHTML = '<div class="sup-list"><p>' + esc(boot.labels.devicesOnline.replace(':online', current.online).replace(':total', current.total))
            + (products ? ' · <span dir="ltr">' + esc(products) + '</span>' : '')
            + '</p><p class="sup-meta">' + esc(boot.labels.last) + ': ' + esc(current.lastSeen || boot.labels.never) + '</p>'
            + (current.error ? '<p class="sup-error">' + esc(current.error) + '</p>' : '')
            + devices.map(function (device) {
                var product = productLabel(device.product) || device.kind || '';
                return '<div class="sup-device is-hit" role="button" tabindex="0" data-printers="' + esc(device.id) + '">'
                    + '<div class="sup-device__top"><div><strong>' + esc(device.name) + '</strong>'
                    + '<div class="sup-meta"><span dir="ltr">' + esc(product) + (device.version ? ' · ' + esc(device.version) : '') + '</span></div></div>'
                    + '<span class="sup-badge is-' + esc(device.status) + '">' + esc(boot.labels[device.status] || device.status) + '</span></div>'
                    + '<div class="sup-device-logs" data-logs-for="' + esc(device.id) + '"><div class="sup-skel"></div></div>'
                    + '</div>';
            }).join('') + '</div>';
        var overviewId = current.id;
        fetch(boot.feed + '?restaurant=' + encodeURIComponent(overviewId) + '&view=bugs&hours=12&limit=200', { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!current || current.id !== overviewId || tab !== 'overview') return;
                var groups = {};
                ((payload && payload.logs) || []).forEach(function (log) {
                    var id = log.device && log.device.id;
                    if (!id || !recentEnough(log, 12)) return;
                    (groups[id] || (groups[id] = [])).push(log);
                });
                body.querySelectorAll('[data-logs-for]').forEach(function (slot) {
                    var rows = groups[slot.getAttribute('data-logs-for')] || [];
                    slot.innerHTML = rows.length ? rows.map(logRow).join('') : '';
                });
            })
            .catch(function () {
                body.querySelectorAll('[data-logs-for]').forEach(function (slot) { slot.innerHTML = ''; });
            });
    }

    function closeModal() {
        if (typeof closePrinters === 'function') closePrinters();
        if (!modal || modal.hidden) return;
        modal.classList.remove('is-open');
        window.setTimeout(function () { modal.hidden = true; }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
    }

    function openModal(id) {
        current = byId[id];
        if (!current || !modal) return;
        tab = 'overview';
        modal.hidden = false;
        window.requestAnimationFrame(function () { modal.classList.add('is-open'); });
        Array.prototype.forEach.call(modal.querySelectorAll('[data-tab]'), function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-tab') === 'overview');
        });
        render();
    }

    grid.addEventListener('click', function (event) {
        var card = event.target.closest('[data-open]');
        // A real restaurant opens the shared restaurant modal (data-act); only the login-page card uses this one.
        if (card && !card.hasAttribute('data-act')) openModal(card.getAttribute('data-open'));
    });
    var printerModal = document.getElementById('sup-printers');
    var printerLayer = document.querySelector('[data-sup-printer-layer]');
    if (printerLayer) {
        printerLayer.addEventListener('cancel', function (event) {
            event.preventDefault();
            closePrinters();
        });
    }
    var printerBody = document.getElementById('sup-printer-body');
    var printerTitle = document.getElementById('sup-printer-title');
    var printerDevice = null;
    var printerTimer = 0;
    var printerView = 'printers';
    var printerNote = '';
    var pendingSettings = null;
    var pizzaQuery = '';
    var printerPayload = null;
    var printerSig = '';
    var showChecks = false;
    var pressureCopies = '5';
    var pressureSeconds = '1';
    var pressureTargets = null;
    var mapPick = '';
    var mapSummary = true;
    var scanWait = false;
    var scanSaw = false;
    var scanBaseline = false;
    var scanTimer = 0;
    var knownScanIds = {};
    var mapWait = false;
    var mapSaw = false;
    var mapBaseline = false;
    var mapTimer = 0;
    var knownMapIds = {};
    var mapFitted = false;
    var mapCam = { scale: 1, x: 16, y: 16 };

    function deviceUrl(template, id) {
        return String(template || '').replace('/live/0/', '/live/' + encodeURIComponent(id) + '/');
    }

    function closePrinters() {
        if (!printerModal || printerModal.hidden) return;
        window.clearInterval(printerTimer);
        stopSearch(false);
        stopSearch(true);
        printerModal.classList.remove('is-open');
        window.setTimeout(function () {
            printerModal.hidden = true;
            if (printerLayer && printerLayer.open) printerLayer.close();
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
    }

    function icon(name) {
        var paths = {
            search: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.2-4.2"/>',
            print: '<path d="M7 8V4h10v4"/><path d="M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 13h10v7H7z"/>',
            trash: '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/>',
            sync: '<path d="M20 12a8 8 0 0 1-13.7 5.6L4 16"/><path d="M4 20v-4h4"/><path d="M4 12a8 8 0 0 1 13.7-5.6L20 8"/><path d="M20 4v4h-4"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            back: '<path d="M15 5l-7 7 7 7"/>',
            pos: '<rect x="4" y="3" width="16" height="12" rx="2"/><path d="M8 20h8M12 15v5"/>',
            router: '<rect x="3" y="11" width="18" height="7" rx="2"/><path d="M7 11V7M12 11V4M17 11V7"/>',
            switch: '<path d="M4 8h16M4 16h16M8 8v8M16 8v8"/>',
            wifi: '<path d="M5 10a10 10 0 0 1 14 0"/><path d="M8 13a6 6 0 0 1 8 0"/><path d="M12 17h.01"/>',
            printer: '<path d="M7 8V4h10v4"/><path d="M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 13h10v7H7z"/>',
            camera: '<path d="M4 8h4l2-2h4l2 2h4v10H4z"/><circle cx="12" cy="13" r="3"/>',
            computer: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            web: '<circle cx="12" cy="12" r="8"/><path d="M4 12h16"/><path d="M12 4a14 14 0 0 1 0 16"/><path d="M12 4a14 14 0 0 0 0 16"/>',
            hub: '<circle cx="12" cy="12" r="2"/><path d="M12 4v4M12 16v4M4 12h4M16 12h4"/>'
        };
        return '<svg class="sup-ico" viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || paths.hub) + '</svg>';
    }

    function hostOf(printer) {
        var ip = String(printer.ip || printer.address || printer.host || '');
        var port = Number(printer.port || 9100) || 9100;
        var match = ip.match(/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/);
        if (match) return { ip: match[1], port: Number(match[2]) || port, host: match[1] };
        return { ip: ip, port: port, host: ip };
    }

    function rowAttrs(printer) {
        var host = hostOf(printer);
        return ' data-ip="' + esc(host.ip) + '" data-port="' + host.port + '" data-mac="' + esc(printer.mac || '') + '" data-printer="' + esc(printer.name || '') + '"';
    }

    function argsFrom(el) {
        var row = el.closest('[data-ip]');
        if (!row) return {};
        var args = { ip: row.getAttribute('data-ip') || '', port: Number(row.getAttribute('data-port') || 9100) };
        var mac = row.getAttribute('data-mac') || '';
        var name = row.getAttribute('data-printer') || '';
        if (mac) args.mac = mac;
        if (name) args.printer = name;
        return args;
    }

    function titleOf(printer, saved) {
        if (!saved) return boot.labels.newPrinter;
        var parts = [];
        if (printer.receipt) parts.push(boot.labels.receipt);
        if (printer.sticker) parts.push(boot.labels.sticker);
        if (printer.kitchen) {
            var stations = printer.stations || [];
            if (stations.length) parts = parts.concat(stations);
            else parts.push(boot.labels.kitchen);
        }
        if (!parts.length && printer.name) return printer.name;
        return parts.join(' · ') || boot.labels.newPrinter;
    }

    function roleChip(role, on, label) {
        return '<button type="button" class="sup-role' + (on ? ' is-on' : '') + '" data-role="' + role + '" data-on="' + (on ? '1' : '0') + '">' + esc(label) + '</button>';
    }

    function kitchenChips(printer, types) {
        if (!printer.kitchen || !types.length) return '';
        var stations = printer.stations || [];
        var ids = printer.stationIds || printer.station_ids || [];
        return '<div class="sup-kitchens"><span>' + esc(boot.labels.whichKitchen) + '</span>' + types.map(function (type) {
            var id = String(type.id || type.name || '');
            var name = String(type.name || type.id || '');
            var on = stations.indexOf(name) >= 0 || ids.indexOf(id) >= 0;
            return '<button type="button" class="sup-role' + (on ? ' is-on' : '') + '" data-station="' + esc(name) + '" data-station-id="' + esc(id) + '" data-on="' + (on ? '1' : '0') + '">' + esc(name) + '</button>';
        }).join('') + '</div>';
    }

    function printerCard(printer, saved, types) {
        var host = hostOf(printer);
        var known = printer.reachable === true || printer.reachable === false || printer.reachable === 1 || printer.reachable === 0 || printer.reachable === 'true' || printer.reachable === 'false';
        var on = printer.reachable === true || printer.reachable === 1 || printer.reachable === 'true';
        var actions = saved
            ? roleChip('receipt', !!printer.receipt, boot.labels.receipt) + roleChip('sticker', !!printer.sticker, boot.labels.sticker) + roleChip('kitchen', !!printer.kitchen, boot.labels.kitchen)
                + '<button type="button" class="sup-ghost" data-test>' + icon('print') + esc(boot.labels.testPrint) + '</button>'
                + '<button type="button" class="sup-ghost is-danger" data-forget>' + icon('trash') + esc(boot.labels.remove) + '</button>'
                + kitchenChips(printer, types)
            : '<button type="button" class="sup-primary" data-remember>' + icon('plus') + esc(boot.labels.add) + '</button>';
        var badge = known ? '<em class="' + (on ? 'is-on' : '') + '">' + esc(on ? boot.labels.connected : boot.labels.disconnected) + '</em>' : '';
        return '<article class="sup-prow' + (saved ? '' : ' is-new') + '"' + rowAttrs(printer) + '><div class="sup-prow__top"><span class="sup-prow__icon">' + icon('printer') + '</span><div><strong>' + esc(titleOf(printer, saved)) + '</strong><div class="sup-meta" dir="ltr">' + esc(host.host) + '</div></div>' + badge + '</div><div class="sup-prow__actions">' + actions + '</div></article>';
    }

    function printerKey(printer) {
        var host = hostOf(printer);
        return host.ip + ':' + host.port;
    }

    function isTarget(printer) {
        if (!pressureTargets) return true;
        return !!pressureTargets[printerKey(printer)];
    }

    function pressureHtml(device) {
        var saved = device.printers || [];
        if (!saved.length && !showChecks) return '';
        var pressure = device.pressure || {};
        var toggle = '<button type="button" class="sup-ghost" data-checks>' + esc(showChecks ? boot.labels.pressureTest : boot.labels.moreChecks) + '</button>';
        if (!showChecks) return '<div class="sup-more">' + toggle + '</div>';
        var chips = saved.map(function (printer) {
            return '<button type="button" class="sup-role' + (isTarget(printer) ? ' is-on' : '') + '" data-target="' + esc(printerKey(printer)) + '">' + esc(titleOf(printer, true) + '  ' + hostOf(printer).host) + '</button>';
        }).join('');
        var running = !!pressure.running;
        var logs = (pressure.logs || []).map(function (entry) {
            return '<div class="is-' + esc(entry.kind || '') + '">' + esc([entry.at, entry.printer, entry.message].filter(Boolean).join('  ')) + '</div>';
        }).join('');
        var stats = String(boot.labels.pressureStats || '')
            .replace('{waiting}', pressure.waiting || 0)
            .replace('{sending}', pressure.sending || 0)
            .replace('{ok}', pressure.ok || 0)
            .replace('{fail}', pressure.fail || 0);
        return '<div class="sup-more">' + toggle
            + '<p>' + esc(boot.labels.pressureHint) + '</p>'
            + '<div class="sup-prow__actions">' + chips + '</div>'
            + '<div class="sup-pressure"><input data-copies type="number" min="1" value="' + esc(pressureCopies) + '" aria-label="' + esc(boot.labels.pressureCopies) + '"><input data-seconds type="number" min="1" value="' + esc(pressureSeconds) + '" aria-label="' + esc(boot.labels.pressureSeconds) + '">'
            + (running
                ? '<button type="button" class="sup-ghost" data-pressure-stop>' + esc(boot.labels.pressureStop) + '</button>'
                : '<button type="button" class="sup-primary" data-pressure-start>' + esc(boot.labels.pressureStart) + '</button>')
            + '</div>'
            + (pressure.issue ? '<p class="sup-alert">' + esc(boot.labels.pressureIssue) + '</p>' : '')
            + (logs ? '<div class="sup-plog"><div class="sup-plog__head"><strong>' + esc(boot.labels.pressureLog) + '</strong><button type="button" class="sup-ghost" data-pressure-clear>' + esc(boot.labels.pressureClear) + '</button></div><p class="sup-meta">' + esc(stats) + '</p><div class="sup-plog__body">' + logs + '</div></div>' : '')
            + '</div>';
    }

    function settingOn(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function settingEqual(live, pending) {
        if (Array.isArray(pending)) {
            var left = (Array.isArray(live) ? live : []).map(String).sort();
            var right = pending.map(String).sort();
            return JSON.stringify(left) === JSON.stringify(right);
        }
        if (typeof pending === 'boolean') return settingOn(live) === pending;
        return String(live == null ? '' : live) === String(pending);
    }

    function reconcileSettings(device) {
        if (!pendingSettings || !device || !device.settings) return;
        var live = device.settings;
        Object.keys(pendingSettings).forEach(function (key) {
            if (settingEqual(live[key], pendingSettings[key])) delete pendingSettings[key];
        });
        if (!Object.keys(pendingSettings).length) pendingSettings = null;
    }

    function shownSettings(device) {
        var base = {
            printKitchenCancel: false,
            localZReport: false,
            printReceiptOnReceive: true,
            allowKitchenReprint: false,
            kitchenReprintPassword: '',
            autoPrintOnThisTablet: false,
            builtInKeyboard: true,
            pauseOrder: false,
            pizzaInterface: false,
            pizzaMealIds: []
        };
        var live = (device && device.settings) || {};
        Object.keys(base).forEach(function (key) {
            if (Object.prototype.hasOwnProperty.call(live, key)) base[key] = live[key];
        });
        if (pendingSettings) {
            Object.keys(pendingSettings).forEach(function (key) {
                base[key] = pendingSettings[key];
            });
        }
        if (!Array.isArray(base.pizzaMealIds)) base.pizzaMealIds = [];
        return base;
    }

    function settingRow(key, title, hint, on) {
        return '<label class="sup-set"><span><strong>' + esc(title) + '</strong>'
            + (hint ? '<small>' + esc(hint) + '</small>' : '')
            + '</span><input type="checkbox" data-setting="' + esc(key) + '"' + (on ? ' checked' : '') + '></label>';
    }

    function settingsHtml(device) {
        var values = shownSettings(device);
        var waiter = String((device && device.role) || (printerDevice && printerDevice.role) || '').toLowerCase() === 'waiter';
        var meals = (device && device.pizzaMeals) || [];
        var selected = {};
        values.pizzaMealIds.forEach(function (id) { selected[String(id)] = true; });
        var known = {};
        var mealRows = meals.map(function (meal) {
            var id = String(meal.id || '');
            if (!id) return '';
            known[id] = true;
            var name = meal.name || id;
            return '<label class="sup-meal" data-pizza-row data-name="' + esc(name) + '"><input type="checkbox" data-pizza-meal value="' + esc(id) + '"' + (selected[id] ? ' checked' : '') + '><span>' + esc(name) + '</span></label>';
        }).join('');
        Object.keys(selected).forEach(function (id) {
            if (known[id]) return;
            mealRows += '<label class="sup-meal" data-pizza-row data-name="' + esc(id) + '"><input type="checkbox" data-pizza-meal value="' + esc(id) + '" checked><span dir="ltr">' + esc(id) + '</span></label>';
        });
        var html = '<p class="sup-settings-note">' + esc(device && device.settings ? boot.labels.settingsWait : boot.labels.settingsUnreported) + '</p>';
        html += settingRow('printKitchenCancel', boot.labels.printKitchenCancel, boot.labels.printKitchenCancelHint, settingOn(values.printKitchenCancel));
        if (!waiter) html += settingRow('localZReport', boot.labels.localZ, boot.labels.localZHint, settingOn(values.localZReport));
        html += settingRow('printReceiptOnReceive', boot.labels.printReceipt, '', settingOn(values.printReceiptOnReceive));
        html += '<label class="sup-set"><span><strong>' + esc(boot.labels.kitchenReprint) + '</strong><small>' + esc(boot.labels.kitchenReprintHint) + '</small></span>'
            + '<input class="sup-set__pin" data-reprint-pin type="text" inputmode="numeric" autocomplete="off" maxlength="64" aria-label="' + esc(boot.labels.reprintPin) + '" value="' + esc(values.kitchenReprintPassword || '') + '">'
            + '<input type="checkbox" data-setting="allowKitchenReprint"' + (settingOn(values.allowKitchenReprint) ? ' checked' : '') + '></label>';
        if (!waiter) html += settingRow('autoPrintOnThisTablet', boot.labels.autoPrint, boot.labels.autoPrintHint, settingOn(values.autoPrintOnThisTablet));
        html += settingRow('builtInKeyboard', boot.labels.builtinKeyboard, boot.labels.builtinKeyboardHint, settingOn(values.builtInKeyboard));
        html += settingRow('pauseOrder', boot.labels.pauseOrder, boot.labels.pauseOrderHint, settingOn(values.pauseOrder));
        html += settingRow('pizzaInterface', boot.labels.pizza, boot.labels.pizzaHint, settingOn(values.pizzaInterface));
        if (settingOn(values.pizzaInterface)) {
            html += '<h3>' + esc(boot.labels.pizzaMeals) + '</h3>';
            html += '<input class="sup-pizza-search" data-pizza-search type="search" value="' + esc(pizzaQuery) + '" placeholder="' + esc(boot.labels.pizzaSearch) + '" aria-label="' + esc(boot.labels.pizzaSearch) + '">';
            html += mealRows ? '<div class="sup-meals">' + mealRows + '</div>' : '<p class="sup-empty">' + esc(boot.labels.pizzaMealsEmpty) + '</p>';
        }
        html += '<button type="button" class="sup-set sup-set--link" data-show-printers><span><strong>' + esc(boot.labels.printers) + '</strong></span></button>';
        if (printerNote) html += '<p class="sup-meta">' + esc(printerNote) + '</p>';
        return html;
    }

    function filterMeals() {
        if (!printerBody) return;
        var query = pizzaQuery.trim().toLowerCase();
        printerBody.querySelectorAll('[data-pizza-row]').forEach(function (row) {
            var name = String(row.getAttribute('data-name') || '').toLowerCase();
            row.hidden = query !== '' && name.indexOf(query) === -1;
        });
    }

    function queueSettings(patch) {
        pendingSettings = Object.assign({}, pendingSettings || {}, patch);
        sendCommand('apply_settings', patch);
    }

    function printersHtml(device) {
        var scan = device.scan || {};
        var looking = scanWait;
        var saved = device.printers || [];
        var found = device.discovered || [];
        var types = device.kitchenTypes || device.kitchen_types || [];
        var html = '<div class="sup-pactions"><button type="button" class="sup-primary" data-scan-printers' + (looking ? ' disabled' : '') + '>' + icon(looking ? 'sync' : 'search') + esc(looking ? boot.labels.lookingPrinters : boot.labels.findPrinters) + '</button><button type="button" class="sup-ghost" data-kitchens>' + icon('sync') + esc(boot.labels.syncApi) + '</button></div>';
        if (looking) {
            if (scan.total) {
                var width = Math.min(100, Math.round(((scan.done || 0) / scan.total) * 100));
                html += '<div class="sup-progress" dir="ltr"><i style="width:' + width + '%"></i></div><p class="sup-meta" dir="ltr">' + esc((scan.done || 0) + ' / ' + scan.total) + '</p>';
            } else {
                html += '<div class="sup-progress is-wait"><i></i></div>';
            }
        }
        if (printerNote) html += '<p class="sup-meta">' + esc(printerNote) + '</p>';
        html += '<h3>' + esc(boot.labels.savedPrinters) + '</h3>';
        html += saved.length ? saved.map(function (printer) { return printerCard(printer, true, types); }).join('') : '<p class="sup-empty">' + esc(boot.labels.nonePrinters) + '</p>';
        if (found.length) {
            html += '<h3>' + esc(boot.labels.foundPrinters) + '</h3>';
            html += found.map(function (printer) { return printerCard(printer, false, types); }).join('');
        }
        html += pressureHtml(device);
        return html;
    }

    function lanRank(node) {
        if (node.role === 'gateway' || node.gear === 'router') return 0;
        if (node.gear === 'switch' || node.role === 'switch') return 1;
        if (node.gear === 'ap' || node.role === 'ap') return 2;
        return ({ tablet: 3, printer: 4, camera: 5, computer: 6, web: 7 })[node.role] != null ? ({ tablet: 3, printer: 4, camera: 5, computer: 6, web: 7 })[node.role] : 8;
    }

    function compareIp(a, b) {
        var left = String(a || '').split('.');
        var right = String(b || '').split('.');
        if (left.length === 4 && right.length === 4) {
            for (var i = 0; i < 4; i++) {
                var da = parseInt(left[i], 10);
                var db = parseInt(right[i], 10);
                if (isNaN(da) || isNaN(db)) break;
                if (da !== db) return da - db;
            }
        }
        return String(a || '').localeCompare(String(b || ''));
    }

    function layoutLan(nodes, links) {
        var cardW = 216;
        var cardH = 72;
        var gapX = 28;
        var gapY = 52;
        var margin = 28;
        var columns = 3;
        var byId = {};
        var aliases = {};
        nodes.forEach(function (node) {
            var id = node.id || node.ip;
            if (!id || byId[id]) return;
            byId[id] = node;
            aliases[id] = id;
            if (node.ip) aliases[node.ip] = id;
        });
        var ids = Object.keys(byId);
        if (!ids.length) return { points: {}, aliases: {}, width: 640, height: 220, cardW: cardW, cardH: cardH };
        function idOf(ref) {
            var id = aliases[ref];
            return id && byId[id] ? id : null;
        }
        function kindWeight(kind) {
            return ({ uplink: 0, lan: 1, missing: 2, device: 3 })[kind] != null ? ({ uplink: 0, lan: 1, missing: 2, device: 3 })[kind] : 4;
        }
        function compare(a, b) {
            var rank = lanRank(byId[a]) - lanRank(byId[b]);
            return rank || compareIp(byId[a].ip, byId[b].ip);
        }
        var parentOf = {};
        var kindOf = {};
        function attach(parentId, childId, kind) {
            if (!parentId || !childId || parentId === childId) return;
            if (kind !== 'device' && lanRank(byId[parentId]) > lanRank(byId[childId])) {
                var swap = parentId;
                parentId = childId;
                childId = swap;
            }
            var current = parentOf[childId];
            if (current) {
                var betterRank = lanRank(byId[parentId]) < lanRank(byId[current]);
                var same = lanRank(byId[parentId]) === lanRank(byId[current]) && kindWeight(kind) < kindWeight(kindOf[childId] || '');
                if (!betterRank && !same) return;
            }
            parentOf[childId] = parentId;
            kindOf[childId] = kind;
        }
        (links || []).forEach(function (link) {
            var from = idOf(link.from);
            var to = idOf(link.to);
            if (!from || !to) return;
            if (link.kind === 'uplink') attach(to, from, link.kind);
            else attach(from, to, link.kind || 'lan');
        });
        var seen = {};
        function breakCycles(id, stack) {
            if (stack[id]) { delete parentOf[id]; return; }
            if (seen[id]) return;
            stack[id] = 1;
            if (parentOf[id]) breakCycles(parentOf[id], stack);
            delete stack[id];
            seen[id] = 1;
        }
        ids.forEach(function (id) { breakCycles(id, {}); });
        var children = {};
        ids.forEach(function (id) { children[id] = []; });
        Object.keys(parentOf).forEach(function (child) {
            if (children[parentOf[child]] && child !== parentOf[child]) children[parentOf[child]].push(child);
        });
        Object.keys(children).forEach(function (id) { children[id].sort(compare); });
        var roots = ids.filter(function (id) { return !parentOf[id]; }).sort(compare);
        var hubs = roots.filter(function (id) { return children[id].length; });
        var loners = roots.filter(function (id) { return !children[id].length; });
        var top = hubs.length ? hubs : loners;
        var below = hubs.length ? loners : [];
        var memo = {};
        var visiting = {};
        function chunks(list) {
            var rows = [];
            for (var i = 0; i < list.length; i += columns) rows.push(list.slice(i, i + columns));
            return rows;
        }
        function measure(id) {
            if (memo[id]) return memo[id];
            if (visiting[id]) return { width: cardW, height: cardH };
            visiting[id] = 1;
            var packed = pack(children[id] || []);
            delete visiting[id];
            if (!packed.length) return (memo[id] = { width: cardW, height: cardH });
            var width = cardW;
            var body = 0;
            packed.forEach(function (row, index) {
                width = Math.max(width, row.width);
                body += row.height + (index ? gapY : 0);
            });
            return (memo[id] = { width: width, height: cardH + gapY + body });
        }
        function pack(list) {
            return chunks(list).map(function (chunk) {
                var sizes = chunk.map(measure);
                var width = 0;
                var height = 0;
                sizes.forEach(function (size, index) {
                    width += size.width + (index ? gapX : 0);
                    height = Math.max(height, size.height);
                });
                return { ids: chunk, sizes: sizes, width: width, height: height };
            });
        }
        function measureGroup(list) {
            var packed = pack(list);
            if (!packed.length) return { width: 0, height: 0 };
            var width = 0;
            var height = 0;
            packed.forEach(function (row, index) {
                width = Math.max(width, row.width);
                height += row.height + (index ? gapY : 0);
            });
            return { width: width, height: height };
        }
        var points = {};
        function place(id, left, topY) {
            var size = measure(id);
            points[id] = { x: left + (size.width - cardW) / 2, y: topY };
            var y = topY + cardH + gapY;
            pack(children[id] || []).forEach(function (row) {
                var x = left + (size.width - row.width) / 2;
                row.ids.forEach(function (child, index) {
                    place(child, x, y);
                    x += row.sizes[index].width + gapX;
                });
                y += row.height + gapY;
            });
        }
        function placeGroup(list, topY, contentWidth) {
            var y = topY;
            pack(list).forEach(function (row) {
                var x = (contentWidth - row.width) / 2;
                row.ids.forEach(function (id, index) {
                    place(id, x, y);
                    x += row.sizes[index].width + gapX;
                });
                y += row.height + gapY;
            });
        }
        var topBox = measureGroup(top);
        var belowBox = measureGroup(below);
        var between = top.length && below.length ? gapY : 0;
        var contentWidth = Math.max(topBox.width, belowBox.width);
        var contentHeight = topBox.height + between + belowBox.height;
        placeGroup(top, 0, contentWidth);
        if (below.length) placeGroup(below, topBox.height + between, contentWidth);
        var width = Math.max(640, contentWidth + margin * 2);
        var dx = Math.max(margin, (width - contentWidth) / 2);
        Object.keys(points).forEach(function (id) {
            points[id].x += dx;
            points[id].y += margin;
        });
        return { points: points, aliases: aliases, width: width, height: Math.max(220, contentHeight + margin + 8), cardW: cardW, cardH: cardH };
    }

    function faceOf(node, device) {
        var role = node.role && node.role !== 'other' ? node.role : 'other';
        var ports = (node.ports || []).map(function (port) { return Number(port.port || port); });
        if (role === 'other') {
            if (ports.indexOf(9100) >= 0 || ports.indexOf(515) >= 0) role = 'printer';
            else if (ports.indexOf(554) >= 0) role = 'camera';
            else if (ports.indexOf(3389) >= 0 || ports.indexOf(22) >= 0) role = 'computer';
            else if (ports.indexOf(80) >= 0 || ports.indexOf(443) >= 0) role = 'web';
        }
        var gear = node.gear || '';
        if (gear === 'switch') role = 'switch';
        if (gear === 'ap') role = 'ap';
        var name = String(node.name || '').trim();
        if (!name || name === node.ip) {
            var stations = (node.printer && node.printer.stations) || [];
            name = (node.printer && node.printer.name) || stations[0] || '';
        }
        if ((!name || name === node.ip) && role === 'tablet') name = device.hostname || '';
        var status = 'offline';
        if (node.seen === 'inferred') status = 'inferred';
        else if (node.online) status = (node.seen === 'arp' || node.seen === 'route') ? 'partial' : 'online';
        else if (node.seen === 'saved') status = 'saved';
        else if (node.seen === 'route') status = 'route';
        return { role: role, gear: gear, name: name && name !== node.ip ? name : '', status: status, family: node.family || '' };
    }

    function roleText(face) {
        var gear = face.gear === 'router' ? boot.labels.roleRouter : (face.gear === 'switch' ? boot.labels.roleSwitch : (face.gear === 'ap' ? boot.labels.roleAp : ''));
        var role = ({
            tablet: boot.labels.roleTablet,
            gateway: boot.labels.roleGateway,
            printer: boot.labels.rolePrinter,
            camera: boot.labels.roleCamera,
            computer: boot.labels.roleComputer,
            web: boot.labels.roleWeb,
            switch: boot.labels.roleSwitch,
            ap: boot.labels.roleAp
        })[face.role] || boot.labels.roleOther;
        var kind = gear || role;
        return face.family && face.gear ? (face.family + ' ' + kind) : kind;
    }

    function statusText(status) {
        return ({
            online: boot.labels.stOnline,
            partial: boot.labels.stPartial,
            saved: boot.labels.stSaved,
            route: boot.labels.stRoute,
            inferred: boot.labels.stInferred
        })[status] || boot.labels.stOffline;
    }

    function roleIcon(role) {
        return ({ tablet: 'pos', gateway: 'router', switch: 'switch', ap: 'wifi', printer: 'printer', camera: 'camera', computer: 'computer', web: 'web' })[role] || 'hub';
    }

    function isWifiName(name) {
        var value = String(name || '').toLowerCase();
        return value.indexOf('wi-fi') >= 0 || value.indexOf('wifi') >= 0 || value.indexOf('wlan') >= 0 || value.indexOf('wireless') >= 0 || value.indexOf('wlp') === 0 || value.indexOf('wlx') === 0;
    }

    function nodeId(node) {
        return node.id || node.ip || '';
    }

    function enrichLan(lan) {
        var source = ((lan && lan.nodes) || []).filter(function (node) { return node.seen !== 'inferred'; });
        if (!source.length) return { nodes: [], links: [] };
        var gateway = null;
        source.forEach(function (node) {
            if (!gateway && (node.role === 'gateway' || (lan.gateway && node.ip === lan.gateway))) gateway = node;
        });
        function ownMedium(node) {
            if (node.medium === 'wifi' || node.medium === 'cable') return node.medium;
            var label = node.port || node.iface || '';
            if (!label) return '';
            return isWifiName(label) ? 'wifi' : 'cable';
        }
        function linkMedium(node) {
            if (node.role === 'printer' || node.role === 'camera' || node.role === 'gateway') return 'cable';
            var own = ownMedium(node);
            if (node.role === 'tablet' && (own === 'wifi' || isWifiName(node.iface) || isWifiName(node.port))) return 'wifi';
            if (node.role === 'tablet') return 'cable';
            return own === 'wifi' ? 'wifi' : 'cable';
        }
        var wifi = [];
        var cable = [];
        source.forEach(function (node) {
            if (gateway && (nodeId(node) === nodeId(gateway) || node.ip === gateway.ip)) return;
            (linkMedium(node) === 'wifi' ? wifi : cable).push(node);
        });
        var links = [];
        var seen = {};
        function add(from, to, kind, medium) {
            if (!from || !to || from === to) return;
            var key = from + '>' + to + ':' + kind;
            if (seen[key]) return;
            seen[key] = 1;
            links.push({ from: from, to: to, kind: kind, medium: medium });
        }
        (lan.links || []).forEach(function (link) {
            if (link.kind === 'device') add(link.from, link.to, 'device', 'cable');
        });
        var extra = [];
        var groups = {};
        cable.forEach(function (node) {
            var key = node.subnet || lan.subnet || 'lan';
            (groups[key] || (groups[key] = [])).push(node);
        });
        Object.keys(groups).forEach(function (key) {
            var group = groups[key];
            function attach(node, parentId, medium) {
                var kind = node.online ? 'lan' : 'missing';
                var id = nodeId(node);
                if (node.role === 'tablet') add(id, parentId, 'uplink', medium);
                else add(parentId, id, kind, medium);
            }
            if (group.length < 2) {
                if (gateway) group.forEach(function (node) { attach(node, nodeId(gateway), 'cable'); });
                return;
            }
            var hubId = 'switch:' + key;
            extra.push({ id: hubId, ip: '', name: '', role: 'switch', gear: 'switch', seen: 'inferred', online: true, subnet: key, ports: [], medium: 'cable' });
            if (gateway) add(nodeId(gateway), hubId, 'lan', 'cable');
            group.forEach(function (node) { attach(node, hubId, 'cable'); });
        });
        wifi.forEach(function (node) {
            if (!gateway) return;
            var kind = node.online ? 'lan' : 'missing';
            var id = nodeId(node);
            if (node.role === 'tablet') add(id, nodeId(gateway), 'uplink', 'wifi');
            else add(nodeId(gateway), id, kind, 'wifi');
        });
        return { nodes: source.concat(extra), links: links };
    }

    function mapHtml(device) {
        var lan = device.lan || {};
        var shaped = enrichLan(lan);
        var nodes = shaped.nodes;
        var scan = device.lanScan || {};
        var mapping = mapWait;
        var online = nodes.filter(function (node) { return faceOf(node, device).status === 'online'; }).length;
        var summary = nodes.length ? (nodes.length + ' · ' + online) : boot.labels.lanMap;
        var layout = layoutLan(nodes, shaped.links || []);
        var paths = '';
        (shaped.links || []).forEach(function (link) {
            var fromId = layout.aliases[link.from];
            var toId = layout.aliases[link.to];
            var from = fromId && layout.points[fromId];
            var to = toId && layout.points[toId];
            if (!from || !to || fromId === toId) return;
            var fromCenter = { x: from.x + layout.cardW / 2, y: from.y + layout.cardH / 2 };
            var toCenter = { x: to.x + layout.cardW / 2, y: to.y + layout.cardH / 2 };
            function port(point, other) {
                var dx = other.x - (point.x + layout.cardW / 2);
                var dy = other.y - (point.y + layout.cardH / 2);
                if (Math.abs(dy) > 4) return { x: point.x + layout.cardW / 2, y: dy > 0 ? point.y + layout.cardH : point.y };
                return { x: dx >= 0 ? point.x + layout.cardW : point.x, y: point.y + layout.cardH / 2 };
            }
            var start = port(from, toCenter);
            var end = port(to, fromCenter);
            var d;
            if (Math.abs(end.y - start.y) > 12) {
                var mid = (start.y + end.y) / 2;
                d = 'M' + start.x + ' ' + start.y + ' C ' + start.x + ' ' + mid + ', ' + end.x + ' ' + mid + ', ' + end.x + ' ' + end.y;
            } else {
                var midX = (start.x + end.x) / 2;
                d = 'M' + start.x + ' ' + start.y + ' C ' + midX + ' ' + start.y + ', ' + midX + ' ' + end.y + ', ' + end.x + ' ' + end.y;
            }
            var kind = link.kind === 'uplink' ? 'is-uplink' : ((link.kind === 'missing' || link.kind === 'device') ? 'is-missing' : '');
            paths += '<path class="' + kind + (link.medium === 'wifi' ? ' is-wifi' : '') + '" d="' + d + '" />';
        });
        var cards = nodes.map(function (node) {
            var id = node.id || node.ip;
            var point = layout.points[id];
            if (!point) return '';
            var face = faceOf(node, device);
            var title = face.name || node.vendor || roleText(face);
            var subtitle = node.ip ? (face.name ? (roleText(face) + ' · ' + node.ip) : node.ip) : roleText(face);
            var dim = mapPick && mapPick !== id ? ' is-dim' : '';
            var selected = mapPick === id ? ' is-selected' : '';
            return '<button type="button" class="sup-node is-' + esc(face.role) + ' is-' + esc(face.status) + selected + dim + '" data-node="' + esc(id) + '" style="left:' + point.x + 'px;top:' + point.y + 'px"><span class="sup-node__mark">' + icon(roleIcon(face.role)) + '</span><span><strong>' + esc(title) + '</strong><em>' + esc(subtitle) + '</em><b>' + esc(statusText(face.status)) + '</b></span></button>';
        }).join('');
        var list = nodes.map(function (node) {
            var id = node.id || node.ip;
            var face = faceOf(node, device);
            return '<button type="button" class="sup-node-row' + (mapPick === id ? ' is-on' : '') + '" data-node="' + esc(id) + '"><b class="is-' + esc(face.status) + '"></b><span>' + esc(face.name || roleText(face) || node.ip || '') + '</span><em dir="ltr">' + esc(node.ip || '') + '</em></button>';
        }).join('');
        var bar = mapping ? (scan.total ? '<div class="sup-progress"><i style="width:' + Math.min(100, Math.round(((scan.done || 0) / scan.total) * 100)) + '%"></i></div>' : '<div class="sup-progress is-wait"><i></i></div>') : '';
        var world = nodes.length
            ? '<div class="sup-map__world" data-map-world style="width:' + layout.width + 'px;height:' + layout.height + 'px"><svg class="sup-map__links" width="' + layout.width + '" height="' + layout.height + '">' + paths + '</svg>' + cards + '</div>'
            : '<p class="sup-empty">' + esc(boot.labels.lanEmpty) + '</p>';
        var summaryBox = mapSummary
            ? '<aside class="sup-map__card"><div class="sup-map__head"><strong dir="ltr">' + esc(summary) + '</strong><span><button type="button" class="sup-ghost" data-map-lan' + (mapping ? ' disabled' : '') + '>' + icon(mapping ? 'sync' : 'search') + esc(mapping ? boot.labels.lanScanning : boot.labels.lanScan) + '</button><button type="button" class="sup-x" data-hide-summary aria-label="' + esc(boot.labels.back) + '">×</button></span></div>' + bar + (list ? '<div class="sup-map__list">' + list + '</div>' : '') + '</aside>'
            : '<button type="button" class="sup-map__show" data-show-summary>' + esc(summary) + '</button>';
        return '<div class="sup-map"><div class="sup-map__stage" data-map-stage>' + world + summaryBox
            + '<div class="sup-map__zoom"><button type="button" data-zoom="in" aria-label="+">+</button><button type="button" data-zoom="out" aria-label="−">−</button><button type="button" data-zoom="fit">⤢</button></div></div></div>';
    }

    function applyMapCam() {
        var world = printerBody && printerBody.querySelector('[data-map-world]');
        if (!world) return;
        world.style.transform = 'translate(' + mapCam.x + 'px,' + mapCam.y + 'px) scale(' + mapCam.scale + ')';
    }

    function fitMap() {
        var stage = printerBody && printerBody.querySelector('[data-map-stage]');
        var world = printerBody && printerBody.querySelector('[data-map-world]');
        if (!stage || !world) return;
        var fit = Math.min(1, (stage.clientWidth - 24) / world.offsetWidth, (stage.clientHeight - 24) / world.offsetHeight);
        mapCam.scale = Math.max(0.35, fit || 1);
        mapCam.x = 16;
        mapCam.y = 16;
        applyMapCam();
    }

    function bindMap() {
        var stage = printerBody && printerBody.querySelector('[data-map-stage]');
        if (!stage || stage.getAttribute('data-bound')) {
            applyMapCam();
            return;
        }
        stage.setAttribute('data-bound', '1');
        var drag = null;
        stage.addEventListener('pointerdown', function (event) {
            if (event.target.closest('button, a, input')) return;
            drag = { x: event.clientX, y: event.clientY, ox: mapCam.x, oy: mapCam.y };
            stage.setPointerCapture(event.pointerId);
        });
        stage.addEventListener('pointermove', function (event) {
            if (!drag) return;
            mapCam.x = drag.ox + (event.clientX - drag.x);
            mapCam.y = drag.oy + (event.clientY - drag.y);
            applyMapCam();
        });
        stage.addEventListener('pointerup', function () { drag = null; });
        stage.addEventListener('wheel', function (event) {
            event.preventDefault();
            mapCam.scale = Math.min(1.8, Math.max(0.35, mapCam.scale + (event.deltaY < 0 ? 0.08 : -0.08)));
            applyMapCam();
        }, { passive: false });
        if (!mapFitted) window.requestAnimationFrame(function () { fitMap(); mapFitted = true; });
        else applyMapCam();
    }

    function syncPrinterChrome() {
        if (!printerModal) return;
        var mapButton = printerModal.querySelector('[data-show-map]');
        var settingsButton = printerModal.querySelector('[data-show-settings]');
        var backButton = printerModal.querySelector('[data-show-printers]');
        if (mapButton) mapButton.hidden = printerView !== 'printers';
        if (settingsButton) settingsButton.hidden = printerView !== 'printers';
        if (backButton) {
            backButton.hidden = printerView === 'printers';
            backButton.innerHTML = icon('back') + esc(boot.labels.back);
        }
        if (printerTitle) {
            printerTitle.textContent = printerView === 'map'
                ? boot.labels.lanTitle
                : printerView === 'settings'
                    ? (boot.labels.settings || boot.labels.printers)
                    : ((printerDevice && printerDevice.name) || boot.labels.printers);
        }
        var panel = printerModal.querySelector('.sup-modal__panel');
        if (panel) panel.classList.toggle('is-map', printerView === 'map');
    }

    function isMapCommand(command) {
        var args = (command && command.args) || {};
        var value = args.map != null ? args.map : args.scope;
        return value === true || value === 1 || String(value).toLowerCase() === 'true' || String(value) === '1' || String(value) === 'map' || String(value) === 'lan';
    }

    function rememberJobs(payload, map) {
        var known = {};
        ((payload && payload.commands) || []).forEach(function (command) {
            var mapped = command.name === 'map_lan' || (command.name === 'scan_lan' && isMapCommand(command));
            var printerScan = command.name === 'scan_lan' && !isMapCommand(command);
            if (map ? mapped : printerScan) known[command.id] = true;
        });
        return known;
    }

    function freshJob(payload, known, map) {
        var found = null;
        ((payload && payload.commands) || []).forEach(function (command) {
            if (found || known[command.id]) return;
            var mapped = command.name === 'map_lan' || (command.name === 'scan_lan' && isMapCommand(command));
            var printerScan = command.name === 'scan_lan' && !isMapCommand(command);
            if (map ? mapped : printerScan) found = command;
        });
        return found;
    }

    function stopSearch(map) {
        if (map) {
            window.clearTimeout(mapTimer);
            mapWait = false;
            mapSaw = false;
            mapBaseline = false;
        } else {
            window.clearTimeout(scanTimer);
            scanWait = false;
            scanSaw = false;
            scanBaseline = false;
        }
    }

    function sawThisSearch(running, saw, baseline) {
        if (baseline && running) return { saw: false, baseline: true };
        if (running) return { saw: true, baseline: false };
        return { saw: saw, baseline: false };
    }

    function watchUserSearch(payload) {
        var device = (payload && payload.device) || {};
        if (scanWait) {
            var scan = device.scan || {};
            var next = sawThisSearch(!!scan.running, scanSaw, scanBaseline);
            scanSaw = next.saw;
            scanBaseline = next.baseline;
            var status = (freshJob(payload, knownScanIds, false) || {}).status;
            var finished = status === 'done' || status === 'failed' || status === 'expired';
            if (finished || (scanSaw && !scan.running)) stopSearch(false);
        }
        if (mapWait) {
            var lanScan = device.lanScan || {};
            var mapNext = sawThisSearch(!!lanScan.running, mapSaw, mapBaseline);
            mapSaw = mapNext.saw;
            mapBaseline = mapNext.baseline;
            var mapStatus = (freshJob(payload, knownMapIds, true) || {}).status;
            var mapFinished = mapStatus === 'done' || mapStatus === 'failed' || mapStatus === 'expired';
            if (mapFinished || (mapSaw && !lanScan.running)) stopSearch(true);
        }
    }

    function armSearch(map) {
        var waitMs = map ? 70000 : 90000;
        if (map) {
            mapWait = true;
            mapSaw = false;
            mapBaseline = !!(((printerPayload && printerPayload.device && printerPayload.device.lanScan) || {}).running);
            knownMapIds = rememberJobs(printerPayload, true);
            window.clearTimeout(mapTimer);
            mapTimer = window.setTimeout(function () {
                stopSearch(true);
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
            }, waitMs);
        } else {
            scanWait = true;
            scanSaw = false;
            scanBaseline = !!(((printerPayload && printerPayload.device && printerPayload.device.scan) || {}).running);
            knownScanIds = rememberJobs(printerPayload, false);
            window.clearTimeout(scanTimer);
            scanTimer = window.setTimeout(function () {
                stopSearch(false);
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
            }, waitMs);
        }
        printerSig = '';
        if (printerPayload) paintPrinters(printerPayload);
    }

    function paintPrinters(payload) {
        watchUserSearch(payload);
        printerPayload = payload;
        var device = (payload && payload.device) || {};
        reconcileSettings(device);
        syncPrinterChrome();
        var typing = printerView === 'settings' && printerBody && document.activeElement && printerBody.contains(document.activeElement) && document.activeElement.matches('[data-reprint-pin], [data-pizza-search]');
        if (typing) return;
        var sig = (scanWait ? '1' : '0') + '|' + (mapWait ? '1' : '0') + '|' + printerView + '|' + printerNote + '|' + (showChecks ? '1' : '0') + '|' + mapPick + '|' + pizzaQuery + '|' + JSON.stringify({
            printers: device.printers || [],
            discovered: device.discovered || [],
            scan: device.scan || null,
            kitchens: device.kitchenTypes || device.kitchen_types || [],
            lan: printerView === 'map' ? (device.lan || null) : null,
            lanScan: device.lanScan || null,
            pressure: showChecks ? (device.pressure || null) : null,
            settings: printerView === 'settings' ? shownSettings(device) : (device.settings || null),
            pending: pendingSettings
        });
        if (sig === printerSig && printerBody && printerBody.childElementCount) return;
        printerSig = sig;
        if (printerBody) printerBody.innerHTML = printerView === 'map' ? mapHtml(device) : printerView === 'settings' ? settingsHtml(device) : printersHtml(device);
        if (printerView === 'map') bindMap();
        if (printerView === 'settings') filterMeals();
    }

    function loadPrinters() {
        if (!printerDevice || !boot.deviceFeed) return;
        fetch(deviceUrl(boot.deviceFeed, printerDevice.id), { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(paintPrinters)
            .catch(function () {});
    }

    function sendCommand(name, args) {
        if (!printerDevice || !boot.command) return;
        var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        printerNote = boot.labels.queued || '';
        if (printerPayload) paintPrinters(printerPayload);
        fetch(deviceUrl(boot.command, printerDevice.id), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ name: name, args: args || {} })
        }).then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
            .then(function (result) {
                if (!result.ok) { stopSearch(false); stopSearch(true); }
                printerNote = (result.json && result.json.message) || (result.ok ? boot.labels.queued : boot.labels.busy);
                printerSig = '';
                loadPrinters();
            })
            .catch(function () {
                stopSearch(false);
                stopSearch(true);
                printerNote = boot.labels.busy || '';
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
            });
    }

    function openPrinters(id) {
        printerDevice = (current && current.devices || []).filter(function (device) { return String(device.id) === String(id); })[0] || { id: id, name: id };
        printerView = 'printers';
        printerNote = '';
        pendingSettings = null;
        pizzaQuery = '';
        printerPayload = null;
        printerSig = '';
        stopSearch(false);
        stopSearch(true);
        showChecks = false;
        pressureTargets = null;
        mapPick = '';
        if (!printerModal) return;
        // Above the restaurant modal, which sits in the top layer.
        if (printerLayer && !printerLayer.open) printerLayer.showModal();
        syncPrinterChrome();
        if (printerBody) printerBody.innerHTML = '<div class="sup-skel"></div><div class="sup-skel"></div>';
        printerModal.hidden = false;
        window.requestAnimationFrame(function () { printerModal.classList.add('is-open'); });
        loadPrinters();
        window.clearInterval(printerTimer);
        printerTimer = window.setInterval(loadPrinters, 2000);
    }

    if (printerModal) {
        printerModal.addEventListener('input', function (event) {
            if (event.target.matches('[data-pizza-search]')) {
                pizzaQuery = event.target.value;
                filterMeals();
            }
        });
        printerModal.addEventListener('change', function (event) {
            var pin = event.target.closest('[data-reprint-pin]');
            if (pin) {
                queueSettings({ kitchenReprintPassword: pin.value });
                return;
            }
            var setting = event.target.closest('[data-setting]');
            if (setting) {
                var patch = {};
                patch[setting.getAttribute('data-setting')] = !!setting.checked;
                queueSettings(patch);
                return;
            }
            if (!event.target.closest('[data-pizza-meal]') || !printerBody) return;
            var ids = [];
            printerBody.querySelectorAll('[data-pizza-meal]').forEach(function (box) {
                if (box.checked) ids.push(box.value);
            });
            queueSettings({ pizzaMealIds: ids });
        });
        printerModal.addEventListener('click', function (event) {
            if (event.target === printerModal || event.target.closest('[data-printer-close]')) {
                closePrinters();
                return;
            }
            if (event.target.closest('[data-show-settings]')) {
                printerView = 'settings';
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-show-map]')) {
                printerView = 'map';
                printerSig = '';
                mapFitted = false;
                mapCam = { scale: 1, x: 16, y: 16 };
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-show-printers]')) {
                printerView = 'printers';
                printerSig = '';
                mapPick = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            var zoom = event.target.closest('[data-zoom]');
            if (zoom) {
                if (zoom.getAttribute('data-zoom') === 'fit') fitMap();
                else {
                    mapCam.scale = Math.min(1.8, Math.max(0.35, mapCam.scale + (zoom.getAttribute('data-zoom') === 'in' ? 0.12 : -0.12)));
                    applyMapCam();
                }
                return;
            }
            var node = event.target.closest('[data-node]');
            if (node) {
                var id = node.getAttribute('data-node');
                mapPick = mapPick === id ? '' : id;
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-hide-summary]')) {
                mapSummary = false;
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-show-summary]')) {
                mapSummary = true;
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-scan-printers]')) {
                if (scanWait) return;
                armSearch(false);
                sendCommand('scan_lan', {});
                return;
            }
            if (event.target.closest('[data-map-lan]')) {
                if (mapWait) return;
                armSearch(true);
                sendCommand('scan_lan', { map: true });
                return;
            }
            if (event.target.closest('[data-kitchens]')) {
                var assure = printerModal.querySelector('[data-assure]');
                if (assure) assure.hidden = false;
                return;
            }
            if (event.target.closest('[data-assure-no]')) {
                var box = printerModal.querySelector('[data-assure]');
                if (box) box.hidden = true;
                return;
            }
            if (event.target.closest('[data-assure-yes]')) {
                var yes = printerModal.querySelector('[data-assure]');
                if (yes) yes.hidden = true;
                sendCommand('sync_kitchen_api', {});
                return;
            }
            if (event.target.closest('[data-checks]')) {
                showChecks = !showChecks;
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            var target = event.target.closest('[data-target]');
            if (target) {
                var saved = ((printerPayload && printerPayload.device && printerPayload.device.printers) || []);
                if (!pressureTargets) {
                    pressureTargets = {};
                    saved.forEach(function (printer) { pressureTargets[printerKey(printer)] = true; });
                }
                var key = target.getAttribute('data-target');
                pressureTargets[key] = !pressureTargets[key];
                printerSig = '';
                if (printerPayload) paintPrinters(printerPayload);
                return;
            }
            if (event.target.closest('[data-pressure-stop]')) { sendCommand('pressure_stop', {}); return; }
            if (event.target.closest('[data-pressure-clear]')) { sendCommand('pressure_clear', {}); return; }
            if (event.target.closest('[data-pressure-start]')) {
                var printers = ((printerPayload && printerPayload.device && printerPayload.device.printers) || []).filter(isTarget).map(function (printer) {
                    var host = hostOf(printer);
                    return { ip: host.ip, port: host.port };
                });
                sendCommand('pressure_test', {
                    copies: parseInt(pressureCopies, 10) || 5,
                    seconds: parseInt(pressureSeconds, 10) || 1,
                    printers: printers
                });
                return;
            }
            var role = event.target.closest('[data-role]');
            if (role) {
                var roleArgs = argsFrom(role);
                roleArgs.role = role.getAttribute('data-role');
                roleArgs.enabled = role.getAttribute('data-on') !== '1';
                sendCommand('set_role', roleArgs);
                return;
            }
            var station = event.target.closest('[data-station]');
            if (station) {
                var stationArgs = argsFrom(station);
                stationArgs.station = station.getAttribute('data-station');
                stationArgs.stationId = station.getAttribute('data-station-id');
                stationArgs.selected = station.getAttribute('data-on') !== '1';
                sendCommand('set_kitchen_type', stationArgs);
                return;
            }
            var test = event.target.closest('[data-test]');
            if (test) {
                var testArgs = argsFrom(test);
                testArgs.printer = testArgs.port && Number(testArgs.port) !== 9100 ? (testArgs.ip + ':' + testArgs.port) : testArgs.ip;
                sendCommand('test_print', testArgs);
                return;
            }
            var forget = event.target.closest('[data-forget]');
            if (forget) { sendCommand('forget_printer', argsFrom(forget)); return; }
            var remember = event.target.closest('[data-remember]');
            if (remember) sendCommand('remember_printer', argsFrom(remember));
        });
        printerModal.addEventListener('input', function (event) {
            if (event.target.matches('[data-copies]')) pressureCopies = event.target.value;
            if (event.target.matches('[data-seconds]')) pressureSeconds = event.target.value;
        });
    }
    if (modal) {
        modal.addEventListener('click', function (event) {
            var printers = event.target.closest('[data-printers]');
            if (printers) {
                event.stopPropagation();
                openPrinters(printers.getAttribute('data-printers'));
                return;
            }
            if (event.target === modal || event.target.closest('[data-sup-close]')) closeModal();
            var button = event.target.closest('[data-tab]');
            if (!button) return;
            tab = button.getAttribute('data-tab');
            Array.prototype.forEach.call(modal.querySelectorAll('[data-tab]'), function (item) {
                item.classList.toggle('is-active', item === button);
            });
            render();
        });
    }
    document.addEventListener('keydown', function (event) {
        var hit = (event.key === 'Enter' || event.key === ' ') && event.target.closest && event.target.closest('.sup-device.is-hit');
        if (hit) {
            event.preventDefault();
            openPrinters(event.target.closest('.sup-device.is-hit').getAttribute('data-printers'));
            return;
        }
        if (event.key !== 'Escape') return;
        if (printerModal && !printerModal.hidden) { closePrinters(); return; }
        if (modal) closeModal();
    });
    document.querySelectorAll('[data-status-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            var next = button.getAttribute('data-status-filter');
            if (button.classList.contains('is-active')) {
                statusFilter = 'recent';
                button.classList.remove('is-active');
            } else {
                statusFilter = next;
                document.querySelectorAll('[data-status-filter]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            }
            apply();
        });
    });
    if (search) search.addEventListener('input', apply);
    var tools = document.querySelector('[data-sup-tools]');
    if (tools) tools.addEventListener('submit', function (event) { event.preventDefault(); });
    apply();
    if (boot.open) openModal(boot.open);

    // The shared restaurant modal opens a device's printer tools from its Support section.
    window.SupportBoard = {
        openPrinters: function (restaurant, deviceId) {
            current = byId[restaurant] || current;
            openPrinters(deviceId);
        }
    };
    // A restaurant modal already open (from ?open=) redraws so its devices open their printer tools.
    if (window.KamanAgents && window.KamanAgents.refresh) window.KamanAgents.refresh();

    function cardNode(id) {
        var safe = window.CSS && CSS.escape ? CSS.escape(id) : id;
        return grid.querySelector('[data-open="' + safe + '"]');
    }

    function shortError(message) {
        var text = String(message || '');
        var cut = text.lastIndexOf('->');
        if (cut !== -1) text = text.slice(cut + 2).trim();
        return text.length > 90 ? text.slice(0, 87) + '…' : text;
    }

    function flash(node) {
        if (!node) return;
        node.classList.remove('is-live-change');
        void node.offsetWidth;
        node.classList.add('is-live-change');
        window.setTimeout(function () { node.classList.remove('is-live-change'); }, 2700);
    }

    function paintCounts() {
        var counts = { healthy: 0, warning: 0, offline: 0, unknown: 0, devices: 0 };
        grid.querySelectorAll('.sup-card').forEach(function (card) {
            var status = card.getAttribute('data-status') || 'unknown';
            counts[status] = (counts[status] || 0) + 1;
            counts.devices += Number(card.getAttribute('data-total') || 0);
        });
        Object.keys(counts).forEach(function (key) {
            var node = document.querySelector('[data-count="' + key + '"]');
            if (!node || node.textContent === String(counts[key])) return;
            node.textContent = String(counts[key]);
            node.classList.remove('is-tick');
            void node.offsetWidth;
            node.classList.add('is-tick');
        });
    }

    function refreshCard(row) {
        var card = cardNode(row.id);
        if (!card) return;
        var online = 0;
        var warning = 0;
        var offline = 0;
        var unknown = 0;
        var error = '';
        var lastAt = '';
        var lastHuman = '';
        row.devices.forEach(function (device) {
            if (device.status === 'warning') {
                warning += 1;
                online += 1;
            } else if (device.status === 'online' || device.online) {
                online += 1;
            } else if (device.lastSeenAt) {
                offline += 1;
            } else {
                unknown += 1;
            }
            if (!error && device.status === 'warning' && device.error) error = shortError(device.error);
            if (device.lastSeenAt && device.lastSeenAt > lastAt) {
                lastAt = device.lastSeenAt;
                lastHuman = device.lastSeen || '';
            }
        });
        var total = row.devices.length;
        var status = (total === 0 || (online === 0 && offline === 0 && unknown === total))
            ? 'unknown'
            : (online === 0 ? 'offline' : ((warning > 0 || online < total) ? 'warning' : 'healthy'));
        var changed = card.getAttribute('data-status') !== status
            || card.getAttribute('data-online') !== String(online)
            || (card.querySelector('[data-error]') || {}).textContent !== error;
        row.online = online;
        row.status = status;
        row.error = error;
        row.lastSeenAt = lastAt;
        row.lastSeen = lastHuman;
        row.products = productLine(row.devices);
        card.setAttribute('data-status', status);
        card.setAttribute('data-online', String(online));
        card.setAttribute('data-seen', lastAt);
        var ageMinutes = lastAt ? (Date.now() - Date.parse(lastAt)) / 60000 : 9999;
        var loginCard = card.getAttribute('data-open') === '_pending';
        card.setAttribute('data-recent', (loginCard || status === 'healthy' || status === 'warning' || ageMinutes <= 20) ? '1' : '0');
        var dot = card.querySelector('.sup-dot');
        if (dot) {
            dot.className = 'sup-dot is-' + status + (changed ? ' is-pulse' : '');
        }
        var count = card.querySelector('[data-count-line]');
        if (count) count.textContent = boot.labels.devicesOnline.replace(':online', online).replace(':total', total);
        var seen = card.querySelector('[data-seen-line]');
        if (seen) seen.textContent = lastHuman || boot.labels.never;
        var products = card.querySelector('[data-products]');
        if (products) {
            products.textContent = row.products;
            products.hidden = !row.products;
        }
        var errorNode = card.querySelector('[data-error]');
        if (errorNode) {
            errorNode.textContent = error;
            errorNode.hidden = !error;
        }
        if (changed) flash(card);
        if (current && current.id === row.id && modal && !modal.hidden) render();
    }

    function positions() {
        var map = new Map();
        grid.querySelectorAll('.sup-card').forEach(function (card) {
            map.set(card, card.getBoundingClientRect());
        });
        return map;
    }

    function glide(before) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        grid.querySelectorAll('.sup-card').forEach(function (card) {
            var start = before.get(card);
            if (!start || card.hidden) return;
            var next = card.getBoundingClientRect();
            var dx = start.left - next.left;
            var dy = start.top - next.top;
            if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
            card.classList.remove('is-moving');
            card.style.transition = 'none';
            card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
            requestAnimationFrame(function () {
                card.classList.add('is-moving');
                card.style.transition = '';
                card.style.transform = '';
            });
        });
    }

    function loadLogos() {
        if (!boot.logos) return;
        var ids = Object.keys(byId);
        if (!ids.length) return;
        var query = ids.map(function (id) { return 'restaurants[]=' + encodeURIComponent(id); }).join('&');
        fetch(boot.logos + '?' + query, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                var logos = (payload && payload.logos) || {};
                Object.keys(logos).forEach(function (id) {
                    if (!logos[id] || !byId[id]) return;
                    byId[id].logo = logos[id];
                    var card = cardNode(id);
                    if (card) paintLogo(card.querySelector('[data-logo]'), logos[id]);
                    if (current && current.id === id) render();
                });
            })
            .catch(function () {});
    }

    loadLogos();

    if (boot.feed) {
        var polling = false;
        function poll() {
            if (polling || document.hidden) return;
            polling = true;
            fetch(boot.feed + '?cards=1', { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    var incoming = {};
                    ['live', 'recent'].forEach(function (bucket) {
                        ((payload && payload[bucket]) || []).forEach(function (device) {
                            var key = device.restaurant || '';
                            (incoming[key] || (incoming[key] = {}))[device.deviceId] = device;
                        });
                    });
                    var before = positions();
                    var moved = false;
                    Object.keys(byId).forEach(function (key) {
                        var row = byId[key];
                        var updates = incoming[key];
                        if (!updates) return;
                        var touched = false;
                        row.devices.forEach(function (device) {
                            var next = updates[device.deviceId];
                            if (!next) return;
                            var source = String(next.failedSource || '').toLowerCase();
                            var message = String(next.failedMessage || '');
                            var printerish = !!next.online && (source === 'flutter' || source === 'printer' || /print/i.test(message));
                            var status = next.online ? (printerish ? 'warning' : 'online') : (next.lastSeenAt ? 'offline' : 'unknown');
                            if (device.online !== !!next.online || device.status !== status || device.version !== (next.appVersion || device.version) || device.product !== (next.product || device.product)) {
                                touched = true;
                            }
                            device.online = !!next.online;
                            device.status = status;
                            device.version = next.appVersion || device.version;
                            device.product = next.product || device.product;
                            device.lastSeen = next.lastSeen || device.lastSeen;
                            device.lastSeenAt = next.lastSeenAt || device.lastSeenAt;
                            device.error = printerish ? message : null;
                        });
                        if (touched) {
                            moved = true;
                            refreshCard(row);
                        }
                    });
                    if (moved) {
                        apply();
                        glide(before);
                        paintCounts();
                    }
                })
                .catch(function () {})
                .then(function () { polling = false; });
        }
        window.setTimeout(poll, 2000);
        window.setInterval(poll, 8000);
    }
})();
