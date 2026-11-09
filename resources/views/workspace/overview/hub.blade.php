@php
    // Kaman Portal: one circle, four curved doors around the Kaman core.
    // Geometry lives in a 600×600 viewBox centred on 300,300; every label is horizontal HTML.
    $rtl = in_array(app()->getLocale(), ['ar', 'he'], true);
    $cx = 300;
    $rCore = 102;
    $rIn = 114;
    $rOut = 282;
    $rRim = 292;
    $seam = 1.6; // half the seam width; seams keep one width from core to rim

    $pt = fn (float $r, float $deg): array => [
        round($cx + $r * cos(deg2rad($deg)), 2),
        round($cx + $r * sin(deg2rad($deg)), 2),
    ];
    $pct = fn (array $p): string => sprintf('left:%s%%;top:%s%%', round($p[0] / 6, 3), round($p[1] / 6, 3));
    // A quarter ring whose straight edges sit a fixed distance from the seam line, not a fixed angle.
    $door = function (float $r0, float $r1, float $a0, float $a1, float $s) use ($pt): string {
        $o0 = rad2deg(asin($s / $r0));
        $o1 = rad2deg(asin($s / $r1));
        [$ax, $ay] = $pt($r0, $a0 + $o0);
        [$bx, $by] = $pt($r1, $a0 + $o1);
        [$cx2, $cy2] = $pt($r1, $a1 - $o1);
        [$dx, $dy] = $pt($r0, $a1 - $o0);

        return "M{$ax} {$ay}L{$bx} {$by}A{$r1} {$r1} 0 0 1 {$cx2} {$cy2}L{$dx} {$dy}A{$r0} {$r0} 0 0 0 {$ax} {$ay}Z";
    };
    $arc = function (float $r, float $a0, float $a1) use ($pt): string {
        [$x0, $y0] = $pt($r, $a0);
        [$x1, $y1] = $pt($r, $a1);

        return "M{$x0} {$y0}A{$r} {$r} 0 0 1 {$x1} {$y1}";
    };

    // Sales sits on top; the rest follow clockwise in LTR and anticlockwise in RTL.
    $angles = $rtl
        ? ['agents' => 270, 'support' => 180, 'development' => 90, 'finance' => 0]
        : ['agents' => 270, 'support' => 0, 'development' => 90, 'finance' => 180];

    $icons = [
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'headset' => 'M4 13a8 8 0 0 1 16 0v4a2 2 0 0 1-2 2h-2v-6h4M4 13v4a2 2 0 0 0 2 2h2v-6H4',
        'code' => 'm8 8-5 4 5 4M16 8l5 4-5 4M13.5 5l-3 14',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20V8',
    ];

    $doors = [
        ['key' => 'agents', 'href' => $agentsHref, 'name' => __('app.hub_agents_nav'), 'desc' => __('workspace.agents_desc'), 'icon' => 'users'],
        ['key' => 'support', 'href' => $supportHref, 'name' => __('app.hub_support_nav'), 'desc' => __('workspace.support_desc'), 'icon' => 'headset'],
        ['key' => 'development', 'href' => $devHref, 'name' => __('app.hub_dev'), 'desc' => __('workspace.development_desc'), 'icon' => 'code'],
        ['key' => 'finance', 'href' => $financeHref, 'name' => __('app.hub_finance'), 'desc' => __('workspace.finance_desc'), 'icon' => 'chart'],
    ];

    foreach ($doors as &$d) {
        $d['locked'] = ! auth()->user()->canOpenDoor(\App\Enums\Door::from($d['key']));
        $m = $angles[$d['key']];
        $a0 = $m - 45;
        $a1 = $m + 45;
        // Pinwheel hinges: each door turns on its leading seam (mirrored in RTL) and swings toward the viewer.
        $hinge = $rtl ? $a1 : $a0;
        $sw = $rtl ? -1 : 1;
        [$hx, $hy] = $pt(200, $hinge);
        $tx = -sin(deg2rad($hinge)) * $sw;
        $ty = cos(deg2rad($hinge)) * $sw;
        // Zoom target when entering: the lit passage, toward the free edge of the swung-open door.
        $room = $pt(194, $m + 20 * $sw);
        $d += [
            'wide' => $m === 90 || $m === 270,
            'leaf' => $door($rIn, $rOut, $a0, $a1, $seam),
            'room' => $door($rIn + 0.5, $rOut - 0.5, $a0, $a1, $seam + 0.5),
            'hit' => $door($rIn - 8, $rRim, $a0, $a1, 0.01),
            'steps' => [$arc(152, $a0 + 1, $a1 - 1), $arc(196, $a0 + 1, $a1 - 1), $arc(244, $a0 + 1, $a1 - 1)],
            'glow' => $arc($rIn + 1.2, $a0 + 1.4, $a1 - 1.4),
            'mark' => $arc($rRim, $m - 5, $m + 5),
            'inset' => $door($rIn + 7, $rOut - 7, $a0, $a1, $seam + 7),
            'shade' => [$hx, $hy, round($hx + $tx * 110, 2), round($hy + $ty * 110, 2)],
            'label' => $pct($pt(200, $m)),
            'style' => sprintf(
                '--ox:%s%%;--oy:%s%%;--ax:%s;--ay:%s;--sw:%d;--zx:%s%%;--zy:%s%%',
                round($hx / 6, 3), round($hy / 6, 3),
                round(cos(deg2rad($hinge)), 4), round(sin(deg2rad($hinge)), 4),
                $sw,
                round($room[0] / 6, 3), round($room[1] / 6, 3),
            ),
        ];
    }
    unset($d);
