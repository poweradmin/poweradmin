import users from '../../fixtures/users.json';

describe('Add User', () => {
    describe('Admin User - Add User Form', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should display the add user form', () => {
            cy.goToAddUser();
            cy.get('[data-testid="add-user-heading"]').should('be.visible');
            cy.get('[data-testid="add-user-form"]').should('be.visible');
        });

        it('should display all form fields', () => {
            cy.goToAddUser();
            cy.get('[data-testid="username-input"]').should('be.visible');
            cy.get('[data-testid="fullname-input"]').should('be.visible');
            cy.get('[data-testid="password-input"]').should('be.visible');
            cy.get('[data-testid="email-input"]').should('be.visible');
            cy.get('[data-testid="active-checkbox"]').should('exist');
            cy.get('[data-testid="add-user-submit"]').should('be.visible');
        });

        it('should display template select when user has permission', () => {
            cy.goToAddUser();
            cy.get('[data-testid="template-select"]').should('be.visible');
        });

        it('should display description textarea', () => {
            cy.goToAddUser();
            cy.get('[data-testid="description-textarea"]').should('be.visible');
        });

        it('should have required attribute on username field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="username-input"]').should('have.attr', 'required');
        });

        it('should have required attribute on email field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="email-input"]').should('have.attr', 'required');
        });

        it('should show validation error when submitting without username', () => {
            cy.goToAddUser();
            cy.get('[data-testid="add-user-submit"]').click();
            cy.get('[data-testid="username-input"]:invalid').should('exist');
        });

        it('should show validation error when submitting without email', () => {
            cy.goToAddUser();
            cy.get('[data-testid="username-input"]').type('testuser');
            cy.get('[data-testid="password-input"]').type('testpassword');
            cy.get('[data-testid="add-user-submit"]').click();
            cy.get('[data-testid="email-input"]:invalid').should('exist');
        });

        it('should allow typing in username field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="username-input"]').type('newuser');
            cy.get('[data-testid="username-input"]').should('have.value', 'newuser');
        });

        it('should allow typing in fullname field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="fullname-input"]').type('New User Full Name');
            cy.get('[data-testid="fullname-input"]').should('have.value', 'New User Full Name');
        });

        it('should allow typing in password field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="password-input"]').type('secretpassword');
            cy.get('[data-testid="password-input"]').should('have.value', 'secretpassword');
        });

        it('should allow typing in email field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="email-input"]').type('newuser@example.com');
            cy.get('[data-testid="email-input"]').should('have.value', 'newuser@example.com');
        });

        it('should allow typing in description field', () => {
            cy.goToAddUser();
            cy.get('[data-testid="description-textarea"]').type('Test user description');
            cy.get('[data-testid="description-textarea"]').should('have.value', 'Test user description');
        });

        it('should allow selecting template', () => {
            cy.goToAddUser();
            cy.get('[data-testid="template-select"]').should('be.enabled');
        });

        it('should allow toggling active checkbox', () => {
            cy.goToAddUser();
            // Check initial state and toggle
            cy.get('[data-testid="active-checkbox"]').check();
            cy.get('[data-testid="active-checkbox"]').should('be.checked');
            cy.get('[data-testid="active-checkbox"]').uncheck();
            cy.get('[data-testid="active-checkbox"]').should('not.be.checked');
        });

        it('should create user successfully via API', () => {
            const uniqueUsername = `testuser_${Date.now()}`;
            cy.goToAddUser();

            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                cy.request({
                    method: 'POST',
                    url: '/index.php?page=add_user',
                    form: true,
                    body: {
                        _token: csrfToken,
                        username: uniqueUsername,
                        fullname: 'Test User',
                        password: 'testpassword123',
                        email: `${uniqueUsername}@example.com`,
                        active: '1',
                        commit: 'Add'
                    }
                }).then((response) => {
                    // Verify successful response (redirect or success page)
                    expect(response.status).to.be.oneOf([200, 302]);
                });
            });
        });
    });

    describe('Manager User - Add User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
        });

        it('should not have access to add user page', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('[data-testid="add-user-heading"]').should('not.exist');
        });
    });

    describe('Client User - Add User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should not have access to add user page', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('[data-testid="add-user-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Add User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should not have access to add user page', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('[data-testid="add-user-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Add User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
        });

        it('should not have access to add user page', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('[data-testid="add-user-heading"]').should('not.exist');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToAddUser();
        });

        it('should allow long username', () => {
            const longUsername = 'a'.repeat(50);
            cy.get('[data-testid="username-input"]').type(longUsername);
            cy.get('[data-testid="username-input"]').should('have.value', longUsername);
        });

        it('should allow long email', () => {
            const longEmail = 'a'.repeat(50) + '@example.com';
            cy.get('[data-testid="email-input"]').type(longEmail);
            cy.get('[data-testid="email-input"]').should('have.value', longEmail);
        });

        it('should allow long description', () => {
            const longDesc = 'b'.repeat(500);
            cy.get('[data-testid="description-textarea"]').type(longDesc);
            cy.get('[data-testid="description-textarea"]').should('have.value', longDesc);
        });
    });
});
