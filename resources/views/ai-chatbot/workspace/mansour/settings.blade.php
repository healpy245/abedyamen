@php
    $activeProject = 'ai-chatbot';
    $opening = \App\Services\AiChatbot\MansourPrompt::opening($instance);
    $hookPrefix = rtrim(route('ai-chatbot.greenapi.webhook', $instance->greenapi_webhook_token), '/');
    $storedLines = app(\App\Services\AiChatbot\MansourLines::class)->lines($instance);
    $submitted = old('greenapi_urls');
    if (is_array($submitted)) {
        $byUrl = [];
        foreach ($storedLines as $storedLine) {
            $byUrl[$storedLine['url']] = $storedLine;
        }
        $lines = [];
        foreach ($submitted as $submittedUrl) {
            $submittedUrl = trim((string) $submittedUrl);
            if ($submittedUrl === '') {
                continue;
            }
            if (isset($byUrl[$submittedUrl])) {
                $lines[] = $byUrl[$submittedUrl];
                continue;
            }
            $lines[] = [
                'id' => '',
                'url' => $submittedUrl,
                'status' => 'ready',
                'reason' => null,
                'changed_at' => null,
                'name' => '',
                'phone' => '',
                'avatar' => '',
                'pending' => true,
            ];
        }
    } else {
        $lines = $storedLines;
    }
    $poolMode = ($instance->user?->isMowahada() ?? false) && ! is_array($submitted);
    $poolOwners = [];
    if ($poolMode) {
        $pool = app(\App\Services\AiChatbot\MowahadaLinePool::class);
        $lines = $pool->rowsFor($instance, $storedLines);
        $poolOwners = $pool->owners();
    }
    $lineCount = count($lines);
    $waProvider = old('whatsapp_provider', app(\App\Services\AiChatbot\WhapiCloudClient::class)->provider());
    $blockedLabels = [
        'blocked' => 'محظور',
        'yellowCard' => 'إنذار من واتساب',
        'suspended' => 'موقوف',
        'notAuthorized' => 'غير مسجّل',
        'quota' => 'انتهى الرصيد',
    ];
@endphp
@extends('layouts.farz')

@section('title', __('chatbot.mansour.settings').' — '.$instance->name)
@section('tag', __('chatbot.workspace.tag'))

