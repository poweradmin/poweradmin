import users from '../../fixtures/users.json';

describe('Master Zone Management', () => {
    beforeEach(() => {
        cy.loginAs('admin');
    });

    describe('Add Master Zone Page', () => {
        beforeEach(() => {
            cy.visit('/index.php?page=add_zone_master');
        });

        it('should display add master zone heading', () => {
            cy.get('h5').should('contain', 'Add master zone');
        });

        it('should display zone name input', () => {
            cy.get('[data-testid="zone-name-input"]').should('be.visible');
        });

        it('should display owner selection', () => {
            cy.get('[data-testid="zone-owner-select"]').should('be.visible');
        });

        it('should display zone template selection', () => {
            cy.get('[data-testid="zone-template-select"]').should('be.visible');
        });

        it('should display zone type selection', () => {
            cy.get('[data-testid="zone-type-select"]').should('be.visible');
        });

        it('should display add zone button', () => {
            cy.get('[data-testid="add-zone-button"]').should('be.visible');
        });

        it('should allow typing zone name', () => {
            cy.get('[data-testid="zone-name-input"]').type('test-zone.example.com');
            cy.get('[data-testid="zone-name-input"]').should('have.value', 'test-zone.example.com');
        });

        it('should have form with correct method', () => {
            cy.get('form.needs-validation').should('have.attr', 'method', 'post');
        });

        it('should have CSRF token in form', () => {
            cy.get('form.needs-validation').within(() => {
                cy.get('input[name="_token"]').should('exist');
            });
        });
    });

    describe('Zone Creation via API', () => {
        const testZoneName = 'cypress-test-' + Date.now() + '.example.com';

        it('should add a master zone successfully via form submission', () => {
            // Get CSRF token from add zone page
            cy.visit('/index.php?page=add_zone_master');
            cy.get('input[name="_token"]').invoke('val').then((token) => {
                // Submit form via cy.request to maintain session
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_zone_master',
                    form: true,
                    body: {
                        _token: token,
                        domain: testZoneName,
                        owner: 1,  // admin user
                        dom_type: 'MASTER',
                        zone_template: 'none'
                    },
                    followRedirect: true
                }).then((response) => {
                    expect(response.status).to.eq(200);
                });
            });
        });
    });

    describe('Reverse Zone', () => {
        beforeEach(() => {
            cy.visit('/index.php?page=add_zone_master');
        });

        it('should allow entering reverse zone name', () => {
            cy.get('[data-testid="zone-name-input"]').type('1.168.192.in-addr.arpa');
            cy.get('[data-testid="zone-name-input"]').should('have.value', '1.168.192.in-addr.arpa');
        });
    });
});
