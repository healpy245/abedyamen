@php
    $tone = $status->tone();
@endphp
<span class="kaman-badge {{ $tone['bg'] }} {{ $tone['text'] }} {{ $tone['border'] }}">
    @include('app-development.partials.icon', ['name' => $status->icon()])
    {{ $status->label() }}
</span>
