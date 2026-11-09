/*
 * The ticket modal. Support tickets have no page of their own: the inbox, the restaurant modal and any
 * link to a ticket open this dialog. TicketView.open(url) loads the ticket as JSON from that url.
 */
(function () {
    if (window.TicketView) return;

    var dialog = null;
    var screenTimer = 0;
    var request = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function shut() {
        if (!dialog || !dialog.open) return;
        if (window.kamanCloseDialog) window.kamanCloseDialog(dialog);
        else dialog.close();
    }

    function mount() {
        if (dialog) return dialog;
        dialog = el('dialog', 'tk-assure tk-assure--view tk-ticket');
        dialog.setAttribute('aria-labelledby', 'tk-view-title');
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog || event.target.closest('[data-tk-view-close]')) shut();
            // The restaurant name opens the restaurant modal, which takes over from here.
            if (event.target.closest('[data-act="open-support"]')) shut();
        });
        dialog.addEventListener('close', stopMedia);
        document.body.appendChild(dialog);
        return dialog;
    }

    function stopMedia() {
        if (screenTimer) {
            clearInterval(screenTimer);
            screenTimer = 0;
        }
        var audio = dialog && dialog.querySelector('audio');
        if (audio) {
            audio.pause();
            audio.removeAttribute('src');
        }
    }

    function closeButton(copy) {
        var x = el('button', 'ds-btn ds-btn--ghost ds-btn--icon tk-assure__x');
        x.type = 'button';
        x.setAttribute('data-tk-view-close', '');
        x.setAttribute('aria-label', copy.close || '');
        x.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        return x;
    }

    function fact(list, label, value) {
        if (!value) return null;
        var row = el('div');
        row.append(el('dt', null, label));
        var dd = el('dd');
        if (value instanceof Node) dd.append(value);
        else dd.textContent = value;
        row.append(dd);
        list.append(row);
        return dd;
    }

    function render(payload) {
        var ticket = payload.ticket || {};
        var copy = payload.copy || {};
        var sheet = el('div', 'tk-assure__sheet');

        var head = el('div', 'tk-assure__head');
        var title = el('div', 'tk-ticket__title');
        var h2 = el('h2', null, ticket.title || ticket.id || '');
        h2.id = 'tk-view-title';
        var meta = el('p', 'tk-ticket__meta');
        var id = el('bdi', null, ticket.id || '');
        id.dir = 'ltr';
        meta.append(id);
        if (ticket.at) meta.append(document.createTextNode(' · ' + ticket.at));
        var badges = el('div', 'tk-ticket__badges');
        if (ticket.statusLabel) badges.append(el('span', 'tk-ticket__badge is-' + (ticket.status || 'open'), ticket.statusLabel));
        if (ticket.levelLabel) badges.append(el('span', 'tk-ticket__badge is-level-' + (ticket.level || ''), ticket.levelLabel));
        title.append(h2, meta, badges);
        head.append(title, closeButton(copy));

        var body = el('dl', 'tk-assure__body');
        fact(body, copy.details, ticket.details || '—');
        if (ticket.restaurant) {
            var place = el('button', 'sp-rest-link', ticket.restaurant);
            place.type = 'button';
            place.setAttribute('data-act', 'open-support');
            place.setAttribute('data-id', ticket.restaurantKey || '');
            place.disabled = !ticket.restaurantKey;
            fact(body, copy.restaurant, place);
        }
        if (ticket.device) {
            var device = el('bdi', null, ticket.device);
            device.dir = 'ltr';
            fact(body, copy.device, device);
        }

        sheet.append(head, body);

        if (ticket.voice || ticket.screen) {
            var media = el('div', 'tk-assure__media');
            if (ticket.voice) {
                var voice = el('div');
                var audio = el('audio');
                audio.controls = true;
                audio.preload = 'none';
                audio.src = ticket.voice;
                voice.append(el('p', null, copy.voice), audio);
                media.append(voice);
            }
            if (ticket.screen) {
                var screen = el('div');
                var canvas = el('canvas');
                screen.append(el('p', null, copy.screen), canvas);
                media.append(screen);
                playScreen(canvas, ticket.screen);
            }
            sheet.append(media);
        }

        if (ticket.events && ticket.events.length) {
            var events = el('div', 'tk-ticket__events');
            events.append(el('p', 'tk-ticket__label', copy.events));
            ticket.events.forEach(function (event) {
                var item = el('div', 'tk-ticket__event');
                item.append(el('strong', null, event.type), el('small', null, [event.actor, event.at].filter(Boolean).join(' · ')));
                if (event.message) item.append(el('p', null, event.message));
                events.append(item);
            });
            sheet.append(events);
        }

        var foot = el('div', 'tk-assure__foot');
        var close = el('button', 'ds-btn ds-btn--secondary', copy.close);
        close.type = 'button';
        close.setAttribute('data-tk-view-close', '');
        foot.append(close);
        sheet.append(foot);

        dialog.replaceChildren(sheet);
        close.focus({ preventScroll: true });
    }

    function playScreen(canvas, url) {
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) { return response.arrayBuffer(); })
            .then(async function (buf) {
                var view = new DataView(buf);
                if (buf.byteLength < 13) return;
                var magic = String.fromCharCode(view.getUint8(0), view.getUint8(1), view.getUint8(2), view.getUint8(3));
                if (magic !== 'KSCR') return;
                var delay = view.getUint16(5);
                canvas.width = view.getUint16(7);
                canvas.height = view.getUint16(9);
                var count = view.getUint16(11);
                var ctx = canvas.getContext('2d');
                if (!ctx) return;
                var offset = 13;
                var frames = [];
                for (var i = 0; i < count; i++) {
                    if (offset + 4 > buf.byteLength) break;
                    var len = view.getUint32(offset);
                    offset += 4;
                    if (len < 1 || offset + len > buf.byteLength) break;
                    frames.push(await createImageBitmap(new Blob([buf.slice(offset, offset + len)], { type: 'image/jpeg' })));
                    offset += len;
                }
                if (!frames.length || !dialog.open || !canvas.isConnected) return;
                var frame = 0;
                var draw = function () {
                    ctx.drawImage(frames[frame], 0, 0, canvas.width, canvas.height);
                    frame = (frame + 1) % frames.length;
                };
                draw();
                if (frames.length > 1) screenTimer = setInterval(draw, Math.max(delay, 50));
            })
            .catch(function () {});
    }

    function open(url) {
        if (!url) return;
        mount();
        stopMedia();
        var mine = ++request;
        dialog.replaceChildren(el('div', 'tk-assure__sheet tk-ticket--loading', '…'));
        if (!dialog.open) dialog.showModal();
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.json(); })
            .then(function (payload) { if (mine === request) render(payload); })
            .catch(function () { if (mine === request) shut(); });
    }

    window.TicketView = { open: open, close: shut };
})();
