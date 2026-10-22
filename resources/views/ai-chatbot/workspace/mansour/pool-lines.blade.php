@php
    use App\Services\AiChatbot\MansourLines;
    use App\Support\FarzUi;

    $mansourLines = app(MansourLines::class);
    $authz = app(\App\Services\AiChatbot\ChatbotAuthorizationService::class);
    $moveTargets = $mansourLines->siblings($instance)
        ->filter(fn ($bot): bool => $authz->can(auth()->user(), $bot, \App\Services\AiChatbot\ChatbotAuthorizationService::ABILITY_MANAGE_SETTINGS))
        ->values();
    $groups = ['here' => 0, 'linked' => 0, 'free' => 0];
    $stats = ['on' => 0, 'off' => 0, 'blocked' => 0];
    foreach ($lines as $line) {
        $group = ! empty($line['on_bot']) ? 'here' : (isset($poolOwners[$line['id']]) ? 'linked' : 'free');
        $groups[$group]++;
        if ($group === 'here') {
            $stats[$line['status'] === MansourLines::BLOCKED ? 'blocked' : ($mansourLines->isOn($line) ? 'on' : 'off')]++;
        }
    }
    $startTab = $groups['here'] > 0 ? 'here' : ($groups['free'] > 0 ? 'free' : 'linked');
@endphp

<div class="farz-panel__row">
    <h2 class="farz-panel__title">{{ FarzUi::svg('phone') }} أرقام واتساب لهذا البوت <small class="farz-count">{{ $groups['here'] }}</small></h2>
    <div class="farz-lines__tools">
        @if($canManageSettings && $lines !== [])
            <button type="button" class="farz-refresh" id="farz-lines-refresh" title="يفحص كل رقم في Green API: هل هو متصل أو موقوف، رقمه واسمه وصورته، ويعيد توجيه الويب هوك لهذا البوت">{{ FarzUi::svg('refresh') }} <span>فحص الحالة</span></button>
        @endif
        <a href="{{ route('ai-chatbot.farz.numbers', ['bot' => $instance->id]) }}" class="farz-lines__add">{{ FarzUi::svg('plus') }} مصدر الأرقام</a>
    </div>
</div>

<p class="farz-nums__intro">
    {{ FarzUi::svg('link') }}
    <span>كل رقم يعمل على <b>بوت واحد فقط</b>. اربط الرقم بهذا البوت وسيُنقل تلقائياً من أي بوت آخر ويتحوّل الويب هوك إلى هنا. المفتاح بجانب الرقم يشغّله أو يوقفه بدون فصله. الأرقام الجديدة تُضاف من <a href="{{ route('ai-chatbot.farz.numbers', ['bot' => $instance->id]) }}">مصدر الأرقام</a>.</span>
</p>

@if($groups['here'] > 0)
    <div class="farz-nums__stats">
        <span class="farz-nums__stat is-on">يعمل <b data-stat="on">{{ $stats['on'] }}</b></span>
        <span class="farz-nums__stat">متوقف <b data-stat="off">{{ $stats['off'] }}</b></span>
        <span class="farz-nums__stat is-bad">موقوف من واتساب <b data-stat="blocked">{{ $stats['blocked'] }}</b></span>
    </div>
@endif

