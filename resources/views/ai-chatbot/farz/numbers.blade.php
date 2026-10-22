@php
    use App\Services\AiChatbot\MansourLines;
    use App\Support\FarzUi;

    $blockedLabels = [
        'blocked' => 'محظور',
        'yellowCard' => 'إنذار من واتساب',
        'suspended' => 'موقوف',
        'notAuthorized' => 'غير مسجّل',
    ];
    $greenApi = app(\App\Services\AiChatbot\ChatbotGreenApiService::class);
    $pool = app(\App\Services\AiChatbot\MowahadaLinePool::class);
    $hookPrefix = rtrim(route('ai-chatbot.greenapi.webhook', $greenApi->ensureWebhookToken($instance)), '/');
    $botHooks = [];
    foreach (collect($owners)->values()->merge($bots)->unique('id') as $hookBot) {
        $botHooks[$hookBot->id] = rtrim(route('ai-chatbot.greenapi.webhook', $greenApi->ensureWebhookToken($hookBot)), '/');
    }
    $counts = ['all' => $lines->count(), 'linked' => 0, 'free' => 0, 'blocked' => 0];
    foreach ($lines as $countLine) {
        $counts[isset($owners[$countLine->wa_instance]) ? 'linked' : 'free']++;
        if ($countLine->status === MansourLines::BLOCKED) {
            $counts['blocked']++;
        }
    }
    $idDrafts = old('ids');
    $tokenDrafts = old('tokens');
    if (! is_array($idDrafts)) {
        $idDrafts = ['', '', '', ''];
        $tokenDrafts = ['', '', '', ''];
    } else {
        $idDrafts = array_values(array_map(static fn ($value): string => trim((string) $value), $idDrafts));
        $tokenDrafts = is_array($tokenDrafts)
            ? array_values(array_map(static fn ($value): string => trim((string) $value), $tokenDrafts))
            : [];
    }
    $rowCount = max(count($idDrafts), count($tokenDrafts), 4);
    while (count($idDrafts) < $rowCount) {
        $idDrafts[] = '';
    }
    while (count($tokenDrafts) < $rowCount) {
        $tokenDrafts[] = '';
    }
@endphp
@extends('layouts.farz')

@section('title', 'مصدر الأرقام — '.$instance->name)

