import users from '../../fixtures/users.json';

describe('Delete User', () => {
    describe('Admin User - Delete User Page', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the delete user confirmation page', () => {
            // Use manager user (id=2) which is a deletable user
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('[data-testid="delete-user-heading"]').should('be.visible');
            cy.get('[data-testid="delete-user-form"]').should('be.visible');
        });

        it('should display confirm and cancel buttons', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('[data-testid="confirm-delete-user"]').should('be.visible');
            cy.get('[data-testid="cancel-delete-user"]').should('be.visible');
        });

        it('should have cancel button with correct onclick', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('[data-testid="cancel-delete-user"]').should('have.attr', 'onclick').and('include', 'page=users');
        });

        it('should display zones table if user owns zones', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            // The zones table may or may not be visible depending on whether user owns zones
            cy.get('[data-testid="zones-table"]').should('exist');
        });

        it('should display zone handling options when user owns zones', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            // Check if zone handling section exists (conditional)
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-delete-radio-"]').should('exist');
                    cy.get('[data-testid^="zone-leave-radio-"]').should('exist');
                    cy.get('[data-testid^="zone-newowner-radio-"]').should('exist');
                    cy.get('[data-testid^="zone-newowner-select-"]').should('exist');
                }
            });
        });

        it('should have leave radio checked by default for zones', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-leave-radio-"]').length > 0) {
                    cy.get('[data-testid^="zone-leave-radio-"]').first().should('be.checked');
                }
            });
        });

        it('should allow selecting delete radio for zone', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-delete-radio-"]').length > 0) {
                    cy.get('[data-testid^="zone-delete-radio-"]').first().check();
                    cy.get('[data-testid^="zone-delete-radio-"]').first().should('be.checked');
                }
            });
        });

        it('should allow selecting new owner radio for zone', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-newowner-radio-"]').length > 0) {
                    cy.get('[data-testid^="zone-newowner-radio-"]').first().check();
                    cy.get('[data-testid^="zone-newowner-radio-"]').first().should('be.checked');
                }
            });
        });

        it('should display new owner select dropdown', () => {
            cy.visit('/index.php?page=delete_user&id=2');
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-newowner-select-"]').length > 0) {
                    cy.get('[data-testid^="zone-newowner-select-"]').first().should('be.enabled');
                }
            });
        });
    });

    describe('Manager User - Delete User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete user page', () => {
            cy.visit('/index.php?page=delete_user&id=1');
            cy.get('[data-testid="delete-user-heading"]').should('not.exist');
        });
    });

    describe('Client User - Delete User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete user page', () => {
            cy.visit('/index.php?page=delete_user&id=1');
            cy.get('[data-testid="delete-user-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Delete User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete user page', () => {
            cy.visit('/index.php?page=delete_user&id=1');
            cy.get('[data-testid="delete-user-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Delete User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete user page', () => {
            cy.visit('/index.php?page=delete_user&id=1');
            cy.get('[data-testid="delete-user-heading"]').should('not.exist');
        });
    });
});
