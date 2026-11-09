@php
    $level = $ticket->reportLevel();
    $levelTone = match ($level) {
        'danger_now' => 'red',
        'issue' => 'amber',
        'question' => 'blue',
        default => null,
    };
@endphp
@if($level && $levelTone)
    <x-ds.badge soft :tone="$levelTone">{{ __('kaman-support.level.'.$level) }}</x-ds.badge>
@endif
