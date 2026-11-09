(function () {
    var root = document.querySelector('.kb-filters');
    if (!root) return;

    var pop = null;
    var active = null;

    function close() {
        if (pop) {
            pop.remove();
            pop = null;
        }
        if (active) {
            active.setAttribute('aria-expanded', 'false');
            active = null;
        }
    }

    root.querySelectorAll('select:not([data-ds-select])').forEach(function (select) {
        var wrap = document.createElement('span');
        wrap.className = 'filter-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        var arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        arrow.setAttribute('class', 'filter-chevron');
        arrow.setAttribute('viewBox', '0 0 24 24');
        arrow.setAttribute('fill', 'none');
        arrow.setAttribute('stroke', 'currentColor');
        arrow.setAttribute('stroke-width', '2');
        arrow.innerHTML = '<path d="M6 9l6 6 6-6"/>';
        wrap.appendChild(arrow);

        select.setAttribute('aria-haspopup', 'listbox');
        select.setAttribute('aria-expanded', 'false');

        select.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            if (active === select) {
                close();
                return;
            }
            open(select);
        });

        select.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
                event.preventDefault();
                open(select);
            } else if (event.key === 'Escape') {
                close();
            }
        });
    });

    function open(select) {
        close();
        active = select;
        select.setAttribute('aria-expanded', 'true');
        pop = document.createElement('div');
        pop.className = 'filter-popover';
        pop.setAttribute('role', 'listbox');
        pop.setAttribute('aria-label', select.getAttribute('aria-label') || 'Filter');

        var heading = document.createElement('div');
        heading.className = 'filter-pop-title';
        heading.textContent = select.getAttribute('aria-label') || 'Filter';
        pop.appendChild(heading);

        Array.prototype.forEach.call(select.options, function (option, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', select.selectedIndex === index ? 'true' : 'false');
            var text = document.createElement('span');
            text.textContent = option.textContent;
            var check = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            check.setAttribute('class', 'filter-check');
            check.setAttribute('viewBox', '0 0 24 24');
            check.setAttribute('fill', 'none');
            check.setAttribute('stroke', 'currentColor');
            check.setAttribute('stroke-width', '2.4');
            check.innerHTML = '<path d="M5 12l5 5L20 7"/>';
            button.append(text, check);
            button.addEventListener('click', function () {
                select.selectedIndex = index;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close();
                select.focus();
            });
            pop.appendChild(button);
        });

        document.body.appendChild(pop);
        var rect = select.getBoundingClientRect();
        var width = Math.max(205, rect.width);
        pop.style.width = width + 'px';
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        if (document.documentElement.dir === 'rtl') {
            left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
        }
        pop.style.left = left + 'px';
        var height = pop.getBoundingClientRect().height;
        pop.style.top = (rect.bottom + 8 + height > window.innerHeight && rect.top > height + 8
            ? rect.top - height - 8
            : rect.bottom + 8) + 'px';
        var selected = pop.querySelector('[aria-selected="true"]');
        if (selected) selected.focus({ preventScroll: true });
    }

    document.addEventListener('pointerdown', function (event) {
        if (pop && !pop.contains(event.target) && !event.target.closest('.filter-select')) close();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') close();
    });
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
})();