<div class="farz-nums__bar">
    <div class="farz-nums__tabs" role="tablist">
        <button type="button" role="tab" class="farz-nums__tab" data-tab="here">على هذا البوت <b>{{ $groups['here'] }}</b></button>
        <button type="button" role="tab" class="farz-nums__tab" data-tab="free">غير مربوطة <b>{{ $groups['free'] }}</b></button>
        <button type="button" role="tab" class="farz-nums__tab" data-tab="linked">على بوت آخر <b>{{ $groups['linked'] }}</b></button>
    </div>
    <label class="farz-nums__search">
        {{ FarzUi::svg('search') }}
        <input type="search" id="farz-nums-q" class="kaman-input" placeholder="ابحث برقم الهاتف أو الاسم أو رقم المثيل" autocomplete="off">
    </label>
    @if($canManageSettings)
        <div class="farz-nums__bulk" id="farz-nums-bulk" hidden>
            <span class="farz-nums__count">محدد: <b id="farz-nums-count">0</b></span>
            <button type="button" class="farz-num__go is-soft" id="farz-nums-all">تحديد الكل</button>
            <button type="button" class="farz-num__go" data-bulk="here" data-only="free linked">ربط بهذا البوت</button>
            <button type="button" class="farz-num__go" data-bulk="on" data-only="here">تشغيل</button>
            <button type="button" class="farz-num__go is-soft" data-bulk="off" data-only="here">إيقاف</button>
            @if($moveTargets->isNotEmpty())
                <select class="kaman-input" id="farz-nums-move" data-only="here" aria-label="نقل إلى بوت آخر">
                    <option value="">نقل إلى بوت…</option>
                    @foreach($moveTargets as $target)
                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                    @endforeach
                </select>
            @endif
            <button type="button" class="farz-num__go is-off" data-bulk="release" data-only="here">فصل</button>
            <button type="button" class="farz-num__go is-soft" id="farz-nums-clear" aria-label="إلغاء التحديد">{{ FarzUi::svg('x') }}</button>
        </div>
    @endif
</div>
<p class="farz-nums__note" id="farz-nums-note" hidden></p>

