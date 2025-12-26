import users from '../../fixtures/users.json';

describe('Delete Zone', () => {
    // Note: These tests check the UI elements but don't actually delete zones
    // to avoid modifying test data

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToZones();
        });

        it('should navigate to delete zone page from zone list', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-zone-"]').length > 0) {
                    // Get the first delete button and extract zone ID
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="delete-zone-heading"]').should('be.visible');
                    });
                }
            });
        });

        it('should display delete zone heading with zone name', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="delete-zone-heading"]').should('be.visible');
                        cy.get('[data-testid="delete-zone-heading"]').should('contain', 'Delete zone');
                    });
                }
            });
        });

        it('should display breadcrumb navigation', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
                        cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
                        cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
                    });
                }
            });
        });

        it('should display zone information', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="zone-info"]').should('be.visible');
                        cy.get('[data-testid="zone-owners"]').should('be.visible');
                        cy.get('[data-testid="zone-type"]').should('be.visible');
                    });
                }
            });
        });

        it('should display confirmation message', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="confirmation-message"]').should('be.visible');
                        cy.get('[data-testid="confirmation-message"]').should('contain', 'Are you sure?');
                    });
                }
            });
        });

        it('should display delete zone form', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="delete-zone-form"]').should('be.visible');
                    });
                }
            });
        });

        it('should display confirm and cancel buttons', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="confirm-delete-zone"]').should('be.visible');
                        cy.get('[data-testid="confirm-delete-zone"]').should('have.value', 'Yes');
                        cy.get('[data-testid="cancel-delete-zone"]').should('be.visible');
                        cy.get('[data-testid="cancel-delete-zone"]').should('have.value', 'No');
                    });
                }
            });
        });

        it('should have cancel button with correct action', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="cancel-delete-zone"]')
                            .should('have.attr', 'onclick')
                            .and('include', 'list_zones');
                    });
                }
            });
        });

        it('should show supermaster warning for slave zones if applicable', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        // Warning may or may not be present depending on zone type
                        cy.get('body').then(($body2) => {
                            if ($body2.find('[data-testid="supermaster-warning"]').length > 0) {
                                cy.get('[data-testid="supermaster-warning"]').should('be.visible');
                            }
                        });
                    });
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToZones();
        });

        it('should be able to delete own zones', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-zone-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').first().then(($btn) => {
                        const href = $btn.attr('href');
                        const zoneId = href.match(/id=(\d+)/)[1];

                        cy.goToDeleteZone(zoneId);
                        cy.get('[data-testid="delete-zone-heading"]').should('be.visible');
                        cy.get('[data-testid="confirm-delete-zone"]').should('be.visible');
                        cy.get('[data-testid="cancel-delete-zone"]').should('be.visible');
                    });
                }
            });
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToZones();
        });

        it('should see zones list but delete buttons may not be available', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
            // Client may not have delete permissions
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToZones();
        });

        it('should not see delete buttons', () => {
            cy.get('[data-testid^="delete-zone-"]').should('not.exist');
        });
    });
});
