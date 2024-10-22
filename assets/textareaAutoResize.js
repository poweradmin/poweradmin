const textareaTypes = new Set([
    'APL', 'CERT', 'CDNSKEY', 'CDS', 'CSYNC', 'DHCID', 'DLV', 'DNSKEY', 'DS',
    'HTTPS', 'IPSECKEY', 'KEY', 'LUA', 'NAPTR', 'NSEC', 'NSEC3', 'NSEC3PARAM',
    'OPENPGPKEY', 'RKEY', 'RRSIG', 'SIG', 'SMIMEA', 'SPF', 'SSHFP', 'SVCB',
    'TLSA', 'TKEY', 'TSIG', 'TXT', 'URI', 'ZONEMD'
]);

function updateContentInput() {
    const elements = {
        select: document.getElementById('recordTypeSelect'),
        container: document.getElementById('contentInputContainer')
    };

    if (!elements.select || !elements.container) return;

    const value = elements.container.dataset.initialValue || '';
    const isTextarea = textareaTypes.has(elements.select.value);

    elements.container.innerHTML = isTextarea
        ? `<textarea id="recordContent" class="form-control form-control-sm" name="content" rows="1" required>${value}</textarea>`
        : `<input id="recordContent" class="form-control form-control-sm" type="text" name="content" value="${value}" required>`;

    if (isTextarea) {
        const textarea = document.getElementById('recordContent');
        const adjustHeight = () => {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
        };

        textarea.addEventListener('input', adjustHeight);
        if (textarea.value) adjustHeight();
    }
}


document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('contentInputContainer');
    const record = document.getElementById('recordContent');
    const select = document.getElementById('recordTypeSelect');

    if (container && record && select) {
        container.dataset.initialValue = record.value;
        updateContentInput();
        select.addEventListener('change', updateContentInput);
    }
});
