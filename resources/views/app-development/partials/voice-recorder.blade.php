@php
    $inputName = $inputName ?? 'voices[]';
@endphp
<div class="app-dev-voice app-dev-voice--compact" data-voice-recorder data-voice-input="{{ $inputName }}">
    <button type="button"
            class="app-dev-voice__mic"
            data-voice-toggle
            title="{{ __('app-development.tickets.record_voice') }}"
            aria-label="{{ __('app-development.tickets.record_voice') }}"
            aria-pressed="false">
        <span class="app-dev-voice__mic-icon" data-voice-icon>
            @include('app-development.partials.icon', ['name' => 'mic'])
        </span>
        <span class="app-dev-voice__timer" data-voice-timer hidden>0:00</span>
    </button>
    <p class="sr-only" data-voice-status></p>
    <div class="app-dev-voice__list" data-voice-list></div>
</div>
