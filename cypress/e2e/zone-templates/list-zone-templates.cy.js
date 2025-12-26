import users from '../../fixtures/users.json';

describe('List Zone Templates', () => {
    describe('Admin User Access', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the zone templates list page', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="zone-templates-heading"]').should('be.visible');
            cy.get('[data-testid="zone-templates-table"]').should('be.visible');
        });

        it('should display the add zone template button', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="add-zone-template-button"]').should('be.visible');
        });

        it('should display zone templates table with headers', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="zone-templates-table"]').within(() => {
                cy.contains('th', 'Name').should('be.visible');
                cy.contains('th', 'Description').should('be.visible');
                cy.contains('th', 'Type').should('be.visible');
                cy.contains('th', 'Zones linked').should('be.visible');
            });
        });

        it('should display template rows if templates exist', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="template-row-"]').length > 0) {
                    cy.get('[data-testid^="template-row-"]').should('have.length.at.least', 1);
                }
            });
        });

        it('should display edit and delete buttons for each template', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="template-row-"]').length > 0) {
                    cy.get('[data-testid^="template-row-"]').first().within(() => {
                        cy.get('[data-testid^="edit-template-"]').should('be.visible');
                        cy.get('[data-testid^="delete-template-"]').should('be.visible');
                    });
                }
            });
        });

        it('should display template name for each template', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="template-name-"]').length > 0) {
                    cy.get('[data-testid^="template-name-"]').first().should('be.visible');
                }
            });
        });

        it('should display template type (private/global)', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="template-type-"]').length > 0) {
                    cy.get('[data-testid^="template-type-"]').first().should('be.visible');
                }
            });
        });

        it('should display zones linked count', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="template-zones-linked-"]').length > 0) {
                    cy.get('[data-testid^="template-zones-linked-"]').first().should('be.visible');
                }
            });
        });

        it('should navigate to add template page from button', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="add-zone-template-button"]').then(($link) => {
                const href = $link.attr('href');
                cy.visit('/' + href);
            });
            cy.url().should('include', 'page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('be.visible');
        });

        it('should navigate to edit page when clicking edit button', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.url().should('include', 'page=edit_zone_templ');
                    cy.get('[data-testid="edit-template-heading"]').should('be.visible');
                }
            });
        });

        it('should navigate to delete page when clicking delete button', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-template-"]').length > 0) {
                    cy.get('[data-testid^="delete-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.url().should('include', 'page=delete_zone_templ');
                    cy.get('[data-testid="delete-template-heading"]').should('be.visible');
                }
            });
        });
    });

    describe('Manager User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should display the zone templates list page for manager', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="zone-templates-heading"]').should('be.visible');
            cy.get('[data-testid="zone-templates-table"]').should('be.visible');
        });

        it('should display add template button for manager', () => {
            cy.goToZoneTemplates();
            cy.get('[data-testid="add-zone-template-button"]').should('be.visible');
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to zone templates page', () => {
            cy.visit('/index.php?page=list_zone_templ');
            cy.get('[data-testid="zone-templates-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to zone templates page', () => {
            cy.visit('/index.php?page=list_zone_templ');
            cy.get('[data-testid="zone-templates-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to zone templates page', () => {
            cy.visit('/index.php?page=list_zone_templ');
            cy.get('[data-testid="zone-templates-heading"]').should('not.exist');
        });
    });

    describe('Inactive User Access', () => {
        it('should not be able to login with inactive account', () => {
            cy.visit('/index.php?page=login');
            cy.login(users.inactive.username, users.inactive.password);
            cy.url().should('include', '/index.php?page=login');
            cy.get('[data-testid="session-error"]').should('be.visible');
        });
    });
});
