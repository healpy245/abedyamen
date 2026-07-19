@php
    $currentLocale = app()->getLocale();
    $locales = [
        'ar' => __('form.arabic'),
        'en' => __('form.english'),
        'he' => __('form.hebrew'),
    ];
@endphp

<div class="flex items-center gap-1 rounded-full border border-[#f1dfc5] bg-white/80 p-0.5 text-[11px] font-semibold uppercase tracking-wide">
    @foreach($locales as $code => $label)
        <a href="{{ route('lang.switch', $code) }}"
           class="rounded-full px-2.5 py-1 transition {{ $currentLocale === $code
               ? 'bg-[#f47a2e] text-white shadow-sm'
               : 'text-[#a78a6c] hover:text-[#f16229] hover:bg-[#f47a2e]/8' }}"
           @if($currentLocale === $code) aria-current="true" @endif>
            {{ $code === 'he' ? 'עב' : ($code === 'ar' ? 'ع' : 'EN') }}
        </a>
    @endforeach
</div>
