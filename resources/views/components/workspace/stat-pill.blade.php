@props([
    'label',
    'tone' => 'orange',
])

@php
    $tones = [
        'orange' => 'bg-orange-50 text-orange-700 border-orange-200',
        'amber' => 'bg-amber-50 text-amber-800 border-amber-200',
        'slate' => 'bg-slate-50 text-slate-700 border-slate-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    ];
    $classes = $tones[$tone] ?? $tones['orange'];
@endphp

<span {{ $attributes->merge(['class' => "kaman-stat-pill {$classes}"]) }}>
    {{ $label }}
</span>
