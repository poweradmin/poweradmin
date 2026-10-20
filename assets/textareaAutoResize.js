const textareaTypes = new Set([
    'APL', 'CERT', 'CDNSKEY', 'CDS', 'CSYNC', 'DHCID', 'DLV', 'DNSKEY', 'DS',
    'HTTPS', 'IPSECKEY', 'KEY', 'LUA', 'NAPTR', 'NSEC', 'NSEC3', 'NSEC3PARAM',
    'OPENPGPKEY', 'RKEY', 'RRSIG', 'SIG', 'SMIMEA', 'SPF', 'SSHFP', 'SVCB',
    'TLSA', 'TKEY', 'TSIG', 'TXT', 'URI', 'ZONEMD'
]);

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function updateContentInput(selectId, containerId, contentId) {
    const elements = {
        select: document.getElementById(selectId),
        container: document.getElementById(containerId)
    };

    if (!elements.select || !elements.container) return;

    const currentInput = document.getElementById(contentId);
    const currentClasses = currentInput ? currentInput.classList.toString() : '';
    const currentValue = currentInput ? currentInput.value : '';
    const currentName = currentInput ? currentInput.name : 'content';
    const isTextarea = textareaTypes.has(elements.select.value);

    const escapedValue = escapeHtml(currentValue);

    elements.container.innerHTML = isTextarea
        ? `<textarea id="${contentId}" class="${currentClasses}" name="${currentName}" rows="1" required>${escapedValue}</textarea>`
        : `<input id="${contentId}" class="${currentClasses}" type="text" name="${currentName}" value="${escapedValue}" data-testid="record-content-input" required>`;

    if (isTextarea) {
        const textarea = document.getElementById(contentId);
        const adjustHeight = () => {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
        };

        textarea.removeEventListener('input', adjustHeight);
        textarea.addEventListener('input', adjustHeight);
        if (textarea.value) adjustHeight();
    }

    syncSoaEditors();
}

document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('[id^="contentInputContainer"]');
    containers.forEach(container => {
        const baseString = "contentInputContainer";
        const suffix = container.id.slice(baseString.length);

        const selectId = `recordTypeSelect${suffix}`;
        const contentId = `recordContent${suffix}`;
        const select = document.getElementById(selectId);

        if (container && select) {
            container.dataset.initialValue = document.getElementById(contentId).value;
            updateContentInput(selectId, container.id, contentId);
            select.addEventListener('change', () => updateContentInput(selectId, container.id, contentId));
        }
    });
});

// Compact duration in the largest whole units; must match SoaContent::duration() in PHP
function soaDuration(value) {
    if (!/^\d{1,12}$/.test(value)) return null;
    let remaining = Number(value);
    if (remaining === 0) return '0s';
    const parts = [];
    [['w', 604800], ['d', 86400], ['h', 3600], ['m', 60], ['s', 1]].forEach(([unit, size]) => {
        if (remaining >= size) {
            parts.push(`${Math.floor(remaining / size)}${unit}`);
            remaining %= size;
        }
    });
    return parts.join(' ');
}

// Refill a labelled SOA breakdown from its content input; more than seven fields is shown in red
function refillSoaBreakdowns(input) {
    document.querySelectorAll(`[data-soa-input="${input.id}"]`).forEach(breakdown => {
        const parts = input.value.trim().split(/\s+/).filter(part => part !== '');
        breakdown.querySelectorAll('[data-soa-field]').forEach(field => {
            const value = parts[Number(field.dataset.soaField)] ?? '-';
            const duration = field.dataset.soaDuration ? soaDuration(value) : null;
            field.textContent = duration ? `${value} (${duration})` : value;
        });
        breakdown.classList.toggle('text-danger', parts.length > 7);
    });
}

function syncSoaBreakdowns() {
    document.querySelectorAll('[data-soa-input]').forEach(breakdown => {
        const input = document.getElementById(breakdown.dataset.soaInput);
        if (input) refillSoaBreakdowns(input);
    });
    document.querySelectorAll('[data-soa-type]').forEach(wrapper => {
        const select = document.getElementById(wrapper.dataset.soaType);
        if (select) wrapper.classList.toggle('d-none', select.value !== 'SOA');
    });
}

document.addEventListener('input', event => refillSoaBreakdowns(event.target));
document.addEventListener('change', syncSoaBreakdowns);
document.addEventListener('DOMContentLoaded', syncSoaBreakdowns);
// A form reset restores the controls without input or change events, and only after this handler
document.addEventListener('reset', () => setTimeout(syncSoaBreakdowns, 0));

// Structured SOA editor: its parts are joined with single spaces into the hidden content input
function soaEditorParts(editor) {
    return Array.from(editor.querySelectorAll('[data-soa-part]'))
        .sort((a, b) => Number(a.dataset.soaPart) - Number(b.dataset.soaPart));
}

