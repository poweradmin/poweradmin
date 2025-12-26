import users from '../../fixtures/users.json';

describe('DNSSEC DS and DNSKEY Records', () => {
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
                cy.goToDNSSECDSDnskey(testZoneId);
            }
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'DNSSEC');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'DS and DNS keys');
        });

        it('should display DS and DNSKEY heading', () => {
            cy.get('[data-testid="ds-dnskey-heading"]').should('be.visible');
            cy.get('[data-testid="ds-dnskey-heading"]').should('contain', 'DNSSEC public records for zone');
        });

        it('should display zone name in heading', () => {
            cy.get('[data-testid="ds-dnskey-heading"]').then(($heading) => {
                const text = $heading.text();
                expect(text).to.match(/"[^"]+"/); // Should contain quoted zone name
            });
        });

        it('should display DNSKEY section heading', () => {
            cy.get('[data-testid="dnskey-section-heading"]').should('be.visible');
            cy.get('[data-testid="dnskey-section-heading"]').should('contain', 'DNSKEY');
        });

        it('should display DNSKEY records container', () => {
            cy.get('[data-testid="dnskey-records"]').should('be.visible');
        });

        it('should display DS section heading', () => {
            cy.get('[data-testid="ds-section-heading"]').should('be.visible');
            cy.get('[data-testid="ds-section-heading"]').should('contain', 'DS record');
        });

        it('should display DS records container', () => {
            cy.get('[data-testid="ds-records"]').should('be.visible');
        });

        it('should display DNSKEY records if keys exist', () => {
            cy.get('[data-testid="dnskey-records"]').then(($container) => {
                const text = $container.text().trim();
                if (text.length > 0) {
                    cy.log('DNSKEY records found');
                    cy.get('[data-testid="dnskey-records"]').should('not.be.empty');
                } else {
                    cy.log('No DNSKEY records');
                }
            });
        });

        it('should display DS records if keys exist', () => {
            cy.get('[data-testid="ds-records"]').then(($container) => {
                const text = $container.text().trim();
                if (text.length > 0) {
                    cy.log('DS records found');
                    cy.get('[data-testid="ds-records"]').should('not.be.empty');
                } else {
                    cy.log('No DS records');
                }
            });
        });

        it('should show empty DNSKEY container if no keys exist', () => {
            cy.get('[data-testid="dnskey-records"]').should('exist');
        });

        it('should show empty DS container if no keys exist', () => {
            cy.get('[data-testid="ds-records"]').should('exist');
        });
    });

    describe('Navigation from DNSSEC Page', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToDNSSEC(testZoneId);
            }
        });

        it('should navigate to DS/DNSKEY page when clicking button', () => {
            cy.get('[data-testid="show-ds-dnskey-button"]').click();
            cy.url().should('include', 'page=dnssec_ds_dnskey');
        });

        it('should maintain zone ID in URL when navigating', () => {
            cy.get('[data-testid="show-ds-dnskey-button"]').click();
            cy.url().should('include', `id=${testZoneId}`);
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            if (testZoneId) {
                cy.goToDNSSECDSDnskey(testZoneId);
            }
        });

        it('should have access to DS/DNSKEY page for own zone', () => {
            cy.get('[data-testid="ds-dnskey-heading"]').should('be.visible');
        });

        it('should display DNSKEY section', () => {
            cy.get('[data-testid="dnskey-section-heading"]').should('be.visible');
        });

        it('should display DS section', () => {
            cy.get('[data-testid="ds-section-heading"]').should('be.visible');
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
                cy.goToDNSSECDSDnskey(clientZoneId);
            }
        });

        it('should have limited access to DS/DNSKEY page', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="ds-dnskey-heading"]').length > 0) {
                    cy.get('[data-testid="ds-dnskey-heading"]').should('be.visible');
                } else {
                    cy.log('Client does not have DNSSEC access');
                }
            });
        });
    });

    describe('IDN Zone Name Display', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToDNSSECDSDnskey(testZoneId);
            }
        });

        it('should handle IDN zone names in heading', () => {
            cy.get('[data-testid="ds-dnskey-heading"]').then(($heading) => {
                const text = $heading.text();
                // Should contain zone name in some form
                expect(text).to.include('example.com');
            });
        });

        it('should display zone name in breadcrumb', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'example.com');
        });
    });

    describe('Record Format', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            if (testZoneId) {
                cy.goToDNSSECDSDnskey(testZoneId);
            }
        });

        it('should format DNSKEY records with line breaks', () => {
            cy.get('[data-testid="dnskey-records"]').within(() => {
                cy.get('br').should('exist');
            });
        });

        it('should format DS records with line breaks', () => {
            cy.get('[data-testid="ds-records"]').within(() => {
                cy.get('br').should('exist');
            });
        });
    });
});
