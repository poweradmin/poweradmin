import users from '../../fixtures/users.json';

describe('Delete Zone Template Record', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the delete record confirmation page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="delete-record-heading"]').should('be.visible');
                            cy.get('[data-testid="record-info-table"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display record information table', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-info-table"]').within(() => {
                                cy.contains('th', 'Name').should('be.visible');
                                cy.contains('th', 'Type').should('be.visible');
                                cy.contains('th', 'Content').should('be.visible');
                                cy.contains('th', 'Priority').should('be.visible');
                                cy.contains('th', 'TTL').should('be.visible');
                            });
                        }
                    });
                }
            });
        });

        it('should display record details in table', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-name"]').should('be.visible');
                            cy.get('[data-testid="record-type"]').should('be.visible');
                            cy.get('[data-testid="record-content"]').should('be.visible');
                            cy.get('[data-testid="record-priority"]').should('be.visible');
                            cy.get('[data-testid="record-ttl"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display confirmation message', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="confirmation-message"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display Yes and No buttons', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="confirm-button"]').should('be.visible');
                            cy.get('[data-testid="cancel-button"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should have cancel button that links to edit template page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);

                        cy.get('body').then(($body) => {
                            if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                                cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                    const href = $link.attr('href');
                                    cy.visit('/' + href);
                                });
                                // Verify the cancel button has the correct onclick handler
                                cy.get('[data-testid="cancel-button"]')
                                    .should('have.attr', 'onclick')
                                    .and('include', 'edit_zone_templ');
                            }
                        });
                    });
                }
            });
        });

        it('should show heading with template name', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="delete-record-heading"]').should('contain', 'Delete record in zone template');
                        }
                    });
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should allow manager to delete records from their templates', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="delete-record-"]').length > 0) {
                            cy.get('[data-testid^="delete-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="delete-record-heading"]').should('be.visible');
                            cy.get('[data-testid="record-info-table"]').should('be.visible');
                        }
                    });
                }
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template record page', () => {
            cy.visit('/index.php?page=delete_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="delete-record-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template record page', () => {
            cy.visit('/index.php?page=delete_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="delete-record-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to delete zone template record page', () => {
            cy.visit('/index.php?page=delete_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="delete-record-heading"]').should('not.exist');
        });
    });
});
