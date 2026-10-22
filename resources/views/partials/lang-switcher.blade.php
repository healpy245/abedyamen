@php
    if (auth()->user()?->isMowahada()) {
        return;
    }
    $currentLocale = app()->getLocale();
    $locales = [
        'ar' => __('form.arabic'),
        'en' => __('form.english'),
        'he' => __('form.hebrew'),
    ];
@endphp

<details class="ws-lang">
    <summary aria-label="{{ $locales[$currentLocale] ?? 'Language' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/>
            <path d="M3 12h18M12 3c2.5 2.8 3.8 5.8 3.8 9s-1.3 6.2-3.8 9c-2.5-2.8-3.8-5.8-3.8-9S9.5 5.8 12 3z"/>
        </svg>
    </summary>
    <div class="ws-lang__menu">
        @foreach($locales as $code => $label)
            <a href="{{ route('lang.switch', $code) }}" @if($currentLocale === $code) aria-current="true" @endif>
                {{ $label }}
            </a>
        @endforeach
    </div>
</details>
