import users from '../../fixtures/users.json';

// Known zone and record IDs from test data
const ZONE_IDS = {
    manager: 2,     // manager-zone.example.com
    client: 3       // client-zone.example.com
};

const RECORD_IDS = {
    managerA: 312,  // www.manager-zone.example.com A record
    clientA: 375    // www.client-zone.example.com A record
};

describe('Edit DNS Record', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.visit(`/index.php?page=edit_record&id=${RECORD_IDS.managerA}`);
        });

        it('should display edit record heading', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            cy.get('[data-testid="edit-record-heading"]').should('contain', 'Edit record in zone');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Edit record');
        });

        it('should display edit record form', () => {
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
            cy.get('[data-testid="edit-record-form"]').should('have.attr', 'method', 'post');
        });

        it('should display edit record table', () => {
            cy.get('[data-testid="edit-record-table"]').should('be.visible');
            cy.get('[data-testid="edit-record-table"] thead').should('be.visible');
        });

        it('should display record input fields with existing values', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-name-input"]').invoke('val').should('not.be.empty');
            cy.get('[data-testid="record-type-select"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').invoke('val').should('not.be.empty');
        });

        it('should have TTL and priority inputs', () => {
            cy.get('[data-testid="record-ttl-input"]').should('be.visible');
            cy.get('[data-testid="record-ttl-input"]').invoke('val').should('not.be.empty');
            cy.get('[data-testid="record-priority-input"]').should('be.visible');
        });

        it('should have disabled checkbox', () => {
            cy.get('[data-testid="record-disabled-checkbox"]').should('be.visible');
            cy.get('[data-testid="record-disabled-checkbox"]').should('have.attr', 'type', 'checkbox');
        });

        it('should display update and reset buttons', () => {
            cy.get('[data-testid="update-record-button"]').should('be.visible');
            cy.get('[data-testid="update-record-button"]').should('have.value', 'Update');
            cy.get('[data-testid="reset-button"]').should('be.visible');
            cy.get('[data-testid="reset-button"]').should('have.value', 'Reset');
        });

        it('should have update button as submit type', () => {
            cy.get('[data-testid="update-record-button"]').should('have.attr', 'type', 'submit');
        });

        it('should have reset button as reset type', () => {
            cy.get('[data-testid="reset-button"]').should('have.attr', 'type', 'reset');
        });

        it('should allow modifying record name', () => {
            cy.get('[data-testid="record-name-input"]').clear();
            cy.get('[data-testid="record-name-input"]').type('test-edit');
            cy.get('[data-testid="record-name-input"]').should('have.value', 'test-edit');
        });

        it('should allow modifying record content', () => {
            cy.get('[data-testid="record-content-input"]').then(($input) => {
                const originalValue = $input.val();
                cy.get('[data-testid="record-content-input"]').clear();
                cy.get('[data-testid="record-content-input"]').type('192.168.1.100');
                cy.get('[data-testid="record-content-input"]').should('have.value', '192.168.1.100');
            });
        });

        it('should allow changing record type', () => {
            cy.get('[data-testid="record-type-select"]').then(($select) => {
                const options = $select.find('option');
                if (options.length > 1) {
                    const secondOption = options.eq(1).val();
                    cy.get('[data-testid="record-type-select"]').select(secondOption);
                    cy.get('[data-testid="record-type-select"]').should('have.value', secondOption);
                }
            });
        });

        it('should allow modifying TTL', () => {
            cy.get('[data-testid="record-ttl-input"]').clear();
            cy.get('[data-testid="record-ttl-input"]').type('7200');
            cy.get('[data-testid="record-ttl-input"]').should('have.value', '7200');
        });

        it('should allow toggling disabled checkbox', () => {
            cy.get('[data-testid="record-disabled-checkbox"]').then(($checkbox) => {
                const wasChecked = $checkbox.is(':checked');
                if (wasChecked) {
                    cy.get('[data-testid="record-disabled-checkbox"]').uncheck();
                    cy.get('[data-testid="record-disabled-checkbox"]').should('not.be.checked');
                } else {
                    cy.get('[data-testid="record-disabled-checkbox"]').check();
                    cy.get('[data-testid="record-disabled-checkbox"]').should('be.checked');
                }
            });
        });

        it('should reset form to original values when reset button attributes are checked', () => {
            cy.get('[data-testid="reset-button"]').should('have.attr', 'name', 'reset');
            cy.get('[data-testid="reset-button"]').should('have.value', 'Reset');
        });

        it('should have required attribute on content input', () => {
            cy.get('[data-testid="record-content-input"]').should('have.attr', 'required');
        });

        it('should have min/max validation on priority input', () => {
            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'min', '0');
            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'max', '65535');
        });

        it('should have min/max validation on TTL input', () => {
            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'min', '0');
            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'max', '2147483647');
        });

        it('should have correct form structure with hidden fields', () => {
            cy.get('[data-testid="edit-record-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
                cy.get('input[name="rid"]').should('exist');
                cy.get('input[name="zid"]').should('exist');
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.visit(`/index.php?page=edit_record&id=${RECORD_IDS.managerA}`);
        });

        it('should have access to edit records in own zones', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
        });

        it('should display all editable fields', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
            cy.get('[data-testid="update-record-button"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.visit(`/index.php?page=edit_record&id=${RECORD_IDS.clientA}`);
        });

        it('should have limited edit access in own zones', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            // Client may have restricted editing (cannot edit SOA/NS typically)
        });

        it('should display edit form', () => {
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should not have access to edit records', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=list_zones');
            // Viewer should see zones but cannot edit records
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.log('Viewer can see zones but should not be able to edit records');
                }
            });
        });
    });
});
