import users from '../../fixtures/users.json';

describe('Delete Permission Template', () => {
    describe('Admin User - Delete Permission Template', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should display the delete confirmation page', () => {
            // First create a template to delete
            const templateName = `Delete Test ${Date.now()}`;
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
                cy.get('[data-testid^="delete-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('deleteHref');
                });
            });

            cy.get('@deleteHref').then((href) => {
                cy.visit('/' + href);
            });

            cy.url().should('include', 'page=delete_perm_templ');
            cy.get('[data-testid="delete-permission-template-heading"]').should('be.visible');
            cy.get('[data-testid="delete-confirmation-text"]').should('be.visible');
            cy.get('[data-testid="confirm-delete-template"]').should('be.visible');
            cy.get('[data-testid="cancel-delete-template"]').should('be.visible');
        });

        it('should show the template name in the delete confirmation', () => {
            // First create a template to delete
            const templateName = `Confirm Delete ${Date.now()}`;
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
                cy.get('[data-testid^="delete-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('deleteHref');
                });
            });

            cy.get('@deleteHref').then((href) => {
                cy.visit('/' + href);
            });

            cy.get('[data-testid="delete-permission-template-heading"]').should('contain', templateName);
        });

        it('should delete the template when confirming', () => {
            // First create a template to delete
            const templateName = `To Be Deleted ${Date.now()}`;
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
                cy.get('[data-testid^="delete-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('deleteHref');
                });
            });

            cy.get('@deleteHref').then((href) => {
                cy.visit('/' + href);
            });

            // Get template ID from URL and confirm deletion via request
            cy.url().then((url) => {
                const match = url.match(/id=(\d+)/);
                if (match) {
                    const templateId = match[1];
                    cy.request({
                        method: 'GET',
                        url: `/index.php?page=delete_perm_templ&id=${templateId}&confirm=1`
                    });
                }
            });

            // Navigate to list and verify template is gone
            cy.goToPermissionTemplates();
            cy.contains(templateName).should('not.exist');
        });

        it('should cancel deletion and return to list when clicking No', () => {
            // First create a template
            const templateName = `Cancel Delete ${Date.now()}`;
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
                cy.get('[data-testid^="delete-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    cy.wrap(href).as('deleteHref');
                });
            });

            cy.get('@deleteHref').then((href) => {
                cy.visit('/' + href);
            });

            // Cancel deletion by navigating back to list
            cy.goToPermissionTemplates();

            // Template should still exist
            cy.contains(templateName).should('be.visible');

            // Cleanup: delete the template
            cy.contains('tr', templateName).within(() => {
                cy.get('[data-testid^="delete-template-"]').then(($link) => {
                    const href = $link.attr('href');
                    const match = href.match(/id=(\d+)/);
                    if (match) {
                        const templateId = match[1];
                        cy.request({
                            method: 'GET',
                            url: `/index.php?page=delete_perm_templ&id=${templateId}&confirm=1`
                        });
                    }
                });
            });
        });
    });

    describe('Non-Admin User Access', () => {
        it('manager should not have access to delete permission template', () => {
            cy.loginAs('manager');
            cy.visit('/index.php?page=delete_perm_templ&id=1');
            cy.get('[data-testid="delete-permission-template-heading"]').should('not.exist');
        });

        it('client should not have access to delete permission template', () => {
            cy.loginAs('client');
            cy.visit('/index.php?page=delete_perm_templ&id=1');
            cy.get('[data-testid="delete-permission-template-heading"]').should('not.exist');
        });

        it('viewer should not have access to delete permission template', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=delete_perm_templ&id=1');
            cy.get('[data-testid="delete-permission-template-heading"]').should('not.exist');
        });

        it('noperm user should not have access to delete permission template', () => {
            cy.loginAs('noperm');
            cy.visit('/index.php?page=delete_perm_templ&id=1');
            cy.get('[data-testid="delete-permission-template-heading"]').should('not.exist');
        });
    });
});
