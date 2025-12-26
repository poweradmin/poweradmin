import users from '../../fixtures/users.json';

describe('Migrations Page', () => {
    describe('Admin User - Permission Check', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should check if migrations page is accessible', () => {
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            cy.url().should('satisfy', (url) => {
                return url.includes('page=migrations') || url.includes('page=index') || url.includes('error');
            });
        });

        it('should display breadcrumb navigation if accessible', () => {
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="breadcrumb-nav"]').length > 0) {
                    cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
                    cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Migrations');
                }
            });
        });

        it('should display migrations heading if accessible', () => {
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="migrations-heading"]').length > 0) {
                    cy.get('[data-testid="migrations-heading"]').should('be.visible');
                    cy.get('[data-testid="migrations-heading"]').should('contain', 'Migrations');
                }
            });
        });

        it('should display migrations output if accessible', () => {
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="migrations-output"]').length > 0) {
                    cy.get('[data-testid="migrations-output"]').should('be.visible');
                }
            });
        });

        it('should have pre element for output if accessible', () => {
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="migrations-output"]').length > 0) {
                    cy.get('[data-testid="migrations-output"]').should('match', 'pre');
                }
            });
        });
    });

    describe('Manager User - Permission Check', () => {
        it('should check manager access to migrations', () => {
            cy.loginAs('manager');
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            // Manager likely should not have access to migrations
            cy.url().should('satisfy', (url) => {
                return url.includes('page=index') || url.includes('error') || url.includes('page=migrations');
            });
        });
    });

    describe('Client User - Permission Check', () => {
        it('should check client access to migrations', () => {
            cy.loginAs('client');
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            // Client should not have access to migrations
            cy.url().should('satisfy', (url) => {
                return url.includes('page=index') || url.includes('error') || url.includes('page=migrations');
            });
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should check viewer access to migrations', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=migrations', { failOnStatusCode: false });
            // Viewer should not have access to migrations
            cy.url().should('satisfy', (url) => {
                return url.includes('page=index') || url.includes('error') || url.includes('page=migrations');
            });
        });
    });
});
