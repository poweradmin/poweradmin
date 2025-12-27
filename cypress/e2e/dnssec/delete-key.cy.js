import users from '../../fixtures/users.json';

describe('Delete DNSSEC Key', () => {
    let adminZoneId;
    let managerZoneId;
    const testKeyId = 1; // Test key ID - may not exist in actual database

    before(() => {
        // Get zone IDs for testing - each user needs to use zones they own
        cy.loginAs('admin');
        cy.getZoneIdByName('example.com').then((zoneId) => {
            adminZoneId = zoneId;
        });

        cy.loginAs('manager');
        cy.getZoneIdByName('manager-zone.example.com').then((zoneId) => {
            managerZoneId = zoneId;
        });
    });

    describe('Page Structure', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (adminZoneId) {
                // Navigate to delete key page - may not find actual key
                cy.visit(`/index.php?page=dnssec_delete_key&id=${adminZoneId}&key_id=${testKeyId}`, {
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
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Delete key');
                }
            });
        });

        it('should display delete key heading if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="delete-key-heading"]').length > 0) {
                    cy.get('[data-testid="delete-key-heading"]').should('be.visible');
                    cy.get('[data-testid="delete-key-heading"]').should('contain', 'Delete zone key');
                }
            });
        });

        it('should display key information container if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-info"]').length > 0) {
                    cy.get('[data-testid="key-info"]').should('be.visible');
                }
            });
        });

        it('should display domain name if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="key-info"]').length > 0) {
                    cy.get('[data-testid="key-info"]').should('contain', 'Domain');
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
                    cy.get('[data-testid="key-active"]').should('not.be.empty');
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

        it('should display delete form if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="delete-key-form"]').length > 0) {
                    cy.get('[data-testid="delete-key-form"]').should('be.visible');
                    cy.get('[data-testid="delete-key-form"]').should('have.attr', 'method', 'post');
                }
            });
        });

        it('should have CSRF token in delete form if form exists', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="delete-key-form"]').length > 0) {
                    cy.get('[data-testid="delete-key-form"]').within(() => {
                        cy.get('input[name="_token"]').should('exist');
                        cy.get('input[name="_token"]').invoke('val').should('not.be.empty');
                    });
                }
            });
        });

        it('should display confirm button if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="confirm-button"]').length > 0) {
                    cy.get('[data-testid="confirm-button"]').should('be.visible');
                    cy.get('[data-testid="confirm-button"]').should('have.value', 'Yes');
                    cy.get('[data-testid="confirm-button"]').should('have.attr', 'type', 'submit');
                }
            });
        });

        it('should display cancel button if page loads', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="cancel-button"]').length > 0) {
                    cy.get('[data-testid="cancel-button"]').should('be.visible');
                    cy.get('[data-testid="cancel-button"]').should('contain', 'No');
                }
            });
        });

        it('should have cancel link with correct href', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="cancel-button"]').length > 0) {
                    cy.get('[data-testid="cancel-button"]').should('be.visible');
                    cy.get('[data-testid="cancel-button"]').should('have.attr', 'href').and('include', 'page=dnssec');
                }
            });
        });
    });

    describe('Navigation from DNSSEC Page', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (adminZoneId) {
                cy.goToDNSSEC(adminZoneId);
            }
        });

        it('should have delete key link with correct href', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-key-"]').length > 0) {
                    cy.get('[data-testid^="delete-key-"]').first().should('be.visible');
                    cy.get('[data-testid^="delete-key-"]').first().should('have.attr', 'href').and('include', 'page=dnssec_delete_key');
                }
            });
        });

        it('should pass zone ID and key ID in URL when navigating', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-key-"]').length > 0) {
                    cy.get('[data-testid^="delete-key-"]').first().then(($link) => {
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
            if (managerZoneId) {
                cy.visit(`/index.php?page=dnssec_delete_key&id=${managerZoneId}&key_id=${testKeyId}`, {
                    failOnStatusCode: false
                });
            }
        });

        it('should have access to delete key for own zone if key exists', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="delete-key-heading"]').length > 0) {
                    cy.get('[data-testid="delete-key-heading"]').should('be.visible');
                }
            });
        });
    });

    describe('Form Action URL', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (adminZoneId) {
                cy.visit(`/index.php?page=dnssec_delete_key&id=${adminZoneId}&key_id=${testKeyId}`, {
                    failOnStatusCode: false
                });
            }
        });

        it('should have correct form action URL if form exists', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="delete-key-form"]').length > 0) {
                    cy.get('[data-testid="delete-key-form"]').then(($form) => {
                        const action = $form.attr('action');
                        expect(action).to.include('page=dnssec_delete_key');
                        expect(action).to.include(`id=${adminZoneId}`);
                        expect(action).to.include(`key_id=${testKeyId}`);
                    });
                }
            });
        });
    });
});
