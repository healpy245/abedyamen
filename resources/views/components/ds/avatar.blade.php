@props(['src' => null, 'name' => '', 'size' => null])
@php
    $initial = mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1));
@endphp
<span {{ $attributes->class(['ds-avatar', 'ds-avatar--'.$size => $size, 'is-initial' => ! $src]) }} aria-hidden="true">
    @if($src)
        <img src="{{ $src }}" alt="" decoding="async">
    @else
        {{ $initial }}
    @endif
</span>
