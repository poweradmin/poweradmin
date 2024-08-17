import users from '../../fixtures/users.json';

describe('Login Authentication', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
    });

    it('should redirect to dashboard on successful login', () => {
        cy.login(users.validUser.username, users.validUser.password);
        cy.url().should('include', '/index.php?page=index');
    });

    it('should remain on login page for invalid credentials', () => {
        cy.login(users.invalidUser.username, users.invalidUser.password);
        cy.url().should('include', '/index.php?page=login');
    });

    it('should display error message for invalid login', () => {
        cy.get('[data-testid="username-input"]').type(users.invalidUser.username);
        cy.get('[data-testid="password-input"]').type(users.invalidUser.password);
        cy.get('[data-testid="login-button"]').click();
        cy.get('[data-testid="session-error"]').should('be.visible');
    });
});
