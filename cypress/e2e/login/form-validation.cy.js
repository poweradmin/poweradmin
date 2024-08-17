import users from '../../fixtures/users.json';

describe('Login Form Validation', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
    });

    // it('should show error for empty fields', () => {
    //     cy.get('[data-testid="login-button"]').click();
    //     cy.get('[data-testid="error-message"]').should('be.visible');
    // });
    //
    // it('should show error for short username', () => {
    //     cy.get('[data-testid="username-input"]').type('ab');
    //     cy.get('[data-testid="password-input"]').type(users.validUser.password);
    //     cy.get('[data-testid="login-button"]').click();
    //     cy.get('[data-testid="error-message"]').should('be.visible');
    // });
    //
    // it('should show error for short password', () => {
    //     cy.get('[data-testid="username-input"]').type(users.validUser.username);
    //     cy.get('[data-testid="password-input"]').type('short');
    //     cy.get('[data-testid="login-button"]').click();
    //     cy.get('[data-testid="error-message"]').should('be.visible');
    // });
    //
    // it('should not show error for valid input', () => {
    //     cy.get('[data-testid="username-input"]').type(users.validUser.username);
    //     cy.get('[data-testid="password-input"]').type(users.validUser.password);
    //     cy.get('[data-testid="login-button"]').click();
    //     cy.get('[data-testid="error-message"]').should('not.be.visible');
    // });
});
