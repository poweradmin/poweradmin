import users from '../../fixtures/users.json';

describe('Add Slave Zone', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToAddSlaveZone();
        });

        it('should display add slave zone heading', () => {
            cy.get('[data-testid="add-slave-zone-heading"]').should('be.visible');
            cy.get('[data-testid="add-slave-zone-heading"]').should('contain', 'Add slave zone');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
        });

        it('should display add slave zone form', () => {
            cy.get('[data-testid="add-slave-zone-form"]').should('be.visible');
        });

        it('should display zone name input', () => {
            cy.get('[data-testid="zone-name-input"]').should('be.visible');
        });

        it('should display master IP input', () => {
            cy.get('[data-testid="master-ip-input"]').should('be.visible');
        });

        it('should display owner select', () => {
            cy.get('[data-testid="owner-select"]').should('be.visible');
        });

        it('should display add zone submit button', () => {
            cy.get('[data-testid="add-slave-zone-submit"]').should('be.visible');
            cy.get('[data-testid="add-slave-zone-submit"]').should('have.value', 'Add zone');
        });

        it('should show validation error when zone name is empty', () => {
            cy.get('[data-testid="add-slave-zone-submit"]').click();
            cy.get('[data-testid="zone-name-error"]').should('be.visible');
        });

        it('should show validation error when master IP is empty', () => {
            cy.get('[data-testid="zone-name-input"]').type('slave-zone.example.com');
            cy.get('[data-testid="add-slave-zone-submit"]').click();
            cy.get('[data-testid="master-ip-error"]').should('be.visible');
        });

        it('should require both zone name and master IP', () => {
            cy.get('[data-testid="add-slave-zone-submit"]').click();
            cy.get('[data-testid="zone-name-error"]').should('be.visible');
            cy.get('[data-testid="master-ip-error"]').should('be.visible');
        });

        it('should allow selecting owner', () => {
            cy.get('[data-testid="owner-select"]').select(1);
            cy.get('[data-testid="owner-select"]').should('not.have.value', '');
        });

        it('should accept valid zone name', () => {
            cy.get('[data-testid="zone-name-input"]').type('test-slave.example.com');
            cy.get('[data-testid="zone-name-input"]').should('have.value', 'test-slave.example.com');
        });

        it('should accept valid master IP', () => {
            cy.get('[data-testid="master-ip-input"]').type('192.168.1.1');
            cy.get('[data-testid="master-ip-input"]').should('have.value', '192.168.1.1');
        });

        it('should accept IPv6 master address', () => {
            cy.get('[data-testid="master-ip-input"]').clear();
            cy.get('[data-testid="master-ip-input"]').type('2001:db8::1');
            cy.get('[data-testid="master-ip-input"]').should('have.value', '2001:db8::1');
        });

        it('should have all required form fields', () => {
            cy.get('[data-testid="zone-name-input"]').should('have.attr', 'required');
            cy.get('[data-testid="master-ip-input"]').should('have.attr', 'required');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToAddSlaveZone();
        });

        it('should display add slave zone page', () => {
            cy.get('[data-testid="add-slave-zone-heading"]').should('be.visible');
        });

        it('should display all form fields', () => {
            cy.get('[data-testid="zone-name-input"]').should('be.visible');
            cy.get('[data-testid="master-ip-input"]').should('be.visible');
            cy.get('[data-testid="owner-select"]').should('be.visible');
            cy.get('[data-testid="add-slave-zone-submit"]').should('be.visible');
        });

        it('should show validation errors', () => {
            cy.get('[data-testid="add-slave-zone-submit"]').click();
            cy.get('[data-testid="zone-name-error"]').should('be.visible');
            cy.get('[data-testid="master-ip-error"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should not have add slave zone permission', () => {
            // Client users don't have zone_slave_add permission
            // They would be redirected or see an error if they try to access
            cy.goToZones();
            cy.get('[data-testid="add-slave-zone-button"]').should('not.exist');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should not have add slave zone permission', () => {
            // Viewer users are read-only
            cy.goToZones();
            cy.get('[data-testid="add-slave-zone-button"]').should('not.exist');
        });
    });
});
