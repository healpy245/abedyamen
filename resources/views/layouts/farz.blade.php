{{--
    Green فرز layout for Mansour bot pages (chats, test, settings).

    Needs $instance. Pages provide @section('title'), @section('content'),
    @push('head') and @push('scripts') exactly like layouts.kaman, so a page
    can pick either layout without other changes.
--}}
@php
    use App\Support\FarzUi;

    /** @var \App\Models\AiChatbot\ChatbotInstance $instance */
    if (auth()->check() && (int) $instance->user_id > 0) {
        $mowahadaOwner = \App\Models\User::query()->where('email', \App\Models\User::MOWAHADA_EMAIL)->first();
        if ($mowahadaOwner instanceof \App\Models\User && (int) $instance->user_id === (int) $mowahadaOwner->id) {
            \Illuminate\Support\Facades\Cache::remember('mowahada-invite-ready', 60, function () use ($mowahadaOwner): bool {
                $bots = app(\App\Services\AiChatbot\MansourBotService::class);
                $bots->ensureInviteBot($mowahadaOwner);
                $bots->ensureYouthInviteBot($mowahadaOwner);
                $bots->shareInviteBot();

                return true;
            });
        }
    }
    $farzBots = auth()->check()
        ? app(\App\Services\AiChatbot\FarzDashboardService::class)->botsFor(auth()->user())
        : collect();
    if (! isset($canControlBot) && auth()->check()) {
        $canControlBot = app(\App\Services\AiChatbot\ChatbotAuthorizationService::class)
            ->can(auth()->user(), $instance, \App\Services\AiChatbot\ChatbotAuthorizationService::ABILITY_CONTROL_BOT);
    }
    $canToggleBot = $canManageSettings ?? $canControlBot ?? false;
    $showGreenSync = (bool) ($canControlBot ?? false) && ($instance->user?->isMowahada() ?? false);
    $farzNav = [
        ['route' => 'ai-chatbot.workspace.conversations', 'match' => 'ai-chatbot.workspace.conversations*', 'icon' => 'chat', 'label' => 'المحادثات', 'title' => __('chatbot.workspace.nav_conversations')],
        ['route' => 'ai-chatbot.workspace.test.page', 'match' => 'ai-chatbot.workspace.test*', 'icon' => 'flask', 'label' => 'تجربة', 'title' => __('chatbot.workspace.nav_test')],
        ['route' => 'ai-chatbot.workspace.settings', 'match' => 'ai-chatbot.workspace.settings', 'icon' => 'gear', 'label' => 'الإعدادات', 'title' => __('chatbot.workspace.nav_settings')],
    ];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $instance->name)</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>

    <link rel="stylesheet" href="{{ asset('css/kaman.css') }}?v={{ filemtime(public_path('css/kaman.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/farz-theme.css') }}?v={{ @filemtime(public_path('css/farz-theme.css')) ?: time() }}">

    @stack('head')
</head>
<body class="farz-app antialiased min-h-screen flex flex-col">

