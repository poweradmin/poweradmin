import users from '../../fixtures/users.json';

describe('Edit Permission Template', () => {
    describe('Admin User - Edit Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the edit permission template form', () => {
            cy.goToPermissionTemplates();
            // Get the href from the first edit button and navigate to it
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.url().should('include', 'page=edit_perm_templ');
            cy.get('[data-testid="edit-permission-template-heading"]').should('be.visible');
            cy.get('[data-testid="edit-permission-template-form"]').should('be.visible');
        });

        it('should display form fields with existing data', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });

            cy.get('[data-testid="template-name-input"]').should('be.visible');
            cy.get('[data-testid="template-name-input"]').should('not.have.value', '');
            cy.get('[data-testid="template-description-input"]').should('be.visible');
            cy.get('[data-testid="permissions-table"]').should('be.visible');
            cy.get('[data-testid="submit-template-button"]').should('be.visible');
        });

        it('should display permissions checkboxes', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.get('[data-testid="permissions-tbody"]').should('exist');
            cy.get('[data-testid^="permission-checkbox-"]').should('have.length.at.least', 1);
        });

        it('should update template name successfully', () => {
            // First create a template to edit
            const templateName = `Update Name Test ${Date.now()}`;
            cy.goToAddPermissionTemplate();
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

            // Navigate to list and find the template
            cy.goToPermissionTemplates();
            cy.contains('tr', templateName).within(() => {
                cy.get('[data-testid^="edit-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('editHref');
                });
            });

            cy.get('@editHref').then((href) => {
                cy.visit('/' + href);
            });

            // Update the name using cy.request
            const updatedName = `Updated Name ${Date.now()}`;
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.get('input[name="templ_id"]').invoke('val').then((templId) => {
                    cy.request({
                        method: 'POST',
                        url: '/index.php?page=edit_perm_templ&id=' + templId,
                        form: true,
                        body: {
                            _token: csrfToken,
                            templ_id: templId,
                            templ_name: updatedName,
                            templ_descr: '',
                            commit: 'Update'
                        }
                    });
                });
            });

            cy.goToPermissionTemplates();
            cy.contains(updatedName).should('be.visible');
        });

        it('should update template description successfully', () => {
            // First create a template to edit
            const templateName = `Update Desc Test ${Date.now()}`;
            cy.goToAddPermissionTemplate();
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

            // Navigate to list and find the template
            cy.goToPermissionTemplates();
            cy.contains('tr', templateName).within(() => {
                cy.get('[data-testid^="edit-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('editHref');
                });
            });

            cy.get('@editHref').then((href) => {
                cy.visit('/' + href);
            });

            // Update the description using cy.request
            const updatedDesc = 'Updated description text';
            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.get('input[name="templ_id"]').invoke('val').then((templId) => {
                    cy.request({
                        method: 'POST',
                        url: '/index.php?page=edit_perm_templ&id=' + templId,
                        form: true,
                        body: {
                            _token: csrfToken,
                            templ_id: templId,
                            templ_name: templateName,
                            templ_descr: updatedDesc,
                            commit: 'Update'
                        }
                    });
                });
            });

            cy.goToPermissionTemplates();
            cy.contains(updatedDesc).should('be.visible');
        });

        it('should toggle permissions successfully', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });

            // Toggle first permission checkbox
            cy.get('[data-testid^="permission-checkbox-"]').first().then(($checkbox) => {
                const wasChecked = $checkbox.is(':checked');
                if (wasChecked) {
                    cy.wrap($checkbox).uncheck();
                    cy.wrap($checkbox).should('not.be.checked');
                } else {
                    cy.wrap($checkbox).check();
                    cy.wrap($checkbox).should('be.checked');
                }
            });
        });

        it('should show validation error when clearing required name field', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });

            cy.get('[data-testid="template-name-input"]').clear();
            cy.get('[data-testid="submit-template-button"]').click();

            // HTML5 validation should prevent submission
            cy.get('[data-testid="template-name-input"]:invalid').should('exist');
        });
    });

    describe('Non-Admin User Access', () => {
        it('manager should not have access to edit permission template', () => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
            cy.visit('/index.php?page=edit_perm_templ&id=1');
            cy.get('[data-testid="edit-permission-template-heading"]').should('not.exist');
        });

        it('client should not have access to edit permission template', () => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
            cy.visit('/index.php?page=edit_perm_templ&id=1');
            cy.get('[data-testid="edit-permission-template-heading"]').should('not.exist');
        });

        it('viewer should not have access to edit permission template', () => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
            cy.visit('/index.php?page=edit_perm_templ&id=1');
            cy.get('[data-testid="edit-permission-template-heading"]').should('not.exist');
        });

        it('noperm user should not have access to edit permission template', () => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
            cy.visit('/index.php?page=edit_perm_templ&id=1');
            cy.get('[data-testid="edit-permission-template-heading"]').should('not.exist');
        });
    });
});
