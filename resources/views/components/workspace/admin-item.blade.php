@props([
    'href',
    'title',
    'description',
    'icon' => 'cog-6-tooth',
    'detail' => null,
    'tone' => 'slate',
])

@php
    $tones = [
        'slate' => [
            'icon_bg' => 'bg-slate-50',
            'icon_text' => 'text-slate-600',
            'border_hover' => 'hover:border-slate-200',
        ],
        'amber' => [
            'icon_bg' => 'bg-amber-50',
            'icon_text' => 'text-amber-700',
            'border_hover' => 'hover:border-amber-200',
        ],
    ];
    $palette = $tones[$tone] ?? $tones['slate'];
@endphp

<a href="{{ $href }}"
   title="{{ $detail ?: $description }}"
   aria-label="{{ __('workspace.open_tool_named', ['tool' => $title]) }}"
   class="workspace-admin-item kaman-card kaman-card--link group {{ $palette['border_hover'] }}">
    <span @class([
        'workspace-admin-item__icon',
        $palette['icon_bg'],
        $palette['icon_text'],
    ])>
        <x-workspace.icon :name="$icon" class="h-4 w-4" />
    </span>

    <span class="workspace-admin-item__content">
        <span class="workspace-admin-item__title">{{ $title }}</span>
        <span class="workspace-admin-item__description">{{ $description }}</span>
    </span>

    <svg class="workspace-admin-item__arrow rtl:rotate-180"
         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/>
    </svg>
</a>
