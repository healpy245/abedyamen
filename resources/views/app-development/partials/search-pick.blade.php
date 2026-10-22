@php
    $kind = $kind ?? 'person';
    $choices = $choices ?? [];
    $logos = $logos ?? [];
    if ($kind === 'restaurant' && $choices === []) {
        $raw = app(\App\Services\KamanSupport\SupportDeviceSessionService::class)->restaurantChoices();
        $choices = array_map(static fn (array $choice): array => [
            'value' => $choice['label'],
            'slug' => $choice['value'],
            'label' => $choice['label'],
        ], $raw);
        $logos = app(\App\Services\KamanSupport\RestaurantLogoResolver::class)->cached(array_column($raw, 'value'));
    }
    $current = (string) old($name, $value ?? '');
    $shown = (string) ($display ?? '');
    if ($shown === '' && $current !== '') {
        foreach ($choices as $choice) {
            if ((string) ($choice['value'] ?? '') === $current) {
                $shown = (string) ($choice['label'] ?? '');
                break;
            }
        }
        if ($shown === '' && $kind === 'restaurant') {
            $shown = $current;
        }
    }
    $empty = $empty ?? ($kind === 'restaurant'
        ? __('app-development.tickets.restaurant_empty')
        : __('app-development.tasks.no_people'));
@endphp
<div class="kpick"
     data-kpick
     data-kpick-kind="{{ $kind }}"
     @if($required ?? false) data-kpick-required @endif
     data-kpick-empty="{{ $empty }}">
    <span class="kpick__label">{{ $label }}@if($required ?? false) *@endif</span>
    <input type="hidden" name="{{ $name }}" value="{{ $current }}" data-kpick-value>
    <input type="text"
           class="kaman-input kpick__input"
           value="{{ $shown }}"
           placeholder="{{ $placeholder ?? '' }}"
           autocomplete="off"
           data-kpick-query>
    <div class="kpick__list" data-kpick-list hidden></div>
    <script type="application/json" data-kpick-choices>@json(array_values($choices))</script>
    @if($kind === 'restaurant')
        <script type="application/json" data-kpick-logos>@json($logos)</script>
        <span hidden data-kpick-logos-url="{{ route('app-development.restaurants.logos') }}"></span>
    @endif
</div>
