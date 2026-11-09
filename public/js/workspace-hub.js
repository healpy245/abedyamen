(function () {
    var scene = document.getElementById('hub-scene');
    var portal = scene && scene.querySelector('[data-portal]');
    if (!portal) return;

    var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var doors = Array.prototype.slice.call(portal.querySelectorAll('.portal-door'));
    var descEl = scene.querySelector('[data-portal-desc]');
    var rtl = document.documentElement.dir === 'rtl';
    var active = null;
    var closeTimer = 0;
    var lastPointer = 'mouse';
    var leaving = false;

    function open(door) {
        window.clearTimeout(closeTimer);
        if (active === door || leaving) return;
        if (active) active.classList.remove('is-open');
        active = door;
        door.classList.add('is-open');
        portal.setAttribute('data-active', door.getAttribute('data-door'));
        scene.classList.add('has-active');
        descEl.textContent = door.getAttribute('data-desc');
    }

    function close(door, delay) {
        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(function () {
            if (leaving || (door && active !== door)) return;
            if (active) active.classList.remove('is-open');
            active = null;
            portal.removeAttribute('data-active');
            scene.classList.remove('has-active');
        }, delay || 0);
    }

    function enter(door) {
        if (leaving) return;
        leaving = true;
        var href = door.href;
        if (motion.matches) {
            window.location.assign(href);
            return;
        }
        open(door);
        portal.style.setProperty('--zx', door.style.getPropertyValue('--zx'));
        portal.style.setProperty('--zy', door.style.getPropertyValue('--zy'));
        // Let the door finish opening (if it was a direct tap) before zooming into the passage.
        window.setTimeout(function () {
            scene.classList.add('is-leaving');
            portal.classList.add('is-entering');
            window.setTimeout(function () { window.location.assign(href); }, 540);
        }, door.classList.contains('was-open') ? 0 : 300);
    }

    doors.forEach(function (door, index) {
        var area = door.querySelector('.portal-hit__area');

        area.addEventListener('pointerenter', function (event) {
            lastPointer = event.pointerType;
            if (event.pointerType === 'mouse' || event.pointerType === 'pen') open(door);
        });
        area.addEventListener('pointerleave', function (event) {
            if (event.pointerType === 'mouse' || event.pointerType === 'pen') close(door, 80);
        });
        area.addEventListener('pointerdown', function (event) {
            lastPointer = event.pointerType;
            door.classList.toggle('was-open', active === door);
        });

        door.addEventListener('focus', function () { open(door); });
        door.addEventListener('blur', function () { close(door, 40); });

        door.addEventListener('click', function (event) {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            // Touch: the first tap previews the open door, the second one walks through it.
            if (lastPointer === 'touch' && !door.classList.contains('was-open')) {
                open(door);
                return;
            }
            door.classList.add('was-open');
            enter(door);
        });

        door.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                lastPointer = 'keyboard';
                door.classList.add('was-open');
                return;
            }
            var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
            if (!step) return;
            if (rtl && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) step = -step;
            event.preventDefault();
            lastPointer = 'keyboard';
            doors[(index + step + doors.length) % doors.length].focus();
        });
    });

    // A tap anywhere else closes a previewed door.
    document.addEventListener('pointerdown', function (event) {
        if (event.pointerType !== 'mouse' && active && !event.target.closest('.portal-hit__area')) close(null, 0);
    });

    // Faint warm light that trails the cursor across the circle.
    var frame = 0;
    var point = null;
    portal.addEventListener('pointermove', function (event) {
        if (event.pointerType !== 'mouse' || motion.matches) return;
        point = event;
        if (frame) return;
        frame = window.requestAnimationFrame(function () {
            frame = 0;
            var box = portal.getBoundingClientRect();
            portal.style.setProperty('--gx', (point.clientX - box.left) + 'px');
            portal.style.setProperty('--gy', (point.clientY - box.top) + 'px');
            portal.classList.add('is-lit');
        });
    });
    portal.addEventListener('pointerleave', function () { portal.classList.remove('is-lit'); });

    // Coming back with the browser's back button restores the page from cache mid-zoom.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        leaving = false;
        scene.classList.remove('is-leaving');
        portal.classList.remove('is-entering');
        doors.forEach(function (door) { door.classList.remove('was-open'); });
        close(null, 0);
    });
})();