@section('content')
<div class="farz-page farz-page--wide">
    <div class="farz-page__head">
        <span class="farz-page__ico">{{ FarzUi::svg('phone') }}</span>
        <h1>مصدر الأرقام</h1>
    </div>

    @if(session('status'))
        <p class="farz-flash is-ok">{{ FarzUi::svg('check') }} {{ session('status') }}</p>
    @endif
    @if($errors->any())
        <p class="farz-flash is-bad">{{ FarzUi::svg('alert') }} {{ $errors->first() }}</p>
    @endif

    <p class="farz-nums__intro">
        {{ FarzUi::svg('link') }}
        <span>هذه أرقام Green API لكل بوتات الموحدة. أضف الرقم هنا مرة واحدة، ثم اختر من القائمة بجانبه <b>البوت الذي يعمل عليه</b>. كل رقم يعمل على بوت واحد فقط، والنقل لبوت آخر يحوّل الويب هوك تلقائياً.</span>
    </p>

    @if($canManageSettings)
        <form id="farz-pool-check" method="post" action="{{ route('ai-chatbot.farz.numbers.check') }}" hidden>
            @csrf
            <input type="hidden" name="bot" value="{{ $instance->id }}">
        </form>
        <details class="farz-panel farz-nums__add" @if($lines->isEmpty() || $errors->has('tokens') || $errors->has('urls') || $errors->has('links')) open @endif>
            <summary>
                <h2 class="farz-panel__title">{{ FarzUi::svg('plus') }} إضافة أرقام جديدة</h2>
            </summary>
            <p class="farz-pool-note">انسخ رقم المثيل وتوكن API من لوحة Green API. الويب هوك يظهر فوراً لتنسخه إلى إعدادات الرقم، ثم احفظ. الويب هوك هنا لبوت {{ $instance->name }}، ويتحوّل تلقائياً عند ربط الرقم ببوت آخر.</p>
            <form method="post" action="{{ route('ai-chatbot.farz.numbers.store') }}" class="farz-form" id="farz-pool-form">
                @csrf
                <input type="hidden" name="bot" value="{{ $instance->id }}">
                <div class="farz-lines__tools" style="margin-bottom: 10px;">
                    <button type="button" class="farz-lines__add" id="farz-pool-add">{{ FarzUi::svg('plus') }} سطر جديد</button>
                    <button type="submit" class="kaman-button farz-lines__save">{{ FarzUi::svg('save') }} إضافة للمصدر</button>
                </div>
                <div class="farz-pool-head" aria-hidden="true">
                    <span>رقم المثيل</span>
                    <span>توكن API</span>
                    <span>ويب هوك</span>
                    <span></span>
                </div>
                <div class="farz-pool-rows" id="farz-pool-rows">
                    @foreach($idDrafts as $index => $idDraft)
                        <div class="farz-pool-row">
                            <input name="ids[]" type="text" value="{{ $idDraft }}" data-id inputmode="numeric" placeholder="رقم المثيل" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                            <input name="tokens[]" type="text" value="{{ $tokenDrafts[$index] ?? '' }}" data-token placeholder="توكن API" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                            <input type="text" readonly value="" data-hook placeholder="ويب هوك" class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" tabindex="-1">
                            <button type="button" class="farz-pool__copy" data-copy-hook>{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }} نسخ</button>
                        </div>
                    @endforeach
                </div>
            </form>
            <template id="farz-pool-row">
                <div class="farz-pool-row">
                    <input name="ids[]" type="text" value="" data-id inputmode="numeric" placeholder="رقم المثيل" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                    <input name="tokens[]" type="text" value="" data-token placeholder="توكن API" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                    <input type="text" readonly value="" data-hook placeholder="ويب هوك" class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" tabindex="-1">
                    <button type="button" class="farz-pool__copy" data-copy-hook>{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }} نسخ</button>
                </div>
            </template>
        </details>
    @endif

    <section class="farz-panel">
        <div class="farz-panel__row">
            <h2 class="farz-panel__title">{{ FarzUi::svg('phone') }} الأرقام <small class="farz-count">{{ $lines->count() }}</small></h2>
            @if($canManageSettings && $lines->isNotEmpty())
                <div class="farz-lines__tools">
                    <button type="submit" form="farz-pool-check" class="farz-refresh" title="يفحص كل رقم في Green API: متصل أو موقوف، ورقم واتساب الحالي">{{ FarzUi::svg('refresh') }} <span>فحص الحالة</span></button>
                    <button type="button" class="farz-refresh" id="farz-warm-open">{{ FarzUi::svg('send') }} تسخين</button>
                </div>
            @endif
        </div>
        @if($lines->isEmpty())
            <p class="farz-pool-note">لا توجد أرقام بعد.</p>
        @else
            <div class="farz-nums__stats">
                <span class="farz-nums__stat is-on">مربوطة ببوت <b data-count="linked">{{ $counts['linked'] }}</b></span>
                <span class="farz-nums__stat is-free">غير مربوطة <b data-count="free">{{ $counts['free'] }}</b></span>
                <span class="farz-nums__stat is-bad">موقوفة <b>{{ $counts['blocked'] }}</b></span>
                <span class="farz-nums__stat" id="farz-hold-note">حالة الإيقاف تتحدث كل دقيقة.</span>
            </div>
            <div class="farz-nums__bar">
                <div class="farz-nums__tabs" role="tablist">
                    <button type="button" role="tab" class="farz-nums__tab is-on" data-tab="all">الكل <b>{{ $counts['all'] }}</b></button>
                    <button type="button" role="tab" class="farz-nums__tab" data-tab="linked">مربوطة <b data-count="linked">{{ $counts['linked'] }}</b></button>
                    <button type="button" role="tab" class="farz-nums__tab" data-tab="free">غير مربوطة <b data-count="free">{{ $counts['free'] }}</b></button>
                    <button type="button" role="tab" class="farz-nums__tab" data-tab="blocked">موقوفة <b>{{ $counts['blocked'] }}</b></button>
                </div>
                <label class="farz-nums__search">
                    {{ FarzUi::svg('search') }}
                    <input type="search" id="farz-nums-q" class="kaman-input" placeholder="ابحث برقم الهاتف أو الاسم أو البوت" autocomplete="off">
                </label>
            </div>
            <p class="farz-nums__note" id="farz-nums-note" hidden></p>
            <ul class="farz-lines" id="farz-nums">
                @foreach($lines as $line)
                    @php
                        $phone = preg_replace('/\D+/', '', (string) ($line->phone ?? '')) ?? '';
                        $title = trim((string) ($line->name ?? '')) !== '' ? $line->name : '#'.$line->wa_instance;
                        $blocked = $line->status === MansourLines::BLOCKED;
                        $hold = $pool->holdLabel($line);
                        $owner = $owners[$line->wa_instance] ?? null;
                        $hook = ($botHooks[$owner?->id ?? $instance->id] ?? $hookPrefix).'/'.$line->wa_instance;
                    @endphp
                    <li @class(['farz-num', 'is-live' => $owner !== null && ! $blocked, 'is-blocked' => $blocked])
                        data-pool-line="{{ $line->wa_instance }}"
                        data-owner="{{ $owner?->id ?? 0 }}"
                        data-find="{{ mb_strtolower($title.' '.$phone.' '.$line->wa_instance.' '.($owner?->name ?? '')) }}">
                        <div class="farz-num__top">
                            <div class="farz-num__who">
                                <span class="farz-line__avatar is-empty">{{ FarzUi::svg('user') }}</span>
                                <div class="farz-num__id">
                                    <strong>{{ $title }}</strong>
                                    <small dir="ltr" data-pool-phone>{{ $phone !== '' ? '+'.$phone : '#'.$line->wa_instance }}</small>
                                    <div class="farz-num__state">
                                        @if($hold)
                                            <span class="farz-line__badge is-blocked" data-pool-badge>{{ FarzUi::svg('shield-x') }} <span data-pool-badge-text>{{ $hold }}</span></span>
                                        @elseif($blocked)
                                            <span class="farz-line__badge is-blocked" data-pool-badge>{{ FarzUi::svg('shield-x') }} <span data-pool-badge-text>{{ $blockedLabels[$line->reason] ?? 'محظور' }}</span></span>
                                        @else
                                            <span class="farz-line__badge is-live" data-pool-badge>{{ FarzUi::svg('signal') }} <span data-pool-badge-text>في المصدر</span></span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($canManageSettings)
                                <div class="farz-line__actions">
                                    <button type="button" class="farz-line__edit" data-line-edit data-id="{{ $line->wa_instance }}" data-token="{{ $pool->tokenOf((string) $line->url) }}" data-label="{{ $title }}" data-action="{{ route('ai-chatbot.farz.numbers.update', $line) }}" title="تعديل وتحديث" aria-label="تعديل وتحديث">{{ FarzUi::svg('gear') }}</button>
                                    <form method="post" action="{{ route('ai-chatbot.farz.numbers.destroy', $line) }}" onsubmit="return confirm('حذف الرقم من المصدر ومن كل بوتات الموحدة؟')">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="bot" value="{{ $instance->id }}">
                                        <button type="submit" class="farz-line__remove" title="حذف من المصدر" aria-label="حذف من المصدر">{{ FarzUi::svg('trash') }}</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                        <div class="farz-num__foot">
                            @if($canManageSettings)
                                <select class="farz-num__move" data-num-owner data-action="{{ route('ai-chatbot.farz.numbers.assign', ['line' => $line, 'bot' => $instance->id]) }}" aria-label="البوت الذي يعمل عليه {{ $title }}">
                                    <option value="0" @selected($owner === null)>— غير مربوط بأي بوت —</option>
                                    @foreach($bots as $bot)
                                        <option value="{{ $bot->id }}" @selected($owner?->id === $bot->id)>يعمل على: {{ $bot->name }}</option>
                                    @endforeach
                                    @if($owner !== null && ! $bots->contains('id', $owner->id))
                                        <option value="{{ $owner->id }}" selected disabled>يعمل على: {{ $owner->name }}</option>
                                    @endif
                                </select>
                            @else
                                <span class="farz-num__owner">{{ $owner ? 'مفعّل على '.$owner->name : 'غير مفعّل على أي بوت' }}</span>
                            @endif
                            <details class="farz-num__details">
                                <summary>الرابط والويب هوك</summary>
                                <div class="farz-line__fields">
                                    <label class="farz-line__urlrow">
                                        <span>رابط</span>
                                        <input type="text" readonly value="{{ $line->url }}" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" tabindex="-1">
                                    </label>
                                    <div class="farz-line__hook">
                                        <span class="farz-line__hooklabel">{{ FarzUi::svg('webhook') }} ويب هوك</span>
                                        <input type="text" readonly value="{{ $hook }}" data-hook class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" tabindex="-1">
                                        <button type="button" class="farz-pool__copy" data-copy-hook>{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }} نسخ</button>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </li>
                @endforeach
                <li class="farz-nums__empty" id="farz-nums-empty" hidden>لا يوجد رقم يطابق.</li>
            </ul>
        @endif
    </section>
