<div class="kaman-boot" role="status" aria-live="polite" aria-label="{{ __('app.loading') }}">
    @include('partials.kaman-ai-loader', ['size' => 'lg'])
    <p class="kaman-boot__caption">{{ __('app.loading') }}</p>
</div>
@once('kaman-boot-script')
<script>
(function () {
    var root = document.documentElement;
    if (!root.classList.contains('kaman-booting')) {
        return;
    }
    var started = Date.now();
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var minMs = reduced ? 0 : 850;
    var finish = function () {
        var wait = Math.max(0, minMs - (Date.now() - started));
        window.setTimeout(function () {
            root.classList.remove('kaman-booting');
            try { sessionStorage.setItem('kaman-boot', '1'); } catch (e) {}
        }, wait);
    };
    if (document.readyState === 'complete') {
        finish();
    } else {
        window.addEventListener('load', finish);
    }
})();
</script>
@endonce
