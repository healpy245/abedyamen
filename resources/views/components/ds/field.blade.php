@props(['label', 'for' => null, 'error' => null, 'hint' => null])
<div {{ $attributes->class(['ds-field']) }}>
    <label class="ds-label" @if($for) for="{{ $for }}" @endif>{{ $label }}</label>
    {{ $slot }}
    @if($error && $errors->has($error))
        <p class="ds-field__error" role="alert">{{ $errors->first($error) }}</p>
    @elseif($hint)
        <p class="ds-field__hint">{{ $hint }}</p>
    @endif
</div>
