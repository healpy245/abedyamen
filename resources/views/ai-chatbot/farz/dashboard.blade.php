@php
    $icon = fn (string $name) => \App\Support\FarzUi::svg($name);
    $groupMeta = \App\Support\FarzUi::groups();
    $dimaMeta = \App\Support\FarzUi::dimaGroups();
    $inviteMeta = \App\Support\FarzUi::inviteGroups();
    $hasDima = collect($data['bots'])->contains(fn (array $row): bool => (bool) ($row['dima'] ?? false));
    $fmt = fn (int $n): string => number_format($n);

    $activity = $data['activity'];
    $apps = $data['apps'];
    $appCards = [
        'aswatkum' => 'تطبيق أصواتكم',
        'muwahada' => 'تطبيق الموحدة',
    ];
    $metrics = [
        'downloaded' => 'نزّلوا',
        'interacted' => 'المتفاعلين',
        'waiting_registration' => 'بانتظار التسجيل',
        'waiting_delegate' => 'بانتظار المندوب',
    ];
    $appImages = [
        'aswatkum' => 'images/farz/aswatkum.jpg',
        'muwahada' => 'images/farz/muwahada-app.jpg',
    ];

    $days = $activity['days'];
    $dayCount = max(1, count($days));
    $maxDay = max(1, (int) $activity['max_day']);
    $plotSpan = 92;
    $replyPts = [];
    foreach ($days as $i => $day) {
        $replyPts[] = [
            round((($i + 0.5) / $dayCount) * 100, 2),
            round(100 - ((int) $day['replies'] / $maxDay) * $plotSpan, 2),
        ];
    }
    $linePath = '';
    foreach ($replyPts as $i => $pt) {
        $linePath .= ($i === 0 ? 'M ' : ' L ').$pt[0].' '.$pt[1];
    }
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>لوحة الموحدة</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/farz-dashboard.css') }}?v={{ @filemtime(public_path('css/farz-dashboard.css')) ?: time() }}">
</head>
<body class="farz">
<div class="farz-app">

<header class="farz-top">
    <a href="{{ route('ai-chatbot.farz.dashboard') }}" class="farz-brand" aria-label="لوحة الموحدة">
        <img class="farz-logo" src="{{ asset('images/farz/muwahada-app.jpg') }}" alt="" width="34" height="34">
        <span>
            <strong>الموحدة</strong>
            <small>لوحة الموحدة</small>
        </span>
    </a>

    <nav class="farz-nav" aria-label="أقسام اللوحة">
        <span class="farz-nav__glide" aria-hidden="true"></span>
        <a href="#apps">{!! $icon('grid') !!}<span>التطبيقات</span></a>
        <a href="#activity" class="is-active">{!! $icon('chart') !!}<span>النشاط</span></a>
        <a href="#bots">{!! $icon('bot') !!}<span>البوتات</span></a>
        <a href="#results">{!! $icon('ballot') !!}<span>الفرز</span></a>
        <a href="#recent">{!! $icon('chat') !!}<span>الردود</span></a>
    </nav>

    <div class="farz-top__actions">
        <span class="farz-live" data-live="updated" title="مباشر"><i class="farz-dot"></i><b dir="ltr">{{ $data['generated_at']->format('H:i') }}</b></span>
        <button type="button" class="farz-chip" data-farz-refresh title="تحديث" aria-label="تحديث">{!! $icon('refresh') !!}</button>
        <a href="{{ route('ai-chatbot.index', ['list' => 1]) }}" class="farz-chip" title="كل البوتات" aria-label="كل البوتات">{!! $icon('list') !!}</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="farz-chip" title="تسجيل الخروج" aria-label="تسجيل الخروج">{!! $icon('logout') !!}</button>
        </form>
    </div>
</header>

