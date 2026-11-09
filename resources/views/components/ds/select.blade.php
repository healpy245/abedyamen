@props([
    'name',
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'label' => null,
    'auto' => false,
    'wrap' => null,
])
{{-- Native select, upgraded by ds.js into the styled dropdown. It still submits and fires "change" as usual. --}}
<select name="{{ $name }}"
        data-ds-select
        @if($label) aria-label="{{ $label }}" @endif
        @if($auto) data-fin-autosubmit @endif
        @if($wrap) data-ds-wrap="{{ $wrap }}" @endif
        {{ $attributes->class(['is-invalid' => $errors->has($name)]) }}>
    @if($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach($options as $key => $text)
        <option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ $text }}</option>
    @endforeach
</select>
