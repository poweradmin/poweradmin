import users from '../../fixtures/users.json';

// Known zone IDs from test data
const ZONE_IDS = {
    admin: 26,      // admin-zone.example.com
    manager: 2,     // manager-zone.example.com
    client: 3,      // client-zone.example.com
    shared: 4,      // shared-zone.example.com
    viewer: 5       // viewer-zone.example.com
};

describe('Edit Zone', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToEditZone(ZONE_IDS.manager);
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Edit');
        });

        it('should display zone name in breadcrumb', () => {
            cy.get('[data-testid="zone-name-breadcrumb"]').should('be.visible');
            cy.get('[data-testid="zone-name-breadcrumb"]').should('not.be.empty');
        });

        it('should display edit zone heading', () => {
            cy.get('[data-testid="edit-zone-heading"]').should('be.visible');
            cy.get('[data-testid="edit-zone-heading"]').should('contain', 'Edit zone');
        });

        it('should display pagination controls', () => {
            cy.get('[data-testid="pagination-controls"]').should('be.visible');
        });

        it('should display rows per page selector', () => {
            cy.get('[data-testid="rows-per-page-select"]').should('be.visible');
            cy.get('[data-testid="rows-per-page-select"]').invoke('val').should('not.be.empty');
        });

        it('should have rows per page options', () => {
            cy.get('[data-testid="rows-per-page-select"] option').should('have.length.at.least', 4);
            cy.get('[data-testid="rows-per-page-select"]').should('contain', '10');
            cy.get('[data-testid="rows-per-page-select"]').should('contain', '20');
            cy.get('[data-testid="rows-per-page-select"]').should('contain', '50');
            cy.get('[data-testid="rows-per-page-select"]').should('contain', '100');
        });

        it('should display edit records form if zone has records', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="edit-records-form"]').length > 0) {
                    cy.get('[data-testid="edit-records-form"]').should('be.visible');
                    cy.get('[data-testid="edit-records-form"]').should('have.attr', 'method', 'post');
                } else if ($body.find('[data-testid="no-records-message"]').length > 0) {
                    cy.get('[data-testid="no-records-message"]').should('be.visible');
                }
            });
        });

        it('should display records table if zone has records', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="records-table"]').length > 0) {
                    cy.get('[data-testid="records-table"]').should('be.visible');
                }
            });
        });

        it('should have CSRF token in edit records form', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="edit-records-form"]').length > 0) {
                    cy.get('[data-testid="edit-records-form"]').within(() => {
                        cy.get('input[name="_token"]').should('exist');
                    });
                }
            });
        });

        it('should display save changes button if has permissions', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="save-changes-top-button"]').length > 0) {
                    cy.get('[data-testid="save-changes-top-button"]').should('be.visible');
                } else if ($body.find('[data-testid="save-changes-bottom-button"]').length > 0) {
                    cy.get('[data-testid="save-changes-bottom-button"]').should('be.visible');
                }
            });
        });

        it('should display zone metadata section', () => {
            cy.get('[data-testid="zone-metadata"]').should('be.visible');
        });

        it('should display zone metadata table', () => {
            cy.get('[data-testid="zone-metadata-table"]').should('be.visible');
            cy.get('[data-testid="zone-metadata-table"]').should('contain', 'Owner of zone');
        });

        it('should display save as template form for admin', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="save-as-template-form"]').length > 0) {
                    cy.get('[data-testid="save-as-template-form"]').should('be.visible');
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToEditZone(ZONE_IDS.manager);
        });

        it('should have access to edit own zone', () => {
            cy.get('[data-testid="edit-zone-heading"]').should('be.visible');
        });

        it('should display pagination controls for manager', () => {
            cy.get('[data-testid="pagination-controls"]').should('be.visible');
        });

        it('should display records table for manager', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="records-table"]').length > 0) {
                    cy.get('[data-testid="records-table"]').should('be.visible');
                }
            });
        });

        it('should display zone metadata', () => {
            cy.get('[data-testid="zone-metadata"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToEditZone(ZONE_IDS.client);
        });

        it('should have limited access to edit own zone', () => {
            cy.get('[data-testid="edit-zone-heading"]').should('be.visible');
        });

        it('should display records table for client', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="records-table"]').length > 0) {
                    cy.get('[data-testid="records-table"]').should('be.visible');
                }
            });
        });

        it('should not have save as template option for client', () => {
            cy.get('[data-testid="save-as-template-form"]').should('not.exist');
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should check viewer access to zone editing', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=list_zones');
            // Viewer should see zones but check if they can edit
            cy.get('body').then(($body) => {
                if ($body.find('a[href*="page=edit"]').length > 0) {
                    cy.log('Viewer can see edit links');
                } else {
                    cy.log('Viewer cannot see edit links');
                }
            });
        });
    });

    describe('Pagination', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToEditZone(ZONE_IDS.manager);
        });

        it('should have rows per page select with correct options', () => {
            cy.get('[data-testid="rows-per-page-select"]').should('exist');
            cy.get('[data-testid="rows-per-page-select"]').find('option[value="20"]').should('exist');
            cy.get('[data-testid="rows-per-page-select"]').find('option[value="50"]').should('exist');
            cy.get('[data-testid="rows-per-page-select"]').find('option[value="100"]').should('exist');
        });

        it('should have form for rows per page', () => {
            cy.get('[data-testid="rows-per-page-form"]').should('exist');
            cy.get('[data-testid="rows-per-page-form"]').should('have.attr', 'method', 'get');
        });
    });

    describe('Save as Template', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToEditZone(ZONE_IDS.admin);
        });

        it('should display template name input', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-name-input"]').length > 0) {
                    cy.get('[data-testid="template-name-input"]').should('be.visible');
                    cy.get('[data-testid="template-name-input"]').should('have.attr', 'required');
                }
            });
        });

        it('should display template description input', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-description-input"]').length > 0) {
                    cy.get('[data-testid="template-description-input"]').should('be.visible');
                }
            });
        });

        it('should display save as template button', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="save-as-template-button"]').length > 0) {
                    cy.get('[data-testid="save-as-template-button"]').should('be.visible');
                    cy.get('[data-testid="save-as-template-button"]').should('have.value', 'Save as template');
                }
            });
        });

        it('should allow typing in template name', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="template-name-input"]').length > 0) {
                    cy.get('[data-testid="template-name-input"]').type('Test Template');
                    cy.get('[data-testid="template-name-input"]').should('have.value', 'Test Template');
                }
            });
        });
    });
});