<header class="farz-bar">
    <div class="farz-bar__row">
        <a href="{{ route('ai-chatbot.farz.dashboard') }}" class="farz-bar__brand" title="لوحة التحليلات" aria-label="لوحة التحليلات">
            <small>بوت</small>
            <strong>الفرز</strong>
        </a>

        <details class="farz-bar__bot">
            <summary title="تبديل البوت">
                <span class="farz-bar__avatar">{{ FarzUi::svg('bot') }}</span>
                <b>{{ $instance->name }}</b>
                @if($farzBots->count() > 1)
                    <span class="farz-bar__caret">{{ FarzUi::svg('arrow') }}</span>
                @endif
            </summary>
            @if($farzBots->count() > 1)
                <div class="farz-bar__menu">
                    @foreach($farzBots as $bot)
                        <a href="{{ route('ai-chatbot.workspace.conversations', $bot) }}" @class(['is-active' => $bot->is($instance)])>
                            {{ FarzUi::svg('bot') }} <span>{{ $bot->name }}</span>
                            <i @class(['farz-bar__dot', 'is-on' => $bot->isBotGloballyActive()])></i>
                        </a>
                    @endforeach
                </div>
            @endif
        </details>

        @include('ai-chatbot.workspace.partials.bot-power-toggle', [
            'instance' => $instance,
            'canToggleBot' => $canToggleBot,
            'compact' => true,
        ])

        <nav class="farz-bar__nav" aria-label="{{ __('chatbot.workspace.nav') }}">
            <a href="{{ route('ai-chatbot.farz.dashboard') }}" title="لوحة التحليلات">{{ FarzUi::svg('grid') }}<span>اللوحة</span></a>
            @foreach($farzNav as $item)
                <a href="{{ route($item['route'], $instance) }}" title="{{ $item['title'] }}" @class(['is-active' => request()->routeIs($item['match'])])>
                    {{ FarzUi::svg($item['icon']) }}<span>{{ $item['label'] }}</span>
                </a>
            @endforeach
            @if($instance->user?->isMowahada())
                <a href="{{ route('ai-chatbot.farz.numbers', ['bot' => $instance->id]) }}" title="مصدر أرقام Green API" @class(['is-active' => request()->routeIs('ai-chatbot.farz.numbers')])>
                    {{ FarzUi::svg('phone') }}<span>الأرقام</span>
                </a>
            @endif
            @if($showGreenSync)
                <button type="button" id="farz-sync-open" class="farz-bar__history" title="مزامنة رسائل Green API الناقصة" data-url="{{ route('ai-chatbot.workspace.green-sync', $instance) }}" data-apply="{{ route('ai-chatbot.workspace.green-sync.apply', $instance) }}">
                    {{ FarzUi::svg('refresh') }}<span>مزامنة</span>
                </button>
            @endif
            <button type="button" id="mansour-history-open" class="farz-bar__history" title="{{ __('chatbot.mansour.history') }}">
                {{ FarzUi::svg('clock') }}<span>{{ __('chatbot.mansour.history') }}</span>
            </button>
            @if($canControlBot ?? true)
                @if($instance->isAppsBot())
                    <button type="button" id="apps-followup-open" class="farz-bar__send farz-bar__send--later" title="{{ __('chatbot.mansour.followup_title') }}">
                        {{ FarzUi::svg('hourglass') }}<span>{{ __('chatbot.mansour.followup') }}</span>
                    </button>
                @endif
                <button type="button" id="mansour-trigger-open" class="farz-bar__send" title="{{ __('chatbot.mansour.trigger') }}">
                    {{ FarzUi::svg('send') }}<span>{{ __('chatbot.mansour.trigger') }}</span>
                </button>
            @endif
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="farz-bar__logout">
            @csrf
            <button type="submit" class="farz-bar__icon" title="تسجيل الخروج" aria-label="تسجيل الخروج">{{ FarzUi::svg('logout') }}</button>
        </form>
    </div>
</header>

@php
    $lineChoices = $instance->hasMansourIntegration()
        ? app(\App\Services\AiChatbot\MansourLines::class)->choices($instance)
        : [];
    $lineAlerts = array_values(array_filter($lineChoices, static fn (array $choice): bool => ($choice['alert'] ?? null) !== null));
@endphp
<button type="button" id="farz-dead-show" class="farz-dead-show" hidden>
    {{ FarzUi::svg('alert') }}
    <span>تنبيه الأرقام</span>
    @if($lineAlerts !== [])<b>{{ count($lineAlerts) }}</b>@endif
</button>
<div id="farz-dead" class="farz-dead" role="alert" @if($lineAlerts === []) hidden @endif>
    @if($lineAlerts !== [])
        <div class="farz-dead__bar">
            <strong>تنبيه</strong>
            <button type="button" id="farz-dead-hide" class="farz-dead__hide" title="إخفاء" aria-label="إخفاء تنبيه الأرقام">{{ FarzUi::svg('x') }}</button>
        </div>
        @foreach($lineAlerts as $dead)
            <p>
                <b dir="ltr">{{ ($dead['phone'] ?? '') !== '' ? $dead['phone'] : $dead['label'] }}</b>
                <span>{{ $dead['alert'] }}</span>
            </p>
        @endforeach
    @endif
</div>

<main class="farz-app__main relative flex min-h-0 flex-1 flex-col">
    @yield('content')
</main>

