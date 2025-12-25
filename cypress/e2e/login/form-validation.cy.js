import users from '../../fixtures/users.json';

describe('Login Form Validation', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
    });

    describe('Empty Field Validation', () => {
        it('should show error for empty username and password', () => {
            cy.get('[data-testid="login-button"]').click();
            // HTML5 validation or custom validation should prevent submission
            cy.url().should('include', 'page=login');
        });

        it('should show error for empty username with valid password', () => {
            cy.get('[data-testid="password-input"]').type(users.admin.password);
            cy.get('[data-testid="login-button"]').click();
            cy.url().should('include', 'page=login');
        });

        it('should show error for valid username with empty password', () => {
            cy.get('[data-testid="username-input"]').type(users.admin.username);
            cy.get('[data-testid="login-button"]').click();
            cy.url().should('include', 'page=login');
        });
    });

    describe('Input Field Behavior', () => {
        it('should allow typing in username field', () => {
            const testUsername = 'testuser123';
            cy.get('[data-testid="username-input"]').type(testUsername);
            cy.get('[data-testid="username-input"]').should('have.value', testUsername);
        });

        it('should allow typing in password field', () => {
            const testPassword = 'testpass123';
            cy.get('[data-testid="password-input"]').type(testPassword);
            cy.get('[data-testid="password-input"]').should('have.value', testPassword);
        });

        it('should mask password input', () => {
            cy.get('[data-testid="password-input"]').should('have.attr', 'type', 'password');
        });

        it('should clear input fields on page refresh', () => {
            cy.get('[data-testid="username-input"]').type('testuser');
            cy.get('[data-testid="password-input"]').type('testpass');
            cy.reload();
            cy.get('[data-testid="username-input"]').should('have.value', '');
            cy.get('[data-testid="password-input"]').should('have.value', '');
        });
    });

    describe('Form Submission', () => {
        it('should submit form with Enter key in password field', () => {
            cy.get('[data-testid="username-input"]').type(users.admin.username);
            cy.get('[data-testid="password-input"]').type(users.admin.password + '{enter}');
            cy.url().should('include', 'page=index');
        });

        it('should have visible login button', () => {
            cy.get('[data-testid="login-button"]').should('be.visible');
        });

        it('should have login button enabled by default', () => {
            cy.get('[data-testid="login-button"]').should('not.be.disabled');
        });
    });

    describe('Special Characters', () => {
        it('should handle special characters in username', () => {
            const specialUsername = 'user@example.com';
            cy.get('[data-testid="username-input"]').type(specialUsername);
            cy.get('[data-testid="username-input"]').should('have.value', specialUsername);
        });

        it('should handle special characters in password', () => {
            const specialPassword = 'P@ssw0rd!#$%';
            cy.get('[data-testid="password-input"]').type(specialPassword);
            cy.get('[data-testid="password-input"]').should('have.value', specialPassword);
        });

        it('should handle unicode characters in password', () => {
            const unicodePassword = 'пароль密码';
            cy.get('[data-testid="password-input"]').type(unicodePassword);
            cy.get('[data-testid="password-input"]').should('have.value', unicodePassword);
        });
    });

    describe('Login Page Elements', () => {
        it('should display username input field', () => {
            cy.get('[data-testid="username-input"]').should('be.visible');
        });

        it('should display password input field', () => {
            cy.get('[data-testid="password-input"]').should('be.visible');
        });

        it('should display login button', () => {
            cy.get('[data-testid="login-button"]').should('be.visible');
        });
    });

    describe('Error Message Display', () => {
        it('should show error message for invalid credentials', () => {
            cy.get('[data-testid="username-input"]').type('invaliduser');
            cy.get('[data-testid="password-input"]').type('invalidpass');
            cy.get('[data-testid="login-button"]').click();
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should show error message for inactive user', () => {
            cy.get('[data-testid="username-input"]').type(users.inactive.username);
            cy.get('[data-testid="password-input"]').type(users.inactive.password);
            cy.get('[data-testid="login-button"]').click();
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should clear error message on successful login after failed attempt', () => {
            // First fail
            cy.get('[data-testid="username-input"]').type('invaliduser');
            cy.get('[data-testid="password-input"]').type('invalidpass');
            cy.get('[data-testid="login-button"]').click();
            cy.get('[data-testid="session-error"]').should('be.visible');

            // Then succeed
            cy.get('[data-testid="username-input"]').clear().type(users.admin.username);
            cy.get('[data-testid="password-input"]').clear().type(users.admin.password);
            cy.get('[data-testid="login-button"]').click();
            cy.url().should('include', 'page=index');
        });
    });

    describe('Security Features', () => {
        it('should not expose password in URL after failed login', () => {
            cy.get('[data-testid="username-input"]').type('testuser');
            cy.get('[data-testid="password-input"]').type('testpassword');
            cy.get('[data-testid="login-button"]').click();
            cy.url().should('not.include', 'testpassword');
        });

        it('should use POST method for form submission', () => {
            // Check that form has method="post"
            cy.get('form').should('have.attr', 'method').and('match', /post/i);
        });
    });
});
