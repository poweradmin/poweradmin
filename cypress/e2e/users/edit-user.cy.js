import users from '../../fixtures/users.json';

describe('Edit User', () => {
    describe('Admin User - Edit User Form', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the edit user form', () => {
            // Navigate directly to edit user page for a known user (manager = id 2)
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="edit-user-heading"]').should('be.visible');
            cy.get('[data-testid="edit-user-form"]').should('be.visible');
        });

        it('should display all form fields', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="username-input"]').should('be.visible');
            cy.get('[data-testid="fullname-input"]').should('be.visible');
            cy.get('[data-testid="password-input"]').should('exist');
            cy.get('[data-testid="email-input"]').should('be.visible');
            cy.get('[data-testid="active-checkbox"]').should('exist');
            cy.get('[data-testid="update-user-button"]').should('be.visible');
        });

        it('should load existing user data in form fields', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="username-input"]').should('not.have.value', '');
        });

        it('should display template select when user has permission', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="template-select"]').should('be.visible');
        });

        it('should display description textarea', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="description-textarea"]').should('be.visible');
        });

        it('should display user permissions list', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="user-permissions-list"]').should('be.visible');
        });

        it('should have required attribute on username field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="username-input"]').should('have.attr', 'required');
        });

        it('should have required attribute on fullname field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="fullname-input"]').should('have.attr', 'required');
        });

        it('should have required attribute on email field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="email-input"]').should('have.attr', 'required');
        });

        it('should allow editing username field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="username-input"]').clear().type('updatedusername');
            cy.get('[data-testid="username-input"]').should('have.value', 'updatedusername');
        });

        it('should allow editing fullname field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="fullname-input"]').clear().type('Updated Full Name');
            cy.get('[data-testid="fullname-input"]').should('have.value', 'Updated Full Name');
        });

        it('should allow editing email field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="email-input"]').clear().type('updated@example.com');
            cy.get('[data-testid="email-input"]').should('have.value', 'updated@example.com');
        });

        it('should allow editing description field', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="description-textarea"]').clear().type('Updated description');
            cy.get('[data-testid="description-textarea"]').should('have.value', 'Updated description');
        });

        it('should display update and reset buttons', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="update-user-button"]').should('be.visible');
            cy.get('[data-testid="reset-user-button"]').should('be.visible');
        });

        it('should allow selecting different template', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="template-select"]').should('be.enabled');
        });

        it('should allow toggling active checkbox', () => {
            cy.visit('/index.php?page=edit_user&id=2');
            cy.get('[data-testid="active-checkbox"]').click({ force: true });
        });
    });

    describe('Manager User - Edit User Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit other users', () => {
            // Try to access edit user page for another user (admin is typically id=1)
            cy.visit('/index.php?page=edit_user&id=1');
            cy.get('[data-testid="edit-user-heading"]').should('not.exist');
        });
    });

    describe('Client User - Edit User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit other users', () => {
            cy.visit('/index.php?page=edit_user&id=1');
            cy.get('[data-testid="edit-user-heading"]').should('not.exist');
        });
    });

    describe('Viewer User - Edit User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit user page', () => {
            cy.visit('/index.php?page=edit_user&id=1');
            cy.get('[data-testid="edit-user-heading"]').should('not.exist');
        });
    });

    describe('No Permission User - Edit User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit user page', () => {
            cy.visit('/index.php?page=edit_user&id=1');
            cy.get('[data-testid="edit-user-heading"]').should('not.exist');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
            cy.visit('/index.php?page=edit_user&id=2');
        });

        it('should show validation error when submitting without username', () => {
            cy.get('[data-testid="username-input"]').clear();
            cy.get('[data-testid="update-user-button"]').click();
            cy.get('[data-testid="username-input"]:invalid').should('exist');
        });

        it('should show validation error when submitting without fullname', () => {
            cy.get('[data-testid="fullname-input"]').clear();
            cy.get('[data-testid="update-user-button"]').click();
            cy.get('[data-testid="fullname-input"]:invalid').should('exist');
        });

        it('should show validation error when submitting without email', () => {
            cy.get('[data-testid="email-input"]').clear();
            cy.get('[data-testid="update-user-button"]').click();
            cy.get('[data-testid="email-input"]:invalid').should('exist');
        });
    });
});
