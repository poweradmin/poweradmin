import users from '../../fixtures/users.json';

describe('List Permission Templates', () => {
    describe('Admin User Access', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the permission templates list page', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid="permission-templates-heading"]').should('be.visible');
            cy.get('[data-testid="permission-templates-table"]').should('be.visible');
        });

        it('should display the add permission template button for admin', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid="add-permission-template-link"]').should('be.visible');
        });

        it('should display existing permission templates in the table', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid="permission-templates-tbody"]').should('exist');
            cy.get('[data-testid^="permission-template-row-"]').should('have.length.at.least', 1);
        });

        it('should display edit and delete buttons for each template', () => {
            cy.goToPermissionTemplates();
            cy.get('[data-testid^="permission-template-row-"]').first().within(() => {
                cy.get('[data-testid^="edit-template-"]').should('be.visible');
                cy.get('[data-testid^="delete-template-"]').should('be.visible');
            });
        });

        it('should navigate to edit page when clicking edit button', () => {
            cy.goToPermissionTemplates();
            // Get the href from the first edit button and navigate to it
            cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.url().should('include', 'page=edit_perm_templ');
            cy.get('[data-testid="edit-permission-template-heading"]').should('be.visible');
        });

        it('should navigate to delete confirmation page when clicking delete button', () => {
            cy.goToPermissionTemplates();
            // Get the href from the first delete button and navigate to it
            cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.url().should('include', 'page=delete_perm_templ');
            cy.get('[data-testid="delete-permission-template-heading"]').should('be.visible');
        });

        it('should navigate to add permission template page from list page', () => {
            cy.goToPermissionTemplates();
            // Get the href from the add button and navigate to it
            cy.get('[data-testid="add-permission-template-link"]').then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.url().should('include', 'page=add_perm_templ');
            cy.get('[data-testid="add-permission-template-heading"]').should('be.visible');
        });
    });

    describe('Manager User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to permission templates page', () => {
            cy.visit('/index.php?page=list_perm_templ');
            // Manager should see an error or be redirected since they don't have templ_perm_edit permission
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to permission templates page', () => {
            cy.visit('/index.php?page=list_perm_templ');
            // Client should see an error or be redirected since they don't have templ_perm_edit permission
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to permission templates page', () => {
            cy.visit('/index.php?page=list_perm_templ');
            // Viewer should see an error or be redirected since they don't have templ_perm_edit permission
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to permission templates page', () => {
            cy.visit('/index.php?page=list_perm_templ');
            // User without permissions should see an error or be redirected
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });
    });

    describe('Inactive User Access', () => {
        it('should not be able to login with inactive account', () => {
            cy.visit('/index.php?page=login');
            cy.login(users.inactive.username, users.inactive.password);
            // Inactive user should not be able to login
            cy.url().should('include', '/index.php?page=login');
            cy.get('[data-testid="session-error"]').should('be.visible');
        });
    });
});