</div>
@if($canManageSettings)
    <div id="farz-warm-modal" class="farz-send fixed inset-0 z-50 hidden items-center justify-center p-3" role="dialog" aria-modal="true" aria-labelledby="farz-warm-title" hidden>
        <div class="farz-send__backdrop absolute inset-0" data-farz-close="farz-warm-modal"></div>
        <div class="farz-send__card relative z-10 flex w-full flex-col overflow-hidden">
            <header class="farz-send__head">
                <span class="farz-send__badge">{{ FarzUi::svg('send') }}</span>
                <h3 id="farz-warm-title">تسخين الأرقام</h3>
                <button type="button" class="farz-send__x" data-farz-close="farz-warm-modal" aria-label="إغلاق">{{ FarzUi::svg('x') }}</button>
            </header>
            <form method="post" action="{{ route('ai-chatbot.farz.numbers.warm') }}" class="farz-send__tools">
                @csrf
                <input type="hidden" name="bot" value="{{ $instance->id }}">
                <p class="farz-pool-note">كل رقم تختاره يرسل هذه الرسالة كما كتبتها إلى أرقام التجربة.</p>
                <label class="farz-warm__all">
                    <input type="checkbox" id="farz-warm-all">
                    <span>كل أرقام المصدر</span>
                </label>
                <div class="farz-warm__lines">
                    @foreach($lines as $line)
                        @php
                            $warmPhone = preg_replace('/\D+/', '', (string) ($line->phone ?? '')) ?? '';
                            $warmTitle = trim((string) ($line->name ?? '')) !== '' ? $line->name : '#'.$line->wa_instance;
                            $picked = is_array(old('lines')) && in_array((string) $line->wa_instance, old('lines'), true);
                            $warmHold = $pool->holdLabel($line);
                        @endphp
                        <label class="farz-warm__line">
                            <input type="checkbox" name="lines[]" value="{{ $line->wa_instance }}" @checked($picked) @disabled($warmHold !== null)>
                            <span>{{ $warmTitle }}</span>
                            @if($warmHold)
                                <small>{{ $warmHold }}</small>
                            @endif
                            <small dir="ltr">{{ $warmPhone !== '' ? '+'.$warmPhone : '#'.$line->wa_instance }}</small>
                        </label>
                    @endforeach
                </div>
                <label class="farz-warm__field">
                    <span>أرقام التجربة</span>
                    <textarea name="phones" rows="4" class="kaman-input font-mono text-xs" dir="ltr" placeholder="9725xxxxxxxx&#10;052xxxxxxx" required>{{ old('phones') }}</textarea>
                </label>
                <label class="farz-warm__field">
                    <span>رسالة التسخين</span>
                    <textarea name="message" rows="3" class="kaman-input" maxlength="1000" placeholder="اكتب الرسالة كما ستصل" required>{{ old('message') }}</textarea>
                </label>
                <button type="submit" class="kaman-button farz-lines__save">{{ FarzUi::svg('send') }} إرسال التسخين</button>
            </form>
        </div>
    </div>
    <div id="farz-edit-modal" class="farz-send fixed inset-0 z-50 hidden items-center justify-center p-3" role="dialog" aria-modal="true" aria-labelledby="farz-edit-title" hidden>
        <div class="farz-send__backdrop absolute inset-0" data-farz-close="farz-edit-modal"></div>
        <div class="farz-send__card relative z-10 flex w-full flex-col overflow-hidden">
            <header class="farz-send__head">
                <span class="farz-send__badge">{{ FarzUi::svg('gear') }}</span>
                <h3 id="farz-edit-title">تعديل الرقم</h3>
                <button type="button" class="farz-send__x" data-farz-close="farz-edit-modal" aria-label="إغلاق">{{ FarzUi::svg('x') }}</button>
            </header>
            <form method="post" action="" class="farz-send__tools" id="farz-edit-form">
                @csrf
                <input type="hidden" name="bot" value="{{ $instance->id }}">
                <p class="farz-pool-note" id="farz-edit-label"></p>
                <label class="farz-warm__field">
                    <span>رقم المثيل</span>
                    <input name="id" id="farz-edit-id" type="text" inputmode="numeric" class="kaman-input font-mono text-xs" dir="ltr" required autocomplete="off">
                </label>
                <label class="farz-warm__field">
                    <span>توكن API</span>
                    <input name="token" id="farz-edit-token" type="text" class="kaman-input font-mono text-xs" dir="ltr" required autocomplete="off">
                </label>
                <label class="farz-warm__field">
                    <span>ويب هوك</span>
                    <span class="farz-edit__hook">
                        <input id="farz-edit-hook" data-hook type="text" readonly class="kaman-input font-mono text-xs" dir="ltr" tabindex="-1">
                        <button type="button" class="farz-pool__copy" data-copy-hook>{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }} نسخ</button>
                    </span>
                </label>
                <button type="submit" class="kaman-button farz-lines__save">{{ FarzUi::svg('refresh') }} تحديث</button>
            </form>
        </div>
    </div>
