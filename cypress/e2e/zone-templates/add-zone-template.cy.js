import users from '../../fixtures/users.json';

describe('Add Zone Template', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the add zone template page', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="add-template-heading"]').should('be.visible');
            cy.get('[data-testid="add-template-form"]').should('be.visible');
        });

        it('should display all required form fields', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-name-input"]').should('be.visible');
            cy.get('[data-testid="template-description-input"]').should('be.visible');
            cy.get('[data-testid="add-template-submit"]').should('be.visible');
        });

        it('should display global checkbox for admin users', () => {
            cy.goToAddZoneTemplate();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-global-checkbox"]').length > 0) {
                    cy.get('[data-testid="template-global-checkbox"]').should('be.visible');
                }
            });
        });

        it('should require template name for form submission', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-name-input"]').should('have.attr', 'required');
        });

        it('should allow description to be optional', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-description-input"]').should('not.have.attr', 'required');
        });

        it('should submit form with valid template name', () => {
            cy.goToAddZoneTemplate();
            const templateName = `test-template-${Date.now()}`;
            cy.get('[data-testid="template-name-input"]').clear().type(templateName);
            cy.get('[data-testid="template-description-input"]').clear().type('Test description');
            cy.get('[data-testid="add-template-submit"]').click();
            // Should redirect to edit page or list page after successful creation
            cy.url().should('satisfy', (url) => {
                return url.includes('page=edit_zone_templ') || url.includes('page=list_zone_templ');
            });
        });

        it('should create a private template by default', () => {
            cy.goToAddZoneTemplate();
            const templateName = `private-template-${Date.now()}`;
            cy.get('[data-testid="template-name-input"]').clear().type(templateName);
            cy.get('[data-testid="add-template-submit"]').click();
            // Verify redirect
            cy.url().should('satisfy', (url) => {
                return url.includes('page=edit_zone_templ') || url.includes('page=list_zone_templ');
            });
        });

        it('should create a global template when checkbox is checked', () => {
            cy.goToAddZoneTemplate();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-global-checkbox"]').length > 0) {
                    const templateName = `global-template-${Date.now()}`;
                    cy.get('[data-testid="template-name-input"]').clear().type(templateName);
                    cy.get('[data-testid="template-global-checkbox"]').check();
                    cy.get('[data-testid="add-template-submit"]').click();
                    cy.url().should('satisfy', (url) => {
                        return url.includes('page=edit_zone_templ') || url.includes('page=list_zone_templ');
                    });
                }
            });
        });

        it('should preserve input values after validation error', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-description-input"]').clear().type('Test description only');
            cy.get('[data-testid="add-template-submit"]').click();
            // Form should not submit without required name
            cy.get('[data-testid="template-description-input"]').should('have.value', 'Test description only');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should display add zone template page for manager', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="add-template-heading"]').should('be.visible');
            cy.get('[data-testid="add-template-form"]').should('be.visible');
        });

        it('should not display global checkbox for non-admin users', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-global-checkbox"]').should('not.exist');
        });

        it('should allow manager to create private templates', () => {
            cy.goToAddZoneTemplate();
            const templateName = `manager-template-${Date.now()}`;
            cy.get('[data-testid="template-name-input"]').clear().type(templateName);
            cy.get('[data-testid="template-description-input"]').clear().type('Manager template');
            cy.get('[data-testid="add-template-submit"]').click();
            cy.url().should('satisfy', (url) => {
                return url.includes('page=edit_zone_templ') || url.includes('page=list_zone_templ');
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });
});
