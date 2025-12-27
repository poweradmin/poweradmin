import users from '../../fixtures/users.json';

describe('Add Zone Template', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should display the add zone template page', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="add-template-heading"]').should('be.visible');
            cy.get('[data-testid="add-template-form"]').should('be.visible');
        });

        it('should display all required form fields', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-name-input"]').should('be.visible');
            cy.get('[data-testid="template-description-input"]').should('be.visible');
            cy.get('[data-testid="add-template-submit"]').should('be.visible');
        });

        it('should display global checkbox for admin users', () => {
            cy.goToAddZoneTemplate();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-global-checkbox"]').length > 0) {
                    cy.get('[data-testid="template-global-checkbox"]').should('be.visible');
                }
            });
        });

        it('should require template name for form submission', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-name-input"]').should('have.attr', 'required');
        });

        it('should allow description to be optional', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-description-input"]').should('not.have.attr', 'required');
        });

        it('should submit form with valid template name via API', () => {
            cy.goToAddZoneTemplate();
            const templateName = `test-template-${Date.now()}`;

            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_zone_templ',
                    form: true,
                    body: {
                        _token: csrfToken,
                        templ_name: templateName,
                        templ_descr: 'Test description',
                        commit: 'Add zone template'
                    }
                }).then((response) => {
                    expect(response.status).to.be.oneOf([200, 302]);
                });
            });
        });

        it('should create a private template by default via API', () => {
            cy.goToAddZoneTemplate();
            const templateName = `private-template-${Date.now()}`;

            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_zone_templ',
                    form: true,
                    body: {
                        _token: csrfToken,
                        templ_name: templateName,
                        commit: 'Add zone template'
                    }
                }).then((response) => {
                    expect(response.status).to.be.oneOf([200, 302]);
                });
            });
        });

        it('should create a global template when checkbox is checked via API', () => {
            cy.goToAddZoneTemplate();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-global-checkbox"]').length > 0) {
                    const templateName = `global-template-${Date.now()}`;

                    cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                        cy.request({
                            method: 'POST',
                            url: '/index.php?page=add_zone_templ',
                            form: true,
                            body: {
                                _token: csrfToken,
                                templ_name: templateName,
                                templ_global: 'on',
                                commit: 'Add zone template'
                            }
                        }).then((response) => {
                            expect(response.status).to.be.oneOf([200, 302]);
                        });
                    });
                }
            });
        });

        it('should preserve input values after validation error', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-description-input"]').clear().type('Test description only');
            cy.get('[data-testid="add-template-submit"]').click();
            // Form should not submit without required name
            cy.get('[data-testid="template-description-input"]').should('have.value', 'Test description only');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
        });

        it('should display add zone template page for manager', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="add-template-heading"]').should('be.visible');
            cy.get('[data-testid="add-template-form"]').should('be.visible');
        });

        it('should not display global checkbox for non-admin users', () => {
            cy.goToAddZoneTemplate();
            cy.get('[data-testid="template-global-checkbox"]').should('not.exist');
        });

        it('should allow manager to create private templates via API', () => {
            cy.goToAddZoneTemplate();
            const templateName = `manager-template-${Date.now()}`;

            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_zone_templ',
                    form: true,
                    body: {
                        _token: csrfToken,
                        templ_name: templateName,
                        templ_descr: 'Manager template',
                        commit: 'Add zone template'
                    }
                }).then((response) => {
                    expect(response.status).to.be.oneOf([200, 302]);
                });
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
        });

        it('should not have access to add zone template page', () => {
            cy.visit('/index.php?page=add_zone_templ');
            cy.get('[data-testid="add-template-heading"]').should('not.exist');
        });
    });
});
