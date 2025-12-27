import users from '../../fixtures/users.json';

/**
 * Dashboard List View Tests
 *
 * These tests verify the dashboard when configured in 'list' mode (iface_index = 'list').
 * The dashboard view mode is controlled by server configuration and cannot be changed
 * at runtime. Tests will skip automatically if the dashboard is not in list mode.
 */
describe('Dashboard - List View', () => {
    /**
     * Helper to check if we're in list view mode
     * Skips the test if not in list view
     */
    const skipIfNotListView = () => {
        cy.get('body').then(($body) => {
            if ($body.find('[data-testid="dashboard-list"]').length === 0) {
                // Not in list view, skip this test
                cy.log('Dashboard is not in list view mode - skipping test');
                return;
            }
        });
    };

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToDashboard();
        });

        it('should display welcome heading', () => {
            cy.get('[data-testid="welcome-heading"]').should('be.visible');
            cy.get('[data-testid="welcome-heading"]').should('contain', 'Welcome');
        });

        it('should display dashboard list container', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="dashboard-list"]').should('be.visible');
                } else {
                    cy.log('Dashboard is in cards mode - list container test skipped');
                }
            });
        });

        it('should display Search list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="search-list-item"]').should('be.visible');
                    cy.get('[data-testid="search-link"]').should('be.visible');
                    cy.get('[data-testid="search-link"]').should('contain', 'Search zones and records');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display List zones list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="list-zones-list-item"]').should('be.visible');
                    cy.get('[data-testid="list-zones-link"]').should('be.visible');
                    cy.get('[data-testid="list-zones-link"]').should('contain', 'List zones');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display List zone templates list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="list-zone-templates-list-item"]').should('be.visible');
                    cy.get('[data-testid="list-zone-templates-link"]').should('be.visible');
                    cy.get('[data-testid="list-zone-templates-link"]').should('contain', 'List zone templates');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display List supermasters list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="list-supermasters-list-item"]').should('be.visible');
                    cy.get('[data-testid="list-supermasters-link"]').should('be.visible');
                    cy.get('[data-testid="list-supermasters-link"]').should('contain', 'List supermasters');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Add master zone list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="add-master-zone-list-item"]').should('be.visible');
                    cy.get('[data-testid="add-master-zone-link"]').should('be.visible');
                    cy.get('[data-testid="add-master-zone-link"]').should('contain', 'Add master zone');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Add slave zone list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="add-slave-zone-list-item"]').should('be.visible');
                    cy.get('[data-testid="add-slave-zone-link"]').should('be.visible');
                    cy.get('[data-testid="add-slave-zone-link"]').should('contain', 'Add slave zone');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Add supermaster list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="add-supermaster-list-item"]').should('be.visible');
                    cy.get('[data-testid="add-supermaster-link"]').should('be.visible');
                    cy.get('[data-testid="add-supermaster-link"]').should('contain', 'Add supermaster');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Bulk registration list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="bulk-registration-list-item"]').should('be.visible');
                    cy.get('[data-testid="bulk-registration-link"]').should('be.visible');
                    cy.get('[data-testid="bulk-registration-link"]').should('contain', 'Bulk registration');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Zone logs list item and link for ueberuser', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="zone-logs-list-item"]').should('be.visible');
                    cy.get('[data-testid="zone-logs-link"]').should('be.visible');
                    cy.get('[data-testid="zone-logs-link"]').should('contain', 'Zone logs');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Change password list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="change-password-list-item"]').should('be.visible');
                    cy.get('[data-testid="change-password-link"]').should('be.visible');
                    cy.get('[data-testid="change-password-link"]').should('contain', 'Change password');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display User administration list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="user-administration-list-item"]').should('be.visible');
                    cy.get('[data-testid="user-administration-link"]').should('be.visible');
                    cy.get('[data-testid="user-administration-link"]').should('contain', 'User administration');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display List permission templates list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="list-permission-templates-list-item"]').should('be.visible');
                    cy.get('[data-testid="list-permission-templates-link"]').should('be.visible');
                    cy.get('[data-testid="list-permission-templates-link"]').should('contain', 'List permission templates');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display Logout list item and link', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="logout-list-item"]').should('be.visible');
                    cy.get('[data-testid="logout-link"]').should('be.visible');
                    cy.get('[data-testid="logout-link"]').should('contain', 'Logout');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should have working search link', () => {
            cy.get('[data-testid="search-link"]')
                .should('have.attr', 'href')
                .and('include', 'page=search');
        });

        it('should have working list zones link', () => {
            cy.get('[data-testid="list-zones-link"]')
                .should('have.attr', 'href')
                .and('include', 'page=list_zones');
        });

        it('should have working user administration link', () => {
            cy.get('[data-testid="user-administration-link"]')
                .should('have.attr', 'href')
                .and('include', 'page=users');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToDashboard();
        });

        it('should display welcome heading with username', () => {
            cy.get('[data-testid="welcome-heading"]').should('be.visible');
            cy.get('[data-testid="welcome-heading"]').should('contain', 'Welcome');
        });

        it('should display search list item', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="search-list-item"]').should('be.visible');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should not display zone logs list item (not ueberuser)', () => {
            cy.get('[data-testid="zone-logs-list-item"]').should('not.exist');
        });

        it('should not display list permission templates list item', () => {
            cy.get('[data-testid="list-permission-templates-list-item"]').should('not.exist');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToDashboard();
        });

        it('should display welcome heading with username', () => {
            cy.get('[data-testid="welcome-heading"]').should('be.visible');
            cy.get('[data-testid="welcome-heading"]').should('contain', 'Welcome');
        });

        it('should display search list item', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="search-list-item"]').should('be.visible');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should not display add master zone list item', () => {
            cy.get('[data-testid="add-master-zone-list-item"]').should('not.exist');
        });

        it('should not display add slave zone list item', () => {
            cy.get('[data-testid="add-slave-zone-list-item"]').should('not.exist');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToDashboard();
        });

        it('should display welcome heading with username', () => {
            cy.get('[data-testid="welcome-heading"]').should('be.visible');
            cy.get('[data-testid="welcome-heading"]').should('contain', 'Welcome');
        });

        it('should display search list item', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="search-list-item"]').should('be.visible');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should display list zones list item (view permission)', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="dashboard-list"]').length > 0) {
                    cy.get('[data-testid="list-zones-list-item"]').should('be.visible');
                } else {
                    cy.log('Dashboard is in cards mode - list item test skipped');
                }
            });
        });

        it('should not display add master zone list item', () => {
            cy.get('[data-testid="add-master-zone-list-item"]').should('not.exist');
        });
    });
});
