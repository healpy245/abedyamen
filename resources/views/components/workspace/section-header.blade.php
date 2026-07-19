@props([
    'title',
    'count' => null,
    'icon' => null,
])

<h2 {{ $attributes->merge(['class' => 'workspace-section-header']) }}>
    @if($icon)
        <x-workspace.icon :name="$icon" class="h-4 w-4 shrink-0 text-[#a78a6c]" />
    @endif
    <span>{{ $title }}</span>
    @if(! is_null($count))
        <span class="workspace-section-header__count" aria-hidden="true">· {{ $count }}</span>
    @endif
</h2>
