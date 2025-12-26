import users from '../../fixtures/users.json';

describe('Delete Zone Template', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the delete confirmation page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="delete-template-heading"]').should('be.visible');
                    cy.get('[data-testid="confirmation-message"]').should('be.visible');
                }
            });
        });

        it('should display Yes and No buttons', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="confirm-button"]').should('be.visible');
                    cy.get('[data-testid="cancel-button"]').should('be.visible');
                }
            });
        });

        it('should have cancel button that links to list page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    // Verify the cancel button has the correct onclick handler
                    cy.get('[data-testid="cancel-button"]')
                        .should('have.attr', 'onclick')
                        .and('include', 'list_zone_templ');
                }
            });
        });

        it('should show template name in heading', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="delete-template-heading"]').should('contain', 'Delete zone template');
                }
            });
        });

        it('should display confirmation message asking for confirmation', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="confirmation-message"]').should('be.visible');
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should allow manager to delete their own templates', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="delete-template-heading"]').should('be.visible');
                    cy.get('[data-testid="confirm-button"]').should('be.visible');
                    cy.get('[data-testid="cancel-button"]').should('be.visible');
                }
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template page', () => {
            cy.visit('/index.php?page=delete_zone_templ&id=1');
            cy.get('[data-testid="delete-template-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template page', () => {
            cy.visit('/index.php?page=delete_zone_templ&id=1');
            cy.get('[data-testid="delete-template-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template page', () => {
            cy.visit('/index.php?page=delete_zone_templ&id=1');
            cy.get('[data-testid="delete-template-heading"]').should('not.exist');
        });
    });
});
