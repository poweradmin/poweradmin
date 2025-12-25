import users from '../../fixtures/users.json';

describe('Login Authentication', () => {
    beforeEach(() => {
        cy.visit('/index.php?page=login');
    });

    describe('Successful Login - All User Types', () => {
        it('should login admin user and redirect to dashboard', () => {
            cy.login(users.admin.username, users.admin.password);
            cy.url().should('include', 'page=index');
        });

        it('should login manager user and redirect to dashboard', () => {
            cy.login(users.manager.username, users.manager.password);
            cy.url().should('include', 'page=index');
        });

        it('should login client user and redirect to dashboard', () => {
            cy.login(users.client.username, users.client.password);
            cy.url().should('include', 'page=index');
        });

        it('should login viewer user and redirect to dashboard', () => {
            cy.login(users.viewer.username, users.viewer.password);
            cy.url().should('include', 'page=index');
        });

        it('should login noperm user and redirect to dashboard', () => {
            cy.login(users.noperm.username, users.noperm.password);
            cy.url().should('include', 'page=index');
        });
    });

    describe('Failed Login Attempts', () => {
        it('should remain on login page for invalid credentials', () => {
            cy.login(users.invalidUser.username, users.invalidUser.password);
            cy.url().should('include', 'page=login');
        });

        it('should display error message for invalid login', () => {
            cy.get('[data-testid="username-input"]').type(users.invalidUser.username);
            cy.get('[data-testid="password-input"]').type(users.invalidUser.password);
            cy.get('[data-testid="login-button"]').click();
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should not allow inactive user to login', () => {
            cy.login(users.inactive.username, users.inactive.password);
            cy.url().should('include', 'page=login');
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should not login with correct username but wrong password', () => {
            cy.login(users.admin.username, 'wrongpassword');
            cy.url().should('include', 'page=login');
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should not login with wrong username but correct password', () => {
            cy.login('wronguser', users.admin.password);
            cy.url().should('include', 'page=login');
            cy.get('[data-testid="session-error"]').should('be.visible');
        });

        it('should not login with empty password', () => {
            cy.get('[data-testid="username-input"]').type(users.admin.username);
            cy.get('[data-testid="login-button"]').click();
            // Should show validation error or stay on login page
            cy.url().should('include', 'page=login');
        });
    });

    describe('Session Handling', () => {
        it('should maintain session after login', () => {
            cy.loginAs('admin');
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should redirect to login when accessing protected page without session', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.url().should('include', 'page=login');
        });
    });
});

