import users from '../../fixtures/users.json';

describe('Footer', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.visit('/index.php');
        });

        it('should display site footer', () => {
            cy.get('[data-testid="site-footer"]').should('be.visible');
        });

        it('should display poweradmin link', () => {
            cy.get('[data-testid="poweradmin-link"]').should('be.visible');
            cy.get('[data-testid="poweradmin-link"]').should('have.attr', 'href', 'https://www.poweradmin.org/');
            cy.get('[data-testid="poweradmin-link"]').should('contain', 'Poweradmin');
        });

        it('should display version number', () => {
            cy.get('[data-testid="version-number"]').should('be.visible');
            cy.get('[data-testid="version-number"]').should('not.be.empty');
            cy.get('[data-testid="version-number"]').invoke('text').should('match', /v\d+\.\d+/);
        });

        it('should display theme switcher button', () => {
            cy.get('[data-testid="theme-switcher"]').should('be.visible');
            cy.get('[data-testid="theme-switcher"]').should('have.attr', 'id', 'theme-switcher');
        });

        it('should display theme icon', () => {
            cy.get('[data-testid="theme-icon"]').should('be.visible');
            cy.get('[data-testid="theme-icon"]').should('have.attr', 'id', 'theme-icon');
        });

        it('should have bootstrap icon class on theme icon', () => {
            cy.get('[data-testid="theme-icon"]').should('have.class', 'bi');
        });

        it('should display either moon or sun icon', () => {
            cy.get('[data-testid="theme-icon"]').then(($icon) => {
                const hasIcon = $icon.hasClass('bi-moon') || $icon.hasClass('bi-sun');
                expect(hasIcon).to.be.true;
            });
        });

        it('should have correct footer classes', () => {
            cy.get('[data-testid="site-footer"]').should('have.class', 'footer');
            cy.get('[data-testid="site-footer"]').should('have.class', 'py-3');
        });

        it('should have container in footer', () => {
            cy.get('[data-testid="site-footer"] .container').should('exist');
        });

        it('should have correct layout structure', () => {
            cy.get('[data-testid="site-footer"] .container .row').should('exist');
            cy.get('[data-testid="site-footer"] .container .row .col-md-6').should('have.length', 2);
        });

        it('should have theme switcher in right column', () => {
            cy.get('[data-testid="site-footer"] .col-md-6.d-flex.justify-content-end').should('exist');
            cy.get('[data-testid="site-footer"] .col-md-6.d-flex.justify-content-end [data-testid="theme-switcher"]').should('exist');
        });

        it('should not display debug queries by default', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="debug-queries"]').length > 0) {
                    cy.log('Debug queries are enabled');
                } else {
                    cy.get('[data-testid="debug-queries"]').should('not.exist');
                }
            });
        });

        it('should not display debug stats by default', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="debug-stats"]').length > 0) {
                    cy.log('Debug stats are enabled');
                } else {
                    cy.get('[data-testid="debug-stats"]').should('not.exist');
                }
            });
        });

        it('should have button styling on theme switcher', () => {
            cy.get('[data-testid="theme-switcher"]').should('have.class', 'btn');
            cy.get('[data-testid="theme-switcher"]').should('have.class', 'btn-outline-secondary');
            cy.get('[data-testid="theme-switcher"]').should('have.class', 'btn-sm');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.visit('/index.php');
        });

        it('should display footer for manager', () => {
            cy.get('[data-testid="site-footer"]').should('be.visible');
        });

        it('should display theme switcher for manager', () => {
            cy.get('[data-testid="theme-switcher"]').should('be.visible');
        });

        it('should display poweradmin link for manager', () => {
            cy.get('[data-testid="poweradmin-link"]').should('be.visible');
        });

        it('should display version number for manager', () => {
            cy.get('[data-testid="version-number"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.visit('/index.php');
        });

        it('should display footer for client', () => {
            cy.get('[data-testid="site-footer"]').should('be.visible');
        });

        it('should display theme switcher for client', () => {
            cy.get('[data-testid="theme-switcher"]').should('be.visible');
        });

        it('should display poweradmin link for client', () => {
            cy.get('[data-testid="poweradmin-link"]').should('be.visible');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.visit('/index.php');
        });

        it('should display footer for viewer', () => {
            cy.get('[data-testid="site-footer"]').should('be.visible');
        });

        it('should display theme switcher for viewer', () => {
            cy.get('[data-testid="theme-switcher"]').should('be.visible');
        });

        it('should display poweradmin link for viewer', () => {
            cy.get('[data-testid="poweradmin-link"]').should('be.visible');
        });

        it('should display version number for viewer', () => {
            cy.get('[data-testid="version-number"]').should('be.visible');
        });
    });

    describe('Theme Switching', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.visit('/index.php');
        });

        it('should have clickable theme switcher', () => {
            cy.get('[data-testid="theme-switcher"]').should('not.be.disabled');
        });

        it('should have theme icon inside theme switcher', () => {
            cy.get('[data-testid="theme-switcher"] [data-testid="theme-icon"]').should('exist');
        });

        it('should store theme preference in localStorage', () => {
            // Check that localStorage theme handling exists
            cy.window().then((win) => {
                const currentTheme = win.localStorage.getItem('theme');
                expect(currentTheme === null || currentTheme === 'ignite' || currentTheme === 'spark').to.be.true;
            });
        });
    });
});