@endif
@if($canControlBot)
    @include('ai-chatbot.workspace.mansour.trigger-modal')
@endif
<script>
    (function () {
        const hookPrefix = @json($hookPrefix);
        const rows = document.getElementById('farz-pool-rows');
        const template = document.getElementById('farz-pool-row');
        const fill = (row) => {
            const idInput = row.querySelector('[data-id]');
            const tokenInput = row.querySelector('[data-token]');
            const hook = row.querySelector('[data-hook]');
            if (!idInput || !tokenInput || !hook) return;
            const blob = idInput.value + ' ' + tokenInput.value;
            const found = blob.match(/waInstance(\d+)\/[A-Za-z]+\/([A-Za-z0-9]+)/i);
            if (found) {
                idInput.value = found[1];
                tokenInput.value = found[2];
            } else {
                idInput.value = idInput.value.replace(/\D+/g, '');
            }
            hook.value = idInput.value ? hookPrefix + '/' + idInput.value : '';
        };
        rows?.querySelectorAll('.farz-pool-row').forEach(fill);
        document.getElementById('farz-pool-add')?.addEventListener('click', () => {
            const next = template.content.cloneNode(true).firstElementChild;
            rows.appendChild(next);
            next.querySelector('[data-id]')?.focus();
        });
        rows?.addEventListener('input', (event) => {
            const row = event.target.closest('.farz-pool-row');
            if (row) fill(row);
        });
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy-hook]');
            if (!button) return;
            const value = button.parentElement?.querySelector('[data-hook]')?.value || '';
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
            } catch (e) {
                const hook = button.parentElement.querySelector('[data-hook]');
                hook?.focus();
                hook?.select();
            }
            button.classList.add('is-done');
            setTimeout(() => button.classList.remove('is-done'), 1200);
        });
    })();
