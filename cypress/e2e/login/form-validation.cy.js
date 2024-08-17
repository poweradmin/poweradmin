import users from '../../fixtures/users.json';

describe('Login Form Validation', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
    });

    it('should show error for empty fields', () => {
        cy.get('[data-testid="login-button"]').click();
        cy.get('[data-testid="username-error"]').should('be.visible');
        cy.get('[data-testid="password-error"]').should('be.visible');
    });
});