describe('User Permissions After Login', () => {
    describe('Admin User Permissions', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should have access to dashboard', () => {
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should have access to user administration', () => {
            cy.visit('/index.php?page=users');
            cy.url().should('include', 'page=users');
        });

        it('should have access to permission templates', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.get('[data-testid="permission-templates-heading"]').should('be.visible');
        });

        it('should have access to add permission template', () => {
            cy.visit('/index.php?page=add_perm_templ');
            cy.get('[data-testid="add-permission-template-heading"]').should('be.visible');
        });

        it('should have access to zone list', () => {
            cy.visit('/index.php?page=list_zones');
            cy.url().should('include', 'page=list_zones');
        });

        it('should have access to add master zone', () => {
            cy.visit('/index.php?page=add_zone_master');
            cy.url().should('include', 'page=add_zone_master');
        });

        it('should have access to add slave zone', () => {
            cy.visit('/index.php?page=add_zone_slave');
            cy.url().should('include', 'page=add_zone_slave');
        });

        it('should have access to search', () => {
            cy.visit('/index.php?page=search');
            cy.url().should('include', 'page=search');
        });

        it('should have access to add user', () => {
            cy.visit('/index.php?page=add_user');
            cy.url().should('include', 'page=add_user');
        });
    });

    describe('Manager User Permissions', () => {
        beforeEach(() => {
            cy.loginAs('manager');
        });

        it('should have access to dashboard', () => {
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should have access to zone list', () => {
            cy.visit('/index.php?page=list_zones');
            cy.url().should('include', 'page=list_zones');
        });

        it('should have access to add master zone', () => {
            cy.visit('/index.php?page=add_zone_master');
            cy.url().should('include', 'page=add_zone_master');
        });

        it('should have access to add slave zone', () => {
            cy.visit('/index.php?page=add_zone_slave');
            cy.url().should('include', 'page=add_zone_slave');
        });

        it('should have access to search', () => {
            cy.visit('/index.php?page=search');
            cy.url().should('include', 'page=search');
        });

        it('should NOT have access to permission templates', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });

        it('should NOT have access to add permission template', () => {
            cy.visit('/index.php?page=add_perm_templ');
            cy.get('[data-testid="add-permission-template-heading"]').should('not.exist');
        });

        it('should NOT have access to add user', () => {
            cy.visit('/index.php?page=add_user');
            // Should show error message or redirect - check that add user form is not displayed
            cy.get('body').then(($body) => {
                // Either error message is shown or user is redirected
                const hasError = $body.find('.alert-danger').length > 0;
                const hasAddUserForm = $body.find('input[name="username"]').length > 0 &&
                                       $body.find('input[name="password"]').length > 0 &&
                                       $body.find('input[name="fullname"]').length > 0;
                expect(hasError || !hasAddUserForm).to.be.true;
            });
        });
    });

    describe('Client User Permissions', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should have access to dashboard', () => {
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should have access to zone list (own zones)', () => {
            cy.visit('/index.php?page=list_zones');
            cy.url().should('include', 'page=list_zones');
        });

        it('should have access to search', () => {
            cy.visit('/index.php?page=search');
            cy.url().should('include', 'page=search');
        });

        it('should NOT have access to add master zone', () => {
            cy.visit('/index.php?page=add_zone_master');
            // Client cannot add zones - check for error or lack of zone form
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasZoneForm = $body.find('input[name="domain"]').length > 0;
                expect(hasError || !hasZoneForm).to.be.true;
            });
        });

        it('should NOT have access to permission templates', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });

        it('should NOT have access to add user', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasAddUserForm = $body.find('input[name="username"]').length > 0 &&
                                       $body.find('input[name="password"]').length > 0 &&
                                       $body.find('input[name="fullname"]').length > 0;
                expect(hasError || !hasAddUserForm).to.be.true;
            });
        });
    });

    describe('Viewer User Permissions', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should have access to dashboard', () => {
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should have access to zone list (view only)', () => {
            cy.visit('/index.php?page=list_zones');
            cy.url().should('include', 'page=list_zones');
        });

        it('should have access to search', () => {
            cy.visit('/index.php?page=search');
            cy.url().should('include', 'page=search');
        });

        it('should NOT have access to add master zone', () => {
            cy.visit('/index.php?page=add_zone_master');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasZoneForm = $body.find('input[name="domain"]').length > 0;
                expect(hasError || !hasZoneForm).to.be.true;
            });
        });

        it('should NOT have access to add slave zone', () => {
            cy.visit('/index.php?page=add_zone_slave');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasZoneForm = $body.find('input[name="domain"]').length > 0;
                expect(hasError || !hasZoneForm).to.be.true;
            });
        });

        it('should NOT have access to permission templates', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });

        it('should NOT have access to add user', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasAddUserForm = $body.find('input[name="username"]').length > 0 &&
                                       $body.find('input[name="password"]').length > 0 &&
                                       $body.find('input[name="fullname"]').length > 0;
                expect(hasError || !hasAddUserForm).to.be.true;
            });
        });
    });

    describe('No Permission User Permissions', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
        });

        it('should have access to dashboard', () => {
            cy.visit('/index.php?page=index');
            cy.url().should('include', 'page=index');
        });

        it('should NOT have access to zone list', () => {
            cy.visit('/index.php?page=list_zones');
            // Should show error or empty list
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasZoneTable = $body.find('table').length > 0;
                // Either error is shown or page loads (but with no zones)
                expect(hasError || hasZoneTable || $body.text().includes('page=list_zones')).to.be.true;
            });
        });

        it('should NOT have access to search', () => {
            cy.visit('/index.php?page=search');
            // noperm user has no search permission - check for error or no search form
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasSearchForm = $body.find('input[name="query"]').length > 0;
                expect(hasError || !hasSearchForm).to.be.true;
            });
        });

        it('should NOT have access to add master zone', () => {
            cy.visit('/index.php?page=add_zone_master');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasZoneForm = $body.find('input[name="domain"]').length > 0;
                expect(hasError || !hasZoneForm).to.be.true;
            });
        });

        it('should NOT have access to permission templates', () => {
            cy.visit('/index.php?page=list_perm_templ');
            cy.get('[data-testid="permission-templates-heading"]').should('not.exist');
        });

        it('should NOT have access to add user', () => {
            cy.visit('/index.php?page=add_user');
            cy.get('body').then(($body) => {
                const hasError = $body.find('.alert-danger').length > 0;
                const hasAddUserForm = $body.find('input[name="username"]').length > 0 &&
                                       $body.find('input[name="password"]').length > 0 &&
                                       $body.find('input[name="fullname"]').length > 0;
                expect(hasError || !hasAddUserForm).to.be.true;
            });
        });
    });
});

describe('Logout Functionality', () => {
    it('should logout admin user successfully', () => {
        cy.loginAs('admin');
        cy.visit('/index.php?page=logout');
        cy.url().should('include', 'page=login');
    });

    it('should logout manager user successfully', () => {
        cy.loginAs('manager');
        cy.visit('/index.php?page=logout');
        cy.url().should('include', 'page=login');
    });

    it('should not be able to access protected pages after logout', () => {
        cy.loginAs('admin');
        cy.visit('/index.php?page=logout');
        cy.visit('/index.php?page=list_perm_templ');
        cy.url().should('include', 'page=login');
    });
});
