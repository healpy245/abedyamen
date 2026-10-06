(function () {
    var root = document.querySelector('.feat');
    if (!root) return;

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var reveals = root.querySelectorAll('.feat-reveal');

    function show(node) {
        node.classList.add('is-in');
    }

    if (reduce || !('IntersectionObserver' in window)) {
        reveals.forEach(show);
    } else {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                show(entry.target);
                observer.unobserve(entry.target);
            });
        }, { threshold: 0, rootMargin: '0px' });

        reveals.forEach(function (node) {
            observer.observe(node);
        });
    }

    var links = root.querySelectorAll('[data-feat-link]');
    var chapters = root.querySelectorAll('[data-feat-chapter]');
    if (!links.length || !chapters.length || !('IntersectionObserver' in window)) return;

    var current = '';
    function setCurrent(id) {
        if (!id || id === current) return;
        current = id;
        links.forEach(function (link) {
            if (link.getAttribute('data-feat-link') === id) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function fromHash() {
        var id = (location.hash || '').replace('#product-', '');
        if (links.length && Array.prototype.some.call(links, function (link) {
            return link.getAttribute('data-feat-link') === id;
        })) {
            setCurrent(id);
        }
    }

    window.addEventListener('hashchange', fromHash);
    fromHash();

    var spy = new IntersectionObserver(function (entries) {
        var visible = entries
            .filter(function (entry) { return entry.isIntersecting; })
            .sort(function (a, b) { return b.intersectionRatio - a.intersectionRatio; });
        if (!visible.length) return;
        setCurrent(visible[0].target.getAttribute('data-feat-chapter'));
    }, { threshold: [0, 0.08, 0.2], rootMargin: '-18% 0px -55% 0px' });

    chapters.forEach(function (chapter) {
        spy.observe(chapter);
    });
})();