</script>
<script>
    (function () {
        const hookPrefix = @json($hookPrefix);
        const open = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.hidden = false;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };
        const close = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.hidden = true;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };
        const editHook = () => {
            const id = document.getElementById('farz-edit-id');
            const hook = document.getElementById('farz-edit-hook');
            if (!id || !hook) return;
            const digits = id.value.replace(/\D+/g, '');
            id.value = digits;
            hook.value = digits ? hookPrefix + '/' + digits : '';
        };
        document.getElementById('farz-warm-open')?.addEventListener('click', () => open('farz-warm-modal'));
        document.getElementById('farz-warm-all')?.addEventListener('change', (event) => {
            document.querySelectorAll('#farz-warm-modal input[name="lines[]"]').forEach((box) => {
                if (!box.disabled) box.checked = event.target.checked;
            });
        });
        document.getElementById('farz-edit-id')?.addEventListener('input', editHook);
        document.addEventListener('click', (event) => {
            const closer = event.target.closest('[data-farz-close]');
            if (closer) close(closer.getAttribute('data-farz-close'));
            const edit = event.target.closest('[data-line-edit]');
            if (!edit) return;
            const form = document.getElementById('farz-edit-form');
            form.action = edit.getAttribute('data-action') || '';
            document.getElementById('farz-edit-label').textContent = edit.getAttribute('data-label') || '';
            document.getElementById('farz-edit-id').value = edit.getAttribute('data-id') || '';
            document.getElementById('farz-edit-token').value = edit.getAttribute('data-token') || '';
            editHook();
            open('farz-edit-modal');
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            close('farz-warm-modal');
            close('farz-edit-modal');
        });
        const reopenEdit = @json(session('edit_line'));
        if (reopenEdit) {
            const button = document.querySelector('[data-line-edit][data-id="' + reopenEdit + '"]');
            button?.click();
            const oldId = @json(old('id'));
            const oldToken = @json(old('token'));
            if (oldId) document.getElementById('farz-edit-id').value = oldId;
            if (oldToken) document.getElementById('farz-edit-token').value = oldToken;
            editHook();
        }
        @if($errors->has('phones') || $errors->has('message') || $errors->has('lines'))
            open('farz-warm-modal');
        @endif
    })();
