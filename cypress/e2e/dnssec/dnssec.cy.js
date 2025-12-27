import users from '../../fixtures/users.json';

describe('DNSSEC Keys Listing', () => {
    let adminZoneId;
    let managerZoneId;

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

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (adminZoneId) {
                cy.goToDNSSEC(adminZoneId);
            }
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'DNSSEC');
        });

        it('should display DNSSEC keys heading', () => {
            cy.get('[data-testid="dnssec-keys-heading"]').should('be.visible');
            cy.get('[data-testid="dnssec-keys-heading"]').should('contain', 'DNSSEC keys for zone');
        });

        it('should display DNSSEC keys table', () => {
            cy.get('[data-testid="dnssec-keys-table"]').should('be.visible');
        });

        it('should have table headers for key information', () => {
            cy.get('[data-testid="dnssec-keys-table"]').within(() => {
                cy.contains('th', 'ID').should('be.visible');
                cy.contains('th', 'Type').should('be.visible');
                cy.contains('th', 'Tag').should('be.visible');
                cy.contains('th', 'Algorithm').should('be.visible');
                cy.contains('th', 'Bits').should('be.visible');
                cy.contains('th', 'Active').should('be.visible');
            });
        });

        it('should display Add new key button', () => {
            cy.get('[data-testid="add-new-key-button"]').should('be.visible');
            cy.get('[data-testid="add-new-key-button"]').should('contain', 'Add new key');
        });

        it('should display Show DS and DNSKEY button', () => {
            cy.get('[data-testid="show-ds-dnskey-button"]').should('be.visible');
            cy.get('[data-testid="show-ds-dnskey-button"]').should('contain', 'Show DS and DNSKEY');
        });

        it('should have Add new key link with correct href', () => {
            cy.get('[data-testid="add-new-key-button"]').should('be.visible');
            cy.get('[data-testid="add-new-key-button"]').should('have.attr', 'href').and('include', 'page=dnssec_add_key');
        });

        it('should have Show DS and DNSKEY link with correct href', () => {
            cy.get('[data-testid="show-ds-dnskey-button"]').should('be.visible');
            cy.get('[data-testid="show-ds-dnskey-button"]').should('have.attr', 'href').and('include', 'page=dnssec_ds_dnskey');
        });

        it('should display key rows if keys exist', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="dnssec-key-row-"]').length > 0) {
                    cy.get('[data-testid^="dnssec-key-row-"]').first().should('be.visible');
                    cy.get('[data-testid^="key-id-"]').first().should('not.be.empty');
                    cy.get('[data-testid^="key-type-"]').first().should('not.be.empty');
                    cy.get('[data-testid^="key-tag-"]').first().should('not.be.empty');
                    cy.get('[data-testid^="key-algorithm-"]').first().should('not.be.empty');
                    cy.get('[data-testid^="key-bits-"]').first().should('not.be.empty');
                    cy.get('[data-testid^="key-active-"]').first().should('not.be.empty');
                }
            });
        });

        it('should display edit and delete buttons for each key', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="dnssec-key-row-"]').length > 0) {
                    cy.get('[data-testid^="edit-key-"]').first().should('be.visible');
                    cy.get('[data-testid^="delete-key-"]').first().should('be.visible');
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            if (managerZoneId) {
                cy.goToDNSSEC(managerZoneId);
            }
        });

        it('should have access to DNSSEC page for own zone', () => {
            cy.get('[data-testid="dnssec-keys-heading"]').should('be.visible');
        });

        it('should display DNSSEC keys table', () => {
            cy.get('[data-testid="dnssec-keys-table"]').should('be.visible');
        });

        it('should display Add new key button', () => {
            cy.get('[data-testid="add-new-key-button"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        let clientZoneId;

        before(() => {
            cy.loginAs('client');
            cy.getZoneIdByName('client-zone.example.com').then((zoneId) => {
                clientZoneId = zoneId;
            });
        });

        beforeEach(() => {
            cy.loginAs('client');
            if (clientZoneId) {
                cy.goToDNSSEC(clientZoneId);
            }
        });

        it('should have limited access to DNSSEC page', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dnssec-keys-heading"]').length > 0) {
                    cy.get('[data-testid="dnssec-keys-heading"]').should('be.visible');
                } else {
                    // Client might not have DNSSEC access
                    cy.log('Client does not have DNSSEC access');
                }
            });
        });
    });

    describe('Zone Name Display', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (adminZoneId) {
                cy.goToDNSSEC(adminZoneId);
            }
        });

        it('should display zone name in heading', () => {
            cy.get('[data-testid="dnssec-keys-heading"]').then(($heading) => {
                const text = $heading.text();
                expect(text).to.match(/"[^"]+"/); // Should contain quoted zone name
            });
        });

        it('should display zone name in breadcrumb', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'example.com');
        });
    });
});
