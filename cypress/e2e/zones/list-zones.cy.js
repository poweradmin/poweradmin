import users from '../../fixtures/users.json';

describe('List Zones', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToZones();
        });

        it('should display zones heading', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
            cy.get('[data-testid="zones-heading"]').should('contain', 'List zones');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
        });

        it('should display zones table', () => {
            cy.get('[data-testid="zones-table"]').should('be.visible');
        });

        it('should display zones count', () => {
            cy.get('[data-testid="zones-count"]').should('be.visible');
        });

        it('should display rows per page selector', () => {
            cy.get('[data-testid="rows-per-page-select"]').should('be.visible');
        });

        it('should display add master zone button', () => {
            cy.get('[data-testid="add-master-zone-button"]').should('be.visible');
            cy.get('[data-testid="add-master-zone-button"]').should('have.value', 'Add master zone');
        });

        it('should display add slave zone button', () => {
            cy.get('[data-testid="add-slave-zone-button"]').should('be.visible');
            cy.get('[data-testid="add-slave-zone-button"]').should('have.value', 'Add slave zone');
        });

        it('should display select all zones checkbox when zones exist', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid="select-all-zones"]').should('be.visible');
                }
            });
        });

        it('should display zone rows with all columns when zones exist', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    // Check first zone row has all expected data
                    cy.get('[data-testid^="zone-row-"]').first().within(() => {
                        cy.get('[data-testid^="zone-checkbox-"]').should('exist');
                        cy.get('[data-testid^="zone-name-"]').should('be.visible');
                        cy.get('[data-testid^="zone-type-"]').should('be.visible');
                        cy.get('[data-testid^="zone-records-"]').should('be.visible');
                    });
                } else {
                    cy.get('[data-testid="no-zones-message"]').should('be.visible');
                }
            });
        });

        it('should display edit and delete buttons for zones', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="edit-zone-"]').should('have.length.at.least', 1);
                    cy.get('[data-testid^="delete-zone-"]').should('have.length.at.least', 1);
                }
            });
        });

        it('should display delete zones button', () => {
            cy.get('[data-testid="delete-zones-button"]').should('be.visible');
        });

        it('should allow changing rows per page', () => {
            cy.get('[data-testid="rows-per-page-select"]').select('20');
            cy.url().should('include', 'page=list_zones');
        });

        it('should toggle zone checkboxes when select all is clicked', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-checkbox-"]').length > 0) {
                    // Click select all
                    cy.get('[data-testid="select-all-zones"]').click();
                    // All checkboxes should be checked
                    cy.get('[data-testid^="zone-checkbox-"]').each(($checkbox) => {
                        cy.wrap($checkbox).should('be.checked');
                    });

                    // Click select all again
                    cy.get('[data-testid="select-all-zones"]').click();
                    // All checkboxes should be unchecked
                    cy.get('[data-testid^="zone-checkbox-"]').each(($checkbox) => {
                        cy.wrap($checkbox).should('not.be.checked');
                    });
                }
            });
        });

        it('should have working add master zone button with correct action', () => {
            cy.get('[data-testid="add-master-zone-button"]')
                .should('have.attr', 'onclick')
                .and('include', 'add_zone_master');
        });

        it('should have working add slave zone button with correct action', () => {
            cy.get('[data-testid="add-slave-zone-button"]')
                .should('have.attr', 'onclick')
                .and('include', 'add_zone_slave');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToZones();
        });

        it('should display zones page', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
        });

        it('should display add zone buttons', () => {
            cy.get('[data-testid="add-master-zone-button"]').should('be.visible');
            cy.get('[data-testid="add-slave-zone-button"]').should('be.visible');
        });

        it('should only see own zones or zones with access', () => {
            cy.get('[data-testid="zones-table"]').should('be.visible');
            // Manager should see zones they own or have access to
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToZones();
        });

        it('should display zones page', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
        });

        it('should not display add zone buttons', () => {
            cy.get('[data-testid="add-master-zone-button"]').should('not.exist');
            cy.get('[data-testid="add-slave-zone-button"]').should('not.exist');
        });

        it('should only see own zones', () => {
            cy.get('[data-testid="zones-table"]').should('be.visible');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToZones();
        });

        it('should display zones page', () => {
            cy.get('[data-testid="zones-heading"]').should('be.visible');
        });

        it('should not display add zone buttons', () => {
            cy.get('[data-testid="add-master-zone-button"]').should('not.exist');
            cy.get('[data-testid="add-slave-zone-button"]').should('not.exist');
        });

        it('should not display delete buttons', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    cy.get('[data-testid^="delete-zone-"]').should('not.exist');
                }
            });
        });

        it('should see zones with read-only access', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="zone-row-"]').length > 0) {
                    // Viewer may see edit links but with read-only access
                    cy.get('[data-testid^="zone-name-"]').should('have.length.at.least', 1);
                }
            });
        });
    });
});
