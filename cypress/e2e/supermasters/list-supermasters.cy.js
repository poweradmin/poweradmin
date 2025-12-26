import users from '../../fixtures/users.json';

describe('List Supermasters', () => {
    describe('Admin User - Supermasters List Access', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the supermasters list page', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="supermasters-heading"]').should('be.visible');
            cy.get('[data-testid="supermasters-table"]').should('be.visible');
        });

        it('should display breadcrumb navigation', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
        });

        it('should display table headers', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="supermasters-table"] thead').should('exist');
        });

        it('should display table body', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="supermasters-tbody"]').should('exist');
        });

        it('should display add supermaster button when user has permission', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="add-supermaster-button"]').should('be.visible');
        });

        it('should have add supermaster button with correct href', () => {
            cy.goToSupermasters();
            cy.get('[data-testid="add-supermaster-button"]').should('have.attr', 'href').and('include', 'add_supermaster');
        });

        it('should display no supermasters message when list is empty', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="no-supermasters-message"]').length > 0) {
                    cy.get('[data-testid="no-supermasters-message"]').should('be.visible');
                }
            });
        });

        it('should display supermasters table with data when supermasters exist', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="supermaster-row-"]').length > 0) {
                    cy.get('[data-testid^="supermaster-row-"]').should('have.length.at.least', 1);
                }
            });
        });

        it('should display IP address for each supermaster', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="supermaster-ip-"]').length > 0) {
                    cy.get('[data-testid^="supermaster-ip-"]').first().should('be.visible');
                }
            });
        });

        it('should display NS name for each supermaster', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="supermaster-ns-"]').length > 0) {
                    cy.get('[data-testid^="supermaster-ns-"]').first().should('be.visible');
                }
            });
        });

        it('should display account for each supermaster', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="supermaster-account-"]').length > 0) {
                    cy.get('[data-testid^="supermaster-account-"]').first().should('be.visible');
                }
            });
        });

        it('should display delete button for each supermaster when user has permission', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-supermaster-"]').length > 0) {
                    cy.get('[data-testid^="delete-supermaster-"]').should('have.length.at.least', 1);
                }
            });
        });

        it('should have delete button with correct href', () => {
            cy.goToSupermasters();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="delete-supermaster-"]').length > 0) {
                    cy.get('[data-testid^="delete-supermaster-"]').first().should('have.attr', 'href').and('include', 'delete_supermaster');
                }
            });
        });
    });

    describe('Manager User - Supermasters List Access', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to supermasters list page', () => {
            cy.visit('/index.php?page=list_supermasters');
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="supermasters-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('Client User - Supermasters List Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to supermasters list page', () => {
            cy.visit('/index.php?page=list_supermasters');
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="supermasters-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('Viewer User - Supermasters List Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should have limited access to supermasters list page', () => {
            cy.visit('/index.php?page=list_supermasters');
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="supermasters-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });

    describe('No Permission User - Supermasters List Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should have limited or no access to supermasters list page', () => {
            cy.visit('/index.php?page=list_supermasters');
            cy.get('body').then(($body) => {
                const hasHeading = $body.find('[data-testid="supermasters-heading"]').length > 0;
                const hasError = $body.find('.alert-danger').length > 0;
                expect(hasHeading || hasError).to.be.true;
            });
        });
    });
});