<ul class="farz-lines" id="farz-nums" data-start="{{ $startTab }}">
    @foreach($lines as $line)
        @php
            $group = ! empty($line['on_bot']) ? 'here' : (isset($poolOwners[$line['id']]) ? 'linked' : 'free');
            $owner = $poolOwners[$line['id']] ?? null;
            $isBlocked = $line['status'] === MansourLines::BLOCKED;
            $isOn = $group === 'here' && $mansourLines->isOn($line);
            $phone = preg_replace('/\D+/', '', (string) ($line['phone'] ?? '')) ?? '';
            $title = ($line['name'] ?? '') !== '' ? $line['name'] : '#'.$line['id'];
        @endphp
        <li @class(['farz-num', 'is-live' => $isOn, 'is-blocked' => $isBlocked])
            data-group="{{ $group }}"
            data-num="{{ $line['id'] }}"
            data-find="{{ mb_strtolower($title.' '.$phone.' '.$line['id'].' '.($owner?->name ?? '')) }}">
            <div class="farz-num__top">
                @if($canManageSettings)
                    <label class="farz-num__pick" title="تحديد"><input type="checkbox" data-num-pick value="{{ $line['id'] }}"></label>
                @endif
                <div class="farz-num__who">
                    @if(str_starts_with((string) ($line['avatar'] ?? ''), 'https://'))
                        <img class="farz-line__avatar" src="{{ $line['avatar'] }}" alt="">
                    @else
                        <span class="farz-line__avatar is-empty">{{ FarzUi::svg('user') }}</span>
                    @endif
                    <div class="farz-num__id">
                        <strong>{{ $title }}</strong>
                        <small dir="ltr" data-num-phone>{{ $phone !== '' ? '+'.$phone : '#'.$line['id'] }}</small>
                        <div class="farz-num__state">
                            @if($isBlocked)
                                <span class="farz-line__badge is-blocked" data-num-badge>{{ FarzUi::svg('shield-x') }} <span data-state>{{ $blockedLabels[$line['reason']] ?? 'محظور' }}</span></span>
                            @elseif($group === 'here')
                                <span @class(['farz-line__badge', 'is-live' => $isOn]) data-num-badge>{{ FarzUi::svg($isOn ? 'signal' : 'clock') }} <span data-state>{{ $isOn ? 'يعمل' : 'متوقف' }}</span></span>
                            @elseif($group === 'linked')
                                <span class="farz-line__badge is-linked">على {{ $owner->name }}</span>
                            @else
                                <span class="farz-line__badge is-free">غير مربوط</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if($group === 'here')
                    <label class="farz-toggle" title="{{ $isBlocked ? 'موقوف من واتساب' : 'تشغيل / إيقاف' }}">
                        <input type="checkbox" data-num-power @checked($isOn) @disabled($isBlocked || ! $canManageSettings) aria-label="تشغيل {{ $title }}">
                        <span></span>
                    </label>
                @endif
            </div>
            <div class="farz-num__foot">
                @if($group === 'here')
                    @if($canManageSettings && $moveTargets->isNotEmpty())
                        <select class="farz-num__move" data-num-move aria-label="نقل إلى بوت آخر">
                            <option value="">نقل إلى بوت آخر…</option>
                            @foreach($moveTargets as $target)
                                <option value="{{ $target->id }}">{{ $target->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="farz-num__owner">مربوط بهذا البوت</span>
                    @endif
                    <button type="button" class="farz-num__icon" data-copy="{{ $hookPrefix.'/'.$line['id'] }}" title="نسخ الويب هوك" aria-label="نسخ الويب هوك">{{ FarzUi::svg('webhook') }}{{ FarzUi::svg('check') }}</button>
                    @if($canManageSettings)
                        <button type="button" class="farz-num__icon" data-num-release title="فصل عن هذا البوت" aria-label="فصل عن هذا البوت">{{ FarzUi::svg('x') }}</button>
                    @endif
                @elseif($group === 'linked')
                    <span class="farz-num__owner">يعمل الآن على <b>{{ $owner->name }}</b></span>
                    @if($canManageSettings)
                        <button type="button" class="farz-num__go is-warn" data-num-here data-from="{{ $owner->name }}" @disabled($isBlocked)>نقل لهذا البوت</button>
                    @endif
                @else
                    <span class="farz-num__owner">متاح لأي بوت</span>
                    @if($canManageSettings)
                        <button type="button" class="farz-num__go" data-num-here @disabled($isBlocked)>{{ FarzUi::svg('link') }} ربط بهذا البوت</button>
                    @endif
                @endif
            </div>
        </li>
    @endforeach
    <li class="farz-nums__empty" id="farz-nums-empty" hidden>لا توجد أرقام هنا.</li>
</ul>

<script>
        (function () {
            const list = document.getElementById('farz-nums');
            if (!list) return;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const botId = @json($instance->id);
            const routes = {
                active: @json(route('ai-chatbot.workspace.mansour.lines.active', $instance)),
                assign: @json(route('ai-chatbot.workspace.mansour.lines.assign', $instance)),
                check: @json(route('ai-chatbot.workspace.mansour.lines.check', $instance)),
                state: @json(route('ai-chatbot.workspace.mansour.lines.state', $instance)),
            };
            const note = document.getElementById('farz-nums-note');
            const bulk = document.getElementById('farz-nums-bulk');
            const search = document.getElementById('farz-nums-q');
            const empty = document.getElementById('farz-nums-empty');
            const cards = () => [...list.querySelectorAll('.farz-num')];
            let tab = list.dataset.start || 'here';

            const say = (text, bad) => {
                if (!note) return;
                note.hidden = !text;
                note.textContent = text || '';
                note.classList.toggle('is-bad', !!bad);
            };
            const post = async (url, body) => {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify(body || {}),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'تعذّر الحفظ، حاول مرة ثانية');
                return data;
            };
            const flashThenReload = (text) => {
                try { sessionStorage.setItem('farz-nums-flash', text || ''); } catch (e) {}
                window.location.reload();
            };
            try {
                const flash = sessionStorage.getItem('farz-nums-flash');
                if (flash) { say(flash); sessionStorage.removeItem('farz-nums-flash'); }
            } catch (e) {}

            const picked = () => cards().filter((card) => card.querySelector('[data-num-pick]')?.checked);
            const syncBulk = () => {
                const chosen = picked();
                cards().forEach((card) => card.classList.toggle('is-picked', !!card.querySelector('[data-num-pick]')?.checked));
                if (!bulk) return;
                bulk.hidden = chosen.length === 0;
                document.getElementById('farz-nums-count').textContent = chosen.length;
                bulk.querySelectorAll('[data-only]').forEach((el) => {
                    el.hidden = !el.dataset.only.split(' ').includes(tab);
                });
            };
            const filter = () => {
                const q = (search?.value || '').trim().toLowerCase();
                let shown = 0;
                cards().forEach((card) => {
                    const visible = card.dataset.group === tab && (!q || (card.dataset.find || '').includes(q));
                    card.hidden = !visible;
                    if (!visible) {
                        const box = card.querySelector('[data-num-pick]');
                        if (box) box.checked = false;
                    }
                    if (visible) shown++;
                });
                if (empty) {
                    empty.hidden = shown > 0;
                    empty.textContent = q ? 'لا يوجد رقم يطابق البحث.' : ({
                        here: 'لا توجد أرقام على هذا البوت بعد. افتح «غير مربوطة» واربط رقماً.',
                        free: 'كل الأرقام مربوطة ببوتات. أضف أرقاماً جديدة من مصدر الأرقام.',
                        linked: 'لا توجد أرقام على بوتات أخرى.',
                    })[tab];
                }
                document.querySelectorAll('.farz-nums__tab').forEach((button) => {
                    const on = button.dataset.tab === tab;
                    button.classList.toggle('is-on', on);
                    button.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                syncBulk();
            };
            document.querySelectorAll('.farz-nums__tab').forEach((button) => {
                button.addEventListener('click', () => { tab = button.dataset.tab; filter(); });
            });
            search?.addEventListener('input', filter);
            filter();

            const paintPower = (card, on) => {
                if (!card || card.classList.contains('is-blocked')) return;
                card.classList.toggle('is-live', on);
                const power = card.querySelector('[data-num-power]');
                if (power) power.checked = on;
                const badge = card.querySelector('[data-num-badge]');
                const state = badge?.querySelector('[data-state]');
                if (badge && state) {
                    badge.classList.toggle('is-live', on);
                    state.textContent = on ? 'يعمل' : 'متوقف';
                }
            };
            const recount = () => {
                const here = cards().filter((card) => card.dataset.group === 'here');
                const set = (key, value) => { const el = document.querySelector('[data-stat="' + key + '"]'); if (el) el.textContent = value; };
                set('blocked', here.filter((card) => card.classList.contains('is-blocked')).length);
                set('on', here.filter((card) => card.classList.contains('is-live')).length);
                set('off', here.filter((card) => !card.classList.contains('is-live') && !card.classList.contains('is-blocked')).length);
            };
            const setPower = async (ids, on) => {
                const touched = ids.map((id) => list.querySelector('[data-num="' + id + '"]'));
                touched.forEach((card) => card?.classList.add('is-busy'));
                try {
                    const data = await post(routes.active, { line_ids: ids, active: on });
                    ids.forEach((id) => paintPower(list.querySelector('[data-num="' + id + '"]'), (data.ids || []).includes(id) ? on : !on));
                    recount();
                    say(data.message || '');
                } catch (error) {
                    ids.forEach((id) => paintPower(list.querySelector('[data-num="' + id + '"]'), !on));
                    say(error.message, true);
                } finally {
                    touched.forEach((card) => card?.classList.remove('is-busy'));
                }
            };
            const assign = async (ids, target, question) => {
                if (!ids.length) return;
                if (question && !window.confirm(question)) return;
                ids.forEach((id) => list.querySelector('[data-num="' + id + '"]')?.classList.add('is-busy'));
                try {
                    const data = await post(routes.assign, { line_ids: ids, bot_id: target });
                    flashThenReload(data.message || '');
                } catch (error) {
                    ids.forEach((id) => list.querySelector('[data-num="' + id + '"]')?.classList.remove('is-busy'));
                    say(error.message, true);
                }
            };
            const idOf = (el) => el.closest('[data-num]')?.dataset.num;

            list.addEventListener('change', (event) => {
                const target = event.target;
                if (target.matches('[data-num-pick]')) { syncBulk(); return; }
                if (target.matches('[data-num-power]')) { setPower([idOf(target)], target.checked); return; }
                if (target.matches('[data-num-move]') && target.value) {
                    const name = target.options[target.selectedIndex].text;
                    const value = target.value;
                    target.value = '';
                    assign([idOf(target)], Number(value), 'نقل الرقم إلى «' + name + '»؟ سيتوقف عن العمل على هذا البوت.');
                }
            });
            list.addEventListener('click', async (event) => {
                const copy = event.target.closest('[data-copy]');
                if (copy) {
                    try { await navigator.clipboard.writeText(copy.dataset.copy); } catch (e) { window.prompt('انسخ الويب هوك', copy.dataset.copy); }
                    copy.classList.add('is-done');
                    setTimeout(() => copy.classList.remove('is-done'), 1200);
                    return;
                }
                const here = event.target.closest('[data-num-here]');
                if (here) {
                    assign([idOf(here)], botId, here.dataset.from ? 'هذا الرقم يعمل على «' + here.dataset.from + '». نقله إلى هذا البوت يوقفه هناك. متابعة؟' : '');
                    return;
                }
                const release = event.target.closest('[data-num-release]');
                if (release) assign([idOf(release)], 0, 'فصل الرقم عن هذا البوت؟ سيبقى في مصدر الأرقام لتربطه ببوت آخر.');
            });

            document.getElementById('farz-nums-all')?.addEventListener('click', () => {
                cards().filter((card) => !card.hidden).forEach((card) => {
                    const box = card.querySelector('[data-num-pick]');
                    if (box) box.checked = true;
                });
                syncBulk();
            });
            document.getElementById('farz-nums-clear')?.addEventListener('click', () => {
                cards().forEach((card) => { const box = card.querySelector('[data-num-pick]'); if (box) box.checked = false; });
                syncBulk();
            });
            bulk?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-bulk]');
                if (!button) return;
                const ids = picked().map((card) => card.dataset.num);
                const action = button.dataset.bulk;
                if (action === 'on' || action === 'off') setPower(ids, action === 'on');
                if (action === 'here') assign(ids, botId, tab === 'linked' ? 'نقل ' + ids.length + ' رقم من بوتاتها إلى هذا البوت؟' : '');
                if (action === 'release') assign(ids, 0, 'فصل ' + ids.length + ' رقم عن هذا البوت؟');
            });
            document.getElementById('farz-nums-move')?.addEventListener('change', (event) => {
                const select = event.target;
                if (!select.value) return;
                const name = select.options[select.selectedIndex].text;
                const value = select.value;
                select.value = '';
                const ids = picked().map((card) => card.dataset.num);
                assign(ids, Number(value), 'نقل ' + ids.length + ' رقم إلى «' + name + '»؟');
            });

            const refresh = document.getElementById('farz-lines-refresh');
            refresh?.addEventListener('click', async () => {
                const label = refresh.querySelector('span');
                refresh.disabled = true;
                refresh.classList.add('is-busy');
                if (label) label.textContent = 'جارٍ الفحص…';
                say('يتم فحص كل الأرقام في Green API معاً، عادةً خلال ثوانٍ.');
                try {
                    const data = await post(routes.check, {});
                    flashThenReload(data.message || '');
                } catch (error) {
                    refresh.disabled = false;
                    refresh.classList.remove('is-busy');
                    if (label) label.textContent = 'فحص الحالة';
                    say(error.message, true);
                }
            });

            const paintHealth = (line) => {
                const card = list.querySelector('[data-num="' + line.id + '"][data-group="here"]');
                if (!card) return;
                const blocked = line.status === 'blocked';
                card.classList.toggle('is-blocked', blocked);
                card.classList.toggle('is-live', !blocked && !!line.on);
                const power = card.querySelector('[data-num-power]');
                if (power) {
                    power.disabled = blocked;
                    power.checked = !blocked && !!line.on;
                }
                const phone = card.querySelector('[data-num-phone]');
                if (phone && line.phone) phone.textContent = '+' + String(line.phone).replace(/\D/g, '');
                const badge = card.querySelector('[data-num-badge]');
                const state = badge?.querySelector('[data-state]');
                if (!badge || !state) return;
                badge.classList.toggle('is-blocked', blocked);
                badge.classList.toggle('is-live', !blocked && !!line.on);
                state.textContent = line.label || '';
            };
            const pullHealth = async () => {
                if (document.visibilityState === 'hidden' || refresh?.disabled) return;
                try {
                    const response = await fetch(routes.state, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    (data.lines || []).forEach(paintHealth);
                    recount();
                } catch (e) {}
            };
            setInterval(pullHealth, 15000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') pullHealth();
            });
        })();
    </script>
