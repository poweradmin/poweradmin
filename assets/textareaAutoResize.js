function updateContentInput() {
    const recordTypeSelect = document.getElementById('recordTypeSelect');
    const contentInputContainer = document.getElementById('contentInputContainer');
    const selectedType = recordTypeSelect.value;
    const initialValue = contentInputContainer.dataset.initialValue || '';

    const textareaTypes = [
        'APL', 'CERT', 'CDNSKEY', 'CDS', 'CSYNC', 'DHCID', 'DLV', 'DNSKEY', 'DS', 'HTTPS', 'IPSECKEY', 'KEY', 'LUA',
        'NAPTR', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'OPENPGPKEY', 'RKEY', 'RRSIG', 'SIG', 'SMIMEA', 'SPF', 'SSHFP', 'SVCB',
        'TLSA', 'TKEY', 'TSIG', 'TXT', 'URI', 'ZONEMD'
    ];

    if (textareaTypes.includes(selectedType)) {
        contentInputContainer.innerHTML = `<textarea id="recordContent" class="form-control form-control-sm" name="content" rows="1" required>${initialValue}</textarea>`;
    } else {
        contentInputContainer.innerHTML = `<input id="recordContent" class="form-control form-control-sm" type="text" name="content" value="${initialValue}" required>`;
    }

    const recordContent = document.getElementById('recordContent');
    if (recordContent.tagName.toLowerCase() === 'textarea') {
        recordContent.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });

        if (recordContent.value !== '') {
            recordContent.style.height = 'auto';
            recordContent.style.height = recordContent.scrollHeight + 'px';
        }
    } else {
        recordContent.addEventListener('input', function() {
            this.style.height = 'auto';
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const contentInputContainer = document.getElementById('contentInputContainer');
    contentInputContainer.dataset.initialValue = document.getElementById('recordContent').value;
    updateContentInput();
    document.getElementById('recordTypeSelect').addEventListener('change', updateContentInput);
});
