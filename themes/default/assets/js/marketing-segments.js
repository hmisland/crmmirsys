/**
 * Dynamic segment rule builder. Reads its definition from
 * <script type="application/json" id="segment-builder-data">
 * ({fields, rules, i18n} — every label already translated by the server)
 * and writes the rules as JSON into the form's hidden
 * [data-segment-rules] input on submit. The server validates everything
 * again against its own allowlist; this script only builds the JSON.
 *
 * Rules: {match: all|any, groups: [{match: all|any, conditions: [{field, op, value[, scope]}]}]}
 * (scope: only for fields that list "scopes", e.g. "all shops" / one shop)
 * No user-visible text lives here.
 */
(function () {
    var dataEl = document.getElementById('segment-builder-data');
    var root = document.querySelector('[data-segment-builder]');
    var form = document.querySelector('[data-segment-form]');
    if (!dataEl || !root || !form) {
        return;
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }
    var fields = data.fields || [];
    var t = data.i18n || {};
    var byKey = {};
    fields.forEach(function (f) { byKey[f.key] = f; });
    var calendar = document.documentElement.lang === 'fa' ? 'jalali' : 'gregorian';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
        return node;
    }

    function matchSelect(value, allLabel, anyLabel, cls) {
        var s = el('select', { 'class': cls, 'aria-label': allLabel + ' / ' + anyLabel });
        s.appendChild(el('option', { value: 'all', text: allLabel }));
        s.appendChild(el('option', { value: 'any', text: anyLabel }));
        s.value = value === 'any' ? 'any' : 'all';
        return s;
    }

    function fieldSelect(value) {
        var s = el('select', { 'class': 'seg-field', 'aria-label': t.field || '' });
        var groups = {};
        fields.forEach(function (f) {
            if (!groups[f.category]) {
                groups[f.category] = el('optgroup', { label: f.category });
                s.appendChild(groups[f.category]);
            }
            groups[f.category].appendChild(el('option', { value: f.key, text: f.label }));
        });
        if (value && byKey[value]) { s.value = value; }
        return s;
    }

    function opSelect(field, value) {
        var s = el('select', { 'class': 'seg-op', 'aria-label': t.operator || '' });
        (field ? field.ops : []).forEach(function (op) { s.appendChild(el('option', { value: op.key, text: op.label })); });
        if (value) { s.value = value; }
        if (!s.value && s.options.length) { s.selectedIndex = 0; }
        return s;
    }

    function valueControl(field, opKey, value) {
        var op = field ? field.ops.filter(function (o) { return o.key === opKey; })[0] : null;
        var type = op ? op.type : 'none';
        var wrap = el('span', { 'class': 'seg-value', 'data-type': type });
        if (type === 'none') {
            return wrap;
        }
        if (type === 'choice') {
            var s = el('select', { 'aria-label': t.value || '' });
            field.choices.forEach(function (c) { s.appendChild(el('option', { value: c.value, text: c.label })); });
            if (value !== undefined && value !== null) { s.value = String(value); }
            wrap.appendChild(s);
            return wrap;
        }
        var input = el('input', { type: type === 'number' || type === 'amount' ? 'number' : 'text', 'aria-label': t.value || '', dir: 'auto' });
        if (type === 'number') { input.min = '1'; input.max = '3650'; }
        if (type === 'amount') { input.min = '0'; input.step = 'any'; }
        if (type === 'date') { input.className = 'crm-datepicker'; input.name = 'seg_date_' + Math.random().toString(36).slice(2); }
        input.value = value !== undefined && value !== null ? String(value) : '';
        wrap.appendChild(input);
        if (type === 'date' && window.CRMDatePicker) {
            // The picker moves the name to a hidden ISO input right after the visible one.
            setTimeout(function () { window.CRMDatePicker.attach(input, { defaultCalendar: calendar }); }, 0);
        }
        return wrap;
    }

    /** Scope select (e.g. all shops / one shop) for fields that have scopes; an empty placeholder otherwise. */
    function scopeControl(field, value) {
        var wrap = el('span', { 'class': 'seg-scope' });
        if (!field || !field.scopes || !field.scopes.length) {
            return wrap;
        }
        var s = el('select', { 'aria-label': t.scope || '' });
        field.scopes.forEach(function (c) { s.appendChild(el('option', { value: c.value, text: c.label })); });
        if (value !== undefined && value !== null) { s.value = String(value); }
        wrap.appendChild(s);
        return wrap;
    }

    function conditionRow(cond) {
        var row = el('div', { 'class': 'seg-condition inline-form-row' });
        var f = fieldSelect(cond.field);
        var field = byKey[f.value] || fields[0];
        var o = opSelect(field, cond.op);
        var v = valueControl(field, o.value, cond.value);
        var sc = scopeControl(field, cond.scope);
        var remove = el('button', { type: 'button', 'class': 'btn btn--ghost btn--sm', text: t.remove || '' });

        f.addEventListener('change', function () {
            var nf = byKey[f.value];
            var no = opSelect(nf, null);
            row.replaceChild(no, row.querySelector('.seg-op'));
            row.replaceChild(valueControl(nf, no.value, null), row.querySelector('.seg-value'));
            row.replaceChild(scopeControl(nf, null), row.querySelector('.seg-scope'));
            bindOp(no);
        });
        function bindOp(sel) {
            sel.addEventListener('change', function () {
                row.replaceChild(valueControl(byKey[f.value], sel.value, null), row.querySelector('.seg-value'));
            });
        }
        bindOp(o);
        remove.addEventListener('click', function () { row.remove(); });
        [f, o, v, sc, remove].forEach(function (n) { row.appendChild(n); });
        return row;
    }

    function groupBox(group, index) {
        var box = el('fieldset', { 'class': 'seg-group' });
        var legend = el('legend', { text: (t.group || '') + ' ' + (index + 1) });
        var head = el('div', { 'class': 'inline-form-row' }, [matchSelect(group.match, t.matchAll || '', t.matchAny || '', 'seg-group-match')]);
        var list = el('div', { 'class': 'seg-conditions' });
        (group.conditions || []).forEach(function (c) { list.appendChild(conditionRow(c)); });
        var add = el('button', { type: 'button', 'class': 'btn btn--sm', text: t.addCondition || '' });
        add.addEventListener('click', function () { list.appendChild(conditionRow({})); });
        var remove = el('button', { type: 'button', 'class': 'btn btn--ghost btn--sm', text: t.remove || '' });
        remove.addEventListener('click', function () { box.remove(); });
        [legend, head, list, el('div', { 'class': 'actions' }, [add, remove])].forEach(function (n) { box.appendChild(n); });
        return box;
    }

    var rules = data.rules || { match: 'all', groups: [] };
    var topMatch = matchSelect(rules.match, t.groupsAll || '', t.groupsAny || '', 'seg-top-match');
    var groupsEl = el('div', { 'class': 'seg-groups' });
    (rules.groups || []).forEach(function (g, i) { groupsEl.appendChild(groupBox(g, i)); });
    if (!groupsEl.children.length) { groupsEl.appendChild(groupBox({ match: 'all', conditions: [{}] }, 0)); }
    var addGroup = el('button', { type: 'button', 'class': 'btn btn--sm', text: t.addGroup || '' });
    addGroup.addEventListener('click', function () {
        groupsEl.appendChild(groupBox({ match: 'all', conditions: [{}] }, groupsEl.children.length));
    });
    root.appendChild(topMatch);
    root.appendChild(groupsEl);
    root.appendChild(el('div', { 'class': 'actions' }, [addGroup]));

    function readValue(row) {
        var wrap = row.querySelector('.seg-value');
        var type = wrap ? wrap.getAttribute('data-type') : 'none';
        if (type === 'none') { return null; }
        if (type === 'choice') { return wrap.querySelector('select').value; }
        if (type === 'date') {
            var hidden = wrap.querySelector('input[type="hidden"]');
            return hidden ? hidden.value : wrap.querySelector('input').value;
        }
        return wrap.querySelector('input').value;
    }

    form.addEventListener('submit', function () {
        var out = { match: topMatch.value, groups: [] };
        groupsEl.querySelectorAll('.seg-group').forEach(function (box) {
            var g = { match: box.querySelector('.seg-group-match').value, conditions: [] };
            box.querySelectorAll('.seg-condition').forEach(function (row) {
                var c = { field: row.querySelector('.seg-field').value, op: row.querySelector('.seg-op').value, value: readValue(row) };
                var scope = row.querySelector('.seg-scope select');
                if (scope) { c.scope = scope.value; }
                g.conditions.push(c);
            });
            out.groups.push(g);
        });
        form.querySelector('[data-segment-rules]').value = JSON.stringify(out);
    });
})();
