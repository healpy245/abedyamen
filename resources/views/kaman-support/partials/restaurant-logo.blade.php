@php
    $slug = (string) ($slug ?? '');
    $name = (string) ($name ?? $slug);
    $url = $slug !== '' ? ($logos[$slug] ?? null) : null;
    $letter = mb_strtoupper(mb_substr($name !== '' ? $name : $slug, 0, 1));
@endphp
@if($letter !== '')
    <span class="sup-inv__logo" @if($slug !== '') data-logo="{{ $slug }}" @endif>
        @if($url)
            <img src="{{ $url }}" alt="" loading="lazy">
        @endif
        <b aria-hidden="true">{{ $letter }}</b>
    </span>
@endif
