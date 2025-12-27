import users from '../../fixtures/users.json';

describe('Delete Supermaster', () => {
    let testSupermasterIP;
    let testSupermasterNS;

    before(() => {
        // Create a test supermaster to delete
        cy.loginAs('admin');
        testSupermasterIP = `192.168.99.${Math.floor(Math.random() * 254) + 1}`;
        testSupermasterNS = 'ns-test-delete.example.com';

        cy.visit('/index.php?page=add_supermaster');
        cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
            cy.request({
                method: 'POST',
                url: '/index.php?page=add_supermaster',
                form: true,
                failOnStatusCode: false,
                body: {
                    _token: csrfToken,
                    master_ip: testSupermasterIP,
                    ns_name: testSupermasterNS,
                    account: 'admin'
                }
            });
        });
    });

    describe('Admin User - Delete Supermaster Page', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should display the delete supermaster confirmation page', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="delete-supermaster-heading"]').should('be.visible');
        });

        it('should display breadcrumb navigation', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
        });

        it('should display supermaster information', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="supermaster-info"]').should('be.visible');
            cy.get('[data-testid="supermaster-ns-name"]').should('be.visible');
            cy.get('[data-testid="supermaster-account"]').should('be.visible');
        });

        it('should display confirmation message', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="confirmation-message"]').should('be.visible');
        });

        it('should display confirm and cancel buttons', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="confirm-delete-supermaster"]').should('be.visible');
            cy.get('[data-testid="cancel-delete-supermaster"]').should('be.visible');
        });

        it('should have cancel button with correct onclick', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="cancel-delete-supermaster"]').should('have.attr', 'onclick').and('include', 'list_supermasters');
        });

        it('should have confirm button with correct onclick', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="confirm-delete-supermaster"]').should('have.attr', 'onclick').and('include', 'confirm=1');
        });

        it('should display NS name in supermaster info', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="supermaster-ns-name"]').should('contain', testSupermasterNS);
        });
    });

    describe('Manager User - Delete Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
        });

        it('should not have access to delete supermaster page', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="delete-supermaster-heading"]').should('not.exist');
        });
    });

    describe('Client User - Delete Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should not have access to delete supermaster page', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="delete-supermaster-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Delete Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should not have access to delete supermaster page', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="delete-supermaster-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Delete Supermaster Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
        });

        it('should not have access to delete supermaster page', () => {
            cy.visit(`/index.php?page=delete_supermaster&master_ip=${testSupermasterIP}&ns_name=${testSupermasterNS}`);
            cy.get('[data-testid="delete-supermaster-heading"]').should('not.exist');
        });
    });
});
