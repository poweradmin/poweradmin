import users from '../../fixtures/users.json';

describe('Header Navigation', () => {
    describe('Logged Out User', () => {
        it('should not display user navigation items when not logged in', () => {
            cy.visit('/index.php?page=login');
            // Navigation container may exist but should be empty or hidden for logged-out users
            cy.get('[data-testid="nav-zones"]').should('not.exist');
            cy.get('[data-testid="nav-users"]').should('not.exist');
            cy.get('[data-testid="nav-account"]').should('not.exist');
        });
    });

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.visit('/index.php');
        });

        it('should display site header', () => {
            cy.get('[data-testid="site-header"]').should('be.visible');
        });

        it('should display home link', () => {
            cy.get('[data-testid="home-link"]').should('be.visible');
            cy.get('[data-testid="home-link"]').should('have.attr', 'href', 'index.php');
        });

        it('should display logo image', () => {
            cy.get('[data-testid="logo-image"]').should('be.visible');
            cy.get('[data-testid="logo-image"]').should('have.attr', 'src', 'assets/logo.png');
            cy.get('[data-testid="logo-image"]').should('have.attr', 'height', '32');
        });

        it('should display site title', () => {
            cy.get('[data-testid="site-title"]').should('be.visible');
            cy.get('[data-testid="site-title"]').should('not.be.empty');
        });

        it('should display main navigation', () => {
            cy.get('[data-testid="main-navigation"]').should('be.visible');
        });

        // Search Navigation
        it('should display search navigation item', () => {
            cy.get('[data-testid="nav-search"]').should('be.visible');
        });

        it('should display search icon link', () => {
            cy.get('[data-testid="nav-search-icon"]').should('be.visible');
            cy.get('[data-testid="nav-search-icon"]').should('have.attr', 'href').and('include', 'page=search');
        });

        it('should display search text link', () => {
            cy.get('[data-testid="nav-search-link"]').should('be.visible');
            cy.get('[data-testid="nav-search-link"]').should('contain', 'Search');
            cy.get('[data-testid="nav-search-link"]').should('have.attr', 'href').and('include', 'page=search');
        });

        // Zones Navigation
        it('should display zones navigation item', () => {
            cy.get('[data-testid="nav-zones"]').should('be.visible');
        });

        it('should display zones icon link', () => {
            cy.get('[data-testid="nav-zones-icon"]').should('be.visible');
            cy.get('[data-testid="nav-zones-icon"]').should('have.attr', 'href').and('include', 'page=list_zones');
        });

        it('should display zones dropdown toggle', () => {
            cy.get('[data-testid="nav-zones-dropdown"]').should('be.visible');
            cy.get('[data-testid="nav-zones-dropdown"]').should('contain', 'Zones');
            cy.get('[data-testid="nav-zones-dropdown"]').should('have.attr', 'data-bs-toggle', 'dropdown');
        });

        it('should display zones dropdown menu', () => {
            cy.get('[data-testid="nav-zones-menu"]').should('exist');
        });

        it('should display list zones menu item', () => {
            cy.get('[data-testid="nav-list-zones"]').should('exist');
            cy.get('[data-testid="nav-list-zones"]').should('contain', 'List zones');
            cy.get('[data-testid="nav-list-zones"]').should('have.attr', 'href').and('include', 'page=list_zones');
        });

        it('should display add master zone menu item', () => {
            cy.get('[data-testid="nav-add-master-zone"]').should('exist');
            cy.get('[data-testid="nav-add-master-zone"]').should('contain', 'Add master zone');
            cy.get('[data-testid="nav-add-master-zone"]').should('have.attr', 'href').and('include', 'page=add_zone_master');
        });

        it('should display add slave zone menu item', () => {
            cy.get('[data-testid="nav-add-slave-zone"]').should('exist');
            cy.get('[data-testid="nav-add-slave-zone"]').should('contain', 'Add slave zone');
            cy.get('[data-testid="nav-add-slave-zone"]').should('have.attr', 'href').and('include', 'page=add_zone_slave');
        });

        it('should display bulk registration menu item', () => {
            cy.get('[data-testid="nav-bulk-registration"]').should('exist');
            cy.get('[data-testid="nav-bulk-registration"]').should('contain', 'Bulk registration');
            cy.get('[data-testid="nav-bulk-registration"]').should('have.attr', 'href').and('include', 'page=bulk_registration');
        });

        it('should display zone logs menu item for admin', () => {
            cy.get('[data-testid="nav-zone-logs"]').should('exist');
            cy.get('[data-testid="nav-zone-logs"]').should('contain', 'Zone logs');
            cy.get('[data-testid="nav-zone-logs"]').should('have.attr', 'href').and('include', 'page=list_log_zones');
        });

        // Users Navigation
        it('should display users navigation item', () => {
            cy.get('[data-testid="nav-users"]').should('be.visible');
        });

        it('should display users icon link', () => {
            cy.get('[data-testid="nav-users-icon"]').should('be.visible');
            cy.get('[data-testid="nav-users-icon"]').should('have.attr', 'href').and('include', 'page=users');
        });

        it('should display users dropdown toggle', () => {
            cy.get('[data-testid="nav-users-dropdown"]').should('be.visible');
            cy.get('[data-testid="nav-users-dropdown"]').should('contain', 'Users');
        });

        it('should display users dropdown menu', () => {
            cy.get('[data-testid="nav-users-menu"]').should('exist');
        });

        it('should display user administration menu item', () => {
            cy.get('[data-testid="nav-user-admin"]').should('exist');
            cy.get('[data-testid="nav-user-admin"]').should('contain', 'User administration');
            cy.get('[data-testid="nav-user-admin"]').should('have.attr', 'href').and('include', 'page=users');
        });

        it('should display add user menu item', () => {
            cy.get('[data-testid="nav-add-user"]').should('exist');
            cy.get('[data-testid="nav-add-user"]').should('contain', 'Add user');
            cy.get('[data-testid="nav-add-user"]').should('have.attr', 'href').and('include', 'page=add_user');
        });

        it('should display list permission templates menu item', () => {
            cy.get('[data-testid="nav-list-perm-templates"]').should('exist');
            cy.get('[data-testid="nav-list-perm-templates"]').should('contain', 'List permission templates');
            cy.get('[data-testid="nav-list-perm-templates"]').should('have.attr', 'href').and('include', 'page=list_perm_templ');
        });

        it('should display add permission template menu item', () => {
            cy.get('[data-testid="nav-add-perm-template"]').should('exist');
            cy.get('[data-testid="nav-add-perm-template"]').should('contain', 'Add permission template');
            cy.get('[data-testid="nav-add-perm-template"]').should('have.attr', 'href').and('include', 'page=add_perm_templ');
        });

        it('should display user logs menu item for admin', () => {
            cy.get('[data-testid="nav-user-logs"]').should('exist');
            cy.get('[data-testid="nav-user-logs"]').should('contain', 'User logs');
            cy.get('[data-testid="nav-user-logs"]').should('have.attr', 'href').and('include', 'page=list_log_users');
        });

        // Templates Navigation
        it('should display templates navigation item', () => {
            cy.get('[data-testid="nav-templates"]').should('be.visible');
        });

        it('should display templates icon link', () => {
            cy.get('[data-testid="nav-templates-icon"]').should('be.visible');
            cy.get('[data-testid="nav-templates-icon"]').should('have.attr', 'href').and('include', 'page=list_zone_templ');
        });

        it('should display templates dropdown toggle', () => {
            cy.get('[data-testid="nav-templates-dropdown"]').should('be.visible');
            cy.get('[data-testid="nav-templates-dropdown"]').should('contain', 'Templates');
        });

        it('should display templates dropdown menu', () => {
            cy.get('[data-testid="nav-templates-menu"]').should('exist');
        });

        it('should display list zone templates menu item', () => {
            cy.get('[data-testid="nav-list-zone-templates"]').should('exist');
            cy.get('[data-testid="nav-list-zone-templates"]').should('contain', 'List zone templates');
            cy.get('[data-testid="nav-list-zone-templates"]').should('have.attr', 'href').and('include', 'page=list_zone_templ');
        });

        it('should display add zone template menu item', () => {
            cy.get('[data-testid="nav-add-zone-template"]').should('exist');
            cy.get('[data-testid="nav-add-zone-template"]').should('contain', 'Add zone template');
            cy.get('[data-testid="nav-add-zone-template"]').should('have.attr', 'href').and('include', 'page=add_zone_templ');
        });

        // Account Navigation
        it('should display account navigation item', () => {
            cy.get('[data-testid="nav-account"]').should('be.visible');
        });

        it('should display account icon link', () => {
            cy.get('[data-testid="nav-account-icon"]').should('be.visible');
        });

        it('should display account dropdown toggle', () => {
            cy.get('[data-testid="nav-account-dropdown"]').should('be.visible');
            cy.get('[data-testid="nav-account-dropdown"]').should('contain', 'Account');
        });

        it('should display account dropdown menu', () => {
            cy.get('[data-testid="nav-account-menu"]').should('exist');
        });

        it('should display change password menu item', () => {
            cy.get('[data-testid="nav-change-password"]').should('exist');
            cy.get('[data-testid="nav-change-password"]').should('contain', 'Change password');
            cy.get('[data-testid="nav-change-password"]').should('have.attr', 'href').and('include', 'page=change_password');
        });

        it('should display logout menu item', () => {
            cy.get('[data-testid="nav-logout"]').should('exist');
            cy.get('[data-testid="nav-logout"]').should('contain', 'Logout');
            cy.get('[data-testid="nav-logout"]').should('have.attr', 'href').and('include', 'page=logout');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.visit('/index.php');
        });

        it('should display navigation for manager', () => {
            cy.get('[data-testid="main-navigation"]').should('be.visible');
        });

        it('should display zones navigation', () => {
            cy.get('[data-testid="nav-zones"]').should('be.visible');
        });

        it('should display templates navigation', () => {
            cy.get('[data-testid="nav-templates"]').should('be.visible');
        });

        it('should display account navigation', () => {
            cy.get('[data-testid="nav-account"]').should('be.visible');
        });

        it('should not display zone logs for manager', () => {
            cy.get('[data-testid="nav-zone-logs"]').should('not.exist');
        });

        it('should not display user logs for manager', () => {
            cy.get('[data-testid="nav-user-logs"]').should('not.exist');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.visit('/index.php');
        });

        it('should display limited navigation for client', () => {
            cy.get('[data-testid="main-navigation"]').should('be.visible');
        });

        it('should display account navigation', () => {
            cy.get('[data-testid="nav-account"]').should('be.visible');
        });

        it('should not display add master zone for client', () => {
            cy.get('[data-testid="nav-add-master-zone"]').should('not.exist');
        });

        it('should not display templates navigation for client', () => {
            cy.get('[data-testid="nav-templates"]').should('not.exist');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.visit('/index.php');
        });

        it('should display minimal navigation for viewer', () => {
            cy.get('[data-testid="main-navigation"]').should('be.visible');
        });

        it('should display account navigation', () => {
            cy.get('[data-testid="nav-account"]').should('be.visible');
        });

        it('should have access to search', () => {
            cy.get('[data-testid="nav-search"]').should('be.visible');
        });

        it('should not display add zone options for viewer', () => {
            cy.get('[data-testid="nav-add-master-zone"]').should('not.exist');
            cy.get('[data-testid="nav-add-slave-zone"]').should('not.exist');
        });
    });
});
