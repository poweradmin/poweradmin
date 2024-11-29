import users from '../../fixtures/users.json';

describe('Master Zone Management', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
        cy.login(users.validUser.username, users.validUser.password);
        cy.url().should('include', '/index.php');
    });

    it('should add a master zone successfully', () => {
        cy.get('[data-testid="add-master-zone-link"]').click();
        cy.get('[data-testid="zone-name-input"]').type('example.com');
        cy.get('[data-testid="add-zone-button"]').click();

        cy.url().should('include', '/index.php?page=list_zones');
        cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been added successfully.');
    });

    it('should add a reverse zone successfully', () => {
        cy.get('[data-testid="add-master-zone-link"]').click();
        cy.get('[data-testid="zone-name-input"]').type('1.168.192.in-addr.arpa');
        cy.get('[data-testid="add-zone-button"]').click();

        cy.url().should('include', '/index.php?page=list_zones');
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
