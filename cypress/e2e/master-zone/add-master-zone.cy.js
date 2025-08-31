import users from '../../fixtures/users.json';

describe('Master Zone Management', () => {
    beforeEach(() => {
        cy.visit('/login');
        cy.login(users.validUser.username, users.validUser.password);
        cy.url().should('eq', Cypress.config('baseUrl') + '/');
    });

    it('should add a master zone successfully', () => {
        // First try clicking the Master Zone card from dashboard
        cy.get('body').then(($body) => {
            if ($body.text().includes('Master Zone')) {
                // Click on Master Zone card from dashboard
                cy.contains('Master Zone').click();
            } else {
                // Fallback: use navigation dropdown
                cy.contains('Zones').click();
                cy.contains('Add master zone').click();
            }
        });
        
        // Fill in zone name
        cy.get('input[name*="zone"], input[placeholder*="zone"], input[name*="name"]').type('example.com');
        
        // Submit the form
        cy.get('button[type="submit"], input[type="submit"]').click();

        // Verify success
        cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
    });

    it('should add a reverse zone successfully', () => {
        cy.get('[data-testid="add-master-zone-link"]').click();
        cy.get('[data-testid="zone-name-input"]').type('1.168.192.in-addr.arpa');
        cy.get('[data-testid="add-zone-button"]').click();

        cy.url().should('include', '/zones/reverse');
        cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been added successfully.');
    });

    it('should add a record to a master zone successfully', () => {
        cy.get('[data-testid="list-zones-link"]').click();

        cy.contains('tr', 'example.com').within(() => {
            cy.get('[data-testid^="edit-zone-"]').click();
        });

        cy.get('[data-testid="record-name-input"]').type('www');
        cy.get('[data-testid="record-content-input"]').type('192.168.1.1');
        cy.get('[data-testid="add-reverse-record-checkbox"]').check();
        cy.get('[data-testid="add-record-button"]').click();

        cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added.');
    });

    it('should delete a master zone successfully', () => {
        cy.get('[data-testid="list-zones-link"]').click();

        cy.contains('tr', 'example.com').within(() => {
            cy.get('[data-testid^="delete-zone-"]').click();
        });

        cy.get('[data-testid="confirm-delete-zone"]').click();

        cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been deleted successfully.');
    });

    it('should delete a reverse zone successfully', () => {
        cy.get('[data-testid="list-zones-link"]').click();

        cy.contains('tr', '1.168.192.in-addr.arpa').within(() => {
            cy.get('[data-testid^="delete-zone-"]').click();
        });

        cy.get('[data-testid="confirm-delete-zone"]').click();

        cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been deleted successfully.');
    });
});
