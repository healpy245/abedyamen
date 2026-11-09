@props(['title', 'icon' => 'search'])
<div {{ $attributes->class(['ds-empty']) }}>
    <span class="ds-empty__icon"><x-ds.icon :name="$icon" /></span>
    <strong>{{ $title }}</strong>
    @if(trim($slot) !== '')
        <p>{{ $slot }}</p>
    @endif
</div>
