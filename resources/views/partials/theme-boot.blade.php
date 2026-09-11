{{-- Apply stored theme before CSS paints. Also skip the boot splash on repeat visits in this tab. --}}
<script>
(function () {
    var root = document.documentElement;
    try {
        var theme = localStorage.getItem('kaman-theme');
        if (theme !== 'dark' && theme !== 'light') {
            theme = 'light';
        }
        root.classList.toggle('dark', theme === 'dark');
        root.setAttribute('data-theme', theme);
    } catch (e) {}
    try {
        if (sessionStorage.getItem('kaman-boot') === '1') {
            root.classList.remove('kaman-booting');
        }
    } catch (e) {}
})();
</script>
<style>html.dark { background: #14110e; }</style>
