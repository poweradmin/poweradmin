import users from '../../fixtures/users.json';

describe('Search Functionality', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    // Set up test zones if they don't exist
    const testZones = ['search-test1.com', 'search-test2.org', 'search-test-special.net'];
    
    testZones.forEach(zone => {
      cy.get('[data-testid="add-master-zone-link"]').click();
      cy.get('[data-testid="zone-name-input"]').type(zone);
      cy.get('[data-testid="add-zone-button"]').click();
      
      // Add a test record
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', zone).within(() => {
        cy.get('[data-testid^="edit-zone-"]').click();
      });
      
      cy.get('[data-testid="record-type-select"]').select('A');
      cy.get('[data-testid="record-name-input"]').type('www');
      cy.get('[data-testid="record-content-input"]').type('192.168.1.10');
      cy.get('[data-testid="add-record-button"]').click();
    });
  });

  it('should search for zones by exact name', () => {
    // Click on Search in navigation or use search card
    cy.contains('Search').click();
    
    // Fill in search input
    cy.get('input[name*="search"], input[placeholder*="search"]').type('search-test1.com');
    
    // Submit search
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify results
    cy.get('table, .results, [class*="search"]').should('be.visible');
    cy.contains('search-test1.com').should('be.visible');
  });

  it('should search for zones by partial name', () => {
    cy.get('[data-testid="search-link"]').click();
    cy.get('[data-testid="search-input"]').type('search-test');
    cy.get('[data-testid="search-button"]').click();
    
    cy.get('[data-testid="search-results"]').should('be.visible');
    cy.contains('search-test1.com').should('be.visible');
    cy.contains('search-test2.org').should('be.visible');
    cy.contains('search-test-special.net').should('be.visible');
  });

  it('should search for records by content', () => {
    cy.get('[data-testid="search-link"]').click();
    cy.get('[data-testid="search-input"]').type('192.168.1.10');
    cy.get('[data-testid="search-type-select"]').select('records');
    cy.get('[data-testid="search-button"]').click();
    
    cy.get('[data-testid="search-results"]').should('be.visible');
    cy.contains('www.search-test1.com').should('be.visible');
    cy.contains('www.search-test2.org').should('be.visible');
    cy.contains('www.search-test-special.net').should('be.visible');
  });

  it('should handle searches with no results', () => {
    cy.get('[data-testid="search-link"]').click();
    cy.get('[data-testid="search-input"]').type('nonexistent-domain.com');
    cy.get('[data-testid="search-button"]').click();
    
    cy.get('[data-testid="no-results-message"]').should('be.visible');
    cy.get('[data-testid="no-results-message"]').should('contain', 'No matches found');
  });

  it('should handle special characters in search', () => {
    cy.get('[data-testid="search-link"]').click();
    cy.get('[data-testid="search-input"]').type('search-test-special');
    cy.get('[data-testid="search-button"]').click();
    
    cy.get('[data-testid="search-results"]').should('be.visible');
    cy.contains('search-test-special.net').should('be.visible');
  });

  // Clean up test zones after all tests
  after(() => {
    const testZones = ['search-test1.com', 'search-test2.org', 'search-test-special.net'];
    
    testZones.forEach(zone => {
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', zone).within(() => {
        cy.get('[data-testid^="delete-zone-"]').click();
      });
      cy.get('[data-testid="confirm-delete-zone"]').click();
    });
  });
});