@php
    $name = $name ?? 'dots';
@endphp
<svg class="kaman-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('upload')
            <path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 20h16"/>
            @break
        @case('download')
            <path d="M12 4v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/>
            @break
        @case('ticket')
            <path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2"/><path d="M20 15v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2"/><circle cx="6.5" cy="12" r=".6" fill="currentColor"/><circle cx="17.5" cy="12" r=".6" fill="currentColor"/><path d="M9 12h6"/>
            @break
        @case('apk')
            <rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>
            @break
        @case('layers')
            <path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/><path d="M3 17l9 5 9-5"/>
            @break
        @case('inbox')
            <path d="M4 13h4l1.5 3h5L16 13h4"/><path d="M4 7h16v12H4z"/>
            @break
        @case('wrench')
            <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4L16 11l-3-3 1.7-1.7z"/>
            @break
        @case('clipboard')
            <rect x="6" y="5" width="12" height="16" rx="2"/><path d="M9 5V3h6v2"/>
            @break
        @case('check')
            <path d="M5 12l5 5L20 7"/>
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="3.5"/><path d="M5 19a7 7 0 0 1 14 0"/>
            @break
        @case('undo')
            <path d="M9 8H5V4"/><path d="M5 8a9 9 0 1 1-1.5 8"/>
            @break
        @case('play')
            <path d="M8 5v14l12-7z" fill="currentColor" stroke="none"/>
            @break
        @case('send')
            <path d="M4 12l16-8-6 16-2-6-8-2z"/>
            @break
        @case('comment')
            <path d="M5 6h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H9l-4 3v-3H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/>
            @break
        @case('pencil')
            <path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13 7l4 4"/>
            @break
        @case('trash')
            <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>
            @break
        @case('x')
            <path d="M6 6l12 12M18 6L6 18"/>
            @break
        @case('eye')
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>
            @break
        @case('alert')
            <path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>
            @break
        @case('chevron-up')
            <path d="M6 14l6-6 6 6"/>
            @break
        @case('chevron-down')
            <path d="M6 10l6 6 6-6"/>
            @break
        @case('minus')
            <path d="M5 12h14"/>
            @break
        @case('bug')
            <path d="M8 9V7a4 4 0 0 1 8 0v2"/><rect x="7" y="9" width="10" height="10" rx="3"/><path d="M5 12h2M17 12h2M5 16h2M17 16h2M9 13h6"/>
            @break
        @case('sparkles')
            <path d="M12 3v4M12 17v4M4.5 12H8M16 12h3.5M7 7l2 2M15 15l2 2M17 7l-2 2M9 15l-2 2"/>
            @break
        @case('trending')
            <path d="M4 17l6-6 4 4 6-7"/><path d="M15 8h5v5"/>
            @break
        @case('zap')
            <path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>
            @break
        @case('layout')
            <rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 10h16M10 10v10"/>
            @break
        @case('save')
            <path d="M5 5h11l3 3v11H5z"/><path d="M8 5v5h8V5"/><path d="M8 19v-5h8v5"/>
            @break
        @case('logout')
            <path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 12H8"/><path d="M16 8l5 4-5 4"/>
            @break
        @case('sun')
            <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
            @break
        @case('moon')
            <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/>
            @break
        @case('bell')
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            @break
        @case('mic')
            <rect x="9" y="2" width="6" height="11" rx="3"/>
            <path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/>
            @break
        @case('stop')
            <rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor" stroke="none"/>
            @break
        @case('printer')
            <path d="M6 9V4h12v5"/><rect x="6" y="14" width="12" height="6" rx="1"/><path d="M6 14H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/>
            @break
        @case('pos')
            <rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/><circle cx="16" cy="15" r="1.2" fill="currentColor" stroke="none"/>
            @break
        @case('globe')
            <circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>
            @break
        @case('smartphone')
            <rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>
            @break
        @case('tasks')
            <path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>
            @break
        @case('pause')
            <rect x="6" y="5" width="4" height="14" rx="1" fill="currentColor" stroke="none"/><rect x="14" y="5" width="4" height="14" rx="1" fill="currentColor" stroke="none"/>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"/>
            @break
        @case('sidebar')
            <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>
            @break
        @case('chart')
            <path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v-4"/><path d="M12 15V8"/><path d="M16 15v-7"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @default
            <circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/>
            <circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>
            <circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/>
    @endswitch
</svg>