</script>
@if($lines->isNotEmpty())
<script>
    (function () {
        const list = document.getElementById('farz-nums');
        if (!list) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const hooks = @json($botHooks);
        const fallbackHook = @json($hookPrefix);
        const note = document.getElementById('farz-nums-note');
        const search = document.getElementById('farz-nums-q');
        const empty = document.getElementById('farz-nums-empty');
        const cards = () => [...list.querySelectorAll('[data-pool-line]')];
        let tab = 'all';
        const say = (text, bad) => {
            if (!note) return;
            note.hidden = !text;
            note.textContent = text || '';
            note.classList.toggle('is-bad', !!bad);
        };
        const matches = (card) => ({
            all: true,
            linked: card.dataset.owner !== '0',
            free: card.dataset.owner === '0',
            blocked: card.classList.contains('is-blocked'),
        })[tab];
        const filter = () => {
            const q = (search?.value || '').trim().toLowerCase();
            let shown = 0;
            cards().forEach((card) => {
                const visible = matches(card) && (!q || (card.dataset.find || '').includes(q));
                card.hidden = !visible;
                if (visible) shown++;
            });
            if (empty) empty.hidden = shown > 0;
            document.querySelectorAll('.farz-nums__tab').forEach((button) => {
                const on = button.dataset.tab === tab;
                button.classList.toggle('is-on', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        };
        const recount = () => {
            const linked = cards().filter((card) => card.dataset.owner !== '0').length;
            document.querySelectorAll('[data-count="linked"]').forEach((el) => { el.textContent = linked; });
            document.querySelectorAll('[data-count="free"]').forEach((el) => { el.textContent = cards().length - linked; });
        };
        document.querySelectorAll('.farz-nums__tab').forEach((button) => {
            button.addEventListener('click', () => { tab = button.dataset.tab; filter(); });
        });
        search?.addEventListener('input', filter);
        filter();

        list.addEventListener('change', async (event) => {
            const select = event.target;
            if (!select.matches('[data-num-owner]')) return;
            const card = select.closest('[data-pool-line]');
            const was = card.dataset.owner;
            const name = select.options[select.selectedIndex].text.replace('يعمل على: ', '');
            const question = select.value === '0'
                ? 'فصل الرقم عن بوته؟ سيتوقف عن الرد حتى تربطه ببوت.'
                : 'نقل الرقم إلى «' + name + '»؟ سيتوقف عن العمل على البوت الحالي.';
            if (was !== '0' && !window.confirm(question)) {
                select.value = was;
                return;
            }
            card.classList.add('is-busy');
            try {
                const res = await fetch(select.dataset.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ target: Number(select.value) }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'تعذّر الحفظ، حاول مرة ثانية');
                const owner = String(data.bot_id || 0);
                card.dataset.owner = owner;
                select.value = owner;
                card.classList.toggle('is-live', owner !== '0' && !card.classList.contains('is-blocked'));
                const hook = card.querySelector('[data-hook]');
                if (hook) hook.value = (hooks[owner] || fallbackHook) + '/' + card.dataset.poolLine;
                recount();
                filter();
                say(data.message || '');
            } catch (error) {
                select.value = was;
                say(error.message, true);
            } finally {
                card.classList.remove('is-busy');
            }
        });

        document.getElementById('farz-pool-check')?.addEventListener('submit', () => {
            document.querySelectorAll('[form="farz-pool-check"]').forEach((button) => {
                button.disabled = true;
                button.classList.add('is-busy');
                const label = button.querySelector('span');
                if (label) label.textContent = 'جارٍ الفحص…';
            });
        });
    })();
</script>
@endif
@if($canManageSettings && $lines->isNotEmpty())
<script>
    (function () {
        const url = @json(route('ai-chatbot.farz.numbers.status', ['bot' => $instance->id]));
        const paint = (payload) => {
            const note = document.getElementById('farz-hold-note');
            if (note && payload.summary) note.textContent = payload.summary;
            (payload.lines || []).forEach((row) => {
                const card = document.querySelector('[data-pool-line="' + row.id + '"]');
                if (!card) return;
                card.classList.toggle('is-blocked', !!row.blocked);
                card.classList.toggle('is-live', !row.blocked && card.dataset.owner !== '0');
                const phone = card.querySelector('[data-pool-phone]');
                if (phone) {
                    const digits = String(row.phone || '').replace(/\D+/g, '');
                    phone.textContent = digits ? '+' + digits : '#' + row.id;
                }
                const badge = card.querySelector('[data-pool-badge]');
                const text = card.querySelector('[data-pool-badge-text]');
                if (!badge || !text) return;
                text.textContent = row.badge || '';
                badge.classList.toggle('is-blocked', !!row.blocked);
                badge.classList.toggle('is-live', !row.blocked);
            });
        };
        const pull = async () => {
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) return;
                paint(await response.json());
            } catch (e) {}
        };
        pull();
        setInterval(() => { if (!document.hidden) pull(); }, 60000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) pull(); });
    })();
</script>
@endif
@endsection
