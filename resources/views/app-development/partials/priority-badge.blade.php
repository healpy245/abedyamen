@php
    $tone = $priority->tone();
@endphp
<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">
    {{ $priority->label() }}
</span>
