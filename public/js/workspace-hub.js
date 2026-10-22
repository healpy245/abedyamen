(function () {
    var scene = document.getElementById('hub-scene');
    if (!scene) return;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    scene.querySelectorAll('.cycle-door').forEach(function (door) {
        var key = door.getAttribute('data-door');
        door.addEventListener('pointerenter', function () { scene.classList.add('is-' + key); });
        door.addEventListener('pointerleave', function () { scene.classList.remove('is-' + key); });
        door.addEventListener('focus', function () { scene.classList.add('is-' + key); });
        door.addEventListener('blur', function () { scene.classList.remove('is-' + key); });
        door.addEventListener('click', function (event) {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
            if (reduce) return;
            event.preventDefault();
            door.classList.add('is-opening');
            window.setTimeout(function () { window.location.assign(door.href); }, 180);
        });
    });
})();
