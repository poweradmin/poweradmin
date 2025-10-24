/**
 * DNS Wizard JavaScript
 *
 * Handles wizard interactions, API calls, and form generation for DNS record wizards.
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */

class DnsWizard {
    constructor(config) {
        this.apiBaseUrl = config.apiBaseUrl;
        this.zoneId = config.zoneId;
        this.zoneName = config.zoneName;
        this.csrfToken = config.csrfToken;
        this.currentWizard = null;
        this.wizards = [];
        this.formData = {};
        this.previewTimeout = null;

        this.init();
    }

    /**
     * Initialize wizard
     */
    async init() {
        await this.loadWizards();
        this.setupEventListeners();
    }

    /**
     * Load available wizards from API
     */
    async loadWizards() {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=list`);

            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Try to parse JSON
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                throw new Error('Invalid JSON response from API');
            }

            if (data.success) {
                this.wizards = data.data.wizards;
                this.renderWizardGrid();
            }
        } catch (error) {
            // Failed to load wizards - grid won't be populated
        }
    }

    /**
     * Render wizard selection grid
     */
    renderWizardGrid() {
        const grid = document.getElementById('wizardGrid');
        grid.innerHTML = '';

        const wizardOrder = ['DMARC', 'SPF', 'DKIM', 'CAA', 'TLSA', 'SRV'];
        const orderedWizards = wizardOrder
            .map(type => this.wizards[type])
            .filter(w => w);

        orderedWizards.forEach(wizard => {
            const card = this.createWizardCard(wizard);
            grid.appendChild(card);
        });
    }

    /**
     * Create wizard selection card
     */
    createWizardCard(wizard) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4';

        const icons = {
            'DMARC': 'shield-check',
            'SPF': 'envelope-check',
            'DKIM': 'key',
            'CAA': 'award',
            'TLSA': 'lock',
            'SRV': 'hdd-network'
        };

        col.innerHTML = `
            <div class="card h-100 wizard-card" data-wizard-type="${wizard.type}" style="cursor: pointer;">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-${icons[wizard.type] || 'file-earmark-text'} me-2 text-primary"></i>
                        ${wizard.name}
                    </h6>
                    <p class="card-text small text-muted">${wizard.description}</p>
                    <div class="mt-2">
                        <span class="badge bg-secondary">${wizard.recordType}</span>
                    </div>
                </div>
            </div>
        `;

        col.querySelector('.wizard-card').addEventListener('click', () => {
            this.selectWizard(wizard.type);
        });

        return col;
    }

    /**
     * Select and load a wizard
     */
    async selectWizard(wizardType) {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=schema&type=${wizardType}`);
            const data = await response.json();