function joinSoaEditor(editor) {
    const input = document.getElementById(editor.dataset.soaEditor);
    if (input) input.value = soaEditorParts(editor).map(part => part.value.trim()).join(' ');
}

function refreshSoaDurations(editor) {
    editor.querySelectorAll('[data-soa-duration-for]').forEach(label => {
        const timer = document.getElementById(label.dataset.soaDurationFor);
        label.textContent = (timer && soaDuration(timer.value.trim())) || '-';
    });
}

// Shows the editor only while the type is SOA, and refills it from the content typed meanwhile
function syncSoaEditors() {
    document.querySelectorAll('[data-soa-editor]').forEach(editor => {
        const input = document.getElementById(editor.dataset.soaEditor);
        const select = document.getElementById(editor.dataset.soaSelect);
        if (!input || !select) return;

        const active = select.value === 'SOA' && editor.dataset.soaMode !== 'text';
        if (active && editor.dataset.soaActive !== '1') {
            const values = input.value.trim().split(/\s+/).filter(value => value !== '');
            if (values.length === 7) {
                soaEditorParts(editor).forEach(part => { part.value = values[Number(part.dataset.soaPart)]; });
            } else {
                joinSoaEditor(editor);
            }
        }
        editor.dataset.soaActive = active ? '1' : '0';
        editor.classList.toggle('d-none', !active);
        editor.querySelectorAll('input, button').forEach(control => { control.disabled = !active; });
        input.classList.toggle('d-none', active);
        document.querySelectorAll(`[data-soa-note="${input.id}"]`).forEach(note => note.classList.toggle('d-none', !active));
        document.querySelectorAll(`[data-soa-fields-button="${input.id}"]`).forEach(button => {
            button.classList.toggle('d-none', active || select.value !== 'SOA');
            button.disabled = input.value.trim().split(/\s+/).filter(value => value !== '').length !== 7;
        });
        refreshSoaDurations(editor);
    });
}

document.addEventListener('DOMContentLoaded', syncSoaEditors);

document.addEventListener('input', event => {
    const editor = event.target.closest ? event.target.closest('[data-soa-editor]') : null;
    if (!editor) return;
    joinSoaEditor(editor);
    refreshSoaDurations(editor);
});

document.addEventListener('click', event => {
    const button = event.target.closest ? event.target.closest('[data-soa-defaults-button]') : null;
    const editor = button ? button.closest('[data-soa-editor]') : null;
    if (!editor) return;
    const defaults = JSON.parse(editor.dataset.soaDefaults || '{}');
    ['refresh', 'retry', 'expire', 'minimum'].forEach(key => {
        const timer = document.getElementById(`soa_${key}`);
        if (timer && defaults[key] !== undefined) timer.value = defaults[key];
    });
    joinSoaEditor(editor);
    refreshSoaDurations(editor);
});

// Edit as text swaps to the raw content input (custom serial, placeholders); Edit as fields swaps back
document.addEventListener('click', event => {
    const textButton = event.target.closest ? event.target.closest('[data-soa-text-button]') : null;
    const textEditor = textButton ? textButton.closest('[data-soa-editor]') : null;
    if (textEditor) {
        joinSoaEditor(textEditor);
        textEditor.dataset.soaMode = 'text';
        syncSoaEditors();
        document.getElementById(textEditor.dataset.soaEditor)?.focus();
        return;
    }
    const fieldsButton = event.target.closest ? event.target.closest('[data-soa-fields-button]') : null;
    if (!fieldsButton) return;
    document.querySelectorAll(`[data-soa-editor="${fieldsButton.dataset.soaFieldsButton}"]`).forEach(editor => {
        editor.dataset.soaMode = '';
        editor.dataset.soaActive = '0';
    });
    syncSoaEditors();
});

document.addEventListener('input', event => {
    if (event.target.id && document.querySelector(`[data-soa-fields-button="${event.target.id}"]`)) syncSoaEditors();
});

document.addEventListener('submit', event => {
    event.target.querySelectorAll('[data-soa-editor][data-soa-active="1"]').forEach(joinSoaEditor);
});

// Reset restores the editor inputs to the stored parts, so the content is rejoined from them
document.addEventListener('reset', event => {
    setTimeout(() => {
        event.target.querySelectorAll('[data-soa-editor]').forEach(editor => {
            const select = document.getElementById(editor.dataset.soaSelect);
            editor.dataset.soaMode = '';
            if (select && select.value === 'SOA') editor.dataset.soaActive = '1';
        });
        syncSoaEditors();
        event.target.querySelectorAll('[data-soa-editor][data-soa-active="1"]').forEach(joinSoaEditor);
    }, 0);
});
