@php
    $selected = collect(old('app_types', $selected ?? []))->map(fn ($v) => (string) $v)->all();
    $name = $name ?? 'app_types[]';
@endphp
<div class="kaman-app-type-picker" data-app-type-picker>
    @foreach(\App\Enums\AppDevelopmentAppType::cases() as $type)
        <label class="kaman-app-type-chip {{ in_array($type->value, $selected, true) ? 'is-selected' : '' }}">
            <input type="checkbox"
                   name="{{ $name }}"
                   value="{{ $type->value }}"
                   @checked(in_array($type->value, $selected, true))>
            @include('app-development.partials.icon', ['name' => $type->icon()])
            <span>{{ $type->label() }}</span>
        </label>
    @endforeach
</div>
<script>
(function () {
    document.querySelectorAll('[data-app-type-picker]').forEach(function (root) {
        root.addEventListener('change', function (event) {
            const input = event.target;
            if (!(input instanceof HTMLInputElement)) return;
            const label = input.closest('.kaman-app-type-chip');
            if (label) label.classList.toggle('is-selected', input.checked);
        });
    });
})();
</script>
