import users from '../../fixtures/users.json';

describe('Add Zone Template Record', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the add record page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-heading"]').should('be.visible');
                    cy.get('[data-testid="add-record-form"]').should('be.visible');
                }
            });
        });

        it('should display all required form fields', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-name-input"]').should('be.visible');
                    cy.get('[data-testid="record-type-select"]').should('be.visible');
                    cy.get('[data-testid="record-content-input"]').should('be.visible');
                    cy.get('[data-testid="record-priority-input"]').should('be.visible');
                    cy.get('[data-testid="record-ttl-input"]').should('be.visible');
                    cy.get('[data-testid="add-record-submit"]').should('be.visible');
                }
            });
        });

        it('should display template hints section', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="template-hints"]').should('be.visible');
                }
            });
        });

        it('should require name field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-name-input"]').should('have.attr', 'required');
                }
            });
        });

        it('should require content field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-content-input"]').should('have.attr', 'required');
                }
            });
        });

        it('should require TTL field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'required');
                }
            });
        });

        it('should display record type options', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-type-select"]').find('option').should('have.length.at.least', 1);
                }
            });
        });

        it('should have numeric constraints on priority field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-priority-input"]').should('have.attr', 'type', 'number');
                    cy.get('[data-testid="record-priority-input"]').should('have.attr', 'min', '0');
                    cy.get('[data-testid="record-priority-input"]').should('have.attr', 'max', '65535');
                }
            });
        });

        it('should have numeric constraints on TTL field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'type', 'number');
                    cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'min', '0');
                    cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'max', '2147483647');
                }
            });
        });

        it('should show template placeholder hints', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="template-hints"]').should('contain', '[ZONE]');
                    cy.get('[data-testid="template-hints"]').should('contain', '[SERIAL]');
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should allow manager to add records to their templates', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-button"]').then(($button) => {
                        const href = $button.attr('onclick').match(/'([^']+)'/)[1];
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="add-record-heading"]').should('be.visible');
                    cy.get('[data-testid="add-record-form"]').should('be.visible');
                }
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template record page', () => {
            cy.visit('/index.php?page=add_zone_templ_record&id=1');
            cy.get('[data-testid="add-record-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template record page', () => {
            cy.visit('/index.php?page=add_zone_templ_record&id=1');
            cy.get('[data-testid="add-record-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add zone template record page', () => {
            cy.visit('/index.php?page=add_zone_templ_record&id=1');
            cy.get('[data-testid="add-record-heading"]').should('not.exist');
        });
    });
});
