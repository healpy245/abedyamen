@php
    $currentLocale = app()->getLocale();
    $locales = [
        'ar' => __('form.arabic'),
        'en' => __('form.english'),
        'he' => __('form.hebrew'),
    ];
@endphp

<div class="kaman-lang-switcher">
    @foreach($locales as $code => $label)
        <a href="{{ route('lang.switch', $code) }}"
           class="kaman-lang-link rounded-[7px] px-2.5 py-1 {{ $currentLocale === $code
               ? 'is-active bg-[#f47a2e] text-white shadow-sm'
               : 'text-[#a78a6c] hover:text-[#f16229] hover:bg-[#f47a2e]/8' }}"
           @if($currentLocale === $code) aria-current="true" @endif>
            {{ $code === 'he' ? 'עב' : ($code === 'ar' ? 'ع' : 'EN') }}
        </a>
    @endforeach
</div>
