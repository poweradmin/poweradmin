import users from '../../fixtures/users.json';

describe('Change Password', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToChangePassword();
        });

        it('should display change password heading', () => {
            cy.get('[data-testid="change-password-heading"]').should('be.visible');
            cy.get('[data-testid="change-password-heading"]').should('contain', 'Change password');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Change password');
        });

        it('should display change password form', () => {
            cy.get('[data-testid="change-password-form"]').should('be.visible');
            cy.get('[data-testid="change-password-form"]').should('have.attr', 'method', 'post');
            cy.get('[data-testid="change-password-form"]').should('have.attr', 'action').and('include', 'page=change_password');
        });

        it('should have novalidate attribute on form', () => {
            cy.get('[data-testid="change-password-form"]').should('have.attr', 'novalidate');
        });

        it('should display current password input', () => {
            cy.get('[data-testid="current-password-input"]').should('be.visible');
            cy.get('[data-testid="current-password-input"]').should('have.attr', 'type', 'password');
            cy.get('[data-testid="current-password-input"]').should('have.attr', 'name', 'old_password');
            cy.get('[data-testid="current-password-input"]').should('have.attr', 'required');
        });

        it('should display current password toggle button', () => {
            cy.get('[data-testid="current-password-toggle"]').should('be.visible');
            cy.get('[data-testid="current-password-toggle"]').should('have.class', 'btn');
            cy.get('[data-testid="current-password-toggle"]').should('have.attr', 'type', 'button');
        });

        it('should have eye icon in current password toggle', () => {
            cy.get('[data-testid="current-password-toggle"] i').should('have.class', 'bi-eye-fill');
        });

        it('should display new password input', () => {
            cy.get('[data-testid="new-password-input"]').should('be.visible');
            cy.get('[data-testid="new-password-input"]').should('have.attr', 'type', 'password');
            cy.get('[data-testid="new-password-input"]').should('have.attr', 'name', 'new_password');
            cy.get('[data-testid="new-password-input"]').should('have.attr', 'required');
        });

        it('should display new password toggle button', () => {
            cy.get('[data-testid="new-password-toggle"]').should('be.visible');
            cy.get('[data-testid="new-password-toggle"]').should('have.class', 'btn');
        });

        it('should have eye icon in new password toggle', () => {
            cy.get('[data-testid="new-password-toggle"] i').should('have.class', 'bi-eye-fill');
        });

        it('should display repeat password input', () => {
            cy.get('[data-testid="repeat-password-input"]').should('be.visible');
            cy.get('[data-testid="repeat-password-input"]').should('have.attr', 'type', 'password');
            cy.get('[data-testid="repeat-password-input"]').should('have.attr', 'name', 'new_password2');
            cy.get('[data-testid="repeat-password-input"]').should('have.attr', 'required');
        });

        it('should display repeat password toggle button', () => {
            cy.get('[data-testid="repeat-password-toggle"]').should('be.visible');
            cy.get('[data-testid="repeat-password-toggle"]').should('have.class', 'btn');
        });

        it('should have eye icon in repeat password toggle', () => {
            cy.get('[data-testid="repeat-password-toggle"] i').should('have.class', 'bi-eye-fill');
        });

        it('should display submit button', () => {
            cy.get('[data-testid="change-password-submit"]').should('be.visible');
            cy.get('[data-testid="change-password-submit"]').should('have.attr', 'type', 'submit');
            cy.get('[data-testid="change-password-submit"]').should('have.value', 'Change password');
        });

        it('should have CSRF token in form', () => {
            cy.get('[data-testid="change-password-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
                cy.get('input[name="_token"]').should('have.attr', 'type', 'hidden');
            });
        });

        it('should allow typing in current password field', () => {
            cy.get('[data-testid="current-password-input"]').clear();
            cy.get('[data-testid="current-password-input"]').type('test-current-password');
            cy.get('[data-testid="current-password-input"]').should('have.value', 'test-current-password');
        });

        it('should allow typing in new password field', () => {
            cy.get('[data-testid="new-password-input"]').clear();
            cy.get('[data-testid="new-password-input"]').type('test-new-password');
            cy.get('[data-testid="new-password-input"]').should('have.value', 'test-new-password');
        });

        it('should allow typing in repeat password field', () => {
            cy.get('[data-testid="repeat-password-input"]').clear();
            cy.get('[data-testid="repeat-password-input"]').type('test-repeat-password');
            cy.get('[data-testid="repeat-password-input"]').should('have.value', 'test-repeat-password');
        });

        it('should have proper input group structure for current password', () => {
            cy.get('[data-testid="current-password-input"]').parent().should('have.class', 'input-group');
        });

        it('should have proper input group structure for new password', () => {
            cy.get('[data-testid="new-password-input"]').parent().should('have.class', 'input-group');
        });

        it('should have proper input group structure for repeat password', () => {
            cy.get('[data-testid="repeat-password-input"]').parent().should('have.class', 'input-group');
        });

        it('should have form-control class on all password inputs', () => {
            cy.get('[data-testid="current-password-input"]').should('have.class', 'form-control');
            cy.get('[data-testid="new-password-input"]').should('have.class', 'form-control');
            cy.get('[data-testid="repeat-password-input"]').should('have.class', 'form-control');
        });

        it('should have form-control-sm class on all password inputs', () => {
            cy.get('[data-testid="current-password-input"]').should('have.class', 'form-control-sm');
            cy.get('[data-testid="new-password-input"]').should('have.class', 'form-control-sm');
            cy.get('[data-testid="repeat-password-input"]').should('have.class', 'form-control-sm');
        });

        it('should have btn-sm class on all toggle buttons', () => {
            cy.get('[data-testid="current-password-toggle"]').should('have.class', 'btn-sm');
            cy.get('[data-testid="new-password-toggle"]').should('have.class', 'btn-sm');
            cy.get('[data-testid="repeat-password-toggle"]').should('have.class', 'btn-sm');
        });

        it('should have btn-outline-secondary class on all toggle buttons', () => {
            cy.get('[data-testid="current-password-toggle"]').should('have.class', 'btn-outline-secondary');
            cy.get('[data-testid="new-password-toggle"]').should('have.class', 'btn-outline-secondary');
            cy.get('[data-testid="repeat-password-toggle"]').should('have.class', 'btn-outline-secondary');
        });

        it('should have btn-primary class on submit button', () => {
            cy.get('[data-testid="change-password-submit"]').should('have.class', 'btn-primary');
        });

        it('should have btn-sm class on submit button', () => {
            cy.get('[data-testid="change-password-submit"]').should('have.class', 'btn-sm');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToChangePassword();
        });

        it('should have access to change password', () => {
            cy.get('[data-testid="change-password-heading"]').should('be.visible');
            cy.get('[data-testid="change-password-form"]').should('be.visible');
        });

        it('should display all password fields for manager', () => {
            cy.get('[data-testid="current-password-input"]').should('be.visible');
            cy.get('[data-testid="new-password-input"]').should('be.visible');
            cy.get('[data-testid="repeat-password-input"]').should('be.visible');
        });

        it('should display submit button for manager', () => {
            cy.get('[data-testid="change-password-submit"]').should('be.visible');
        });

        it('should allow manager to type in password fields', () => {
            cy.get('[data-testid="current-password-input"]').type('current-password');
            cy.get('[data-testid="new-password-input"]').type('new-password');
            cy.get('[data-testid="repeat-password-input"]').type('new-password');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToChangePassword();
        });

        it('should have access to change password', () => {
            cy.get('[data-testid="change-password-heading"]').should('be.visible');
            cy.get('[data-testid="change-password-form"]').should('be.visible');
        });

        it('should display all password fields for client', () => {
            cy.get('[data-testid="current-password-input"]').should('be.visible');
            cy.get('[data-testid="new-password-input"]').should('be.visible');
            cy.get('[data-testid="repeat-password-input"]').should('be.visible');
        });

        it('should display password toggle buttons for client', () => {
            cy.get('[data-testid="current-password-toggle"]').should('be.visible');
            cy.get('[data-testid="new-password-toggle"]').should('be.visible');
            cy.get('[data-testid="repeat-password-toggle"]').should('be.visible');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToChangePassword();
        });

        it('should have access to change password', () => {
            cy.get('[data-testid="change-password-heading"]').should('be.visible');
            cy.get('[data-testid="change-password-form"]').should('be.visible');
        });

        it('should display all password fields for viewer', () => {
            cy.get('[data-testid="current-password-input"]').should('be.visible');
            cy.get('[data-testid="new-password-input"]').should('be.visible');
            cy.get('[data-testid="repeat-password-input"]').should('be.visible');
        });

        it('should display submit button for viewer', () => {
            cy.get('[data-testid="change-password-submit"]').should('be.visible');
        });
    });

    describe('Form Validation', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToChangePassword();
        });

        it('should have required attribute on all password fields', () => {
            cy.get('[data-testid="current-password-input"]').should('have.attr', 'required');
            cy.get('[data-testid="new-password-input"]').should('have.attr', 'required');
            cy.get('[data-testid="repeat-password-input"]').should('have.attr', 'required');
        });

        it('should have needs-validation class on form', () => {
            cy.get('[data-testid="change-password-form"]').should('have.class', 'needs-validation');
        });

        it('should display invalid feedback for current password', () => {
            cy.get('[data-testid="current-password-input"]').siblings('.invalid-feedback').should('exist');
        });

        it('should display invalid feedback for new password', () => {
            cy.get('[data-testid="new-password-input"]').siblings('.invalid-feedback').should('exist');
        });

        it('should display invalid feedback for repeat password', () => {
            cy.get('[data-testid="repeat-password-input"]').siblings('.invalid-feedback').should('exist');
        });
    });

    describe('Password Toggle Functionality', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToChangePassword();
        });

        it('should have onclick handler on current password toggle', () => {
            cy.get('[data-testid="current-password-toggle"]').should('have.attr', 'onclick');
            cy.get('[data-testid="current-password-toggle"]').should('have.attr', 'onclick').and('include', 'showPassword');
        });

        it('should have onclick handler on new password toggle', () => {
            cy.get('[data-testid="new-password-toggle"]').should('have.attr', 'onclick');
            cy.get('[data-testid="new-password-toggle"]').should('have.attr', 'onclick').and('include', 'showPassword');
        });

        it('should have onclick handler on repeat password toggle', () => {
            cy.get('[data-testid="repeat-password-toggle"]').should('have.attr', 'onclick');
            cy.get('[data-testid="repeat-password-toggle"]').should('have.attr', 'onclick').and('include', 'showPassword');
        });

        it('should have unique eye icons for each toggle', () => {
            cy.get('[data-testid="current-password-toggle"] i').should('have.attr', 'id', 'eye1');
            cy.get('[data-testid="new-password-toggle"] i').should('have.attr', 'id', 'eye2');
            cy.get('[data-testid="repeat-password-toggle"] i').should('have.attr', 'id', 'eye3');
        });
    });
});