            if (data.success) {
                this.currentWizard = data.data;
                this.formData = {};

                // Clear any previous errors/warnings
                this.clearValidation();

                this.renderWizardForm();

                // Show form, hide selection
                document.getElementById('wizardSelection').style.display = 'none';
                document.getElementById('wizardForm').style.display = 'block';
                document.getElementById('useWizardRecord').style.display = 'block';

                // Update modal title
                document.getElementById('wizardTitle').textContent = this.currentWizard.name;
            }
        } catch (error) {
            this.showError('Failed to load wizard configuration', true);
        }
    }

    /**
     * Render wizard form from schema
     */
    renderWizardForm() {
        const container = document.getElementById('wizardFields');
        container.innerHTML = '';

        // Initialize formData with default values
        this.currentWizard.schema.sections.forEach(section => {
            if (section.fields) {
                section.fields.forEach(field => {
                    if (field.default !== undefined && field.default !== null && field.default !== '') {
                        this.formData[field.name] = field.default;
                    }
                });
            }
        });

        this.currentWizard.schema.sections.forEach(section => {
            container.appendChild(this.renderSection(section));
        });
    }

    /**
     * Render a form section
     */
    renderSection(section) {
        const sectionDiv = document.createElement('div');
        sectionDiv.className = 'mb-4';

        // Section header
        const header = document.createElement('h6');
        header.className = 'border-bottom pb-2 mb-3';
        header.innerHTML = `<i class="bi bi-gear me-2"></i>${section.title}`;
        sectionDiv.appendChild(header);

        // Section description (if type is 'info' or 'warning')
        if (section.type === 'info' || section.type === 'warning') {
            const alert = document.createElement('div');
            alert.className = section.type === 'info' ? 'alert alert-info' : 'alert alert-warning';
            alert.innerHTML = `<i class="bi bi-info-circle me-1"></i>${section.content || section.description || ''}`;
            sectionDiv.appendChild(alert);
            return sectionDiv;
        }

        // Fields
        if (section.fields) {
            section.fields.forEach(field => {
                sectionDiv.appendChild(this.renderField(field));
            });
        }

        return sectionDiv;
    }

    /**
     * Render a form field
     */
    renderField(field) {
        const fieldDiv = document.createElement('div');
        fieldDiv.className = 'mb-3';

        const label = document.createElement('label');
        label.className = 'form-label';
        label.htmlFor = `wizard_${field.name}`;
        label.textContent = field.label;
        if (field.required) {
            label.innerHTML += ' <span class="text-danger">*</span>';
        }
        fieldDiv.appendChild(label);

        let input;

        switch (field.type) {
            case 'text':
            case 'email':
            case 'url':
                input = this.createTextInput(field);
                break;
            case 'number':
                input = this.createNumberInput(field);
                break;
            case 'textarea':
                input = this.createTextarea(field);
                break;
            case 'select':
                input = this.createSelect(field);
                break;
            case 'radio':
                input = this.createRadioGroup(field);
                break;
            case 'checkbox':
                input = this.createCheckbox(field);
                break;
            case 'checkbox_group':
                input = this.createCheckboxGroup(field);
                break;
            default:
                input = this.createTextInput(field);
        }

        fieldDiv.appendChild(input);

        // Help text
        if (field.help) {
            const help = document.createElement('div');
            help.className = 'form-text';
            help.innerHTML = `<i class="bi bi-question-circle me-1"></i>${field.help}`;
            fieldDiv.appendChild(help);
        }

        return fieldDiv;
    }

    /**
     * Create text input
     */
    createTextInput(field) {
        const input = document.createElement('input');
        input.type = field.type || 'text';
        input.className = 'form-control';
        input.id = `wizard_${field.name}`;
        input.name = field.name;
        input.placeholder = field.placeholder || '';
        if (field.required) input.required = true;
        if (field.pattern) input.pattern = field.pattern;
        if (field.default) input.value = field.default;

        input.addEventListener('input', () => this.onFieldChange(field.name, input.value));

        return input;
    }

    /**
     * Create number input
     */
    createNumberInput(field) {
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control';
        input.id = `wizard_${field.name}`;
        input.name = field.name;
        if (field.min !== undefined) input.min = field.min;
        if (field.max !== undefined) input.max = field.max;
        if (field.default !== undefined) input.value = field.default;
        if (field.required) input.required = true;

        input.addEventListener('input', () => this.onFieldChange(field.name, input.value));

        return input;
    }

    /**
     * Create textarea
     */
    createTextarea(field) {
        const textarea = document.createElement('textarea');
        textarea.className = 'form-control';
        textarea.id = `wizard_${field.name}`;
        textarea.name = field.name;
        textarea.rows = field.rows || 3;
        textarea.placeholder = field.placeholder || '';
        if (field.required) textarea.required = true;
        if (field.default) textarea.value = field.default;

        textarea.addEventListener('input', () => this.onFieldChange(field.name, textarea.value));

        return textarea;
    }

    /**
     * Create select dropdown
     */
    createSelect(field) {
        const select = document.createElement('select');
        select.className = 'form-select';
        select.id = `wizard_${field.name}`;
        select.name = field.name;
        if (field.required) select.required = true;

        field.options.forEach(option => {
            const opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            if (option.value === field.default) opt.selected = true;
            select.appendChild(opt);
        });

        select.addEventListener('change', () => this.onFieldChange(field.name, select.value));

        return select;
    }

    /**
     * Create radio group
     */
    createRadioGroup(field) {
        const div = document.createElement('div');

        field.options.forEach((option, index) => {
            const radioDiv = document.createElement('div');
            radioDiv.className = 'form-check';

            const input = document.createElement('input');
            input.type = 'radio';
            input.className = 'form-check-input';
            input.id = `wizard_${field.name}_${index}`;
            input.name = field.name;
            input.value = option.value;
            if (option.value === field.default) input.checked = true;

            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = `wizard_${field.name}_${index}`;
            label.innerHTML = `<strong>${option.label}</strong>`;
            if (option.description) {
                label.innerHTML += `<br><small class="text-muted">${option.description}</small>`;
            }

            input.addEventListener('change', () => {
                if (input.checked) this.onFieldChange(field.name, input.value);
            });

            radioDiv.appendChild(input);
            radioDiv.appendChild(label);
            div.appendChild(radioDiv);
        });

        return div;
    }

    /**
     * Create checkbox
     */
    createCheckbox(field) {
        const div = document.createElement('div');
        div.className = 'form-check';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input';
        input.id = `wizard_${field.name}`;
        input.name = field.name;
        if (field.default) input.checked = true;

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = `wizard_${field.name}`;
        label.textContent = field.label;

        input.addEventListener('change', () => this.onFieldChange(field.name, input.checked));

        div.appendChild(input);
        div.appendChild(label);

        return div;
    }

    /**
     * Create checkbox group
     */
    createCheckboxGroup(field) {
        const div = document.createElement('div');

        field.options.forEach((option, index) => {
            const checkDiv = document.createElement('div');
            checkDiv.className = 'form-check';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'form-check-input';
            input.id = `wizard_${field.name}_${index}`;
            input.name = `${field.name}[]`;
            input.value = option.value;

            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = `wizard_${field.name}_${index}`;
            label.textContent = option.label;

            input.addEventListener('change', () => this.updateCheckboxGroup(field.name));

            checkDiv.appendChild(input);
            checkDiv.appendChild(label);
            div.appendChild(checkDiv);
        });

        return div;
    }

    /**
     * Update checkbox group value
     */
    updateCheckboxGroup(fieldName) {
        const checkboxes = document.querySelectorAll(`input[name="${fieldName}[]"]:checked`);
        const values = Array.from(checkboxes).map(cb => cb.value);
        this.onFieldChange(fieldName, values);
    }

    /**
     * Handle field change
     */
    onFieldChange(fieldName, value) {
        this.formData[fieldName] = value;
        this.debouncedPreview();
    }

    /**
     * Debounced preview update
     */
    debouncedPreview() {
        clearTimeout(this.previewTimeout);
        this.previewTimeout = setTimeout(() => this.updatePreview(), 500);
    }

    /**
     * Debounced validation
     */
    debouncedValidate() {
        clearTimeout(this.validationTimeout);
        this.validationTimeout = setTimeout(() => this.validateForm(), 700);
    }

    /**
     * Update record preview
     */
    async updatePreview() {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.currentWizard.type,
                    formData: this.formData
                })
            });

            const data = await response.json();

            if (data.success) {
                // Check if validation data is included
                if (data.data.validation) {
                    this.displayValidation(data.data.validation);
                }

                // Only show preview if there's actual content
                if (data.data.preview && data.data.preview.length > 0) {
                    document.getElementById('previewContent').querySelector('code').textContent = data.data.preview;
                    document.getElementById('wizardPreview').style.display = 'block';
                } else {
                    document.getElementById('wizardPreview').style.display = 'none';
                }
            }
        } catch (error) {
            this.showError('Failed to generate preview');
        }
    }

    /**
     * Validate form
     */
    async validateForm() {
        try {
            const response = await fetch(`${this.apiBaseUrl}?action=validate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.currentWizard.type,
                    formData: this.formData
                })
            });

            const data = await response.json();

            if (data.success) {
                this.displayValidation(data.data);
            }
        } catch (error) {
            this.showError('Validation failed');
        }
    }

    /**
     * Display validation results
     */
    displayValidation(validation) {
        const errorsDiv = document.getElementById('wizardErrors');
        const warningsDiv = document.getElementById('wizardWarnings');
        const errorList = document.getElementById('errorList');
        const warningList = document.getElementById('warningList');

        // Errors
        if (validation.errors && validation.errors.length > 0) {
            errorList.innerHTML = validation.errors.map(err => `<li>${err}</li>`).join('');
            errorsDiv.style.display = 'block';
            document.getElementById('wizardValidation').style.display = 'block';
            document.getElementById('useWizardRecord').disabled = true;
        } else {
            errorsDiv.style.display = 'none';
            document.getElementById('useWizardRecord').disabled = false;
        }

        // Warnings
        if (validation.warnings && validation.warnings.length > 0) {
            warningList.innerHTML = validation.warnings.map(warn => `<li>${warn}</li>`).join('');
            warningsDiv.style.display = 'block';
            document.getElementById('wizardValidation').style.display = 'block';
        } else {
            warningsDiv.style.display = 'none';
        }

        if ((!validation.errors || validation.errors.length === 0) && (!validation.warnings || validation.warnings.length === 0)) {
            document.getElementById('wizardValidation').style.display = 'none';
        }
    }

    /**
     * Clear all validation messages
     */
    clearValidation() {
        document.getElementById('wizardValidation').style.display = 'none';
        document.getElementById('wizardErrors').style.display = 'none';
        document.getElementById('wizardWarnings').style.display = 'none';
        document.getElementById('errorList').innerHTML = '';
        document.getElementById('warningList').innerHTML = '';
        document.getElementById('useWizardRecord').disabled = false;
    }

    /**
     * Show error message in the wizard
     */
    showError(message, disableButton = false) {
        const errorsDiv = document.getElementById('wizardErrors');
        const errorList = document.getElementById('errorList');

        errorList.innerHTML = `<li>${message}</li>`;
        errorsDiv.style.display = 'block';
        document.getElementById('wizardValidation').style.display = 'block';

        // Only disable button for validation errors, not for creation errors
        if (disableButton) {
            document.getElementById('useWizardRecord').disabled = true;
        }

        // Hide warnings
        document.getElementById('wizardWarnings').style.display = 'none';
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Back to selection button
        document.getElementById('backToSelection').addEventListener('click', () => {
            this.clearValidation();
            document.getElementById('wizardSelection').style.display = 'block';
            document.getElementById('wizardForm').style.display = 'none';
            document.getElementById('useWizardRecord').style.display = 'none';
            document.getElementById('wizardPreview').style.display = 'none';
            document.getElementById('wizardTitle').textContent = 'DNS Record Wizard';
            this.currentWizard = null;
            this.formData = {};
        });

        // Use wizard record button
        document.getElementById('useWizardRecord').addEventListener('click', () => {
            this.insertRecordIntoForm();
        });
    }

    /**
     * Insert generated record into the add record form
     */
    async insertRecordIntoForm() {
        try {
            // First validate the form
            const validationResponse = await fetch(`${this.apiBaseUrl}?action=validate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.currentWizard.type,
                    formData: this.formData
                })
            });

            const validationData = await validationResponse.json();

            if (validationData.success) {
                const validation = validationData.data;

                // Display validation results
                this.displayValidation(validation);

                // If there are validation errors, stop here
                if (validation.errors && validation.errors.length > 0) {
                    return;
                }
            }

            // If validation passed, generate the record and create it
            const generateResponse = await fetch(`${this.apiBaseUrl}?action=generate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: this.currentWizard.type,
                    formData: this.formData
                })
            });

            const generateData = await generateResponse.json();

            if (generateData.success) {
                const recordData = generateData.data;

                // Create the record via API
                const createResponse = await fetch(`${this.apiBaseUrl}?action=create`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        zone_id: this.zoneId,
                        name: recordData.name || '',
                        type: recordData.type || '',
                        content: recordData.content || '',
                        ttl: recordData.ttl || '',
                        priority: recordData.prio || 0,
                        comment: ''
                    })
                });

                const createData = await createResponse.json();

                if (createData.success) {
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('dnsWizardModal')).hide();

                    // Reload the page to show the new record and success message
                    window.location.reload();
                } else {
                    this.showError(createData.message || 'Failed to create record');
                }
            } else {
                this.showError(generateData.message || 'Failed to generate record');
            }
        } catch (error) {
            this.showError('Failed to create record');
        }
    }
}

// Export for global use
window.DnsWizard = DnsWizard;