<script>
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.farz-bar__bot[open], .farz-switch[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
    (() => {
        const box = document.getElementById('farz-dead');
        const show = document.getElementById('farz-dead-show');
        const hide = document.getElementById('farz-dead-hide');
        if (!box || !show || !hide || box.hasAttribute('hidden')) return;
        const key = 'farz-dead-hidden';
        const apply = (collapsed) => {
            box.classList.toggle('is-collapsed', collapsed);
            show.hidden = !collapsed;
        };
        apply(localStorage.getItem(key) === '1');
        hide.addEventListener('click', () => {
            localStorage.setItem(key, '1');
            apply(true);
        });
        show.addEventListener('click', () => {
            localStorage.setItem(key, '0');
            apply(false);
        });
    })();
</script>
@if($showGreenSync)
    <div id="farz-sync" class="farz-sync" hidden>
        <div class="farz-sync__card" role="dialog" aria-modal="true" aria-labelledby="farz-sync-title">
            <header class="farz-sync__head">
                <h2 id="farz-sync-title">رسائل وصلت واتساب وما انحفظت</h2>
                <button type="button" id="farz-sync-close" class="farz-sync__x" aria-label="إغلاق">{{ FarzUi::svg('x') }}</button>
            </header>
            <p id="farz-sync-note" class="farz-sync__note"></p>
            <div id="farz-sync-list" class="farz-sync__list"></div>
            <footer class="farz-sync__foot">
                <button type="button" id="farz-sync-skip-all" class="farz-sync__btn">تخطي الكل</button>
                <button type="button" id="farz-sync-continue-all" class="farz-sync__btn farz-sync__btn--go">يكمل الكل</button>
                <button type="button" id="farz-sync-apply" class="farz-sync__btn farz-sync__btn--go" disabled>تطبيق</button>
            </footer>
        </div>
    </div>
    <script>
        (() => {
            const open = document.getElementById('farz-sync-open');
            const modal = document.getElementById('farz-sync');
            const list = document.getElementById('farz-sync-list');
            const note = document.getElementById('farz-sync-note');
            const apply = document.getElementById('farz-sync-apply');
            if (!open || !modal || !list) return;
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            let token = '';
            let chats = [];
            const choices = {};
            const post = async (url, body) => {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': tokenMeta ? tokenMeta.content : '',
                    },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.error || 'تعذر المزامنة');
                return data;
            };
            const ready = () => chats.length > 0 && chats.every((chat) => choices[chat.key]);
            const paint = () => {
                list.innerHTML = '';
                chats.forEach((chat) => {
                    const row = document.createElement('div');
                    row.className = 'farz-sync__row';
                    const picked = choices[chat.key] || '';
                    row.innerHTML = '<div><b></b><small></small><p></p></div><div class="farz-sync__picks"><button type="button" data-act="continue">يكمل</button><button type="button" data-act="skip">تخطي</button></div>';
                    row.querySelector('b').textContent = chat.name || chat.phone || 'محادثة';
                    row.querySelector('small').textContent = (chat.phone || '') + (chat.count > 1 ? ' · ' + chat.count + ' رسائل' : '');
                    row.querySelector('p').textContent = chat.preview || '';
                    row.querySelectorAll('button').forEach((button) => {
                        if (button.dataset.act === picked) button.classList.add('is-on');
                        button.addEventListener('click', () => {
                            choices[chat.key] = button.dataset.act;
                            paint();
                        });
                    });
                    list.appendChild(row);
                });
                apply.disabled = !ready();
            };
            const close = () => { modal.hidden = true; };
            const submit = async (forced) => {
                if (forced) chats.forEach((chat) => { choices[chat.key] = forced; });
                if (!ready()) return;
                apply.disabled = true;
                note.textContent = 'جاري الحفظ…';
                try {
                    const data = await post(open.dataset.apply, { token, choices });
                    note.textContent = 'تم. كمل الذكاء ' + (data.continued || 0) + '، وتخطى ' + (data.skipped || 0) + '.';
                    setTimeout(() => window.location.reload(), 700);
                } catch (error) {
                    note.textContent = error.message;
                    apply.disabled = !ready();
                }
            };
            open.addEventListener('click', async () => {
                open.disabled = true;
                const label = open.querySelector('span');
                const previous = label ? label.textContent : '';
                if (label) label.textContent = '…';
                try {
                    const data = await post(open.dataset.url, {});
                    chats = Array.isArray(data.chats) ? data.chats : [];
                    token = data.token || '';
                    Object.keys(choices).forEach((key) => delete choices[key]);
                    if (chats.length === 0) {
                        note.textContent = '';
                        window.alert('ما في رسائل ناقصة.');
                        return;
                    }
                    note.textContent = 'لكل محادثة: خليه يكمل، أو تخطى الرسالة بدون رد.';
                    paint();
                    modal.hidden = false;
                } catch (error) {
                    window.alert(error.message);
                } finally {
                    open.disabled = false;
                    if (label) label.textContent = previous;
                }
            });
            document.getElementById('farz-sync-close')?.addEventListener('click', close);
            modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
            document.getElementById('farz-sync-skip-all')?.addEventListener('click', () => submit('skip'));
            document.getElementById('farz-sync-continue-all')?.addEventListener('click', () => submit('continue'));
            apply.addEventListener('click', () => submit(null));
        })();
    </script>
@endif
@stack('scripts')
@include('partials.csrf-keepalive')
</body>
</html>
