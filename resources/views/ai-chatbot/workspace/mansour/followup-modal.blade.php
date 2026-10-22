@php use App\Support\FarzUi; @endphp
<div id="apps-followup-modal" class="farz-send fixed inset-0 z-50 hidden items-center justify-center p-3" role="dialog" aria-modal="true" aria-labelledby="apps-followup-title" hidden>
    <div class="farz-send__backdrop absolute inset-0" data-followup-close></div>
    <div class="farz-send__card relative z-10 flex w-full flex-col overflow-hidden">
        <header class="farz-send__head">
            <span class="farz-send__badge">{{ FarzUi::svg('hourglass') }}</span>
            <h3 id="apps-followup-title">{{ __('chatbot.mansour.followup_title') }}</h3>
            <p class="farz-send__via">{{ __('chatbot.mansour.followup_hint') }}</p>
            <button type="button" class="farz-send__x" data-followup-close aria-label="{{ __('chatbot.workspace.close') }}" title="{{ __('chatbot.workspace.close') }}">{{ FarzUi::svg('x') }}</button>
        </header>
        @if($instance->isAppsBot())
            <form id="apps-followup-custom" class="farz-send__add" style="padding: 12px 16px 0;">
                <span class="farz-send__field">
                    {{ FarzUi::svg('user') }}
                    <input name="name" type="text" maxlength="120" placeholder="{{ __('chatbot.mansour.add_name') }}" autocomplete="off">
                </span>
                <span class="farz-send__field farz-send__field--grow">
                    {{ FarzUi::svg('phone') }}
                    <input name="phone" type="text" required maxlength="32" placeholder="{{ __('chatbot.mansour.add_phone') }}" dir="ltr" autocomplete="off">
                </span>
                <button type="submit" class="farz-send__iconbtn is-solid" title="{{ __('chatbot.mansour.add') }}" aria-label="{{ __('chatbot.mansour.add') }}">{{ FarzUi::svg('plus') }}</button>
            </form>
        @endif
        <div class="farz-send__bar">
            <button type="button" id="apps-followup-select" class="farz-send__pill" title="{{ __('chatbot.mansour.select_unsent') }}">{{ FarzUi::svg('checks') }}<span>{{ __('chatbot.mansour.select_unsent') }}</span></button>
            <button type="button" id="apps-followup-clear" class="farz-send__pill" title="{{ __('chatbot.mansour.clear_all') }}">{{ FarzUi::svg('x') }}<span>{{ __('chatbot.mansour.clear_all') }}</span></button>
        </div>
        <div id="apps-followup-list" class="farz-send__list min-h-0 flex-1 overflow-y-auto"></div>
        <div class="farz-send__foot">
            <p id="apps-followup-error" class="farz-send__error hidden"></p>
            <p id="apps-followup-progress" class="farz-send__via" hidden></p>
            <button type="button" id="apps-followup-send" class="farz-send__go">{{ FarzUi::svg('send') }} {{ __('chatbot.mansour.followup_send') }}</button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('apps-followup-modal');
    const list = document.getElementById('apps-followup-list');
    const error = document.getElementById('apps-followup-error');
    const progress = document.getElementById('apps-followup-progress');
    const sendButton = document.getElementById('apps-followup-send');
    if (!modal || !list) return;

    const url = @json(route('ai-chatbot.workspace.mansour.followups', $instance));
    const customUrl = @json(route('ai-chatbot.workspace.mansour.contacts.custom', $instance));
    const triggerUrl = @json(route('ai-chatbot.workspace.mansour.trigger', $instance));
    const emptyLabel = @json(__('chatbot.mansour.followup_empty'));
    const sentGroupLabel = @json(__('chatbot.mansour.followup_sent_group'));
    const sentLabel = @json(__('chatbot.mansour.followup_already'));
    const resendConfirm = @json(__('chatbot.mansour.followup_resend'));
    const noneSelected = @json(__('chatbot.mansour.none_selected'));
    const waitingLabel = @json(__('chatbot.mansour.debug_waiting'));
    const progressLabel = @json(__('chatbot.mansour.sending_progress'));
    const doneLabel = @json(__('chatbot.mansour.send_done'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const showError = (text) => {
        if (!error) return;
        error.textContent = text || '';
        error.classList.toggle('hidden', !text);
    };

    const rowLabel = (row) => (row.name ? row.name + ' · ' : '') + row.phone;

    const contactRow = (row, nudged) => {
        const label = document.createElement('label');
        label.className = 'farz-send__row' + (nudged ? ' is-sent' : '');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.value = String(row.id);
        box.className = 'apps-followup-pick' + (nudged ? ' apps-followup-pick--sent' : '');
        box.dataset.line = row.line || '';
        const text = document.createElement('span');
        text.className = 'farz-send__who';
        text.textContent = rowLabel(row);
        const preview = document.createElement('span');
        preview.className = 'farz-send__preview';
        preview.textContent = row.preview || '';
        text.append(preview);
        label.append(box, text);
        if (!nudged) return label;
        const badge = document.createElement('span');
        badge.className = 'farz-send__sent';
        badge.textContent = sentLabel;
        label.append(badge);
        return label;
    };

    const paint = (contacts) => {
        list.innerHTML = '';
        if (!contacts.length) {
            const note = document.createElement('p');
            note.className = 'farz-send__empty';
            note.textContent = emptyLabel;
            list.append(note);
            return;
        }
        const pending = contacts.filter((row) => !row.nudged);
        const nudged = contacts.filter((row) => row.nudged);
        pending.forEach((row) => list.append(contactRow(row, false)));
        if (!nudged.length) return;
        const heading = document.createElement('p');
        heading.className = 'farz-send__section';
        heading.textContent = sentGroupLabel;
        list.append(heading);
        nudged.forEach((row) => list.append(contactRow(row, true)));
    };

    const load = () => {
        list.innerHTML = '';
        return fetch(url, { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                paint(data.contacts || []);
                return data;
            })
            .catch(() => {
                paint([]);
                return { contacts: [] };
            });
    };

    document.getElementById('apps-followup-custom')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        showError('');
        const body = new FormData(form);
        body.set('followup', '1');
        let data = {};
        let res;
        try {
            res = await fetch(customUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body,
            });
            data = await res.json();
        } catch (err) {
            showError(waitingLabel);
            return;
        }
        if (!res.ok) {
            showError(data.message || waitingLabel);
            return;
        }
        form.reset();
        await load();
        const box = list.querySelector(`input[value="${data.contact?.id || ''}"]`);
        if (box) box.checked = true;
    });

    const open = () => {
        showError('');
        if (progress) {
            progress.hidden = true;
            progress.textContent = '';
        }
        modal.hidden = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        load();
    };

    const close = () => {
        modal.hidden = true;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    document.getElementById('apps-followup-open')?.addEventListener('click', open);
    modal.querySelectorAll('[data-followup-close]').forEach((btn) => btn.addEventListener('click', close));
    document.getElementById('apps-followup-select')?.addEventListener('click', () => {
        list.querySelectorAll('.apps-followup-pick:not(.apps-followup-pick--sent)').forEach((box) => { box.checked = true; });
        showError('');
    });
    document.getElementById('apps-followup-clear')?.addEventListener('click', () => {
        list.querySelectorAll('.apps-followup-pick').forEach((box) => { box.checked = false; });
    });

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const fill = (template, map) => template.replace(/:(\w+)/g, (_, key) => map[key] ?? '');

    sendButton?.addEventListener('click', async () => {
        const selected = [...list.querySelectorAll('.apps-followup-pick:checked')];
        if (!selected.length) {
            showError(noneSelected);
            return;
        }
        if (selected.some((box) => box.classList.contains('apps-followup-pick--sent')) && !window.confirm(resendConfirm)) {
            return;
        }
        showError('');
        sendButton.disabled = true;
        const people = selected.map((box) => ({
            id: Number(box.value),
            line: box.dataset.line || '',
            ok: null,
        }));
        let runId = null;
        const groups = new Map();
        people.forEach((person) => {
            const key = person.line || '';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(person);
        });
        const postOne = async (person) => {
            let data = {};
            let res;
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
                        contact_ids: [person.id],
                        run_id: runId,
                        finish: false,
                        line: person.line || null,
                        kind: 'followup',
                    }),
                });
                data = await res.json();
            } catch (err) {
                data = { message: waitingLabel };
                res = { ok: false };
            }
            if (data.run_id) runId = data.run_id;
            return { data, res };
        };
        const sendQueue = async (queue) => {
            for (let i = 0; i < queue.length; i++) {
                const person = queue[i];
                const done = people.filter((item) => item.ok !== null).length;
                if (progress) {
                    progress.hidden = false;
                    progress.textContent = fill(progressLabel, { done, total: people.length });
                }
                const { data, res } = await postOne(person);
                if (!res.ok || data.code === 'held') {
                    person.ok = false;
                    showError(data.message || waitingLabel);
                    for (let j = i; j < queue.length; j++) {
                        if (queue[j].ok === null) queue[j].ok = false;
                    }
                    return;
                }
                const row = (data.rows || [])[0];
                person.ok = row ? row.ok === true : false;
                if (row && row.ok === false && row.human) showError(row.human);
                if (i < queue.length - 1) {
                    const gap = 25000 + Math.floor(Math.random() * 15001);
                    const extra = (i + 1) % 4 === 0 ? 60000 + Math.floor(Math.random() * 30001) : 0;
                    await sleep(gap + extra);
                }
            }
        };
        try {
            let stagger = 0;
            await Promise.all([...groups.values()].map((queue) => {
                const wait = stagger;
                stagger += 10000;
                return sleep(wait).then(() => sendQueue(queue));
            }));
            if (runId) {
                await fetch(triggerUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Mansour-Live': '1',
                    },
                    body: JSON.stringify({ contact_ids: [], run_id: runId, finish: true, kind: 'followup' }),
                });
            }
            if (progress) {
                progress.hidden = false;
                progress.textContent = fill(doneLabel, {
                    sent: people.filter((item) => item.ok === true).length,
                    failed: people.filter((item) => item.ok === false).length,
                });
            }
            load();
        } finally {
            sendButton.disabled = false;
        }
    });
});
</script>
