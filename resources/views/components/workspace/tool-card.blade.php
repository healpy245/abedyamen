@props([
    'href',
    'title',
    'description',
    'icon',
    'tone' => [],
    'status' => null,
    'detail' => null,
    'disabled' => false,
    'external' => false,
])

@php
    $tone = array_merge([
        'icon_bg' => 'bg-orange-50',
        'icon_text' => 'text-orange-600',
        'border_hover' => 'hover:border-orange-200',
        'status_bg' => 'bg-emerald-50',
        'status_text' => 'text-emerald-700',
        'status_border' => 'border-emerald-200',
    ], $tone);

    $statusLabel = $status ?? __('workspace.status_available');
    $ariaLabel = __('workspace.open_tool_named', ['tool' => $title]);
    $cardClass = "workspace-tool-card kaman-card group {$tone['border_hover']}";
    $cardClass = $disabled ? "{$cardClass} opacity-60 cursor-not-allowed" : "{$cardClass} kaman-card--link";
@endphp

@if($disabled)
    <div class="{{ $cardClass }}" aria-disabled="true">
@else
    <a href="{{ $href }}"
       title="{{ $detail ?: $description }}"
       aria-label="{{ $ariaLabel }}"
       class="{{ $cardClass }}"
       @if($external) target="_blank" rel="noopener noreferrer" @endif>
@endif
    <div class="workspace-tool-card__top">
        <span @class([
            'workspace-tool-card__icon',
            $tone['icon_bg'],
            $tone['icon_text'],
        ])>
            <x-workspace.icon :name="$icon" class="h-5 w-5" />
        </span>

        <span @class([
            'workspace-status-badge',
            $tone['status_bg'],
            $tone['status_text'],
            $tone['status_border'],
        ])>
            {{ $statusLabel }}
        </span>
    </div>

    <div class="workspace-tool-card__body">
        <h3 class="workspace-tool-card__title">{{ $title }}</h3>
        <p class="workspace-tool-card__description">{{ $description }}</p>
    </div>

    @unless($disabled)
        <span class="workspace-tool-card__cta">
            <span>{{ __('workspace.open_tool') }}</span>
            <svg class="h-4 w-4 transition group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5 rtl:rotate-180"
                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/>
            </svg>
        </span>
    @endunless
@if($disabled)
    </div>
@else
    </a>
@endif
