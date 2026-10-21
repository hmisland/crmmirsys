// Theme shell behaviour — presentation/interaction only, per Phase 0 §27
// (no business logic lives in the theme layer). Every feature here is a
// progressive enhancement: with JS disabled, links/forms still work and
// the sidebar is simply always expanded.
(function () {
    'use strict';

    var root = document.documentElement;
    var shell = document.getElementById('app-shell');
    var sidebar = document.getElementById('app-sidebar');
    var navToggle = document.getElementById('nav-toggle');
    var collapseToggle = document.getElementById('sidebar-collapse');

    // ---- Mobile/tablet drawer -------------------------------------
    function openNav() {
        if (!shell) return;
        shell.classList.add('nav-open');
        document.body.classList.add('nav-locked');
        if (navToggle) navToggle.setAttribute('aria-expanded', 'true');
        var first = sidebar && sidebar.querySelector('a, button');
        if (first) first.focus();
    }

    function closeNav(returnFocus) {
        if (!shell || !shell.classList.contains('nav-open')) return;
        shell.classList.remove('nav-open');
        document.body.classList.remove('nav-locked');
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) navToggle.focus();
        }
    }

    if (navToggle) {
        navToggle.addEventListener('click', function () {
            if (shell.classList.contains('nav-open')) {
                closeNav(true);
            } else {
                openNav();
            }
        });
    }

    document.querySelectorAll('[data-nav-close]').forEach(function (el) {
        el.addEventListener('click', function () { closeNav(true); });
    });

    // Leaving the drawer breakpoint while it's open must not leave the
    // page scroll-locked.
    var drawerQuery = window.matchMedia('(max-width: 1023.98px)');
    var onBreakpoint = function (e) { if (!e.matches) closeNav(false); };
    if (drawerQuery.addEventListener) {
        drawerQuery.addEventListener('change', onBreakpoint);
    } else if (drawerQuery.addListener) {
        drawerQuery.addListener(onBreakpoint);
    }

    // ---- Desktop collapse (remembered per browser) -------------------
    function syncCollapseState() {
        if (!collapseToggle) return;
        collapseToggle.setAttribute('aria-expanded', root.classList.contains('sidebar-collapsed') ? 'false' : 'true');
    }

    if (collapseToggle) {
        syncCollapseState();
        collapseToggle.addEventListener('click', function () {
            var collapsed = root.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('crm_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) { /* storage unavailable: state just isn't remembered */ }
            syncCollapseState();
        });
    }

    // ---- Dropdowns (<details class="dropdown">) -------------------------
    function closeDropdowns(except) {
        document.querySelectorAll('details.dropdown[open]').forEach(function (d) {
            if (d !== except) d.removeAttribute('open');
        });
    }

    document.addEventListener('click', function (e) {
        var inside = e.target.closest ? e.target.closest('details.dropdown') : null;
        closeDropdowns(inside);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('details.dropdown[open]');
        if (open) {
            open.removeAttribute('open');
            var summary = open.querySelector('summary');
            if (summary) summary.focus();
            return;
        }
        closeNav(true);
    });

    // ---- Dismissible alerts -------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-dismiss="alert"]') : null;
        if (!btn) return;
        var alert = btn.closest('.alert');
        if (alert) alert.remove();
    });

    // ---- Copy to clipboard: <button data-copy="text" data-copied-label="Copied"> ----
    // Shows the "copied" label on the button for two seconds.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
        if (!btn) return;
        var text = btn.getAttribute('data-copy') || '';
        var done = function () {
            var label = btn.querySelector('span');
            var copied = btn.getAttribute('data-copied-label');
            if (!label || !copied) return;
            var original = label.textContent;
            label.textContent = copied;
            setTimeout(function () { label.textContent = original; }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () {});
            return;
        }
        var field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.className = 'visually-hidden';
        document.body.appendChild(field);
        field.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* nothing to do */ }
        field.remove();
    });

    // ---- Auto-open notice dialogs (e.g. "no permission" after a 403) ----
    // Rendered with `open` so the message is visible even without JS;
    // with JS it is re-opened as a real modal (focus trap, Esc, backdrop).
    document.querySelectorAll('dialog[data-autoopen]').forEach(function (d) {
        if (typeof d.showModal !== 'function') return;
        d.removeAttribute('open');
        d.showModal();
    });

    // ---- Open a dialog from a link: <a href="…" data-open-dialog="<id>"> ----
    // The href is the no-JS path (a page that renders the dialog open).
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-open-dialog]') : null;
        if (!trigger) return;
        var d = document.getElementById(trigger.getAttribute('data-open-dialog'));
        if (!d || typeof d.showModal !== 'function' || d.open) return;
        e.preventDefault();
        d.showModal();
    });

    // ---- Task checklist: add / tick an item without reloading the page ----
    // The forms are ordinary posts (they work without JS); here they are
    // sent with fetch() and the server answers with the item's HTML and the
    // new progress. Any failure without a message falls back to a reload.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var box = form.closest ? form.closest('[data-checklist]') : null;
        if (!box || !window.fetch || !window.FormData) return;
        var adding = form.hasAttribute('data-checklist-add');
        if (!adding && !form.hasAttribute('data-checklist-toggle')) return;
        e.preventDefault();
        var error = box.querySelector('[data-checklist-error]');

        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (json) { return { ok: r.ok, json: json }; }); })
            .then(function (res) {
                if (!res.ok || !res.json.data) {
                    var message = res.json && res.json.errors && res.json.errors[0] ? res.json.errors[0].title : '';
                    if (error && message) {
                        error.textContent = message;
                        error.hidden = false;
                    } else {
                        window.location.reload();
                    }
                    return;
                }
                if (error) error.hidden = true;
                var data = res.json.data;
                var holder = document.createElement('div');
                holder.innerHTML = data.html.trim();
                if (adding) {
                    box.querySelector('[data-checklist-list]').appendChild(holder.firstElementChild);
                    form.reset();
                    form.querySelector('input[name="title"]').focus();
                } else {
                    form.closest('[data-checklist-item]').replaceWith(holder.firstElementChild);
                }
                var total = data.progress.total, done = data.progress.done;
                var bar = box.querySelector('[data-checklist-progress]');
                var count = box.querySelector('[data-checklist-count]');
                var empty = box.querySelector('[data-checklist-empty]');
                if (bar) {
                    bar.max = Math.max(1, total);
                    bar.value = done;
                    bar.hidden = total === 0;
                    bar.classList.toggle('progress--done', total > 0 && done === total);
                }
                if (count) {
                    count.textContent = data.progress_label;
                    count.hidden = total === 0;
                }
                if (empty) empty.hidden = total > 0;
            })
            .catch(function () { form.submit(); });
    });

    // ---- Number / money inputs ------------------------------------------
    // Amount-like <input type="number"> (decimal step, or an amount-ish
    // name) show thousand separators, accept max 3 decimals and
    // Persian/Arabic digits; the form still posts the plain number. A "0"
    // is cleared on focus and restored if left empty. Opt out with
    // data-no-format.
    var MONEY_NAME = /(price|amount|cost|value|discount|total|rate|fee|topup|withdrawal|balance)/i;
    var MAX_DECIMALS = 3;

    function toLatinDigits(s) {
        return String(s).replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 1776); })
            .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); })
            .replace(/[٫]/g, '.').replace(/[٬،]/g, ',');
    }

    function plainNumber(s) {
        var v = toLatinDigits(s).replace(/,/g, '').replace(/[^\d.\-]/g, '');
        var neg = v.charAt(0) === '-';
        v = v.replace(/-/g, '');
        var dot = v.indexOf('.');
        if (dot !== -1) v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '').slice(0, MAX_DECIMALS);
        return (neg ? '-' : '') + v;
    }

    function groupThousands(plain) {
        if (plain === '' || plain === '-') return plain;
        var neg = plain.charAt(0) === '-';
        var parts = plain.replace('-', '').split('.');
        var intPart = (parts[0] || '0').replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (neg ? '-' : '') + intPart + (parts.length > 1 ? '.' + parts[1] : '');
    }

    // Stored decimals like "1500.000000000000000000" → "1500" (max 3 decimals, trailing zeros dropped).
    function tidyStored(raw) {
        var p = plainNumber(raw);
        if (p === '' || p === '-') return p;
        var n = Number(p);
        if (!isFinite(n)) return p;
        return String(Math.round(n * 1000) / 1000);
    }

    var nativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    // The field itself stays visible (id, classes, listeners untouched) and
    // shows "1,500.25"; its name moves to a hidden twin holding "1500.25".
    // Its `value` property is overridden, so scripts that read or set
    // input.value keep working with plain numbers.
    function enhanceNumber(input) {
        if (input.dataset.numberEnhanced || input.hasAttribute('data-no-format')) return;
        var step = input.getAttribute('step') || '';
        if (step.indexOf('.') === -1 && !MONEY_NAME.test(input.name || '')) return;
        input.dataset.numberEnhanced = '1';

        var min = input.getAttribute('min');
        var max = input.getAttribute('max');
        var initial = tidyStored(nativeValue.get.call(input));

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.name;
        input.removeAttribute('name');
        input.parentNode.insertBefore(hidden, input.nextSibling);

        input.type = 'text';
        input.inputMode = 'decimal';
        input.dir = 'ltr';
        input.autocomplete = 'off';
        ['min', 'max', 'step'].forEach(function (a) { input.removeAttribute(a); });

        function show(plain) {
            nativeValue.set.call(input, groupThousands(plain));
        }

        Object.defineProperty(input, 'value', {
            configurable: true,
            get: function () { return hidden.value; },
            set: function (v) { hidden.value = tidyStored(v); show(hidden.value); }
        });
        input.value = initial;

        var wasZero = false;
        input.addEventListener('focus', function () {
            wasZero = hidden.value !== '' && Number(hidden.value) === 0;
            if (wasZero) { nativeValue.set.call(input, ''); }
        });
        input.addEventListener('input', function () {
            var typed = nativeValue.get.call(input);
            var caretFromEnd = typed.length - (input.selectionEnd || 0);
            var plain = plainNumber(typed);
            hidden.value = plain === '-' ? '' : plain;
            show(plain);
            try {
                var pos = Math.max(0, nativeValue.get.call(input).length - caretFromEnd);
                input.setSelectionRange(pos, pos);
            } catch (err) {}
            input.setCustomValidity('');
        });
        input.addEventListener('blur', function () {
            if (nativeValue.get.call(input) === '' && wasZero) { input.value = '0'; }
            var n = Number(hidden.value);
            var msg = '';
            if (hidden.value !== '' && min !== null && n < Number(min)) msg = '≥ ' + groupThousands(String(min));
            if (hidden.value !== '' && max !== null && n > Number(max)) msg = '≤ ' + groupThousands(String(max));
            input.setCustomValidity(msg);
        });
    }

    window.crmNumber = {
        enhanceWithin: function (root) {
            (root || document).querySelectorAll('input[type="number"]').forEach(enhanceNumber);
        }
    };
    window.crmNumber.enhanceWithin(document);
    // Rows added later (invoice/proforma items, repeaters) get the same treatment.
    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('input[type="number"]')) enhanceNumber(node);
                        else if (node.querySelectorAll) window.crmNumber.enhanceWithin(node);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    // ---- Proforma / invoice line items ---------------------------------
    // [data-line-items]: add/remove rows from <template data-li-template>;
    // code and name fields (data-product-lookup="sku"|"name") search
    // products as you type and, on pick, fill code, name, description,
    // unit price and the hidden product_id. Typing over a picked product
    // detaches it (product_id cleared) so a free line can still be written.
    document.querySelectorAll('[data-line-items]').forEach(function (box) {
        var rows = box.querySelector('[data-li-rows]');
        var template = box.querySelector('template[data-li-template]');
        var url = box.getAttribute('data-lookup-url');
        var emptyText = box.getAttribute('data-empty-text') || '';
        var counter = 1000 + rows.children.length;

        box.querySelector('[data-li-add]').addEventListener('click', function () {
            var holder = document.createElement('div');
            holder.innerHTML = template.innerHTML.split('__INDEX__').join(String(counter++)).trim();
            var row = holder.firstElementChild;
            rows.appendChild(row);
            var first = row.querySelector('[data-product-lookup]');
            if (first) first.focus();
        });

        box.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-li-remove]') : null;
            if (!btn) return;
            if (rows.querySelectorAll('[data-line-item]').length > 1) {
                btn.closest('[data-line-item]').remove();
            } else {
                btn.closest('[data-line-item]').querySelectorAll('input:not([type=hidden])').forEach(function (i) { i.value = ''; });
            }
        });

        var list = document.createElement('ul');
        list.className = 'lookup-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        document.body.appendChild(list);
        var activeInput = null;
        var results = [];
        var highlighted = -1;
        var timer = null;
        var seq = 0;

        function closeList() { list.hidden = true; highlighted = -1; }

        function place(input) {
            var r = input.getBoundingClientRect();
            var vw = document.documentElement.clientWidth;
            var width = Math.min(Math.max(r.width, 260), vw - 16);
            // Anchor to the field's start edge (right in RTL), kept on screen.
            var left = document.documentElement.dir === 'rtl' ? r.right - width : r.left;
            left = Math.max(8, Math.min(left, vw - width - 8));
            list.style.top = (window.scrollY + r.bottom + 2) + 'px';
            list.style.left = (window.scrollX + left) + 'px';
            list.style.width = width + 'px';
        }

        function render() {
            list.innerHTML = '';
            if (results.length === 0) {
                var none = document.createElement('li');
                none.className = 'lookup-list__empty';
                none.textContent = emptyText;
                list.appendChild(none);
            }
            results.forEach(function (p, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = 'lookup-list__item' + (i === highlighted ? ' is-active' : '');
                var code = document.createElement('span');
                code.className = 'lookup-list__code';
                code.textContent = p.sku || '—';
                var name = document.createElement('span');
                name.textContent = p.name;
                li.appendChild(code);
                li.appendChild(name);
                li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(i); });
                list.appendChild(li);
            });
            place(activeInput);
            list.hidden = false;
        }

        function pick(i) {
            var p = results[i];
            var row = activeInput && activeInput.closest('[data-line-item]');
            if (!p || !row) return;
            var set = function (field, value) { var el = row.querySelector('[data-li="' + field + '"]'); if (el) el.value = value; };
            set('product_id', String(p.id));
            set('item_code', p.sku || '');
            set('item_name', p.name || '');
            set('description', p.description || '');
            set('unit_price', p.price || '0');
            closeList();
            var qty = row.querySelector('[data-li="quantity"]');
            if (qty) qty.focus();
        }

        box.addEventListener('input', function (e) {
            var input = e.target;
            if (!input.matches || !input.matches('[data-product-lookup]')) return;
            activeInput = input;
            var row = input.closest('[data-line-item]');
            var pid = row.querySelector('[data-li="product_id"]');
            if (pid) pid.value = '';
            var q = input.value.trim();
            clearTimeout(timer);
            if (q === '') { closeList(); return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : { data: [] }; })
                    .then(function (json) {
                        if (mine !== seq || activeInput !== input) return;
                        results = json.data || [];
                        highlighted = results.length ? 0 : -1;
                        render();
                    })
                    .catch(function () { closeList(); });
            }, 200);
        });

        box.addEventListener('keydown', function (e) {
            if (list.hidden || !e.target.matches || !e.target.matches('[data-product-lookup]')) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!results.length) return;
                highlighted = (highlighted + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
                render();
            } else if (e.key === 'Enter') {
                if (highlighted >= 0) { e.preventDefault(); pick(highlighted); }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });

        box.addEventListener('focusout', function (e) {
            if (e.target.matches && e.target.matches('[data-product-lookup]')) setTimeout(closeList, 150);
        });
        window.addEventListener('resize', closeList);
    });

    // ---- Auto-submitting selects (e.g. rows per page) ---------------------
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.matches && el.matches('select[data-autosubmit]') && el.form) el.form.submit();
    });

    // ---- Bulk selection on list pages ---------------------------------------
    // Row checkboxes (data-bulk-item, form="bulk-form") + the header
    // "select all on this page" box (data-bulk-all) drive the counter and
    // the bulk button in form[data-bulk-form]. Disabled rows (records the
    // user may not delete) are never selected.
    document.querySelectorAll('form[data-bulk-form]').forEach(function (bulkForm) {
        var items = document.querySelectorAll('input[data-bulk-item][form="' + bulkForm.id + '"]:not(:disabled)');
        var all = document.querySelector('input[data-bulk-all]');
        var count = bulkForm.querySelector('[data-bulk-count]');
        var submit = bulkForm.querySelector('[data-bulk-submit]');

        function sync() {
            var n = 0;
            items.forEach(function (cb) { if (cb.checked) n++; });
            if (count) count.textContent = (count.getAttribute('data-template') || '{n}').replace('{n}', String(n));
            if (submit) submit.disabled = n === 0;
            if (all) {
                all.checked = n > 0 && n === items.length;
                all.indeterminate = n > 0 && n < items.length;
            }
        }

        if (all) {
            all.disabled = items.length === 0;
            all.addEventListener('change', function () {
                items.forEach(function (cb) { cb.checked = all.checked; });
                sync();
            });
        }
        items.forEach(function (cb) { cb.addEventListener('change', sync); });
        sync();
    });

    // ---- SMS settings: show the selected provider's fields ----------------
    document.querySelectorAll('form[data-sms-form]').forEach(function (form) {
        var select = form.querySelector('[data-sms-provider]');
        if (!select) return;
        select.addEventListener('change', function () {
            form.querySelectorAll('[data-sms-fields]').forEach(function (group) {
                group.hidden = group.getAttribute('data-sms-fields') !== select.value;
            });
        });
    });

    // ---- Client-side tabs ------------------------------------------------
    // [data-tabs] wraps .tab-btn[data-tab] buttons and .tab-panel[data-panel]
    // panels. The first tab holding a validation error opens first.
    document.querySelectorAll('[data-tabs]').forEach(function (group) {
        var buttons = group.querySelectorAll('.tab-btn[data-tab]');
        var panels = group.querySelectorAll('.tab-panel[data-panel]');

        function show(name) {
            buttons.forEach(function (b) {
                var on = b.dataset.tab === name;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (p) { p.hidden = p.dataset.panel !== name; });
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () { show(b.dataset.tab); });
        });

        var withError = group.querySelector('.tab-panel[data-panel] .field-error');
        if (withError) show(withError.closest('.tab-panel').dataset.panel);
    });

    // ---- CSV import review ------------------------------------------------
    // Rows are plain (unnamed) checkboxes; on submit the unchecked ones
    // travel as one comma-separated field, so a large file never runs
    // into the server's per-request field limit. Changing a column
    // mapping re-renders the preview.
    document.querySelectorAll('form[data-import-form]').forEach(function (form) {
        var toggleAll = form.querySelector('[data-row-toggle-all]');
        var rows = form.querySelectorAll('input[data-row]:not(:disabled)');
        var remapButton = form.querySelector('[data-remap-submit]');

        if (toggleAll) {
            toggleAll.addEventListener('change', function () {
                rows.forEach(function (cb) { cb.checked = toggleAll.checked; });
            });
        }

        form.querySelectorAll('select[data-remap]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (remapButton) remapButton.click();
            });
        });

        form.addEventListener('submit', function (e) {
            if (e.submitter && e.submitter.hasAttribute('data-remap-submit')) return;
            var excluded = [];
            rows.forEach(function (cb) { if (!cb.checked) excluded.push(cb.value); });
            var field = form.querySelector('[data-excluded-rows]');
            if (field) field.value = excluded.join(',');
        });
    });

    // ---- Related-records dialog ---------------------------------------------
    // button[data-related-url] loads an HTML fragment into #related-dialog
    // only when clicked, so a record page stays light however much is
    // attached to it. data-related-dialog picks another dialog (the help
    // popup); a link trigger falls back to its own href without one.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-related-url]') : null;
        if (!trigger) return;

        var url = trigger.getAttribute('data-related-url');
        var relatedDialog = document.getElementById(trigger.getAttribute('data-related-dialog') || 'related-dialog');
        if (!relatedDialog || typeof relatedDialog.showModal !== 'function') {
            if (trigger.tagName !== 'A') window.location.href = url;
            return;
        }
        e.preventDefault();

        var title = relatedDialog.querySelector('[data-related-title]');
        var body = relatedDialog.querySelector('[data-related-body]');
        if (title) title.textContent = trigger.getAttribute('data-related-title') || '';
        if (body) body.innerHTML = '<div class="spinner" role="status"></div>';
        relatedDialog.showModal();

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
            .then(function (html) { if (body) body.innerHTML = html; })
            .catch(function () {
                if (body) body.innerHTML = '<p class="muted">' + (relatedDialog.getAttribute('data-error-text') || '') + '</p>';
            });
    });

    // ---- Confirmation for destructive actions ---------------------------
    // Any submit button marked data-confirm asks first. The form itself,
    // its action and its payload are untouched — the dialog only decides
    // whether the browser's normal submit goes ahead.
    var dialog = document.getElementById('confirm-dialog');
    var dialogText = document.getElementById('confirm-dialog-text');
    var defaultText = dialogText ? dialogText.textContent : '';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var submitter = e.submitter || form.querySelector('[data-confirm]');
        if (!submitter || !submitter.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;

        var message = submitter.getAttribute('data-confirm') || defaultText;
        e.preventDefault();

        var proceed = function () {
            form.dataset.confirmed = '1';
            if (submitter.name) {
                // Preserve the clicked button's name/value in the payload.
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            HTMLFormElement.prototype.submit.call(form);
        };

        if (!dialog || typeof dialog.showModal !== 'function') {
            if (window.confirm(message)) proceed();
            return;
        }

        if (dialogText) dialogText.textContent = message;
        dialog.returnValue = '';
        dialog.addEventListener('close', function onClose() {
            dialog.removeEventListener('close', onClose);
            if (dialog.returnValue === 'confirm') {
                proceed();
            } else {
                submitter.focus();
            }
        });
        dialog.showModal();
    }, true);
})();
