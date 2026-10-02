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