@endphp
<section class="portal-scene" id="hub-scene" @if($page !== 'dashboard') hidden @endif aria-label="{{ __('app.hub_overview') }}">

    <div class="portal" data-portal>
        <svg class="portal-base" viewBox="0 0 600 600" aria-hidden="true" focusable="false">
            <defs>
                <filter id="portal-soft" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="14"/></filter>
                <radialGradient id="portal-plate" cx="300" cy="300" r="{{ $rRim }}" gradientUnits="userSpaceOnUse">
                    <stop offset="0.35" stop-color="#0b0a09"/>
                    <stop offset="1" stop-color="#121010"/>
                </radialGradient>
                <radialGradient id="portal-core" cx="300" cy="250" r="160" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#26211d"/>
                    <stop offset="1" stop-color="#121010"/>
                </radialGradient>
                {{-- The passages are lit from the Kaman core outward. --}}
                <radialGradient id="portal-light" cx="300" cy="300" r="{{ $rOut }}" gradientUnits="userSpaceOnUse">
                    <stop offset="0.4" stop-color="#ffb26a" stop-opacity="0.5"/>
                    <stop offset="0.62" stop-color="#f58220" stop-opacity="0.2"/>
                    <stop offset="1" stop-color="#f58220" stop-opacity="0.02"/>
                </radialGradient>
                @foreach ($doors as $d)
                    <linearGradient id="portal-shade-{{ $d['key'] }}" x1="{{ $d['shade'][0] }}" y1="{{ $d['shade'][1] }}" x2="{{ $d['shade'][2] }}" y2="{{ $d['shade'][3] }}" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#000" stop-opacity="0.72"/>
                        <stop offset="1" stop-color="#000" stop-opacity="0"/>
                    </linearGradient>
                @endforeach
                <linearGradient id="portal-face" x1="0" y1="18" x2="0" y2="582" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#25211e"/>
                    <stop offset="1" stop-color="#181513"/>
                </linearGradient>
                <linearGradient id="portal-edge" x1="0" y1="18" x2="0" y2="582" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#4b2c14"/>
                    <stop offset="1" stop-color="#26170b"/>
                </linearGradient>
            </defs>

            <circle class="portal-base__shadow" cx="300" cy="312" r="{{ $rRim - 6 }}" filter="url(#portal-soft)"/>
            <circle class="portal-base__plate" cx="300" cy="300" r="{{ $rRim }}" fill="url(#portal-plate)"/>
            @foreach ($doors as $d)
                <path class="portal-mark" data-mark="{{ $d['key'] }}" d="{{ $d['mark'] }}"/>
            @endforeach

            @foreach ($doors as $d)
                <g class="portal-room" data-room="{{ $d['key'] }}">
                    <path class="portal-room__floor" d="{{ $d['room'] }}"/>
                    <path d="{{ $d['room'] }}" fill="url(#portal-light)"/>
                    @foreach ($d['steps'] as $step)
                        <path class="portal-room__step" d="{{ $step }}"/>
                    @endforeach
                    <path class="portal-room__shade" d="{{ $d['room'] }}" fill="url(#portal-shade-{{ $d['key'] }})"/>
                </g>
            @endforeach

            <circle class="portal-base__core" cx="300" cy="300" r="{{ $rCore }}" fill="url(#portal-core)"/>
        </svg>


        @foreach ($doors as $d)
            <a class="portal-door{{ $d['wide'] ? ' is-wide' : '' }}{{ $d['locked'] ? ' is-locked' : '' }}" href="{{ $d['href'] }}" data-door="{{ $d['key'] }}"
               data-desc="{{ $d['desc'] }}" style="{{ $d['style'] }}"
               aria-label="{{ __('workspace.open') }} {{ $d['name'] }} — {{ $d['desc'] }}">
                <span class="portal-leaf" aria-hidden="true">
                    <svg class="portal-leaf__edge" viewBox="0 0 600 600"><path d="{{ $d['leaf'] }}"/></svg>
                    <svg class="portal-leaf__edge portal-leaf__edge--deep" viewBox="0 0 600 600"><path d="{{ $d['leaf'] }}"/></svg>
                    <svg class="portal-leaf__face" viewBox="0 0 600 600">
                        <path class="portal-leaf__panel" d="{{ $d['leaf'] }}"/>
                        <path class="portal-leaf__glow" d="{{ $d['glow'] }}"/>
                        <path class="portal-leaf__inset" d="{{ $d['inset'] }}"/>
                        <path class="portal-leaf__shade" d="{{ $d['leaf'] }}"/>
                    </svg>
                    <span class="portal-leaf__label" style="{{ $d['label'] }}">
                        <svg viewBox="0 0 24 24"><path d="{{ $icons[$d['icon']] }}"/></svg>
                        <span>{{ $d['name'] }}</span>
                        @if($d['locked'])
                            <span class="portal-leaf__lock"><x-workspace.lock-mark /></span>
                        @endif
                    </span>
                </span>
                <svg class="portal-hit" viewBox="0 0 600 600" aria-hidden="true" focusable="false">
                    <path class="portal-hit__area" d="{{ $d['hit'] }}"/>
                    <path class="portal-hit__focus" d="{{ $d['leaf'] }}"/>
                </svg>
            </a>
        @endforeach

        <div class="portal-core" aria-hidden="true">
            <span class="portal-core__breath"></span>
            <img src="{{ asset('kaman.png') }}" alt="">
            <span class="portal-core__label">{{ __('workspace.orbit_center') }}</span>
        </div>

        <div class="portal-glow" aria-hidden="true"></div>
        <div class="portal-flash" aria-hidden="true"></div>
    </div>

    <p class="portal-caption" aria-live="polite">
        <span class="portal-caption__hint">{{ __('workspace.orbit_hint') }}</span>
        <span class="portal-caption__desc" data-portal-desc></span>
    </p>

    <a class="portal-browse" @if(auth()->user()->canOpenSubDoor(\App\Enums\Door::Overview, 'restaurants')) data-ag-nav="restaurants" @endif href="{{ route('workspace.overview', ['page' => 'restaurants']) }}">
        <span>{{ __('app.browse_restaurants') }}</span>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
</section>
