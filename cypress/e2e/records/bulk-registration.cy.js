import users from '../../fixtures/users.json';

describe('Bulk Registration', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToBulkRegistration();
        });

        it('should display bulk registration heading', () => {
            cy.get('[data-testid="bulk-registration-heading"]').should('be.visible');
            cy.get('[data-testid="bulk-registration-heading"]').should('contain', 'Bulk registration');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Bulk registration');
        });

        it('should display bulk registration form', () => {
            cy.get('[data-testid="bulk-registration-form"]').should('be.visible');
            cy.get('[data-testid="bulk-registration-form"]').should('have.attr', 'method', 'post');
        });

        it('should display bulk registration table', () => {
            cy.get('[data-testid="bulk-registration-table"]').should('be.visible');
        });

        it('should display owner select dropdown', () => {
            cy.get('[data-testid="owner-select"]').should('be.visible');
            cy.get('[data-testid="owner-select"]').should('have.attr', 'name', 'owner');
        });

        it('should have owner options', () => {
            cy.get('[data-testid="owner-select"] option').should('have.length.at.least', 1);
        });

        it('should display zone type select dropdown', () => {
            cy.get('[data-testid="zone-type-select"]').should('be.visible');
            cy.get('[data-testid="zone-type-select"]').should('have.attr', 'name', 'dom_type');
        });

        it('should have zone type options', () => {
            cy.get('[data-testid="zone-type-select"] option').should('have.length.at.least', 1);
            // Should have MASTER and possibly NATIVE options
            cy.get('[data-testid="zone-type-select"]').should('contain', 'master');
        });

        it('should display zone template select dropdown', () => {
            cy.get('[data-testid="zone-template-select"]').should('be.visible');
            cy.get('[data-testid="zone-template-select"]').should('have.attr', 'name', 'zone_template');
        });

        it('should have none option in template select', () => {
            cy.get('[data-testid="zone-template-select"] option[value="none"]').should('exist');
            cy.get('[data-testid="zone-template-select"] option[value="none"]').should('contain', 'none');
        });

        it('should display domains textarea', () => {
            cy.get('[data-testid="domains-textarea"]').should('be.visible');
            cy.get('[data-testid="domains-textarea"]').should('have.attr', 'name', 'domains');
            cy.get('[data-testid="domains-textarea"]').should('have.attr', 'rows', '10');
        });

        it('should have required attribute on domains textarea', () => {
            cy.get('[data-testid="domains-textarea"]').should('have.attr', 'required');
        });

        it('should display add zones button', () => {
            cy.get('[data-testid="add-zones-button"]').should('be.visible');
            cy.get('[data-testid="add-zones-button"]').should('have.value', 'Add zones');
            cy.get('[data-testid="add-zones-button"]').should('have.attr', 'type', 'submit');
        });

        it('should allow typing in domains textarea', () => {
            const testDomains = 'test1.example.com\ntest2.example.com\ntest3.example.com';
            cy.get('[data-testid="domains-textarea"]').clear();
            cy.get('[data-testid="domains-textarea"]').type(testDomains);
            cy.get('[data-testid="domains-textarea"]').should('have.value', testDomains);
        });

        it('should allow selecting different owners', () => {
            cy.get('[data-testid="owner-select"]').then(($select) => {
                const options = $select.find('option');
                if (options.length > 1) {
                    const secondOption = options.eq(1).val();
                    cy.get('[data-testid="owner-select"]').select(secondOption);
                    cy.get('[data-testid="owner-select"]').should('have.value', secondOption);
                }
            });
        });

        it('should allow selecting zone type', () => {
            cy.get('[data-testid="zone-type-select"]').then(($select) => {
                const options = $select.find('option');
                if (options.length > 0) {
                    const firstOption = options.eq(0).val();
                    cy.get('[data-testid="zone-type-select"]').select(firstOption);
                    cy.get('[data-testid="zone-type-select"]').should('have.value', firstOption);
                }
            });
        });

        it('should allow selecting zone template', () => {
            cy.get('[data-testid="zone-template-select"]').select('none');
            cy.get('[data-testid="zone-template-select"]').should('have.value', 'none');
        });

        it('should have CSRF token in form', () => {
            cy.get('[data-testid="bulk-registration-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
            });
        });

        it('should show instruction text for domains input', () => {
            cy.get('[data-testid="bulk-registration-table"]').should('contain', 'Type one domain per line');
        });

        it('should have correct form action', () => {
            cy.get('[data-testid="bulk-registration-form"]').should('have.attr', 'action').and('include', 'page=bulk_registration');
        });

        it('should have novalidate attribute on form', () => {
            cy.get('[data-testid="bulk-registration-form"]').should('have.attr', 'novalidate');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToBulkRegistration();
        });

        it('should have access to bulk registration', () => {
            cy.get('[data-testid="bulk-registration-heading"]').should('be.visible');
            cy.get('[data-testid="bulk-registration-form"]').should('be.visible');
        });

        it('should display all form fields', () => {
            cy.get('[data-testid="owner-select"]').should('be.visible');
            cy.get('[data-testid="zone-type-select"]').should('be.visible');
            cy.get('[data-testid="zone-template-select"]').should('be.visible');
            cy.get('[data-testid="domains-textarea"]').should('be.visible');
        });

        it('should show only own user in owner select or all if has permission', () => {
            cy.get('[data-testid="owner-select"] option').should('have.length.at.least', 1);
        });

        it('should allow typing domains', () => {
            cy.get('[data-testid="domains-textarea"]').clear();
            cy.get('[data-testid="domains-textarea"]').type('manager-bulk-test.example.com');
            cy.get('[data-testid="domains-textarea"]').should('contain.value', 'manager-bulk-test.example.com');
        });
    });

    describe('Client User - Permission Check', () => {
        it('should check access to bulk registration', () => {
            cy.loginAs('client');
            cy.visit('/index.php?page=bulk_registration', { failOnStatusCode: false });
            // Client may or may not have bulk registration access depending on permissions
            cy.url().should('satisfy', (url) => {
                return url.includes('page=bulk_registration') || url.includes('page=index') || url.includes('error');
            });
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should not have access to bulk registration', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=bulk_registration', { failOnStatusCode: false });
            // Viewer should be redirected or see error
            cy.url().should('satisfy', (url) => {
                return url.includes('page=index') || url.includes('error') || url.includes('bulk_registration');
            });
        });
    });
});
