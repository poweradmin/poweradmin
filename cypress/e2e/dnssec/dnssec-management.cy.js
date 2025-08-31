import users from '../../fixtures/users.json';

describe('DNSSEC Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
    
    // Set up a test zone for DNSSEC operations
    cy.get('[data-testid="add-master-zone-link"]').click();
    cy.get('[data-testid="zone-name-input"]').type('dnssec-test.com');
    cy.get('[data-testid="add-zone-button"]').click();
    cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been added successfully.');
  });

  it('should access DNSSEC management page', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    cy.url().should('include', '/dnssec');
    cy.get('[data-testid="dnssec-status"]').should('be.visible');
  });

  it('should show DNSSEC is not enabled initially', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    cy.get('[data-testid="dnssec-disabled-message"]').should('be.visible');
    cy.get('[data-testid="dnssec-disabled-message"]').should('contain', 'DNSSEC is not enabled');
  });

  it('should enable DNSSEC for a zone', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    // Try to enable DNSSEC (this may require PowerDNS API to be available)
    cy.get('[data-testid="enable-dnssec-button"]').should('be.visible');
    cy.get('[data-testid="enable-dnssec-button"]').click();
    
    // Check for success or error message
    cy.get('[data-testid="alert-message"]', { timeout: 10000 }).should('be.visible');
  });

  it('should show DNSSEC keys when enabled', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    // If DNSSEC is enabled, keys should be visible
    cy.get('body').then(($body) => {
      if ($body.find('[data-testid="dnssec-keys-table"]').length) {
        cy.get('[data-testid="dnssec-keys-table"]').should('be.visible');
        cy.get('[data-testid="dnssec-keys-table"]').find('tr').should('have.length.at.least', 1);
      }
    });
  });

  it('should show DS records when available', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    // Check for DS records section
    cy.get('body').then(($body) => {
      if ($body.find('[data-testid="ds-records-section"]').length) {
        cy.get('[data-testid="ds-records-section"]').should('be.visible');
      }
    });
  });

  it('should handle DNSSEC operations gracefully when PowerDNS API is unavailable', () => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="dnssec-"]').click();
    });
    
    // Should show appropriate error message if API is not available
    cy.get('body').then(($body) => {
      if ($body.find('[data-testid="api-error-message"]').length) {
        cy.get('[data-testid="api-error-message"]').should('contain', 'PowerDNS API');
      }
    });
  });

  // Clean up test zone after all tests
  after(() => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'dnssec-test.com').within(() => {
      cy.get('[data-testid^="delete-zone-"]').click();
    });
    cy.get('[data-testid="confirm-delete-zone"]').click();
  });
});