@section('content')
@php use App\Support\FarzUi; @endphp
<div class="farz-page farz-page--wide">
    <div class="farz-page__head">
        <span class="farz-page__ico">{{ FarzUi::svg('gear') }}</span>
        <h1>{{ __('chatbot.mansour.settings') }}</h1>
    </div>

    @if(session('status'))
        <p class="farz-flash is-ok">{{ FarzUi::svg('check') }} {{ session('status') }}</p>
    @endif
    @if($errors->any())
        <p class="farz-flash is-bad">{{ FarzUi::svg('alert') }} {{ $errors->first() }}</p>
    @endif

    <form method="post" action="{{ route('ai-chatbot.workspace.mansour.settings.update', $instance) }}" class="farz-form">
        @csrf
        @method('PUT')

        <section class="farz-panel">
            <h2 class="farz-panel__title">{{ FarzUi::svg('bot') }} البوت</h2>
            <div class="farz-field farz-field--inline">
                <label for="name">{{ FarzUi::svg('user') }} {{ __('chatbot.mansour.name') }}</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $instance->name) }}" class="kaman-input w-full" @disabled(! $canManageSettings)>
            </div>
        </section>

        <section class="farz-panel">
            @include('ai-chatbot.partials.whatsapp-provider', ['canEdit' => $canManageSettings])

            <div data-provider-panel="greenapi" @if($waProvider === 'whapi') hidden @endif class="space-y-3">
                @if($poolMode)
                    <input type="hidden" name="lines_keep" value="1">
                    @include('ai-chatbot.workspace.mansour.pool-lines')
                @else
                <div class="farz-panel__row">
                    <h2 class="farz-panel__title">{{ FarzUi::svg('phone') }} خطوط واتساب <small class="farz-count">{{ $lineCount }}</small></h2>
                    @if($canManageSettings)
                        <div class="farz-lines__tools">
                            <button type="button" class="farz-lines__add" id="farz-line-add">{{ FarzUi::svg('plus') }} إضافة خط</button>
                            <button type="submit" class="kaman-button farz-lines__save">{{ FarzUi::svg('save') }} {{ __('chatbot.mansour.save') }}</button>
                            @if($lines !== [])
                                <button type="submit" form="farz-lines-check" class="farz-copy__btn" title="تحديث حالة كل خط ورقم واتساب من Green API" aria-label="تحديث حالة الخطوط">{{ FarzUi::svg('refresh') }} تحديث</button>
                            @endif
                        </div>
                    @endif
                </div>

                <input type="hidden" name="greenapi_urls[]" value="">
                <input type="hidden" name="greenapi_active_present" value="1">
                @if($canManageSettings && $lines !== [])
                    <div class="farz-lines__bar">
                        <label class="farz-lines__all">
                            <input type="checkbox" id="farz-lines-all">
                            <span>تحديد الكل</span>
                        </label>
                        <button type="button" class="farz-lines__act is-on" id="farz-lines-on">تشغيل المحدد</button>
                        <button type="button" class="farz-lines__act is-off" id="farz-lines-off">إيقاف المحدد</button>
                        <p id="farz-lines-note" class="farz-lines__note" hidden></p>
                    </div>
                @endif
                <ul class="farz-lines" id="farz-lines">
                    @foreach($lines as $line)
                        @if(!empty($line['pending']))
                            <li class="farz-line is-new">
                                <div class="farz-line__top">
                                    <span class="farz-line__pick is-empty">{{ FarzUi::svg('plus') }}</span>
                                    <div class="farz-line__who">
                                        <span class="farz-line__avatar is-empty">{{ FarzUi::svg('user') }}</span>
                                        <div class="farz-line__id">
                                            <strong>خط جديد</strong>
                                            <span class="farz-line__badge">{{ FarzUi::svg('clock') }} جديد</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="farz-line__fields">
                                    <label class="farz-line__urlrow">
                                        <span>رابط</span>
                                        <input name="greenapi_urls[]" type="text" value="{{ $line['url'] }}" placeholder="https://xxxx.api.greenapi.com/waInstance…/sendMessage/…" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                                    </label>
                                    <div class="farz-line__hook">
                                        <span class="farz-line__hooklabel">{{ FarzUi::svg('webhook') }} ويب هوك</span>
                                        <input type="text" readonly value="" placeholder="يظهر بعد لصق الرابط" class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" data-hook tabindex="-1">
                                        <button type="button" class="farz-copy__btn" data-copy-hook title="{{ __('chatbot.copy') }}" aria-label="{{ __('chatbot.copy') }}">{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }}</button>
                                    </div>
                                </div>
                                @if($canManageSettings)
                                    <button type="button" class="farz-line__remove" data-line-remove title="حذف" aria-label="حذف">{{ FarzUi::svg('trash') }}</button>
                                @endif
                            </li>
                            @continue
                        @endif
                        @php
                            $isBlocked = $line['status'] === \App\Services\AiChatbot\MansourLines::BLOCKED;
                            $isOn = app(\App\Services\AiChatbot\MansourLines::class)->isOn($line);
                        @endphp
                        @php
                            $phone = preg_replace('/\D+/', '', (string) ($line['phone'] ?? '')) ?? '';
                            $hook = $hookPrefix.'/'.$line['id'];
                            $title = $line['name'] !== '' ? $line['name'] : '#'.$line['id'];
                        @endphp
                        <li @class(['farz-line', 'is-live' => $isOn, 'is-blocked' => $isBlocked]) data-line-id="{{ $line['id'] }}">
                            <div class="farz-line__top">
                            @if($canManageSettings)
                                <label class="farz-line__select" title="تحديد">
                                    <input type="checkbox" data-line-pick value="{{ $line['id'] }}" @disabled($isBlocked)>
                                </label>
                            @endif
                            <label class="farz-line__pick" title="{{ $isBlocked ? 'محظور' : ($isOn ? 'يعمل — اضغط للإيقاف' : 'متوقف — اضغط للتشغيل') }}">
                                <input type="checkbox" name="greenapi_active[]" value="{{ $line['id'] }}" @checked($isOn) @disabled($isBlocked || ! $canManageSettings)>
                                <span></span>
                            </label>
                            <div class="farz-line__who">
                                @if(str_starts_with((string) ($line['avatar'] ?? ''), 'https://'))
                                    <img class="farz-line__avatar" src="{{ $line['avatar'] }}" alt="">
                                @else
                                    <span class="farz-line__avatar is-empty">{{ FarzUi::svg('user') }}</span>
                                @endif
                                <div class="farz-line__id">
                                    <strong>{{ $title }}</strong>
                                    <small dir="ltr">{{ $phone !== '' ? '+'.$phone : '#'.$line['id'] }}</small>
                                    @if($isBlocked)
                                        <span class="farz-line__badge is-blocked" title="{{ $line['changed_at'] ? \Illuminate\Support\Carbon::parse($line['changed_at'])->timezone('Asia/Jerusalem')->format('d/m H:i') : '' }}">
                                            {{ FarzUi::svg('shield-x') }} {{ $blockedLabels[$line['reason']] ?? 'محظور' }}
                                        </span>
                                    @elseif($isOn)
                                        <span class="farz-line__badge is-live">{{ FarzUi::svg('signal') }} <span data-state>يعمل</span></span>
                                    @else
                                        <span class="farz-line__badge">{{ FarzUi::svg('clock') }} <span data-state>متوقف</span></span>
                                    @endif
                                </div>
                            </div>
                            </div>
                            <div class="farz-line__fields">
                                <label class="farz-line__urlrow">
                                    <span>رابط</span>
                                    <input name="greenapi_urls[]" type="text" value="{{ $line['url'] }}" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off" @disabled(! $canManageSettings) @readonly(! $canManageSettings)>
                                </label>
                                <div class="farz-line__hook">
                                    <span class="farz-line__hooklabel">{{ FarzUi::svg('webhook') }} ويب هوك</span>
                                    <input type="text" readonly value="{{ $hook }}" class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" data-hook tabindex="-1">
                                    <button type="button" class="farz-copy__btn" data-copy-hook title="{{ __('chatbot.copy') }}" aria-label="{{ __('chatbot.copy') }}">{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }}</button>
                                </div>
                            </div>
                            @if($canManageSettings)
                                <button type="button" class="farz-line__remove" data-line-remove title="حذف" aria-label="حذف">{{ FarzUi::svg('trash') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if($canManageSettings)
                    <template id="farz-line-template">
                        <li class="farz-line is-new">
                            <div class="farz-line__top">
                            <span class="farz-line__pick is-empty">{{ FarzUi::svg('plus') }}</span>
                            <div class="farz-line__who">
                                <span class="farz-line__avatar is-empty">{{ FarzUi::svg('user') }}</span>
                                <div class="farz-line__id">
                                    <strong>خط جديد</strong>
                                    <span class="farz-line__badge">{{ FarzUi::svg('clock') }} جديد</span>
                                </div>
                            </div>
                            </div>
                            <div class="farz-line__fields">
                                <label class="farz-line__urlrow">
                                    <span>رابط</span>
                                    <input name="greenapi_urls[]" type="text" placeholder="https://xxxx.api.greenapi.com/waInstance…/sendMessage/…" class="kaman-input farz-line__url font-mono text-xs" dir="ltr" spellcheck="false" autocomplete="off">
                                </label>
                                <div class="farz-line__hook">
                                    <span class="farz-line__hooklabel">{{ FarzUi::svg('webhook') }} ويب هوك</span>
                                    <input type="text" readonly value="" placeholder="يظهر بعد لصق الرابط" class="kaman-input farz-line__hookurl font-mono text-xs" dir="ltr" data-hook tabindex="-1">
                                    <button type="button" class="farz-copy__btn" data-copy-hook title="{{ __('chatbot.copy') }}" aria-label="{{ __('chatbot.copy') }}">{{ FarzUi::svg('copy') }}{{ FarzUi::svg('check') }}</button>
                                </div>
                            </div>
                            <button type="button" class="farz-line__remove" data-line-remove title="حذف" aria-label="حذف">{{ FarzUi::svg('trash') }}</button>
                        </li>
                    </template>
                @endif
                @endif
            </div>
        </section>

        <section class="farz-panel">
            <h2 class="farz-panel__title">{{ FarzUi::svg('chat') }} الرسائل</h2>
            <div class="farz-land">
                <div class="farz-field">
                    <label for="opening_message">{{ FarzUi::svg('message') }} {{ __('chatbot.mansour.opening') }}</label>
                    <textarea id="opening_message" name="opening_message" rows="8" required class="kaman-input w-full text-sm" @disabled(! $canManageSettings)>{{ old('opening_message', $opening) }}</textarea>
                </div>
                <div class="farz-field">
                    <label for="system_prompt">{{ FarzUi::svg('flask') }} {{ __('chatbot.mansour.prompt') }}</label>
                    <textarea id="system_prompt" name="system_prompt" rows="8" required class="kaman-input w-full text-sm leading-relaxed" @disabled(! $canManageSettings)>{{ old('system_prompt', $instance->system_prompt) }}</textarea>
                </div>
            </div>
        </section>

        @if($canManageSettings)
            <div class="farz-form__save">
                <button type="submit" class="kaman-button">{{ FarzUi::svg('save') }} {{ __('chatbot.mansour.save') }}</button>
            </div>
        @endif
    </form>

    @if($canManageSettings && ! $poolMode)
        <form id="farz-lines-check" method="post" action="{{ route('ai-chatbot.workspace.mansour.lines.check', $instance) }}" hidden>@csrf</form>
        <script>
            (function () {
                const list = document.getElementById('farz-lines');
                const template = document.getElementById('farz-line-template');
                const count = document.querySelector('.farz-count');
                const hookPrefix = @json($hookPrefix);
                const recount = () => { if (count) count.textContent = list.children.length; };
                const fillHook = (row) => {
                    const urlInput = row.querySelector('.farz-line__url');
                    if (!urlInput) return;
                    const hook = row.querySelector('[data-hook]');
                    const match = (urlInput.value || '').match(/waInstance(\d+)/i);
                    if (hook) hook.value = match ? hookPrefix + '/' + match[1] : '';
                };
                document.getElementById('farz-line-add')?.addEventListener('click', () => {
                    if (!template) return;
                    list.appendChild(template.content.cloneNode(true));
                    const input = list.lastElementChild.querySelector('.farz-line__url');
                    list.lastElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    input?.focus();
                    recount();
                });
                document.querySelector('[form="farz-lines-check"]')?.addEventListener('click', (event) => {
                    const pending = [...list.querySelectorAll('.farz-line.is-new .farz-line__url')]
                        .some((input) => input.value.trim() !== '');
                    if (!pending) return;
                    event.preventDefault();
                    showNote('احفظ الخط الجديد أولاً');
                });
                list.addEventListener('input', (event) => {
                    const row = event.target.closest('.farz-line');
                    if (row && event.target.classList.contains('farz-line__url')) fillHook(row);
                });
                list.addEventListener('click', (event) => {
                    const copy = event.target.closest('[data-copy-hook]');
                    if (copy) {
                        const value = copy.parentElement?.querySelector('[data-hook]')?.value || '';
                        if (value) {
                            navigator.clipboard.writeText(value);
                            copy.classList.add('is-done');
                            setTimeout(() => copy.classList.remove('is-done'), 1200);
                        }
                        return;
                    }
                    const button = event.target.closest('[data-line-remove]');
                    if (!button) return;
                    button.closest('.farz-line')?.remove();
                    recount();
                });
                if (!list.children.length) document.getElementById('farz-line-add')?.click();
                list.querySelectorAll('.farz-line').forEach(fillHook);

                const picks = () => [...list.querySelectorAll('[data-line-pick]:not(:disabled):not([data-elsewhere])')];
                const chosen = () => [...list.querySelectorAll('[data-line-pick]:not(:disabled)')].filter((box) => box.checked);
                const allBox = document.getElementById('farz-lines-all');
                const note = document.getElementById('farz-lines-note');
                const syncAll = () => {
                    const boxes = picks();
                    if (!allBox) return;
                    const chosen = boxes.filter((box) => box.checked).length;
                    allBox.checked = boxes.length > 0 && chosen === boxes.length;
                    allBox.indeterminate = chosen > 0 && chosen < boxes.length;
                };
                const showNote = (text) => {
                    if (!note) return;
                    note.hidden = text === '';
                    note.textContent = text;
                };
                const paintLine = (id, on) => {
                    const row = list.querySelector('[data-line-id="' + id + '"]');
                    if (!row || row.classList.contains('is-blocked')) return;
                    row.classList.toggle('is-live', on);
                    const power = row.querySelector('input[name="greenapi_active[]"]');
                    if (power) power.checked = on;
                    const badge = row.querySelector('.farz-line__badge');
                    const state = row.querySelector('[data-state]');
                    if (badge && state) {
                        badge.classList.toggle('is-live', on);
                        state.textContent = on ? 'يعمل' : 'متوقف';
                    }
                };
                allBox?.addEventListener('change', () => {
                    picks().forEach((box) => { box.checked = allBox.checked; });
                    showNote('');
                });
                list.addEventListener('change', (event) => {
                    if (event.target.matches('[data-line-pick]')) syncAll();
                });
                const applySelected = async (on) => {
                    const boxes = chosen();
                    const ids = boxes.map((box) => box.value);
                    if (!ids.length) {
                        showNote('اختار خط أولاً');
                        return;
                    }
                    const moved = boxes.some((box) => !box.closest('[data-line-id]'));
                    showNote('…');
                    const res = await fetch(@json(route('ai-chatbot.workspace.mansour.lines.active', $instance)), {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        },
                        body: JSON.stringify({ line_ids: ids, active: on }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (moved && (data.ids || []).length) {
                        window.location.reload();
                        return;
                    }
                    (data.ids || []).forEach((id) => paintLine(id, on));
                    showNote(data.message || '');
                };
                document.getElementById('farz-lines-on')?.addEventListener('click', () => applySelected(true));
                document.getElementById('farz-lines-off')?.addEventListener('click', () => applySelected(false));

                const paintHealth = (line) => {
                    const row = list.querySelector('[data-line-id="' + line.id + '"]');
                    if (!row || row.classList.contains('is-new')) return;
                    const blocked = line.status === 'blocked';
                    row.classList.toggle('is-blocked', blocked);
                    row.classList.toggle('is-live', !blocked && !!line.on);
                    const power = row.querySelector('input[name="greenapi_active[]"]');
                    const pick = row.querySelector('[data-line-pick]');
                    if (power) {
                        power.disabled = blocked;
                        power.checked = !blocked && !!line.on;
                    }
                    if (pick) {
                        pick.disabled = blocked;
                        if (blocked) pick.checked = false;
                    }
                    const phone = row.querySelector('.farz-line__id small');
                    if (phone && line.phone) phone.textContent = '+' + String(line.phone).replace(/\D/g, '');
                    const badge = row.querySelector('.farz-line__badge');
                    if (!badge) return;
                    badge.classList.toggle('is-blocked', blocked);
                    badge.classList.toggle('is-live', !blocked && !!line.on);
                    let state = badge.querySelector('[data-state]');
                    if (!state) {
                        state = document.createElement('span');
                        state.setAttribute('data-state', '');
                        badge.replaceChildren(state);
                    }
                    state.textContent = line.label || '';
                };
                const pullHealth = async () => {
                    if (document.visibilityState === 'hidden') return;
                    try {
                        const response = await fetch(@json(route('ai-chatbot.workspace.mansour.lines.state', $instance)), {
                            headers: { Accept: 'application/json' },
                        });
                        if (!response.ok) return;
                        const data = await response.json();
                        (data.lines || []).forEach(paintHealth);
                    } catch (e) {}
                };
                setInterval(pullHealth, 8000);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') pullHealth();
                });
            })();
        </script>
    @endif
</div>
@include('ai-chatbot.workspace.mansour.trigger-modal')
@endsection
