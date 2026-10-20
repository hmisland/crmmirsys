/**
 * Products → new product from the tax product ID. Live search in the
 * national list; the star moves a result into "frequently used" (saved per
 * organization), the green star moves it back; "+" opens the product form
 * prefilled. All text comes translated from data-labels.
 */
(function () {
    var root = document.querySelector('[data-tax-catalog]');
    if (!root) {
        return;
    }
    var labels = JSON.parse(root.getAttribute('data-labels') || '{}');
    var input = root.querySelector('[data-search]');
    var results = root.querySelector('[data-results]');
    var favorites = root.querySelector('[data-favorites]');
    var favEmpty = root.querySelector('[data-favorites-empty]');
    var status = root.querySelector('[data-status]');
    var starIcon = '<svg class="icon" aria-hidden="true" focusable="false"><use href="#i-star"></use></svg>';
    var plusIcon = '<svg class="icon" aria-hidden="true" focusable="false"><use href="#i-plus"></use></svg>';
    var timer = null;
    var seq = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function starButton(on) {
        var b = el('button', 'tax-star' + (on ? ' tax-star--on' : ''));
        b.type = 'button';
        b.setAttribute(on ? 'data-unstar' : 'data-star', '');
        b.title = on ? labels.star_remove : labels.star_add;
        b.setAttribute('aria-label', b.title);
        b.innerHTML = starIcon;
        return b;
    }

    function itemRow(item, favorite) {
        var li = el('li');
        li.setAttribute('data-code', item.id);
        li.appendChild(starButton(favorite));
        li.appendChild(el('span', 'tax-catalog-list__title', item.title));
        li.appendChild(el('span', 'muted ltr', item.id));
        if (item.vat !== null && item.vat !== undefined) {
            li.appendChild(el('span', 'badge', labels.vat + ' ' + item.vat + '٪'));
        }
        if (item.taxable) {
            li.appendChild(el('span', 'badge', item.taxable));
        }
        if (favorite) {
            var a = el('a', 'btn btn--sm btn--primary');
            a.href = root.getAttribute('data-create-url') + encodeURIComponent(item.id);
            a.innerHTML = plusIcon;
            a.appendChild(document.createTextNode(labels.add_product));
            li.appendChild(a);
        }
        return li;
    }

    function syncEmpty() {
        if (favEmpty) { favEmpty.hidden = favorites.children.length > 0; }
    }

    function post(url, code) {
        var body = new FormData();
        body.append('_csrf', root.getAttribute('data-csrf'));
        body.append('code', code);
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }

    function search() {
        var q = input.value.trim();
        var mine = ++seq;
        if (q.length < 2) {
            results.innerHTML = '';
            status.textContent = labels.min_chars;
            status.hidden = false;
            return;
        }
        fetch(root.getAttribute('data-search-url') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mine !== seq) { return; }
                results.innerHTML = '';
                (data.items || []).forEach(function (item) { results.appendChild(itemRow(item, false)); });
                status.textContent = labels.no_results;
                status.hidden = results.children.length > 0;
            })
            .catch(function () {
                if (mine === seq) { status.textContent = labels.error; status.hidden = false; }
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 300);
    });

    root.addEventListener('click', function (event) {
        var button = event.target.closest('[data-star], [data-unstar]');
        if (!button) {
            return;
        }
        var li = button.closest('li');
        var code = li.getAttribute('data-code');
        var adding = button.hasAttribute('data-star');
        button.disabled = true;
        post(root.getAttribute(adding ? 'data-add-url' : 'data-remove-url'), code)
            .then(function (data) {
                if (!data.ok) { throw new Error('failed'); }
                li.remove();
                if (adding) {
                    favorites.insertBefore(itemRow(data.item, true), favorites.firstChild);
                } else if (data.item) {
                    results.insertBefore(itemRow(data.item, false), results.firstChild);
                    status.hidden = true;
                }
                syncEmpty();
            })
            .catch(function () {
                button.disabled = false;
                status.textContent = labels.error;
                status.hidden = false;
            });
    });
})();
