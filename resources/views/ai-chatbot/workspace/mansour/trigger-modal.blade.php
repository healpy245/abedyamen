@php use App\Support\FarzUi; @endphp
<div id="mansour-trigger-modal" class="farz-send fixed inset-0 z-50 hidden items-center justify-center p-3" role="dialog" aria-modal="true" aria-labelledby="mansour-trigger-title" hidden>
    <div class="farz-send__backdrop absolute inset-0" data-mansour-close></div>
    <div id="mansour-send-card" class="farz-send__card relative z-10 flex w-full flex-col overflow-hidden">
        <header class="farz-send__head">
            <span class="farz-send__badge">{{ FarzUi::svg('send') }}</span>
            <h3 id="mansour-trigger-title">{{ __('chatbot.mansour.pick') }}</h3>
            <p id="mansour-send-line" class="farz-send__via" hidden></p>
            <div id="mansour-run-controls" class="farz-send__runbtns hidden" hidden>
                <button type="button" id="mansour-live-stop" class="farz-send__pill is-danger">{{ __('chatbot.mansour.send_stop') }}</button>
                <button type="button" id="mansour-live-resume" class="farz-send__pill is-go hidden" hidden>{{ __('chatbot.mansour.send_resume') }}</button>
            </div>
            <button type="button" class="farz-send__x" data-mansour-close aria-label="{{ __('chatbot.workspace.close') }}" title="{{ __('chatbot.workspace.close') }}">{{ FarzUi::svg('x') }}</button>
        </header>

        <div id="mansour-pick" class="flex min-h-0 flex-1 flex-col">
        <div class="farz-send__tools">
            <form method="post" action="{{ route('ai-chatbot.workspace.mansour.contacts.store', $instance) }}" enctype="multipart/form-data" class="farz-send__upload">
                @csrf
                <label for="mansour-excel" class="farz-send__file" title="{{ __('chatbot.mansour.upload') }}">
                    {{ FarzUi::svg('upload') }}
                    <span data-file-label>{{ __('chatbot.mansour.upload') }}</span>
                </label>
                <input id="mansour-excel" name="excel" type="file" accept=".xlsx,.csv,.txt" required class="sr-only"
                       onchange="this.form.querySelector('[data-file-label]').textContent = this.files[0]?.name || this.form.querySelector('[data-file-label]').textContent">
                <button type="submit" class="farz-send__iconbtn is-solid" title="{{ __('chatbot.mansour.save') }}" aria-label="{{ __('chatbot.mansour.save') }}">{{ FarzUi::svg('save') }}</button>
            </form>

            <form id="mansour-custom-form" class="farz-send__add">
                <span class="farz-send__field">
                    {{ FarzUi::svg('user') }}
                    <input name="name" type="text" maxlength="120" placeholder="{{ __('chatbot.mansour.add_name') }}" autocomplete="off">
                </span>
                <span class="farz-send__field farz-send__field--grow">
                    {{ FarzUi::svg('phone') }}
                    <input name="phone" type="text" required maxlength="32" placeholder="{{ __('chatbot.mansour.add_phone') }}" dir="ltr" autocomplete="off">
                </span>
                <button type="submit" class="farz-send__iconbtn is-solid" title="{{ __('chatbot.mansour.add') }}" aria-label="{{ __('chatbot.mansour.add') }}">{{ FarzUi::svg('plus') }}</button>
                <p id="mansour-custom-error" class="farz-send__error hidden"></p>
            </form>
        </div>

        <div class="farz-send__assignbar">
            <div id="mansour-line-chips" class="farz-send__chips"></div>
        </div>

        <div class="farz-send__bar">
            <button type="button" id="mansour-safe-sort" class="farz-send__pill is-safe" title="{{ __('chatbot.mansour.safe_sort') }}">{{ FarzUi::svg('checks') }}<span>{{ __('chatbot.mansour.safe_sort') }}</span></button>
            <button type="button" id="mansour-select-unsent" class="farz-send__pill" title="{{ __('chatbot.mansour.select_unsent') }}">{{ FarzUi::svg('checks') }}<span>{{ __('chatbot.mansour.select_unsent') }}</span></button>
            <button type="button" id="mansour-select-sent" class="farz-send__pill" title="{{ __('chatbot.mansour.select_sent') }}">{{ FarzUi::svg('checks') }}<span>{{ __('chatbot.mansour.select_sent') }}</span></button>
            <button type="button" id="mansour-clear-all" class="farz-send__pill" title="{{ __('chatbot.mansour.clear_all') }}">{{ FarzUi::svg('x') }}<span>{{ __('chatbot.mansour.clear_all') }}</span></button>
            <button type="button" id="mansour-show-history" class="farz-send__pill" title="{{ __('chatbot.mansour.history') }}">{{ FarzUi::svg('clock') }}<span>{{ __('chatbot.mansour.history') }}</span></button>
            <button type="button" id="mansour-delete-selected" class="farz-send__pill is-danger" title="{{ __('chatbot.mansour.delete_selected') }}">{{ FarzUi::svg('trash') }}<span>{{ __('chatbot.mansour.delete_selected') }}</span></button>
        </div>

        <div id="mansour-trigger-list" class="farz-send__list min-h-0 flex-1 overflow-y-auto"></div>

        <form method="post" action="{{ route('ai-chatbot.workspace.mansour.trigger', $instance) }}" class="farz-send__foot">
            @csrf
            <div id="mansour-trigger-ids"></div>
            <p id="mansour-send-error" class="farz-send__error hidden"></p>
            <button type="button" id="mansour-send" class="farz-send__go">{{ FarzUi::svg('send') }} {{ __('chatbot.mansour.send') }}</button>
        </form>
        </div>

        <div id="mansour-live" class="farz-send__live" hidden>
            <div class="farz-send__livebar">
                <button type="button" id="mansour-live-back" class="farz-send__pill">{{ FarzUi::svg('arrow') }}<span>{{ __('chatbot.mansour.back_numbers') }}</span></button>
                <p id="mansour-live-summary"></p>
            </div>
            <div id="mansour-run-chips" class="farz-send__chips"></div>
            <div class="farz-send__stage">
                <div id="mansour-live-list" class="farz-send__log"></div>
                <aside class="farz-send__inspect">
                    <p class="farz-send__kicker">{{ __('chatbot.mansour.debug_title') }}</p>
                    <p id="mansour-live-human"></p>
                    <p class="farz-send__kicker">{{ __('chatbot.mansour.debug_tech') }}</p>
                    <p id="mansour-live-meta" dir="ltr"></p>
                    <pre id="mansour-live-raw" dir="ltr"></pre>
                </aside>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('mansour-trigger-modal');
    const list = document.getElementById('mansour-trigger-list');
    const sendError = document.getElementById('mansour-send-error');
    const customForm = document.getElementById('mansour-custom-form');
    const customError = document.getElementById('mansour-custom-error');
    const url = @json(route('ai-chatbot.workspace.mansour.contacts', $instance));
    const triggerUrl = @json(route('ai-chatbot.workspace.mansour.trigger', $instance));
    const sendsUrl = @json(route('ai-chatbot.workspace.mansour.sends', $instance));
    const pickTitle = @json(__('chatbot.mansour.pick'));
    const historyTitle = @json(__('chatbot.mansour.history'));
    const waitingLabel = @json(__('chatbot.mansour.debug_waiting'));
    const requestFailedLabel = @json(__('chatbot.mansour.debug_request_failed'));
    const progressLabel = @json(__('chatbot.mansour.sending_progress'));
    const pausedLabel = @json(__('chatbot.mansour.send_paused'));
    const continuesLabel = @json(__('chatbot.mansour.send_continues'));
    const doneLabel = @json(__('chatbot.mansour.send_done'));
    const historyEmpty = @json(__('chatbot.mansour.history_empty'));
    const stoppedLabel = @json(__('chatbot.mansour.debug_stopped'));
    const customUrl = @json(route('ai-chatbot.workspace.mansour.contacts.custom', $instance));
    const deleteUrl = @json(route('ai-chatbot.workspace.mansour.contacts.destroy', $instance));
    const emptyLabel = @json(__('chatbot.mansour.empty'));
    const sentLabel = @json(__('chatbot.mansour.already_sent'));
    const sentGroupLabel = @json(__('chatbot.mansour.sent_group'));
    const resendConfirm = @json(__('chatbot.mansour.resend_confirm'));
    const deleteConfirm = @json(__('chatbot.mansour.delete_confirm'));
    const noneSelected = @json(__('chatbot.mansour.none_selected'));
    const lineOffLabel = @json(__('chatbot.mansour.line_off'));
    const linePausedLabel = @json(__('chatbot.mansour.line_paused'));
    const assignNeed = @json(__('chatbot.mansour.assign_need'));
    const assignMissing = @json(__('chatbot.mansour.assign_missing'));
    const paceWait = @json(__('chatbot.mansour.pace_wait'));
    const safeTooMany = @json(__('chatbot.mansour.safe_too_many'));
    const safeNeed = @json(__('chatbot.mansour.safe_need'));
    const safeNone = @json(__('chatbot.mansour.safe_none'));
    const safeOver = @json(__('chatbot.mansour.safe_over'));
    const safeLeft = @json(__('chatbot.mansour.safe_left'));
    const waveCap = @json(\App\Services\AiChatbot\MansourLines::WAVE_CAP);
    const dayCap = @json(\App\Services\AiChatbot\MansourLines::DAY_CAP);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!modal || !list) return;

    const rowLabel = (row) => (row.name ? row.name + ' · ' : '') + row.phone;

    const contactRow = (row, sentAlready) => {
        const label = document.createElement('label');
        label.className = 'farz-send__row' + (sentAlready ? ' is-sent' : '');
        label.dataset.name = row.name || '';
        label.dataset.phone = row.phone || '';
        const text = document.createElement('span');
        text.className = 'farz-send__who';
        text.textContent = rowLabel(row);
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.value = String(row.id);
        box.className = 'mansour-pick' + (sentAlready ? ' mansour-pick--sent' : '');
        box.dataset.sent = (Array.isArray(row.sent_lines) ? row.sent_lines : []).join(',');
        if (!sentAlready) {
            label.append(box, text);
            return label;
        }
        const tick = document.createElement('span');
        tick.className = 'farz-send__tick';
        tick.setAttribute('aria-hidden', 'true');
        tick.textContent = '✓';
        const badge = document.createElement('span');
        badge.className = 'farz-send__sent';
        badge.textContent = sentLabel;
        label.append(box, tick, text, badge);
        return label;
    };

    const pendingBox = () => {
        let box = document.getElementById('mansour-pending');
        if (box) return box;
        if (list.querySelector('.mansour-empty')) {
            list.innerHTML = '';
        }
        box = document.createElement('div');
        box.id = 'mansour-pending';
        const heading = list.querySelector('.farz-send__section');
        list.insertBefore(box, heading);
        return box;
    };

    const appendContact = (row, checked) => {
        const existing = list.querySelector('.mansour-pick[value="' + row.id + '"]');
        if (existing) {
            existing.checked = checked || existing.checked;
            return;
        }
        const label = contactRow(row, false);
        const box = label.querySelector('.mansour-pick');
        if (checked) {
            box.checked = true;
            fresh.add(String(row.id));
        }
        restoreLine(label, row);
        pendingBox().append(label);
    };

    const showEmpty = () => {
        list.innerHTML = '';
        const note = document.createElement('p');
        note.className = 'mansour-empty farz-send__empty';
        note.textContent = emptyLabel;
        list.append(note);
    };

    const hideSendError = () => {
        if (!sendError) return;
        sendError.textContent = '';
        sendError.classList.add('hidden');
    };

    const paintContacts = (contacts) => {
        list.innerHTML = '';
        if (!contacts.length) {
            showEmpty();
            return;
        }
        const alreadySent = (row) => !!row.triggered || (Array.isArray(row.sent_lines) && row.sent_lines.length > 0);
        const pending = contacts.filter((row) => !alreadySent(row));
        const sent = contacts.filter((row) => alreadySent(row));
        const pendingWrap = document.createElement('div');
        pendingWrap.id = 'mansour-pending';
        pending.forEach((row) => {
            const label = contactRow(row, false);
            restoreLine(label, row);
            pendingWrap.append(label);
        });
        list.append(pendingWrap);
        if (!sent.length) return;
        const heading = document.createElement('p');
        heading.className = 'farz-send__section';
        heading.textContent = sentGroupLabel;
        list.append(heading);
        sent.forEach((row) => {
            const label = contactRow(row, true);
            restoreLine(label, row);
            list.append(label);
        });
    };

    const lineChips = document.getElementById('mansour-line-chips');
    let sendLines = [];
    const linked = new Map();
    const fresh = new Set();
    const colorOf = (lineId) => {
        const index = sendLines.findIndex((line) => line.id === lineId);
        return (index < 0 ? 0 : index) % 6;
    };
    const markFrom = (label, lineId) => {
        if (!label) return;
        let from = label.querySelector('.farz-send__from');
        if (!lineId) {
            from?.remove();
            return;
        }
        if (!from) {
            from = document.createElement('b');
            from.className = 'farz-send__from';
            from.dir = 'ltr';
            label.append(from);
        }
        from.textContent = linePhone(lineId);
    };
    const clearLine = (box) => {
        fresh.delete(box.value);
        linked.delete(box.value);
        delete box.dataset.line;
        const label = box.closest('label');
        if (!label) return;
        for (let i = 0; i < 6; i++) label.classList.remove('is-c' + i);
        markFrom(label, '');
    };
    const paintLine = (box, lineId) => {
        box.dataset.line = lineId;
        linked.set(box.value, lineId);
        fresh.delete(box.value);
        const label = box.closest('label');
        if (!label) return;
        for (let i = 0; i < 6; i++) label.classList.remove('is-c' + i);
        label.classList.add('is-c' + colorOf(lineId));
        markFrom(label, lineId);
    };
    const restoreLine = (label, row) => {
        const box = label.querySelector('.mansour-pick');
        if (!box) return;
        const lineId = linked.get(String(row.id));
        if (lineId) {
            box.dataset.line = lineId;
            label.classList.add('is-c' + colorOf(lineId));
            markFrom(label, lineId);
            box.checked = true;
        } else if (fresh.has(String(row.id))) {
            box.checked = true;
        }
    };
    const usableLine = (line) => !!(line && line.active && line.status !== 'blocked' && line.level !== 'dead');
    const roomOf = (line) => {
        if (!usableLine(line)) return 0;
        const today = Number(line.today || 0);
        const cap = Number(line.cap || dayCap);
        return Math.min(waveCap, Math.max(0, cap - today));
    };
    const showSendError = (text) => {
        if (!sendError) return;
        sendError.textContent = text;
        sendError.classList.remove('hidden');
    };
    const assignTo = (lineId) => {
        const line = sendLines.find((item) => item.id === lineId);
        if (!line || !line.active) {
            showSendError(lineOffLabel);
            return;
        }
        const targets = [...list.querySelectorAll('.mansour-pick:checked')].filter((box) => fresh.has(box.value));
        if (!targets.length) {
            showSendError(assignNeed);
            return;
        }
        const already = [...list.querySelectorAll('.mansour-pick')].filter((box) => box.dataset.line === lineId).length;
        const left = Math.max(0, roomOf(line) - already);
        if (left <= 0) {
            showSendError(fill(safeOver, { phone: line.phone || line.label || lineId, max: roomOf(line) }));
            return;
        }
        targets.slice(0, left).forEach((box) => paintLine(box, lineId));
        if (targets.length > left) {
            targets.slice(left).forEach((box) => {
                box.checked = false;
                clearLine(box);
            });
            showSendError(fill(safeOver, { phone: line.phone || line.label || lineId, max: roomOf(line) }));
            return;
        }
        hideSendError();
    };
    const safeSort = () => {
        const selected = [...list.querySelectorAll('.mansour-pick:checked')];
        if (!selected.length) {
            showSendError(safeNeed);
            return;
        }
        const selectedSet = new Set(selected);
        const slots = sendLines.map((line) => {
            const taken = [...list.querySelectorAll('.mansour-pick')]
                .filter((box) => box.dataset.line === line.id && !selectedSet.has(box)).length;
            return { line, left: Math.max(0, roomOf(line) - taken) };
        }).filter((slot) => slot.left > 0);
        const totalRoom = slots.reduce((sum, slot) => sum + slot.left, 0);
        selected.forEach((box) => clearLine(box));
        if (totalRoom <= 0) {
            selected.forEach((box) => { box.checked = false; });
            showSendError(safeNone);
            return;
        }
        let placed = 0;
        selected.forEach((box) => {
            const sent = new Set(String(box.dataset.sent || '').split(',').filter((id) => id !== ''));
            const slot = slots
                .filter((item) => item.left > 0 && !sent.has(item.line.id))
                .sort((a, b) => b.left - a.left || Number(a.line.today || 0) - Number(b.line.today || 0))[0];
            if (!slot) {
                box.checked = false;
                return;
            }
            box.checked = true;
            paintLine(box, slot.line.id);
            slot.left -= 1;
            placed += 1;
        });
        if (placed === 0) {
            showSendError(safeNone);
            return;
        }
        if (placed < selected.length) {
            showSendError(fill(safeTooMany, { max: placed }));
            return;
        }
        hideSendError();
    };
    const fillLines = (lines) => {
        sendLines = Array.isArray(lines) ? lines : [];
        if (!lineChips) return;
        lineChips.innerHTML = '';
        sendLines.forEach((line, index) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'farz-send__chip farz-send__assign is-c' + (index % 6) + (line.active ? '' : ' is-off');
            const phone = line.phone || line.label || line.id;
            const left = roomOf(line);
            chip.textContent = phone + (usableLine(line) ? ' · ' + fill(safeLeft, { count: left }) : ' · ' + linePausedLabel);
            chip.addEventListener('click', () => assignTo(line.id));
            lineChips.append(chip);
        });
    };
    const linePhone = (lineId) => sendLines.find((line) => line.id === lineId)?.phone || '';
    let contactsRequest = null;
    const loadContacts = () => {
        if (!contactsRequest) {
            contactsRequest = fetch(url, { headers: { Accept: 'application/json' } })
                .then((res) => res.json())
                .then((data) => {
                    fillLines(data.lines || []);
                    return data.contacts || [];
                })
                .catch(() => {
                    contactsRequest = null;
                    return [];
                });
        }
        return contactsRequest;
    };

    const card = document.getElementById('mansour-send-card');
    const pick = document.getElementById('mansour-pick');
    const live = document.getElementById('mansour-live');
    const liveList = document.getElementById('mansour-live-list');
    const liveHuman = document.getElementById('mansour-live-human');
    const liveMeta = document.getElementById('mansour-live-meta');
    const liveRaw = document.getElementById('mansour-live-raw');
    const liveSummary = document.getElementById('mansour-live-summary');
    const runChips = document.getElementById('mansour-run-chips');
    const title = document.getElementById('mansour-trigger-title');
    let sending = false;
    let runs = [];
    const run = { paused: false, done: 0, total: 0 };
    let refreshRun = () => {};
    const stopBtn = document.getElementById('mansour-live-stop');
    const resumeBtn = document.getElementById('mansour-live-resume');
    const runControls = document.getElementById('mansour-run-controls');
    const opener = document.getElementById('mansour-trigger-open');
    const openerLabel = opener?.querySelector('span');
    const openerText = openerLabel?.textContent || '';
    const openerTitle = opener?.getAttribute('title') || '';

    const fill = (template, map) => template.replace(/:(\w+)/g, (_, key) => map[key] ?? '');

    const setHidden = (el, hide) => {
        if (!el) return;
        el.hidden = hide;
        el.classList.toggle('hidden', hide);
    };

    const syncControls = () => {
        setHidden(runControls, !sending);
        setHidden(stopBtn, !sending || run.paused);
        setHidden(resumeBtn, !sending || !run.paused);
        opener?.classList.toggle('is-live', sending && !run.paused);
        opener?.classList.toggle('is-held', sending && run.paused);
        if (openerLabel) {
            openerLabel.textContent = sending ? (run.done + ' / ' + run.total) : openerText;
        }
        if (opener) {
            opener.title = sending
                ? fill(run.paused ? pausedLabel : continuesLabel, { done: run.done, total: run.total })
                : openerTitle;
        }
    };

    stopBtn?.addEventListener('click', () => {
        if (!sending) return;
        run.paused = true;
        refreshRun();
    });
    resumeBtn?.addEventListener('click', () => {
        if (!sending) return;
        run.paused = false;
        refreshRun();
    });

    const reveal = () => {
        modal.hidden = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    const hideModal = () => {
        modal.hidden = true;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        syncControls();
    };

    const showPick = () => {
        pick.hidden = false;
        live.hidden = true;
        card?.classList.remove('is-wide');
        if (title) title.textContent = pickTitle;
    };

    const showLive = () => {
        pick.hidden = true;
        live.hidden = false;
        card?.classList.add('is-wide');
        if (title) title.textContent = historyTitle;
    };

    const debugText = (row) => {
        const debug = row?.debug;
        if (!debug) return '';
        const lines = [];
        if (Array.isArray(debug.attempts) && debug.attempts.length) {
            debug.attempts.forEach((attempt, index) => {
                lines.push('#' + (index + 1) + '  ' + (attempt.provider === 'whapi' ? 'Whapi' : 'Green API') + '  HTTP ' + attempt.http);
                if (attempt.message_id) lines.push(attempt.message_id);
                if (attempt.line) lines.push(attempt.line);
                if (attempt.body) lines.push(attempt.body);
                lines.push('');
            });
            return lines.join('\n').trim();
        }
        return debug.body || '';
    };

    const metaText = (row) => {
        const debug = row?.debug;
        if (!debug) return '';
        return [debug.provider, debug.http ? 'HTTP ' + debug.http : '', debug.whatsapp, debug.message_id, debug.line]
            .filter((part) => part)
            .join('  ·  ');
    };

    const showDebug = (row) => {
        liveHuman.textContent = row?.human || '';
        liveMeta.textContent = metaText(row);
        liveRaw.textContent = debugText(row);
    };

    const paintLive = (people, activeId) => {
        try {
        liveList.innerHTML = '';
        let active = null;
        people.forEach((row) => {
            const hit = document.createElement('button');
            hit.type = 'button';
            const state = row.ok === true ? ' is-ok' : row.ok === false ? ' is-bad' : ' is-wait';
            const on = String(row.contact_id) === String(activeId);
            hit.className = 'farz-send__hit' + state + (on ? ' is-on' : '');
            const who = document.createElement('span');
            who.textContent = row.name || row.phone || '';
            const num = document.createElement('span');
            num.className = 'farz-send__num';
            num.textContent = (row.line_phone ? row.line_phone + ' → ' : '') + (row.phone || '');
            const outcome = document.createElement('span');
            outcome.className = 'farz-send__outcome';
            outcome.textContent = row.human || waitingLabel;
            hit.append(who, num, outcome);
            hit.addEventListener('click', () => {
                liveList.querySelectorAll('.farz-send__hit').forEach((el) => el.classList.remove('is-on'));
                hit.classList.add('is-on');
                showDebug(row);
            });
            liveList.append(hit);
            if (on) active = row;
        });
        showDebug(active || people[people.length - 1] || null);
        const current = liveList.querySelector('.is-on');
        current?.scrollIntoView({ block: 'nearest' });
        } catch (_) {}
    };

    const paintRuns = (activeId) => {
        runChips.innerHTML = '';
        runs.forEach((run) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'farz-send__chip' + (run.id === activeId ? ' is-on' : '');
            const when = run.at ? new Date(run.at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
            chip.textContent = [when, fill(doneLabel, { sent: run.sent ?? 0, failed: run.failed ?? 0 }), run.finished ? '' : stoppedLabel]
                .filter((part) => part)
                .join(' · ');
            chip.addEventListener('click', () => {
                if (sending) return;
                paintRuns(run.id);
                const people = run.results || [];
                liveSummary.textContent = run.note || fill(doneLabel, { sent: run.sent ?? 0, failed: run.failed ?? 0 });
                paintLive(people, people[0]?.contact_id);
            });
            runChips.append(chip);
        });
    };

    const loadHistory = async (preferId) => {
        const res = await fetch(sendsUrl, { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        runs = data.runs || [];
        if (!runs.length) {
            runChips.innerHTML = '';
            liveList.innerHTML = '';
            liveSummary.textContent = historyEmpty;
            showDebug(null);
            return;
        }
        const run = runs.find((item) => item.id === preferId) || runs[0];
        paintRuns(run.id);
        const people = run.results || [];
        liveSummary.textContent = run.note || (run.finished ? fill(doneLabel, { sent: run.sent ?? 0, failed: run.failed ?? 0 }) : stoppedLabel);
        paintLive(people, people[0]?.contact_id);
    };

    const openResults = async () => {
        reveal();
        showLive();
        if (sending) {
            syncControls();
            return;
        }
        liveSummary.textContent = '…';
        await loadHistory();
    };

    const open = async () => {
        if (sending) {
            reveal();
            showLive();
            syncControls();
            return;
        }
        hideSendError();
        reveal();
        showPick();
        if (!list.querySelector('.mansour-pick') && !list.querySelector('.mansour-empty')) {
            list.textContent = '…';
        }
        paintContacts(await loadContacts());
    };

    loadContacts();

    document.getElementById('mansour-trigger-open')?.addEventListener('click', () => {
        contactsRequest = null;
        open();
    });
    document.getElementById('mansour-history-open')?.addEventListener('click', () => {
        openResults();
    });
    document.getElementById('mansour-show-history')?.addEventListener('click', () => {
        openResults();
    });
    document.getElementById('mansour-live-back')?.addEventListener('click', () => {
        contactsRequest = null;
        showPick();
        loadContacts().then(paintContacts);
    });
    document.getElementById('mansour-safe-sort')?.addEventListener('click', () => {
        safeSort();
    });
    document.getElementById('mansour-select-unsent')?.addEventListener('click', () => {
        const boxes = list.querySelectorAll('#mansour-pending .mansour-pick');
        boxes.forEach((box) => {
            box.checked = true;
            if (!box.dataset.line) fresh.add(box.value);
        });
        if (boxes.length) hideSendError();
    });
    document.getElementById('mansour-select-sent')?.addEventListener('click', () => {
        const boxes = list.querySelectorAll('.mansour-pick--sent');
        boxes.forEach((box) => {
            box.checked = true;
            if (!box.dataset.line) fresh.add(box.value);
        });
        if (boxes.length) hideSendError();
    });
    document.getElementById('mansour-clear-all')?.addEventListener('click', () => {
        list.querySelectorAll('.mansour-pick').forEach((box) => {
            box.checked = false;
            clearLine(box);
        });
        fresh.clear();
    });
    document.getElementById('mansour-delete-selected')?.addEventListener('click', async () => {
        const selected = [...list.querySelectorAll('#mansour-pending .mansour-pick:checked')];
        if (!selected.length || !window.confirm(deleteConfirm)) {
            return;
        }
        const res = await fetch(deleteUrl, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ contact_ids: selected.map((box) => box.value) }),
        });
        if (!res.ok) {
            return;
        }
        selected.forEach((box) => box.closest('label')?.remove());
        if (!list.querySelector('.mansour-pick') && !list.querySelector('.is-sent')) {
            showEmpty();
        }
    });
    customForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (customError) {
            customError.classList.add('hidden');
            customError.textContent = '';
        }
        const body = new FormData(customForm);
        const res = await fetch(customUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.contact) {
            if (customError) {
                customError.textContent = data.message || '';
                customError.classList.remove('hidden');
            }
            return;
        }
        appendContact(data.contact, true);
        customForm.reset();
    });
    modal.querySelectorAll('[data-mansour-close]').forEach((btn) => btn.addEventListener('click', hideModal));
    list.addEventListener('change', (event) => {
        const box = event.target;
        if (!(box instanceof HTMLInputElement) || !box.classList.contains('mansour-pick')) return;
        if (box.checked) {
            fresh.add(box.value);
            hideSendError();
            return;
        }
        clearLine(box);
    });
    document.getElementById('mansour-send')?.addEventListener('click', async () => {
        const selected = [...list.querySelectorAll('.mansour-pick:checked')];
        if (selected.some((box) => box.classList.contains('mansour-pick--sent')) && !window.confirm(resendConfirm)) {
            return;
        }
        if (!selected.length) {
            if (sendError) {
                sendError.textContent = noneSelected;
                sendError.classList.remove('hidden');
            }
            return;
        }
        if (selected.some((box) => !box.dataset.line)) {
            showSendError(assignMissing);
            return;
        }
        const sendingCount = new Map();
        selected.forEach((box) => {
            const id = box.dataset.line;
            sendingCount.set(id, (sendingCount.get(id) || 0) + 1);
        });
        for (const [id, count] of sendingCount) {
            const line = sendLines.find((item) => item.id === id);
            const max = roomOf(line);
            if (count > max) {
                showSendError(fill(safeOver, { phone: line?.phone || line?.label || id, max }));
                return;
            }
        }
        hideSendError();
        const sendButton = document.getElementById('mansour-send');
        if (sendButton) sendButton.disabled = true;
        const people = selected.map((box) => {
            const label = box.closest('label');
            const line = box.dataset.line;
            return {
                contact_id: Number(box.value),
                name: label?.dataset.name || '',
                phone: label?.dataset.phone || '',
                line,
                line_phone: linePhone(line),
                ok: null,
                human: waitingLabel,
                debug: null,
            };
        });
        sending = true;
        run.paused = false;
        run.done = 0;
        run.total = people.length;
        showLive();
        liveSummary.textContent = fill(progressLabel, { done: 0, total: people.length });
        paintLive(people, people[0]?.contact_id);
        syncControls();
        let runId = null;
        let stopped = false;
        const waits = new Map();
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const gapMs = () => 25000 + Math.floor(Math.random() * 15001);
        const breathMs = () => 60000 + Math.floor(Math.random() * 30001);
        const renderProgress = (finished) => {
            const done = people.filter((item) => item.ok !== null).length;
            run.done = done;
            run.total = people.length;
            if (finished) {
                liveSummary.textContent = fill(doneLabel, {
                    sent: people.filter((item) => item.ok === true).length,
                    failed: people.filter((item) => item.ok === false).length,
                });
                syncControls();
                return;
            }
            if (run.paused) {
                liveSummary.textContent = fill(pausedLabel, { done, total: people.length });
                syncControls();
                return;
            }
            let text = fill(progressLabel, { done, total: people.length });
            const left = [...waits.values()].filter((seconds) => seconds > 0);
            if (left.length) text += ' · ' + fill(paceWait, { seconds: Math.min(...left) });
            liveSummary.textContent = text;
            syncControls();
        };
        refreshRun = () => renderProgress(false);
        let runChain = Promise.resolve();
        const failPerson = (person, message) => {
            person.ok = false;
            person.human = message || requestFailedLabel;
            person.code = 'request_failed';
            person.debug = person.debug || {
                provider: 'Green API',
                http: 0,
                body: person.human,
                attempts: [],
            };
        };
        const postOne = (person) => {
            let release;
            const previous = runChain;
            runChain = new Promise((resolve) => { release = resolve; });
            return previous.then(async () => {
                let data = {};
                let res;
                const controller = new AbortController();
                const timer = setTimeout(() => controller.abort(), 40000);
                try {
                    res = await fetch(triggerUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Mansour-Live': '1',
                        },
                        body: JSON.stringify({
                            contact_ids: [person.contact_id],
                            run_id: runId,
                            finish: false,
                            line: person.line,
                        }),
                        signal: controller.signal,
                    });
                    data = await res.json();
                } catch (error) {
                    data = { message: requestFailedLabel };
                    res = { ok: false };
                } finally {
                    clearTimeout(timer);
                }
                if (data.run_id) runId = data.run_id;
                if (res.ok && !(data.rows || [])[0]) {
                    data = { message: data.message || requestFailedLabel, run_id: data.run_id };
                    res = { ok: false };
                }
                release();
                return { data, res };
            });
        };
        const hold = async () => {
            while (run.paused && !stopped) {
                renderProgress(false);
                await sleep(400);
            }
        };
        const pause = async (line, ms) => {
            let spent = 0;
            while (!stopped && spent < ms) {
                if (run.paused) {
                    waits.delete(line);
                    renderProgress(false);
                    await hold();
                    continue;
                }
                const left = ms - spent;
                waits.set(line, Math.ceil(left / 1000));
                renderProgress(false);
                const slice = Math.min(400, left);
                await sleep(slice);
                if (!run.paused) spent += slice;
            }
            waits.delete(line);
            if (!stopped && !run.paused) renderProgress(false);
        };
        const sendQueue = async (queue) => {
            for (let i = 0; i < queue.length; i++) {
                await hold();
                if (stopped) return;
                const person = queue[i];
                paintLive(people, person.contact_id);
                const { data, res } = await postOne(person);
                if (data.code === 'held') {
                    stopped = true;
                    people.forEach((row) => {
                        if (row.ok === null) {
                            row.ok = false;
                            row.human = data.message || '';
                            row.code = 'held';
                        }
                    });
                    liveSummary.textContent = data.message || '';
                    paintLive(people, person.contact_id);
                    return;
                }
                if (!res.ok) {
                    for (let j = i; j < queue.length; j++) {
                        if (queue[j].ok === null) failPerson(queue[j], data.message);
                    }
                    paintLive(people, person.contact_id);
                    renderProgress(false);
                    return;
                }
                const row = (data.rows || [])[0];
                if (row) Object.assign(person, row);
                paintLive(people, person.contact_id);
                renderProgress(false);
                if (!stopped && i < queue.length - 1) {
                    const extra = (i + 1) % 4 === 0 ? breathMs() : 0;
                    await pause(person.line, gapMs() + extra);
                }
            }
        };
        try {
            const groups = new Map();
            people.forEach((person) => {
                if (!groups.has(person.line)) groups.set(person.line, []);
                groups.get(person.line).push(person);
            });
            let stagger = 0;
            await Promise.all([...groups.values()].map((queue) => {
                const wait = stagger;
                stagger += 10000;
                return sleep(wait).then(() => sendQueue(queue));
            }));
            if (!stopped) {
                people.forEach((person) => {
                    if (person.ok === null) failPerson(person, requestFailedLabel);
                });
            }
            if (!stopped && runId) {
                await fetch(triggerUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Mansour-Live': '1',
                    },
                    body: JSON.stringify({ contact_ids: [], run_id: runId, finish: true }),
                }).catch(() => {});
            }
            if (!stopped) renderProgress(true);
        } finally {
            sending = false;
            run.paused = false;
            refreshRun = () => {};
            syncControls();
            if (sendButton) sendButton.disabled = false;
            contactsRequest = null;
            if (runId) {
                const saved = await fetch(sendsUrl, { headers: { Accept: 'application/json' } })
                    .then((res) => res.json())
                    .catch(() => ({}));
                runs = saved.runs || [];
                paintRuns(runId);
            }
        }
    });
});
</script>
@if($instance->isAppsBot())
    @include('ai-chatbot.workspace.mansour.followup-modal')
@endif
