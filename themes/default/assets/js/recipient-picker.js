/**
 * Gmail-style recipient picker. Convention:
 *   <input class="crm-recipient-picker" data-name="to[]" data-source="mail-colleagues-data" data-selected="1,4,9">
 *   <script type="application/json" id="mail-colleagues-data">[{"id":1,"name":"Jane Doe"},...]</script>
 *
 * Typing filters the source list by name (substring, case-insensitive);
 * a dropdown of matches appears below the input. Click a suggestion,
 * or press Enter/Tab while exactly one match is shown or one is
 * arrow-key-highlighted, to commit it as a removable pill — each pill
 * becomes its own hidden input (name from data-name) carrying the
 * user's id, so the form submits a plain array of ids, same shape as
 * the old <select multiple>. Unlike tag-input.js, free text is never
 * committed on its own — only a real match from the source list can
 * become a pill, since a recipient has to be a known user.
 *
 * Auto-initialized from layout.php, same bootstrap pattern as
 * datepicker.js/tag-input.js.
 */
(function () {
    function parseSource(sourceId) {
        var el = document.getElementById(sourceId);
        if (!el) {
            return [];
        }
        try {
            return JSON.parse(el.textContent || '[]');
        } catch (e) {
            return [];
        }
    }

    function buildWidget(sourceInput) {
        var people = parseSource(sourceInput.getAttribute('data-source') || '');
        var formName = sourceInput.getAttribute('data-name') || 'recipients[]';
        var preselected = (sourceInput.getAttribute('data-selected') || '')
            .split(',')
            .map(function (s) { return parseInt(s, 10); })
            .filter(function (n) { return !isNaN(n); });

        var wrapper = document.createElement('div');
        wrapper.className = 'tag-input-wrapper recipient-picker-wrapper';

        var pillsContainer = document.createElement('div');
        pillsContainer.className = 'tag-input-pills';

        var textInput = document.createElement('input');
        textInput.type = 'text';
        textInput.className = 'tag-input-text';
        textInput.placeholder = sourceInput.getAttribute('placeholder') || '';
        textInput.setAttribute('autocomplete', 'off');

        var suggestions = document.createElement('div');
        suggestions.className = 'recipient-picker-suggestions';
        suggestions.hidden = true;

        wrapper.appendChild(pillsContainer);
        wrapper.appendChild(textInput);
        wrapper.appendChild(suggestions);
        wrapper.style.position = 'relative';
        sourceInput.parentNode.insertBefore(wrapper, sourceInput);
        sourceInput.remove();

        var addedIds = [];
        var activeIndex = -1;
        var currentMatches = [];

        function addPill(person) {
            if (addedIds.indexOf(person.id) !== -1) {
                textInput.value = '';
                hideSuggestions();
                return;
            }
            addedIds.push(person.id);

            var pill = document.createElement('span');
            pill.className = 'tag-pill';

            var label = document.createElement('span');
            label.textContent = person.name;
            pill.appendChild(label);

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = formName;
            hidden.value = String(person.id);
            pill.appendChild(hidden);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'tag-pill-remove';
            remove.textContent = '\u00d7';
            remove.addEventListener('click', function () {
                pill.remove();
                addedIds = addedIds.filter(function (id) { return id !== person.id; });
            });
            pill.appendChild(remove);

            pillsContainer.appendChild(pill);
            textInput.value = '';
            hideSuggestions();
        }

        function hideSuggestions() {
            suggestions.hidden = true;
            suggestions.innerHTML = '';
            activeIndex = -1;
            currentMatches = [];
        }

        function renderSuggestions(matches) {
            currentMatches = matches;
            activeIndex = matches.length > 0 ? 0 : -1;
            suggestions.innerHTML = '';

            if (matches.length === 0) {
                suggestions.hidden = true;
                return;
            }

            matches.slice(0, 8).forEach(function (person, idx) {
                var item = document.createElement('div');
                item.className = 'recipient-picker-suggestion' + (idx === activeIndex ? ' is-active' : '');
                item.textContent = person.name;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    addPill(person);
                });
                suggestions.appendChild(item);
            });
            suggestions.hidden = false;
        }

        function updateActiveHighlight() {
            Array.prototype.forEach.call(suggestions.children, function (child, idx) {
                child.classList.toggle('is-active', idx === activeIndex);
            });
        }

        function filterAndShow() {
            var q = textInput.value.trim().toLowerCase();
            if (q === '') {
                hideSuggestions();
                return;
            }
            var matches = people.filter(function (p) {
                return addedIds.indexOf(p.id) === -1 && p.name.toLowerCase().indexOf(q) !== -1;
            });
            renderSuggestions(matches);
        }

        textInput.addEventListener('input', filterAndShow);
        textInput.addEventListener('focus', filterAndShow);

        textInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' && currentMatches.length > 0) {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, currentMatches.length - 1);
                updateActiveHighlight();
            } else if (e.key === 'ArrowUp' && currentMatches.length > 0) {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateActiveHighlight();
            } else if ((e.key === 'Enter' || e.key === 'Tab' || e.key === ',') && activeIndex !== -1 && currentMatches[activeIndex]) {
                e.preventDefault();
                addPill(currentMatches[activeIndex]);
            } else if (e.key === 'Escape') {
                hideSuggestions();
            } else if (e.key === 'Backspace' && textInput.value === '') {
                var last = pillsContainer.lastElementChild;
                if (last) {
                    var hiddenInput = last.querySelector('input[type="hidden"]');
                    if (hiddenInput) {
                        addedIds = addedIds.filter(function (id) { return id !== parseInt(hiddenInput.value, 10); });
                    }
                    last.remove();
                }
            }
        });

        textInput.addEventListener('blur', function () {
            // Delay so a suggestion's mousedown (which also fires
            // blur) still gets to run addPill() first.
            setTimeout(hideSuggestions, 150);
        });

        preselected.forEach(function (id) {
            var person = people.find(function (p) { return p.id === id; });
            if (person) {
                addPill(person);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.crm-recipient-picker').forEach(buildWidget);
    });
})();
