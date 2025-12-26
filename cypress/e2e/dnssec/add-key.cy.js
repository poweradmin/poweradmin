import users from '../../fixtures/users.json';

describe('Add DNSSEC Key', () => {
    let testZoneId;

    before(() => {
        // Get a zone ID for testing
        cy.loginAs('admin');
        cy.getZoneIdByName('manager-zone.example.com').then((zoneId) => {
            testZoneId = zoneId;
        });
    });

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToAddDNSSECKey(testZoneId);
            }
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'DNSSEC');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Add key');
        });

        it('should display add key heading', () => {
            cy.get('[data-testid="add-key-heading"]').should('be.visible');
            cy.get('[data-testid="add-key-heading"]').should('contain', 'Add key for zone');
        });

        it('should display zone name in heading', () => {
            cy.get('[data-testid="add-key-heading"]').then(($heading) => {
                const text = $heading.text();
                expect(text).to.match(/"[^"]+"/); // Should contain quoted zone name
            });
        });

        it('should display add key form', () => {
            cy.get('[data-testid="add-key-form"]').should('be.visible');
            cy.get('[data-testid="add-key-form"]').should('have.attr', 'method', 'post');
        });

        it('should have CSRF token in form', () => {
            cy.get('[data-testid="add-key-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
                cy.get('input[name="_token"]').should('have.value');
            });
        });

        it('should display key type select', () => {
            cy.get('[data-testid="key-type-select"]').should('be.visible');
            cy.get('[data-testid="key-type-select"]').should('have.attr', 'required');
        });

        it('should have KSK and ZSK options for key type', () => {
            cy.get('[data-testid="key-type-select"]').find('option[value="ksk"]').should('exist');
            cy.get('[data-testid="key-type-select"]').find('option[value="zsk"]').should('exist');
        });

        it('should display bits select', () => {
            cy.get('[data-testid="bits-select"]').should('be.visible');
            cy.get('[data-testid="bits-select"]').should('have.attr', 'required');
        });

        it('should have standard bit length options', () => {
            cy.get('[data-testid="bits-select"]').within(() => {
                cy.contains('option', '2048').should('exist');
                cy.contains('option', '1024').should('exist');
                cy.contains('option', '768').should('exist');
                cy.contains('option', '384').should('exist');
                cy.contains('option', '256').should('exist');
            });
        });

        it('should display algorithm select', () => {
            cy.get('[data-testid="algorithm-select"]').should('be.visible');
            cy.get('[data-testid="algorithm-select"]').should('have.attr', 'required');
        });

        it('should have algorithm options', () => {
            cy.get('[data-testid="algorithm-select"]').find('option').should('have.length.at.least', 2);
        });

        it('should display Add key submit button', () => {
            cy.get('[data-testid="add-key-submit"]').should('be.visible');
            cy.get('[data-testid="add-key-submit"]').should('have.value', 'Add key');
        });

        it('should allow selecting key type KSK', () => {
            cy.get('[data-testid="key-type-select"]').select('ksk');
            cy.get('[data-testid="key-type-select"]').should('have.value', 'ksk');
        });

        it('should allow selecting key type ZSK', () => {
            cy.get('[data-testid="key-type-select"]').select('zsk');
            cy.get('[data-testid="key-type-select"]').should('have.value', 'zsk');
        });

        it('should allow selecting bit length 2048', () => {
            cy.get('[data-testid="bits-select"]').select('2048');
            cy.get('[data-testid="bits-select"]').should('have.value', '2048');
        });

        it('should allow selecting bit length 1024', () => {
            cy.get('[data-testid="bits-select"]').select('1024');
            cy.get('[data-testid="bits-select"]').should('have.value', '1024');
        });

        it('should allow selecting algorithm', () => {
            cy.get('[data-testid="algorithm-select"]').find('option').then(($options) => {
                if ($options.length > 1) {
                    const firstValue = $options.eq(1).val();
                    cy.get('[data-testid="algorithm-select"]').select(firstValue);
                    cy.get('[data-testid="algorithm-select"]').should('have.value', firstValue);
                }
            });
        });

        it('should show validation error when submitting empty form', () => {
            cy.get('[data-testid="add-key-form"]').within(() => {
                cy.get('[data-testid="add-key-submit"]').click();
            });
            // Form validation should prevent submission
            cy.get('[data-testid="key-type-select"]:invalid').should('exist');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToAddDNSSECKey(testZoneId);
            }
        });

        it('should require key type selection', () => {
            cy.get('[data-testid="key-type-select"]').should('have.attr', 'required');
        });

        it('should require bits selection', () => {
            cy.get('[data-testid="bits-select"]').should('have.attr', 'required');
        });

        it('should require algorithm selection', () => {
            cy.get('[data-testid="algorithm-select"]').should('have.attr', 'required');
        });

        it('should have novalidate attribute on form', () => {
            cy.get('[data-testid="add-key-form"]').should('have.attr', 'novalidate');
        });

        it('should have needs-validation class on form', () => {
            cy.get('[data-testid="add-key-form"]').should('have.class', 'needs-validation');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            if (testZoneId) {
                cy.goToAddDNSSECKey(testZoneId);
            }
        });

        it('should have access to add DNSSEC key for own zone', () => {
            cy.get('[data-testid="add-key-heading"]').should('be.visible');
        });

        it('should display add key form', () => {
            cy.get('[data-testid="add-key-form"]').should('be.visible');
        });

        it('should be able to select key type', () => {
            cy.get('[data-testid="key-type-select"]').should('be.visible');
            cy.get('[data-testid="key-type-select"]').select('ksk');
        });
    });
});
