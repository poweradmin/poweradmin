import users from '../../fixtures/users.json';

describe('Edit Zone Comment', () => {
    // Note: These tests check the UI elements but use read-only operations
    // to avoid modifying test data

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToZones();
        });

        it('should navigate to edit comment page for a zone', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    // Get first zone ID
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="edit-comment-heading"]').should('be.visible');
                    });
                }
            });
        });

        it('should display edit comment heading with zone name', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="edit-comment-heading"]').should('be.visible');
                        cy.get('[data-testid="edit-comment-heading"]').should('contain', 'Edit comment in zone');
                    });
                }
            });
        });

        it('should display breadcrumb navigation', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
                        cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
                        cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
                    });
                }
            });
        });

        it('should display edit comment form', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="edit-comment-form"]').should('be.visible');
                    });
                }
            });
        });

        it('should display comment textarea', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="comment-textarea"]').should('be.visible');
                    });
                }
            });
        });

        it('should display update button', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="update-comment-button"]').should('be.visible');
                        cy.get('[data-testid="update-comment-button"]').should('have.value', 'Update');
                    });
                }
            });
        });

        it('should display reset button', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="reset-comment-button"]').should('be.visible');
                        cy.get('[data-testid="reset-comment-button"]').should('have.value', 'Reset');
                    });
                }
            });
        });

        it('should allow typing in comment textarea', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);

                        const testComment = 'Test comment text';
                        cy.get('[data-testid="comment-textarea"]').clear();
                        cy.get('[data-testid="comment-textarea"]').type(testComment);
                        cy.get('[data-testid="comment-textarea"]').should('have.value', testComment);
                    });
                }
            });
        });

        it('should reset comment when reset button is clicked', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);

                        // Get original value
                        cy.get('[data-testid="comment-textarea"]').invoke('val').then((originalValue) => {
                            // Type new text
                            cy.get('[data-testid="comment-textarea"]').clear();
                            cy.get('[data-testid="comment-textarea"]').type('New comment');

                            // Click reset
                            cy.get('[data-testid="reset-comment-button"]').click();

                            // Should be back to original
                            cy.get('[data-testid="comment-textarea"]').should('have.value', originalValue);
                        });
                    });
                }
            });
        });

        it('should have multiline textarea', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="comment-textarea"]').should('have.attr', 'rows');
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

        it('should be able to edit comments for own zones', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.goToEditZoneComment(zoneId);
                        cy.get('[data-testid="edit-comment-heading"]').should('be.visible');
                        cy.get('[data-testid="comment-textarea"]').should('be.visible');
                        cy.get('[data-testid="update-comment-button"]').should('be.visible');
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

        it('should display zones list', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToZones();
        });

        it('should display zones list but may have read-only access', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
        });

        it('should have disabled form fields if accessing edit comment', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="zone-row-"]').first().invoke('attr', 'data-testid').then((testid) => {
                        const zoneId = testid.replace('zone-row-', '');
                        cy.visit(`/index.php?page=edit_comment&id=${zoneId}`, { failOnStatusCode: false });

                        cy.get('body').then(($body2) => {
                            if ($body2.find('[data-testid="comment-textarea"]').length > 0) {
                                // If viewer can see the page, textarea should be disabled
                                cy.get('[data-testid="comment-textarea"]').should('be.disabled');
                                cy.get('[data-testid="update-comment-button"]').should('not.exist');
                            }
                        });
                    });
                }
            });
        });
    });
});
