<button type="button"
        class="kaman-theme-toggle"
        data-kaman-theme-toggle
        data-label-dark="{{ __('app.theme_dark') }}"
        data-label-light="{{ __('app.theme_light') }}"
        aria-pressed="{{ 'false' }}"
        aria-label="{{ __('app.theme_dark') }}">
    <span class="kaman-theme-toggle__moon">
        @include('app-development.partials.icon', ['name' => 'moon'])
    </span>
    <span class="kaman-theme-toggle__sun">
        @include('app-development.partials.icon', ['name' => 'sun'])
    </span>
</button>
@once('kaman-theme-toggle-script')
<script>
(function () {
    var KEY = 'kaman-theme';
    function current() {
        return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    }
    function apply(theme) {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem(KEY, theme); } catch (e) {}
        document.querySelectorAll('[data-kaman-theme-toggle]').forEach(function (btn) {
            var dark = theme === 'dark';
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? btn.dataset.labelLight : btn.dataset.labelDark);
        });
    }
    document.querySelectorAll('[data-kaman-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            apply(current() === 'dark' ? 'light' : 'dark');
        });
    });
    apply(current());
})();
</script>
@endonce
