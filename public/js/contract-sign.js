(function () {
    'use strict';

    // The client's signature pad: draw with a finger or a mouse, sent as a PNG with the form.
    var form = document.querySelector('[data-ct-form]');
    if (!form) return;
    var pad = form.querySelector('[data-ct-pad]');
    var canvas = form.querySelector('[data-ct-canvas]');
    var output = form.querySelector('[data-ct-signature]');
    var error = form.querySelector('[data-ct-pad-error]');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var drawn = false;
    var last = null;

    var size = function () {
        // Resizing clears the pad, so it is only done before anything is drawn.
        if (drawn) return;
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var box = canvas.getBoundingClientRect();
        canvas.width = Math.round(box.width * ratio);
        canvas.height = Math.round(box.height * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1b1f3b';
    };

    var point = function (event) {
        var box = canvas.getBoundingClientRect();
        return { x: event.clientX - box.left, y: event.clientY - box.top };
    };

    canvas.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        drawing = true;
        last = point(event);
        ctx.beginPath();
        ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2);
        ctx.fillStyle = ctx.strokeStyle;
        ctx.fill();
        drawn = true;
        pad.classList.remove('is-bad');
        error.hidden = true;
    });
    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) return;
        event.preventDefault();
        var next = point(event);
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(next.x, next.y);
        ctx.stroke();
        last = next;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
        canvas.addEventListener(name, function () { drawing = false; });
    });

    form.querySelector('[data-ct-clear]').addEventListener('click', function () {
        drawn = false;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        size();
    });

    form.addEventListener('submit', function (event) {
        if (!drawn) {
            event.preventDefault();
            pad.classList.add('is-bad');
            error.hidden = false;
            pad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        output.value = canvas.toDataURL('image/png');
        form.querySelector('[data-ct-submit]').classList.add('is-loading');
    });

    window.addEventListener('resize', size);
    size();
})();
