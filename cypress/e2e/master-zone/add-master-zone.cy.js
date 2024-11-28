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
});
