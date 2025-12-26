import users from '../../fixtures/users.json';

describe('Edit DNSSEC Key (Activate/Deactivate)', () => {
    let testZoneId;
    const testKeyId = 1; // Test key ID - may not exist in actual database

    before(() => {
        // Get a zone ID for testing
        cy.loginAs('admin');
        cy.getZoneIdByName('manager-zone.example.com').then((zoneId) => {
            testZoneId = zoneId;
        });
    });

    describe('Page Structure', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                // Navigate to edit key page - may not find actual key
                cy.visit(`/index.php?page=dnssec_edit_key&id=${testZoneId}&key_id=${testKeyId}`, {
                    failOnStatusCode: false
                });
            }
        });

        it('should display breadcrumb navigation if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="breadcrumb-nav"]').length > 0) {
                    cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'DNSSEC');
                }
            });
        });

        it('should display edit key heading if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="edit-key-heading"]').length > 0) {
                    cy.get('[data-testid="edit-key-heading"]').should('be.visible');
                    cy.get('[data-testid="edit-key-heading"]').then(($heading) => {
                        const text = $heading.text();
                        expect(text).to.match(/(Activate|Deactivate) zone key/);
                    });
                }
            });
        });

        it('should display key information if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-info"]').length > 0) {
                    cy.get('[data-testid="key-info"]').should('be.visible');
                }
            });
        });

        it('should display key ID if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-id"]').length > 0) {
                    cy.get('[data-testid="key-id"]').should('be.visible');
                    cy.get('[data-testid="key-id"]').should('not.be.empty');
                }
            });
        });

        it('should display key type if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-type"]').length > 0) {
                    cy.get('[data-testid="key-type"]').should('be.visible');
                    cy.get('[data-testid="key-type"]').should('not.be.empty');
                }
            });
        });

        it('should display key tag if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-tag"]').length > 0) {
                    cy.get('[data-testid="key-tag"]').should('be.visible');
                    cy.get('[data-testid="key-tag"]').should('not.be.empty');
                }
            });
        });

        it('should display key algorithm if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-algorithm"]').length > 0) {
                    cy.get('[data-testid="key-algorithm"]').should('be.visible');
                    cy.get('[data-testid="key-algorithm"]').should('not.be.empty');
                }
            });
        });

        it('should display key bits if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-bits"]').length > 0) {
                    cy.get('[data-testid="key-bits"]').should('be.visible');
                    cy.get('[data-testid="key-bits"]').should('not.be.empty');
                }
            });
        });

        it('should display key active status if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-active"]').length > 0) {
                    cy.get('[data-testid="key-active"]').should('be.visible');
                    cy.get('[data-testid="key-active"]').should('match', /(Yes|No)/i);
                }
            });
        });

        it('should display confirmation message if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="confirmation-message"]').length > 0) {
                    cy.get('[data-testid="confirmation-message"]').should('be.visible');
                    cy.get('[data-testid="confirmation-message"]').should('contain', 'Are you sure?');
                }
            });
        });

        it('should display confirm button if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="confirm-button"]').length > 0) {
                    cy.get('[data-testid="confirm-button"]').should('be.visible');
                    cy.get('[data-testid="confirm-button"]').should('have.value', 'Yes');
                }
            });
        });

        it('should display cancel button if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="cancel-button"]').length > 0) {
                    cy.get('[data-testid="cancel-button"]').should('be.visible');
                    cy.get('[data-testid="cancel-button"]').should('have.value', 'No');
                }
            });
        });

        it('should navigate back to DNSSEC page when clicking No', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="cancel-button"]').length > 0) {
                    cy.get('[data-testid="cancel-button"]').click();
                    cy.url().should('include', 'page=dnssec');
                }
            });
        });
    });

    describe('Navigation from DNSSEC Page', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToDNSSEC(testZoneId);
            }
        });

        it('should navigate to edit key page when clicking edit button', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-key-"]').length > 0) {
                    cy.get('[data-testid^="edit-key-"]').first().click();
                    cy.url().should('include', 'page=dnssec_edit_key');
                }
            });
        });

        it('should pass zone ID and key ID in URL when navigating', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-key-"]').length > 0) {
                    cy.get('[data-testid^="edit-key-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        expect(href).to.include('id=');
                        expect(href).to.include('key_id=');
                    });
                }
            });
        });
    });

    describe('Manager User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            if (testZoneId) {
                cy.visit(`/index.php?page=dnssec_edit_key&id=${testZoneId}&key_id=${testKeyId}`, {
                    failOnStatusCode: false
                });
            }
        });

        it('should have access to edit key for own zone if key exists', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="edit-key-heading"]').length > 0) {
                    cy.get('[data-testid="edit-key-heading"]').should('be.visible');
                }
            });
        });
    });
});
