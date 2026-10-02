/**
 * Tag-pill input. Convention: <input class="crm-tag-input" name="tags[]">
 * auto-upgrades into a pill-style tag entry widget. Typing text and
 * pressing Enter (or comma) commits it as a removable pill and starts
 * a new one; each pill becomes its own hidden `tags[]` input so the
 * form submits a plain array of tag strings, same shape either way.
 *
 * Auto-initialized from layout.php on every page, same bootstrap
 * pattern as datepicker.js.
 */
(function () {
    function buildWidget(sourceInput) {
        const wrapper = document.createElement('div');
        wrapper.className = 'tag-input-wrapper';

        const pillsContainer = document.createElement('div');
        pillsContainer.className = 'tag-input-pills';

        const textInput = document.createElement('input');
        textInput.type = 'text';
        textInput.className = 'tag-input-text';
        textInput.placeholder = sourceInput.getAttribute('placeholder') || 'Add a tag and press Enter';

        wrapper.appendChild(pillsContainer);
        wrapper.appendChild(textInput);
        sourceInput.parentNode.insertBefore(wrapper, sourceInput);
        sourceInput.remove();

        const formName = sourceInput.getAttribute('data-name') || 'tags[]';

        function addPill(text) {
            text = text.trim();
            if (text === '') {
                return;
            }

            const pill = document.createElement('span');
            pill.className = 'tag-pill';

            const label = document.createElement('span');
            label.textContent = text;
            pill.appendChild(label);

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = formName;
            hidden.value = text;
            pill.appendChild(hidden);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'tag-pill-remove';
            remove.textContent = '\u00d7';
            remove.addEventListener('click', function () {
                pill.remove();
            });
            pill.appendChild(remove);

            pillsContainer.appendChild(pill);
            textInput.value = '';
        }

        textInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addPill(textInput.value);
            } else if (e.key === 'Backspace' && textInput.value === '') {
                const last = pillsContainer.lastElementChild;
                if (last) {
                    last.remove();
                }
            }
        });

        // Committing on blur too, so a tag typed but not confirmed
        // with Enter isn't silently lost when the user clicks away.
        textInput.addEventListener('blur', function () {
            if (textInput.value.trim() !== '') {
                addPill(textInput.value);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.crm-tag-input').forEach(buildWidget);
    });
})();
