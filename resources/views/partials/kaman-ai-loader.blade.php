@php
    $size = $size ?? 'lg';
@endphp
<div class="kaman-ai-loader kaman-ai-loader--{{ $size }}" aria-hidden="true">
    <span class="kaman-ai-loader__ring"></span>
    <span class="kaman-ai-loader__ring kaman-ai-loader__ring--b"></span>
    <span class="kaman-ai-loader__ring kaman-ai-loader__ring--c"></span>
    <span class="kaman-ai-loader__orbit"><i></i><i></i><i></i><i></i></span>
    <span class="kaman-ai-loader__pulse"></span>
    <img src="{{ asset('kaman.png') }}" alt="" class="kaman-ai-loader__logo">
</div>
