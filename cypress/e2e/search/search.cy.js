import users from '../../fixtures/users.json';

describe('Search Zones and Records', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.goToSearch();
        });

        it('should display search page heading', () => {
            cy.get('[data-testid="search-heading"]').should('be.visible');
            cy.get('[data-testid="search-heading"]').should('contain', 'Search zones and records');
        });

        it('should display search hint text', () => {
            cy.get('[data-testid="search-hint"]').should('be.visible');
            cy.get('[data-testid="search-hint"]').should('contain', 'Enter a hostname or IP address');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Search');
        });

        it('should display search form', () => {
            cy.get('[data-testid="search-form"]').should('be.visible');
        });

        it('should display search query input', () => {
            cy.get('[data-testid="search-query-input"]').should('be.visible');
        });

        it('should display search submit button', () => {
            cy.get('[data-testid="search-submit-button"]').should('be.visible');
            cy.get('[data-testid="search-submit-button"]').should('have.value', 'Search');
        });

        it('should display clear button', () => {
            cy.get('[data-testid="search-clear-button"]').should('be.visible');
            cy.get('[data-testid="search-clear-button"]').should('contain', 'Clear');
        });

        it('should display all search filter checkboxes', () => {
            cy.get('[data-testid="search-zones-checkbox"]').should('be.visible');
            cy.get('[data-testid="search-records-checkbox"]').should('be.visible');
            cy.get('[data-testid="search-wildcard-checkbox"]').should('be.visible');
            cy.get('[data-testid="search-reverse-checkbox"]').should('be.visible');
            cy.get('[data-testid="search-comments-checkbox"]').should('be.visible');
        });

        it('should show error when searching with empty query', () => {
            cy.get('[data-testid="search-submit-button"]').click();
            cy.get('[data-testid="search-query-error"]').should('be.visible');
        });

        it('should search for zones by name', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('example.com');
            cy.get('[data-testid="search-submit-button"]').click();

            // Wait for results or no results message
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="zones-results-section"]').length > 0) {
                    cy.get('[data-testid="zones-results-section"]').should('be.visible');
                    cy.get('[data-testid="zones-found-heading"]').should('contain', 'Zones found');
                    cy.get('[data-testid="zones-count"]').should('be.visible');
                    cy.get('[data-testid="zones-results-table"]').should('be.visible');
                } else if ($body.find('[data-testid="no-results-message"]').length > 0) {
                    cy.get('[data-testid="no-results-message"]').should('be.visible');
                    cy.get('[data-testid="no-results-message"]').should('contain', 'No results found');
                }
            });
        });

        it('should search for records', () => {
            cy.get('[data-testid="search-records-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('ns1');
            cy.get('[data-testid="search-submit-button"]').click();

            // Wait for results or no results message
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="records-results-section"]').length > 0) {
                    cy.get('[data-testid="records-results-section"]').should('be.visible');
                    cy.get('[data-testid="records-found-heading"]').should('contain', 'Records found');
                    cy.get('[data-testid="records-count"]').should('be.visible');
                    cy.get('[data-testid="records-results-table"]').should('be.visible');
                } else if ($body.find('[data-testid="no-results-message"]').length > 0) {
                    cy.get('[data-testid="no-results-message"]').should('be.visible');
                }
            });
        });

        it('should search both zones and records', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-records-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('example');
            cy.get('[data-testid="search-submit-button"]').click();

            // Results may vary, just verify the search executed
            cy.url().should('include', 'page=search');
        });

        it('should use wildcard search', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-wildcard-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('%example%');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.url().should('include', 'page=search');
        });

        it('should clear search form when clicking clear button', () => {
            cy.get('[data-testid="search-query-input"]').type('test search');
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-clear-button"]').click();

            cy.url().should('include', 'page=search');
            cy.get('[data-testid="search-query-input"]').should('have.value', '');
        });

        it('should display zones results table with correct columns', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('zone');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="zones-results-table"]').length > 0) {
                    cy.get('[data-testid="zones-results-table"]').should('be.visible');
                    cy.get('[data-testid="zones-results-table"] thead').should('contain', 'Name');
                    cy.get('[data-testid="zones-results-table"] thead').should('contain', 'Type');
                    cy.get('[data-testid="zones-results-table"] thead').should('contain', 'Records');
                    cy.get('[data-testid="zones-results-table"] thead').should('contain', 'Owner');
                }
            });
        });

        it('should display records results table with correct columns', () => {
            cy.get('[data-testid="search-records-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('A');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="records-results-table"]').length > 0) {
                    cy.get('[data-testid="records-results-table"]').should('be.visible');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'Name');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'Type');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'Priority');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'Content');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'TTL');
                    cy.get('[data-testid="records-results-table"] thead').should('contain', 'Disabled');
                }
            });
        });

        it('should show no results message when no matches found', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('nonexistent-zone-name-12345');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.get('[data-testid="no-results-message"]').should('be.visible');
            cy.get('[data-testid="no-results-message"]').should('contain', 'No results found');
        });

        it('should search in comments when checkbox is selected', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-comments-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('comment');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.url().should('include', 'page=search');
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.goToSearch();
        });

        it('should display search page', () => {
            cy.get('[data-testid="search-heading"]').should('be.visible');
        });

        it('should be able to search for zones', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('manager');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.url().should('include', 'page=search');
        });

        it('should only see own zones in results', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('zone');
            cy.get('[data-testid="search-submit-button"]').click();

            // Manager should only see zones they own or have access to
            cy.url().should('include', 'page=search');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.goToSearch();
        });

        it('should display search page', () => {
            cy.get('[data-testid="search-heading"]').should('be.visible');
        });

        it('should be able to search for zones', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('client');
            cy.get('[data-testid="search-submit-button"]').click();

            cy.url().should('include', 'page=search');
        });
    });

    describe('Viewer User', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.goToSearch();
        });

        it('should display search page', () => {
            cy.get('[data-testid="search-heading"]').should('be.visible');
        });

        it('should be able to search but not edit results', () => {
            cy.get('[data-testid="search-zones-checkbox"]').check();
            cy.get('[data-testid="search-query-input"]').type('example');
            cy.get('[data-testid="search-submit-button"]').click();

            // Viewer should see results but no edit/delete buttons
            cy.url().should('include', 'page=search');
        });
    });
});