<main class="farz-main">
    <h1 class="farz-sr">لوحة الموحدة</h1>

    <section class="farz-kpis farz-rise" data-live="kpis" aria-label="المؤشرات" style="--i: 0">
        <article class="farz-kpi farz-kpi--lead">
            <span class="farz-kpi__ico">{!! $icon('percent') !!}</span>
            <strong data-count="{{ $data['reply_rate'] }}" data-suffix="%">{{ $data['reply_rate'] }}%</strong>
            <span class="farz-kpi__label">نسبة الرد</span>
            <i class="farz-meter" title="{{ $fmt($data['replied']) }} من {{ $fmt($data['reached']) }}"><b style="width: {{ $data['reply_rate'] }}%"></b></i>
        </article>
        <article class="farz-kpi">
            <span class="farz-kpi__ico">{!! $icon('chat') !!}</span>
            <strong data-count="{{ $data['replied'] }}">{{ $fmt($data['replied']) }}</strong>
            <span class="farz-kpi__label">ردّوا</span>
        </article>
        <article class="farz-kpi">
            <span class="farz-kpi__ico">{!! $icon('send') !!}</span>
            <strong data-count="{{ $data['sent'] }}">{{ $fmt($data['sent']) }}</strong>
            <span class="farz-kpi__label">انبعت</span>
        </article>
        <article class="farz-kpi">
            <span class="farz-kpi__ico">{!! $icon('spark') !!}</span>
            <strong data-count="{{ $data['ai_messages'] }}">{{ $fmt($data['ai_messages']) }}</strong>
            <span class="farz-kpi__label">رسائل الذكاء</span>
        </article>
        <article class="farz-kpi">
            <span class="farz-kpi__ico">{!! $icon('users') !!}</span>
            <strong data-count="{{ $data['contacts'] }}">{{ $fmt($data['contacts']) }}</strong>
            <span class="farz-kpi__label">أرقام</span>
        </article>
    </section>

    <div class="farz-board">
        <div class="farz-board__main">
            <section class="farz-card farz-rise" id="activity" style="--i: 1">
                <header class="farz-head">
                    <h2>{!! $icon('chart') !!} النشاط</h2>
                    <span>14 يوم</span>
                    <ul class="farz-legend">
                        <li><i class="is-sent"></i> انبعت</li>
                        <li><i class="is-reply"></i> ردود</li>
                    </ul>
                </header>
                <div data-live="charts">
                    <div class="farz-chart" dir="ltr" role="img" aria-label="الإرسال والردود يومياً">
                        <div class="farz-chart__stage">
                            <div class="farz-chart__yscale" aria-hidden="true">
                                <span>{{ $fmt($maxDay) }}</span>
                                <span>{{ $maxDay > 2 ? $fmt((int) round($maxDay / 2)) : '' }}</span>
                                <span>0</span>
                            </div>
                            <div class="farz-chart__grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                            <svg class="farz-chart__svg" viewBox="0 0 100 100" width="100%" height="100%" preserveAspectRatio="none" aria-hidden="true">
                                @if($linePath !== '')
                                    <path class="farz-chart__glow" d="{{ $linePath }}"></path>
                                    <path class="farz-chart__line" d="{{ $linePath }}"></path>
                                @endif
                            </svg>
                            <div class="farz-chart__cols" style="grid-template-columns: repeat({{ $dayCount }}, minmax(0, 1fr))">
                                @foreach($days as $day)
                                    <div class="farz-chart__col" style="--d: {{ $loop->index * 36 }}ms">
                                        <div class="farz-tip" role="tooltip">
                                            <b dir="ltr">{{ $day['label'] }}</b>
                                            <span><i class="is-sent"></i> انبعت <strong>{{ $fmt((int) $day['sent']) }}</strong></span>
                                            <span><i class="is-reply"></i> ردود <strong>{{ $fmt((int) $day['replies']) }}</strong></span>
                                        </div>
                                        <span class="farz-chart__dot" style="--y: {{ $replyPts[$loop->index][1] }}%"></span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="farz-chart__labels" style="grid-template-columns: repeat({{ $dayCount }}, minmax(0, 1fr))">
                            @foreach($days as $day)
                                <span dir="ltr">{{ $day['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                    @if($activity['peak_hour'] !== null)
                        <div class="farz-hours-wrap">
                            <div class="farz-hours-wrap__top">
                                <span>ذروة الرد</span>
                                <time dir="ltr">{{ sprintf('%02d:00', $activity['peak_hour']) }}</time>
                            </div>
                            <div class="farz-hours" dir="ltr" aria-hidden="true">
                                @foreach($activity['hours'] as $hour => $count)
                                    <i title="{{ sprintf('%02d:00', $hour) }}" style="--h: {{ (int) round($count / max(1, $activity['max_hour']) * 100) }}%"></i>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <section id="apps" class="farz-rise" style="--i: 3">
                <header class="farz-head">
                    <h2>{!! $icon('grid') !!} التطبيقات</h2>
                </header>
                @php
                    $unified = [
                        'downloaded' => (int) $apps['aswatkum']['downloaded'] + (int) $apps['muwahada']['downloaded'],
                        'interacted' => max((int) $apps['aswatkum']['interacted'], (int) $apps['muwahada']['interacted']),
                        'waiting_registration' => max((int) $apps['aswatkum']['waiting_registration'], (int) $apps['muwahada']['waiting_registration']),
                        'waiting_delegate' => max((int) $apps['aswatkum']['waiting_delegate'], (int) $apps['muwahada']['waiting_delegate']),
                    ];
                    $shown = [];
                    foreach ($metrics as $metric => $metricLabel) {
                        if (str_starts_with($metric, 'waiting_') && $unified[$metric] === 0) {
                            continue;
                        }
                        $shown[$metric] = [$unified[$metric], $metricLabel];
                    }
                    $sparkValues = array_map(fn (array $row): int => $row[0], array_values($shown));
                    $sparkMax = max(1, $sparkValues === [] ? 0 : max($sparkValues));
                @endphp
                <div data-live="apps">
                    <article class="farz-appcard">
                        <span class="farz-appcard__logos">
                            @foreach($appImages as $image)
                                <img src="{{ asset($image) }}" alt="" width="44" height="44">
                            @endforeach
                        </span>
                        <div class="farz-appcard__main">
                            <b>{{ $appCards['aswatkum'] }} · {{ $appCards['muwahada'] }}</b>
                            <span class="farz-spark" aria-hidden="true">
                                @foreach($shown as $row)
                                    <i style="--h: {{ round($row[0] / $sparkMax * 100) }}%; --d: {{ $loop->index * 50 }}ms"></i>
                                @endforeach
                            </span>
                        </div>
                        <ul>
                            @foreach($shown as $metric => $row)
                                <li class="{{ $metric === 'downloaded' ? 'is-lead' : '' }}">
                                    <strong data-count="{{ $row[0] }}">{{ $fmt($row[0]) }}</strong>
                                    <span>{{ $row[1] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                </div>
            </section>
        </div>

        <div class="farz-board__side">
            <section class="farz-card farz-rise" id="bots" style="--i: 2">
                <header class="farz-head">
                    <h2>{!! $icon('bot') !!} البوتات</h2>
                    <span data-live="active">{{ $data['active_bots'] }} شغّال</span>
                    <a href="{{ route('ai-chatbot.index', ['list' => 1]) }}">كل البوتات</a>
                </header>
                <div class="farz-tiles" data-live="tiles">
                    @foreach($data['bots'] as $row)
                        @php
                            $bot = $row['bot'];
                            $downloads = $row['apps'] && is_array($row['funnel'])
                                ? (int) $row['funnel']['aswatkum']['downloaded'] + (int) $row['funnel']['muwahada']['downloaded']
                                : null;
                        @endphp
                        <button type="button" class="farz-tile {{ $row['active'] ? 'is-on' : 'is-off' }}" data-farz-open="farz-bot-{{ $bot->id }}" aria-haspopup="dialog">
                            <span class="farz-avatar {{ $row['apps'] ? 'farz-avatar--pair' : '' }}">
                                @if($row['apps'])
                                    <img src="{{ asset('images/farz/aswatkum.jpg') }}" alt="">
                                    <img src="{{ asset('images/farz/muwahada-app.jpg') }}" alt="">
                                @elseif($row['dima'] ?? false)
                                    {!! $icon('spark') !!}
                                @elseif($row['invite'] ?? false)
                                    {!! $icon('send') !!}
                                @else
                                    {!! $icon('ballot') !!}
                                @endif
                            </span>
                            <span class="farz-tile__main">
                                <b>{{ $bot->name }}</b>
                                <span class="farz-tile__meta">
                                    @if($downloads !== null)
                                        <strong data-count="{{ $downloads }}">{{ $fmt($downloads) }}</strong> نزّلوا
                                    @else
                                        <strong data-count="{{ $row['reply_rate'] }}" data-suffix="%">{{ $row['reply_rate'] }}%</strong> رد
                                    @endif
                                    <i></i>
                                    <strong data-count="{{ $row['sent'] }}">{{ $fmt($row['sent']) }}</strong> انبعت
                                </span>
                            </span>
                            <span class="farz-status {{ $row['active'] ? 'is-on' : 'is-off' }}">{{ $row['active'] ? 'شغّال' : 'موقّف' }}</span>
                        </button>
                    @endforeach
                    @unless($hasDima)
                        @include('ai-chatbot.farz.partials.persuasion-bot')
                    @endunless
                </div>
            </section>

            <section class="farz-card farz-rise" id="results" style="--i: 4">
                <header class="farz-head">
                    <h2>{!! $icon('ballot') !!} الفرز</h2>
                    <span data-live="share">الموحدة {{ $data['certain_share'] }}%</span>
                </header>
                <div data-live="results">
                    <div class="farz-stack" aria-hidden="true">
                        @foreach($groupMeta as $group => $meta)
                            @if(($data['shares'][$group] ?? 0) > 0)
                                <i style="--c: {{ $meta['color'] }}; width: {{ $data['shares'][$group] }}%"></i>
                            @endif
                        @endforeach
                    </div>
                    <ul class="farz-groups">
                        @foreach($groupMeta as $group => $meta)
                            <li style="--c: {{ $meta['color'] }}">
                                <i></i>
                                <span>{{ $meta['label'] }}</span>
                                <b>{{ $fmt($data['totals'][$group]) }}</b>
                                <em dir="ltr">{{ $data['shares'][$group] }}%</em>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        </div>

        <section class="farz-card farz-board__recent farz-rise" id="recent" style="--i: 5">
            <header class="farz-head">
                <h2>{!! $icon('chat') !!} آخر الردود</h2>
            </header>
            <div data-live="recent">
                @if($data['recent'] === [])
                    <p class="farz-empty">ما في ردود لسا</p>
                @else
                    <ul class="farz-recent">
                        @foreach($data['recent'] as $item)
                            @php $meta = $item['group'] ? ($groupMeta[$item['group']] ?? $dimaMeta[$item['group']] ?? $inviteMeta[$item['group']] ?? null) : null; @endphp
                            <li>
                                <a href="{{ route('ai-chatbot.workspace.conversations.show', [$item['conversation']->instance_id, $item['conversation']->id]) }}" style="--c: {{ $meta['color'] ?? '#92A99D' }}" title="{{ trim(($meta['label'] ?? 'بالحديث').' · '.$item['name']) }}">
                                    <span class="farz-recent__av">{{ mb_substr(trim((string) $item['name']), 0, 1) ?: '•' }}</span>
                                    <b>{{ $item['name'] }}</b>
                                    <span>{{ $item['snippet'] !== '' ? $item['snippet'] : '—' }}</span>
                                    <time datetime="{{ $item['at']?->toIso8601String() }}" dir="ltr">{{ $item['at']?->format('d/m H:i') }}</time>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>
</main>
</div>

@foreach($data['bots'] as $row)
    @php
        $bot = $row['bot'];
        $botReached = max(1, $row['reached']);
        $sentPct = $row['contacts'] > 0 ? round($row['sent'] / $row['contacts'] * 100) : 0;
        $modalGroups = match (true) {
            $row['apps'] => \App\Support\FarzUi::appGroups(),
            (bool) ($row['dima'] ?? false) => $dimaMeta,
            (bool) ($row['invite'] ?? false) => $inviteMeta,
            default => $groupMeta,
        };
    @endphp
    <dialog class="farz-modal" id="farz-bot-{{ $bot->id }}" aria-labelledby="farz-bot-{{ $bot->id }}-title">
        <div class="farz-modal__card" data-live="bot-{{ $bot->id }}">
            <header class="farz-modal__head">
                <span class="farz-tile__avatar">
                    @if($row['apps'])
                        <img src="{{ asset('images/farz/aswatkum.jpg') }}" alt="">
                    @elseif($row['dima'] ?? false)
                        {!! $icon('spark') !!}
                    @elseif($row['invite'] ?? false)
                        {!! $icon('send') !!}
                    @else
                        {!! $icon('ballot') !!}
                    @endif
                </span>
                <div class="farz-modal__title">
                    <h3 id="farz-bot-{{ $bot->id }}-title">{{ $bot->name }}</h3>
                    <p>
                        <span class="farz-state {{ $row['active'] ? 'is-on' : 'is-off' }}" title="{{ $row['active'] ? 'شغّال' : 'موقّف' }}">{!! $icon('power') !!}</span>
                        <span class="farz-state {{ $row['linked'] ? 'is-on' : 'is-warn' }}" title="{{ $row['linked'] ? 'واتساب مربوط' : 'بلا واتساب' }}">{!! $icon('phone') !!}</span>
                        <span class="farz-modal__when" title="آخر إرسال">{!! $icon('clock') !!} {{ $row['last_sent'] ? $row['last_sent']->format('d/m H:i') : '—' }}</span>
                    </p>
                </div>
                <button type="button" class="farz-chip" data-farz-close aria-label="إغلاق">{!! $icon('x') !!}</button>
            </header>

            @if($row['apps'] && is_array($row['funnel']))
                @php
                    $unifiedFunnel = [
                        'downloaded' => (int) $row['funnel']['aswatkum']['downloaded'] + (int) $row['funnel']['muwahada']['downloaded'],
                        'interacted' => max((int) $row['funnel']['aswatkum']['interacted'], (int) $row['funnel']['muwahada']['interacted']),
                        'waiting_registration' => max((int) $row['funnel']['aswatkum']['waiting_registration'], (int) $row['funnel']['muwahada']['waiting_registration']),
                        'waiting_delegate' => max((int) $row['funnel']['aswatkum']['waiting_delegate'], (int) $row['funnel']['muwahada']['waiting_delegate']),
                    ];
                @endphp
                <div class="farz-unified">
                    <div class="farz-unified__head">
                        <img src="{{ asset($appImages['aswatkum']) }}" alt="" width="22" height="22">
                        <img src="{{ asset($appImages['muwahada']) }}" alt="" width="22" height="22">
                        <b>التطبيقات</b>
                    </div>
                    <ul>
                        @foreach($metrics as $metric => $label)
                            @continue(str_starts_with($metric, 'waiting_') && $unifiedFunnel[$metric] === 0)
                            <li>
                                <span>{{ $label }}</span>
                                <b>{{ $fmt($unifiedFunnel[$metric]) }}</b>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="farz-modal__send">
                <div class="farz-modal__send-top">
                    <span>{!! $icon('send') !!} انبعت</span>
                    <strong dir="ltr">{{ $fmt($row['sent']) }} <small>/ {{ $fmt($row['contacts']) }}</small></strong>
                </div>
                <div class="farz-progress"><span style="width: {{ $sentPct }}%"></span></div>
                @unless($row['apps'])
                    <div class="farz-modal__kpis">
                        <span title="ردّوا">{!! $icon('chat') !!} <b>{{ $fmt($row['replied']) }}</b></span>
                        <span title="نسبة الرد">{!! $icon('percent') !!} <b>{{ $row['reply_rate'] }}%</b></span>
                        <span title="الموحدة من الردود">{!! $icon('check') !!} <b>{{ $row['certain_share'] }}%</b></span>
                        @if($row['errors'] > 0)
                            <span title="فشل الإرسال" class="is-bad">{!! $icon('alert') !!} <b>{{ $fmt($row['errors']) }}</b></span>
                        @endif
                    </div>
                @endunless
            </div>

            <ul class="farz-results">
                @foreach($modalGroups as $group => $meta)
                    @php $pct = $row['reached'] > 0 ? round($row['groups'][$group] / $botReached * 100) : 0; @endphp
                    <li style="--c: {{ $meta['color'] }}">
                        <span class="farz-results__ico">{!! $icon($meta['icon']) !!}</span>
                        <div class="farz-results__body">
                            <div class="farz-results__top">
                                <b>{{ $meta['label'] }}</b>
                                <span><strong>{{ $fmt($row['groups'][$group]) }}</strong> · {{ $pct }}%</span>
                            </div>
                            <div class="farz-progress farz-progress--c"><span style="width: {{ $pct }}%"></span></div>
                        </div>
                        <a href="{{ route('ai-chatbot.workspace.analytics.export', [$bot, $group]) }}" class="farz-iconbtn" title="تنزيل {{ $meta['label'] }}" aria-label="تنزيل {{ $meta['label'] }}">{!! $icon('download') !!}</a>
                    </li>
                @endforeach
            </ul>

            <footer class="farz-modal__foot">
                <a href="{{ route('ai-chatbot.workspace.conversations', $bot) }}" class="farz-btn">{!! $icon('chat') !!} المحادثات</a>
                <a href="{{ route('ai-chatbot.workspace.test.page', $bot) }}" class="farz-iconbtn" title="تجربة" aria-label="تجربة">{!! $icon('flask') !!}</a>
                <a href="{{ route('ai-chatbot.workspace.settings', $bot) }}" class="farz-iconbtn" title="الإعدادات" aria-label="الإعدادات">{!! $icon('gear') !!}</a>
            </footer>
        </div>
    </dialog>
@endforeach

<script>
    (function () {
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const nav = document.querySelector('.farz-nav');
        const glide = nav?.querySelector('.farz-nav__glide');
        const links = [...document.querySelectorAll('.farz-nav a')];
        const sections = links.map((link) => document.querySelector(link.getAttribute('href'))).filter(Boolean);

        const placeGlide = () => {
            const active = nav?.querySelector('a.is-active');
            if (!nav || !glide || !active) return;
            glide.style.width = active.offsetWidth + 'px';
            glide.style.transform = 'translateX(' + active.offsetLeft + 'px)';
        };

        if (glide) {
            glide.style.transition = 'none';
            placeGlide();
            nav.classList.add('is-ready');
            requestAnimationFrame(() => { glide.style.transition = ''; });
        }

        const syncNav = () => {
            const mark = 150;
            let current = sections[0];
            sections.forEach((section) => {
                if (section.getBoundingClientRect().top <= mark) current = section;
            });
            if (current && sections[0] && sections[0].getBoundingClientRect().top > mark) {
                current = sections.reduce((best, section) => section.getBoundingClientRect().top < best.getBoundingClientRect().top ? section : best);
            }
            const activity = document.getElementById('activity');
            if (activity && current && current.id !== 'activity' && current.id !== 'apps' && current.id !== 'recent') {
                const gap = Math.abs(activity.getBoundingClientRect().top - current.getBoundingClientRect().top);
                if (gap < 90 && activity.getBoundingClientRect().top < window.innerHeight * 0.55) current = activity;
            }
            links.forEach((link) => link.classList.toggle('is-active', link.getAttribute('href') === '#' + current.id));
            placeGlide();
        };
        syncNav();
        window.addEventListener('scroll', () => requestAnimationFrame(syncNav), { passive: true });
        links.forEach((link) => link.addEventListener('click', () => {
            links.forEach((item) => item.classList.toggle('is-active', item === link));
            placeGlide();
        }));
        window.addEventListener('resize', placeGlide);
        document.fonts?.ready.then(placeGlide);

        const countUp = (root) => {
            if (reduced) return;
            root.querySelectorAll('[data-count]').forEach((el) => {
                const target = Number(el.dataset.count);
                if (!Number.isFinite(target)) return;
                const suffix = el.dataset.suffix || '';
                const start = performance.now();
                const tick = (now) => {
                    const t = Math.min(1, (now - start) / 520);
                    const value = Math.round(target * (1 - Math.pow(1 - t, 3)));
                    el.textContent = value.toLocaleString('en-US') + suffix;
                    if (t < 1) requestAnimationFrame(tick);
                };
                el.textContent = '0' + suffix;
                requestAnimationFrame(tick);
            });
        };
        countUp(document);

        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-farz-open]');
            if (opener) document.getElementById(opener.dataset.farzOpen)?.showModal();
            if (event.target.closest('[data-farz-refresh]')) refresh();

            const col = event.target.closest('.farz-chart__col');
            if (col) {
                const was = col.classList.contains('is-open');
                document.querySelectorAll('.farz-chart__col.is-open').forEach((node) => node.classList.remove('is-open'));
                if (!was) col.classList.add('is-open');
            } else {
                document.querySelectorAll('.farz-chart__col.is-open').forEach((node) => node.classList.remove('is-open'));
            }
        });
        document.querySelectorAll('.farz-modal').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog || event.target.closest('[data-farz-close]')) dialog.close();
            });
        });

        let busy = false;
        const refresh = async () => {
            if (busy || document.visibilityState !== 'visible') return;
            busy = true;
            document.querySelector('[data-farz-refresh]')?.classList.add('is-busy');
            try {
                const response = await fetch(location.pathname, { cache: 'no-store', headers: { Accept: 'text/html' } });
                if (response.redirected || !response.ok) {
                    if (response.redirected) location.reload();
                    return;
                }
                const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
                fresh.querySelectorAll('[data-live]').forEach((node) => {
                    const current = document.querySelector('[data-live="' + node.dataset.live + '"]');
                    if (!current || current.innerHTML === node.innerHTML) return;
                    current.innerHTML = node.innerHTML;
                    current.classList.remove('is-updated');
                    void current.offsetWidth;
                    current.classList.add('is-updated');
                    if (node.dataset.live === 'kpis' || node.dataset.live === 'apps' || node.dataset.live === 'tiles') countUp(current);
                });
            } catch (error) {
                // Network hiccup: try again on the next tick.
            } finally {
                busy = false;
                document.querySelector('[data-farz-refresh]')?.classList.remove('is-busy');
            }
        };
        setInterval(refresh, 10000);
        document.addEventListener('visibilitychange', refresh);
    })();
</script>
</body>
</html>
