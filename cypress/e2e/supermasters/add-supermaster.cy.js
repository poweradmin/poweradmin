import users from '../../fixtures/users.json';

describe('Add Supermaster', () => {
    describe('Admin User - Add Supermaster Form', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the add supermaster form', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="add-supermaster-heading"]').should('be.visible');
            cy.get('[data-testid="add-supermaster-form"]').should('be.visible');
        });

        it('should display breadcrumb navigation', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
        });

        it('should display all form fields', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="master-ip-input"]').should('be.visible');
            cy.get('[data-testid="ns-name-input"]').should('be.visible');
            cy.get('[data-testid="account-select"]').should('be.visible');
            cy.get('[data-testid="add-supermaster-submit"]').should('be.visible');
        });

        it('should have required attribute on master IP field', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="master-ip-input"]').should('have.attr', 'required');
        });

        it('should have required attribute on NS name field', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="ns-name-input"]').should('have.attr', 'required');
        });

        it('should show validation error when submitting without master IP', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="add-supermaster-submit"]').click();
            cy.get('[data-testid="master-ip-input"]:invalid').should('exist');
        });

        it('should show validation error when submitting without NS name', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="master-ip-input"]').type('192.168.1.1');
            cy.get('[data-testid="add-supermaster-submit"]').click();
            cy.get('[data-testid="ns-name-input"]:invalid').should('exist');
        });

        it('should allow typing in master IP field', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="master-ip-input"]').type('192.168.1.1');
            cy.get('[data-testid="master-ip-input"]').should('have.value', '192.168.1.1');
        });

        it('should allow typing in NS name field', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="ns-name-input"]').type('ns1.example.com');
            cy.get('[data-testid="ns-name-input"]').should('have.value', 'ns1.example.com');
        });

        it('should allow selecting account', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="account-select"]').should('be.enabled');
        });

        it('should have account select with options', () => {
            cy.goToAddSupermaster();
            cy.get('[data-testid="account-select"] option').should('have.length.at.least', 1);
        });

        it('should create supermaster successfully via API', () => {
            const uniqueIP = `192.168.1.${Math.floor(Math.random() * 254) + 1}`;
            cy.goToAddSupermaster();

            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_supermaster',
                    form: true,
                    body: {
                        _token: csrfToken,
                        master_ip: uniqueIP,
                        ns_name: 'ns1.test-supermaster.com',
                        account: 'admin'
                    }
                }).then((response) => {
                    expect(response.status).to.be.oneOf([200, 302]);
                });
            });
        });
    });

    describe('Manager User - Add Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add supermaster page', () => {
            cy.visit('/index.php?page=add_supermaster');
            cy.get('[data-testid="add-supermaster-heading"]').should('not.exist');
        });
    });

    describe('Client User - Add Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add supermaster page', () => {
            cy.visit('/index.php?page=add_supermaster');
            cy.get('[data-testid="add-supermaster-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Add Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add supermaster page', () => {
            cy.visit('/index.php?page=add_supermaster');
            cy.get('[data-testid="add-supermaster-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Add Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to add supermaster page', () => {
            cy.visit('/index.php?page=add_supermaster');
            cy.get('[data-testid="add-supermaster-heading"]').should('not.exist');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
            cy.goToAddSupermaster();
        });

        it('should allow valid IPv4 address', () => {
            cy.get('[data-testid="master-ip-input"]').type('10.0.0.1');
            cy.get('[data-testid="master-ip-input"]').should('have.value', '10.0.0.1');
        });

        it('should allow valid IPv6 address', () => {
            cy.get('[data-testid="master-ip-input"]').type('2001:db8::1');
            cy.get('[data-testid="master-ip-input"]').should('have.value', '2001:db8::1');
        });

        it('should allow FQDN for NS name', () => {
            cy.get('[data-testid="ns-name-input"]').type('ns1.example.com');
            cy.get('[data-testid="ns-name-input"]').should('have.value', 'ns1.example.com');
        });
    });
});
