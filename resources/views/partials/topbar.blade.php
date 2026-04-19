@php
    $logoPath = $logoPath ?? asset('kaman.png');
    $logoAlt = $logoAlt ?? 'Kaman';
    $showTagBadge = $showTagBadge ?? true;
    $tagText = $tagText ?? 'Partner Portal';
    $poweredByLabel = $poweredByLabel ?? 'powered by';
    $poweredByLogo = $poweredByLogo ?? asset('mfit.png');
    $poweredByAlt = $poweredByAlt ?? 'MFIT';
@endphp

<header class="bg-white/90 backdrop-blur shadow-sm">
    <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <img src="{{ $logoPath }}" alt="{{ $logoAlt }}" class="h-12 w-auto object-contain">
            @if($showTagBadge && $tagText)
                <div class="hidden sm:inline-flex items-center h-8 border-l border-[#edd9be]/60 pl-4">
                    <p class="text-sm text-[#a78a6c] font-semibold uppercase tracking-widest">{{ $tagText }}</p>
                </div>
            @endif
        </div>
        <div class="flex items-center gap-4">
            @if($poweredByLabel)
                <p class="text-xs text-[#a78a6c] uppercase tracking-[0.2em]">{{ $poweredByLabel }}</p>
            @endif
            @if($poweredByLogo)
                <img src="{{ $poweredByLogo }}" alt="{{ $poweredByAlt }}" class="h-9 w-auto object-contain">
            @endif
        </div>
    </div>
</header>

