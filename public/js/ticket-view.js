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

    // The Kaman mark, from next to this script: /js/ticket-view.js -> /kaman.png.
    var script = document.currentScript;
    var kamanLogo = script && script.src ? script.src.replace(/js\/ticket-view\.js.*$/, 'kaman.png') : '/kaman.png';
    var loadingWords = { ar: 'جارٍ تحميل التذكرة…', he: 'טוען את הפנייה…', en: 'Loading the ticket…' };

    // The workspace's own Kaman AI loader (partials/kaman-ai-loader), small, with a caption.
    function loader() {
        var lang = (document.documentElement.lang || 'en').slice(0, 2);
        var box = el('div', 'tk-kaman-loader');
        box.setAttribute('role', 'status');
        var mark = el('div', 'kaman-ai-loader kaman-ai-loader--sm');
        mark.setAttribute('aria-hidden', 'true');
        var orbit = el('span', 'kaman-ai-loader__orbit');
        orbit.append(el('i'), el('i'), el('i'), el('i'));
        var logo = el('img', 'kaman-ai-loader__logo');
        logo.src = kamanLogo;
        logo.alt = '';
        mark.append(
            el('span', 'kaman-ai-loader__ring'),
            el('span', 'kaman-ai-loader__ring kaman-ai-loader__ring--b'),
            el('span', 'kaman-ai-loader__ring kaman-ai-loader__ring--c'),
            orbit,
            el('span', 'kaman-ai-loader__pulse'),
            logo
        );
        box.append(mark, el('p', null, loadingWords[lang] || loadingWords.en));
        return box;
    }

    // The restaurant's logo, or its first letter while there is none.
    function logoMark(ticket) {
        var name = ticket.restaurant || ticket.slug || '';
        var mark = el('span', 'tk-ticket__logo');
        mark.append(el('b', null, name ? name.charAt(0).toUpperCase() : '?'));
        if (ticket.logo) {
            var img = el('img');
            img.alt = '';
            img.src = ticket.logo;
            img.onerror = function () { img.remove(); };
            mark.append(img);
        }
        return mark;
    }

    function header(ticket, copy) {
        var head = el('div', 'tk-assure__head tk-ticket__head');
        var title = el('div', 'tk-ticket__title');
        var name = el('h2');
        name.id = 'tk-view-title';
        var place;
        if (ticket.restaurantKey) {
            place = el('button', 'tk-ticket__place sp-rest-link');
            place.type = 'button';
            place.setAttribute('data-act', 'open-support');
            place.setAttribute('data-id', ticket.restaurantKey);
        } else {
            place = el('span', 'tk-ticket__place');
        }
        place.append(logoMark(ticket), el('span', null, ticket.restaurant || ticket.id || ''));
        name.append(place);
        var meta = el('p', 'tk-ticket__meta');
        var id = el('bdi', null, ticket.id || '');
        id.dir = 'ltr';
        meta.append(id);
        [ticket.at, ticket.cashier].forEach(function (part) { if (part) meta.append(document.createTextNode(' · ' + part)); });
        var badges = el('div', 'tk-ticket__badges');
        if (ticket.statusLabel) badges.append(el('span', 'tk-ticket__badge is-' + (ticket.status || 'open'), ticket.statusLabel));
        if (ticket.levelLabel) badges.append(el('span', 'tk-ticket__badge is-level-' + (ticket.level || ''), ticket.levelLabel));
        title.append(name, meta, badges);
        head.append(title, closeButton(copy));
        return head;
    }

    // What the cashier wrote, once: no title copy, no details copy, no timeline copy.
    function reportBlock(ticket, copy) {
        var text = ticket.report != null ? ticket.report : (ticket.details || ticket.title || '');
        var box = el('div', 'tk-ticket__report');
        if (copy.report) box.append(el('p', 'tk-ticket__label', copy.report));
        box.append(el('p', 'tk-ticket__text' + (text ? '' : ' is-empty'), text || copy.noReport || '—'));
        return box;
    }

    function preview(ticket) {
        var sheet = el('div', 'tk-assure__sheet');
        sheet.append(header(ticket, {}), reportBlock(ticket, {}), loader());
        dialog.replaceChildren(sheet);
    }

    function render(payload) {
        var ticket = payload.ticket || {};
        var copy = payload.copy || {};
        var sheet = el('div', 'tk-assure__sheet');
        var head = header(ticket, copy);
        var report = reportBlock(ticket, copy);

        var body = el('dl', 'tk-assure__body');
        if (ticket.contact && ticket.contact.whatsapp) {
            var chat = el('a', 'ds-btn ds-btn--secondary ds-btn--sm sp-whatsapp');
            chat.href = ticket.contact.whatsapp;
            chat.target = '_blank';
            chat.rel = 'noopener';
            var number = el('bdi', null, ticket.contact.phone || '');
            number.dir = 'ltr';
            chat.append(document.createTextNode((copy.whatsapp || 'WhatsApp') + ' '), number);
            fact(body, copy.contact, chat);
        }
        if (ticket.device) {
            var device = el('bdi', null, ticket.device);
            device.dir = 'ltr';
            fact(body, copy.device, device);
        }

        sheet.append(head, report, body);

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

    // Answers kept for a minute: hovering a row starts the request, the click finds it ready.
    var cache = {};
    function load(url) {
        var hit = cache[url];
        if (hit && Date.now() - hit.at < 60000) return hit.promise;
        var promise = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.json(); });
        cache[url] = { at: Date.now(), promise: promise };
        promise.catch(function () { delete cache[url]; });
        return promise;
    }

    function prefetch(url) {
        if (url && !cache[url]) load(url).catch(function () {});
    }

    function open(url, row) {
        if (!url) return;
        mount();
        stopMedia();
        var mine = ++request;
        if (row && (row.restaurant || row.report || row.title)) preview(row);
        else {
            var sheet = el('div', 'tk-assure__sheet tk-ticket--loading');
            sheet.append(loader());
            dialog.replaceChildren(sheet);
        }
        if (!dialog.open) dialog.showModal();
        load(url)
            .then(function (payload) { if (mine === request) render(payload); })
            .catch(function () { if (mine === request) shut(); });
    }

    window.TicketView = { open: open, close: shut, prefetch: prefetch };
})();
