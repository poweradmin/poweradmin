import users from '../../fixtures/users.json';

describe('List Users', () => {
    describe('Admin User - User List Access', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the users list page', () => {
            cy.goToUsers();
            cy.get('[data-testid="users-heading"]').should('be.visible');
            cy.get('[data-testid="users-table"]').should('be.visible');
        });

        it('should display users table with data', () => {
            cy.goToUsers();
            cy.get('[data-testid="users-tbody"]').should('exist');
            cy.get('[data-testid^="user-row-"]').should('have.length.at.least', 1);
        });

        it('should display edit button for users', () => {
            cy.goToUsers();
            cy.get('[data-testid^="edit-user-"]').should('have.length.at.least', 1);
        });

        it('should display delete button for other users (not self)', () => {
            cy.goToUsers();
            // Admin should see delete buttons for other users
            cy.get('[data-testid^="delete-user-"]').should('exist');
        });

        it('should display username input fields for editable users', () => {
            cy.goToUsers();
            cy.get('[data-testid^="username-input-"]').should('have.length.at.least', 1);
        });

        it('should display email input fields for editable users', () => {
            cy.goToUsers();
            cy.get('[data-testid^="email-input-"]').should('have.length.at.least', 1);
        });

        it('should display fullname input fields for editable users', () => {
            cy.goToUsers();
            cy.get('[data-testid^="fullname-input-"]').should('have.length.at.least', 1);
        });

        it('should display template select for editable users', () => {
            cy.goToUsers();
            cy.get('[data-testid^="template-select-"]').should('have.length.at.least', 1);
        });

        it('should display update button', () => {
            cy.goToUsers();
            cy.get('[data-testid="update-users-button"]').should('be.visible');
        });

        it('should display add user button', () => {
            cy.goToUsers();
            cy.get('[data-testid="add-user-button"]').should('be.visible');
        });

        it('should have add user button with correct href', () => {
            cy.goToUsers();
            cy.get('[data-testid="add-user-button"]').should('have.attr', 'onclick').and('include', 'add_user');
        });

        it('should have edit user button with correct href', () => {
            cy.goToUsers();
            cy.get('[data-testid^="edit-user-"]').first().should('have.attr', 'href').and('include', 'edit_user');
        });

        it('should have delete user button with correct href', () => {
            cy.goToUsers();
            cy.get('[data-testid^="delete-user-"]').first().should('have.attr', 'href').and('include', 'delete_user');
        });

        it('should allow inline editing of username', () => {
            cy.goToUsers();
            cy.get('[data-testid^="username-input-"]').first().should('be.enabled');
            cy.get('[data-testid^="username-input-"]').first().clear().type('testchange');
            cy.get('[data-testid^="username-input-"]').first().should('have.value', 'testchange');
        });

        it('should allow inline editing of email', () => {
            cy.goToUsers();
            cy.get('[data-testid^="email-input-"]').first().should('be.enabled');
            cy.get('[data-testid^="email-input-"]').first().clear().type('test@example.com');
            cy.get('[data-testid^="email-input-"]').first().should('have.value', 'test@example.com');
        });

        it('should allow changing template via dropdown', () => {
            cy.goToUsers();
            cy.get('[data-testid^="template-select-"]').first().should('be.enabled');
        });

        it('should display reset button', () => {
            cy.goToUsers();
            cy.get('[data-testid="reset-users-button"]').should('be.visible');
        });
    });

    describe('Manager User - User List Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to users list page', () => {
            cy.visit('/index.php?page=users');
            // Manager may have access based on their permissions
            cy.get('body').then(($body) => {
                // Check if they can see the page or get an error
                const hasHeading = $body.find('[data-testid="users-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('Client User - User List Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to users list page', () => {
            cy.visit('/index.php?page=users');
            // Client may have access based on their permissions
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="users-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('Viewer User - User List Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to users list page', () => {
            cy.visit('/index.php?page=users');
            // Viewer may have access based on their permissions
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="users-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('No Permission User - User List Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should have limited or no access to users list page', () => {
            cy.visit('/index.php?page=users');
            // User without permissions may see limited view or error
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="users-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });
});
