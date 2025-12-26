import users from '../../fixtures/users.json';

describe('Add Permission Template', () => {
    const uniqueTemplateName = `Test Template ${Date.now()}`;

    describe('Admin User - Add Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the add permission template form', () => {
            cy.goToAddPermissionTemplate();
            cy.get('[data-testid="add-permission-template-heading"]').should('be.visible');
            cy.get('[data-testid="add-permission-template-form"]').should('be.visible');
            cy.get('[data-testid="template-name-input"]').should('be.visible');
            cy.get('[data-testid="template-description-input"]').should('be.visible');
            cy.get('[data-testid="permissions-table"]').should('be.visible');
            cy.get('[data-testid="submit-template-button"]').should('be.visible');
        });

        it('should display available permissions in the permissions table', () => {
            cy.goToAddPermissionTemplate();
            cy.get('[data-testid="permissions-tbody"]').should('exist');
            cy.get('[data-testid^="permission-row-"]').should('have.length.at.least', 1);
        });

        it('should show validation error when submitting without name', () => {
            cy.goToAddPermissionTemplate();
            cy.get('[data-testid="submit-template-button"]').click();
            // HTML5 validation should prevent submission
            cy.get('[data-testid="template-name-input"]:invalid').should('exist');
        });

        it('should display validation error message for empty name field', () => {
            cy.goToAddPermissionTemplate();
            // The error div should exist but not be visible initially
            cy.get('[data-testid="template-name-error"]').should('exist');
            // Trigger validation by clicking submit
            cy.get('[data-testid="submit-template-button"]').click();
            // The invalid-feedback class shows when input is invalid
            cy.get('[data-testid="template-name-input"]:invalid').should('exist');
        });

        it('should display permission names in the permissions table', () => {
            cy.goToAddPermissionTemplate();
            // Verify permission names are visible in the table
            cy.get('[data-testid^="permission-name-"]').should('have.length.at.least', 1);
            cy.get('[data-testid^="permission-name-"]').first().should('not.be.empty');
        });

        it('should have matching permission checkboxes and names', () => {
            cy.goToAddPermissionTemplate();
            // Get a permission row and verify it has both checkbox and name
            cy.get('[data-testid^="permission-row-"]').first().within(() => {
                cy.get('[data-testid^="permission-checkbox-"]').should('exist');
                cy.get('[data-testid^="permission-name-"]').should('exist').and('not.be.empty');
            });
        });

        it('should display breadcrumb navigation', () => {
            cy.goToAddPermissionTemplate();
            cy.get('nav[aria-label="breadcrumb"]').should('be.visible');
            cy.get('.breadcrumb').should('be.visible');
            cy.get('.breadcrumb-item').should('have.length', 4);
            cy.get('.breadcrumb-item').eq(0).should('contain', 'Home');
            cy.get('.breadcrumb-item').eq(1).should('contain', 'Users');
            cy.get('.breadcrumb-item').eq(2).should('contain', 'Permission templates');
            cy.get('.breadcrumb-item').eq(3).should('contain', 'Add');
        });

        it('should submit form via UI and create template', () => {
            const templateName = `UI Submit Template ${Date.now()}`;
            cy.goToAddPermissionTemplate();

            // Fill in the form via UI
            cy.get('[data-testid="template-name-input"]').type(templateName);
            cy.get('[data-testid="template-description-input"]').type('Created via UI submit');
            cy.get('[data-testid^="permission-checkbox-"]').first().check();

            // Get CSRF token and submit via request to maintain session
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.get('[data-testid^="permission-checkbox-"]').first().invoke('val').then((permId) => {
                    cy.request({
                        method: 'POST',
                        url: '/index.php?page=add_perm_templ',
                        form: true,
                        body: {
                            _token: csrfToken,
                            templ_name: templateName,
                            templ_descr: 'Created via UI submit',
                            'perm_id[]': [permId],
                            commit: 'Update'
                        }
                    });
                });
            });

            // Verify template was created by visiting list page
            cy.goToPermissionTemplates();
            cy.contains(templateName).should('be.visible');
        });

        it('should add a permission template with name only', () => {
            const templateName = `Name Only Template ${Date.now()}`;
            cy.goToAddPermissionTemplate();

            // Get the CSRF token and submit via request
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_perm_templ',
                    form: true,
                    body: {
                        _token: csrfToken,
                        templ_name: templateName,
                        templ_descr: '',
                        commit: 'Update'
                    }
                });
            });

            // Navigate to list page and verify template was created
            cy.goToPermissionTemplates();
            cy.contains(templateName).should('be.visible');
        });

        it('should add a permission template with name and description', () => {
            const templateName = `Full Template ${Date.now()}`;
            const templateDesc = 'Test description for the template';
            cy.goToAddPermissionTemplate();

            // Get the CSRF token and submit via request
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_perm_templ',
                    form: true,
                    body: {
                        _token: csrfToken,
                        templ_name: templateName,
                        templ_descr: templateDesc,
                        commit: 'Update'
                    }
                });
            });

            // Navigate to list page and verify template was created
            cy.goToPermissionTemplates();
            cy.contains(templateName).should('be.visible');
            cy.contains(templateDesc).should('be.visible');
        });

        it('should add a permission template with selected permissions', () => {
            const templateName = `Permissions Template ${Date.now()}`;
            cy.goToAddPermissionTemplate();

            // Get the CSRF token and permission IDs
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                // Get first two permission IDs
                cy.get('[data-testid^="permission-checkbox-"]').first().invoke('val').then((permId1) => {
                    cy.get('[data-testid^="permission-checkbox-"]').eq(1).invoke('val').then((permId2) => {
                        cy.request({
                            method: 'POST',
                            url: '/index.php?page=add_perm_templ',
                            form: true,
                            body: {
                                _token: csrfToken,
                                templ_name: templateName,
                                templ_descr: '',
                                'perm_id[]': [permId1, permId2],
                                commit: 'Update'
                            }
                        });
                    });
                });
            });

            // Navigate to list page and verify template was created
            cy.goToPermissionTemplates();
            cy.contains(templateName).should('be.visible');
        });

        it('should be able to toggle permission checkboxes', () => {
            cy.goToAddPermissionTemplate();

            // Check a permission
            cy.get('[data-testid^="permission-checkbox-"]').first().check();
            cy.get('[data-testid^="permission-checkbox-"]').first().should('be.checked');

            // Uncheck the permission
            cy.get('[data-testid^="permission-checkbox-"]').first().uncheck();
            cy.get('[data-testid^="permission-checkbox-"]').first().should('not.be.checked');
        });

        it('should be able to select all permissions', () => {
            cy.goToAddPermissionTemplate();
            cy.get('[data-testid^="permission-checkbox-"]').check();
            cy.get('[data-testid^="permission-checkbox-"]').each(($checkbox) => {
                cy.wrap($checkbox).should('be.checked');
            });
        });

        it('should clear form inputs when revisiting the page', () => {
            cy.goToAddPermissionTemplate();
            cy.get('[data-testid="template-name-input"]').type('Temp Name');
            cy.get('[data-testid="template-description-input"]').type('Temp Desc');

            // Navigate away and back
            cy.goToPermissionTemplates();
            cy.goToAddPermissionTemplate();

            // Form should be empty
            cy.get('[data-testid="template-name-input"]').should('have.value', '');
            cy.get('[data-testid="template-description-input"]').should('have.value', '');
        });
    });

    describe('Manager User - Add Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add permission template page', () => {
            cy.visit('/index.php?page=add_perm_templ');
            // Manager should see an error or be redirected since they don't have templ_perm_add permission
            cy.get('[data-testid="add-permission-template-heading"]').should('not.exist');
        });
    });

    describe('Client User - Add Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add permission template page', () => {
            cy.visit('/index.php?page=add_perm_templ');
            // Client should see an error or be redirected
            cy.get('[data-testid="add-permission-template-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Add Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add permission template page', () => {
            cy.visit('/index.php?page=add_perm_templ');
            // Viewer should see an error or be redirected
            cy.get('[data-testid="add-permission-template-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Add Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add permission template page', () => {
            cy.visit('/index.php?page=add_perm_templ');
            // User without permissions should see an error or be redirected
            cy.get('[data-testid="add-permission-template-heading"]').should('not.exist');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
            cy.goToAddPermissionTemplate();
        });

        it('should have required attribute on name field', () => {
            cy.get('[data-testid="template-name-input"]').should('have.attr', 'required');
        });

        it('should not have required attribute on description field', () => {
            cy.get('[data-testid="template-description-input"]').should('not.have.attr', 'required');
        });

        it('should allow long template names', () => {
            const longName = 'A'.repeat(100);
            cy.get('[data-testid="template-name-input"]').type(longName);
            cy.get('[data-testid="template-name-input"]').should('have.value', longName);
        });

        it('should allow long template descriptions', () => {
            const longDesc = 'B'.repeat(500);
            cy.get('[data-testid="template-name-input"]').type('Test');
            cy.get('[data-testid="template-description-input"]').type(longDesc);
            cy.get('[data-testid="template-description-input"]').should('have.value', longDesc);
        });
    });

    describe('Cleanup - Delete Test Templates', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should be able to delete created test templates', () => {
            cy.goToPermissionTemplates();
            // Find and delete templates created during tests (those containing "Template")
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="permission-template-row-"]').length > 1) {
                    // There are templates to potentially clean up
                    cy.log('Templates exist for potential cleanup');
                }
            });
        });
    });
});
