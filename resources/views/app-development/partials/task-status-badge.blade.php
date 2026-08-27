@php
    /** @var \App\Enums\AppDevelopmentTaskStatus $status */
    $tone = $status->tone();
@endphp
<span class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">
    {{ $status->label() }}
</span>
