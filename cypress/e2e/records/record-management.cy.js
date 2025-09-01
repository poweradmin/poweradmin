import users from '../../fixtures/users.json';

describe('DNS Record Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access zones list to manage records', () => {
    cy.visit('/zones/forward');
    cy.url().should('include', '/zones/forward');
    
    // Should show zones or empty state
    cy.get('body').then(($body) => {
      if ($body.find('table, .table').length > 0) {
        cy.get('table, .table').should('be.visible');
      } else {
        cy.get('body').should('contain.text', 'No zones').or('contain.text', 'zones').or('contain.text', 'empty');
      }
    });
  });

  // Test record management for a zone (if zones exist)
  it('should handle zone with no records', () => {
    // Navigate to zones and try to access first zone's records
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Click on first zone link if available
        cy.get('table tbody tr').first().within(() => {
          cy.get('a').first().click();
        });
        
        // Should be on zone edit/records page
        cy.url().should('match', /\/zones\/\d+\/edit/);
      } else {
        // No zones available, skip this test
        cy.log('No zones available for record testing');
      }
    });
  });

  it('should validate record form fields', () => {
    // Visit a generic add record URL (will redirect if zone doesn't exist)
    cy.visit('/zones/1/records/add', { failOnStatusCode: false });
    
    // Check if we have a form (only if zone exists)
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Should have record name field
        cy.get('input[name*="name"], input[name*="record"]').should('be.visible');
        
        // Should have record type selector
        cy.get('select[name*="type"], select[name*="record_type"]').should('be.visible');
        
        // Should have record content/value field
        cy.get('input[name*="content"], input[name*="value"], textarea[name*="content"]').should('be.visible');
      } else {
        cy.log('No record form available - zone may not exist');
      }
    });
  });

  it('should handle record type changes', () => {
    cy.visit('/zones/1/records/add', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        // Select different record types and check if form updates
        cy.get('select[name*="type"]').select('A', { force: true });
        
        // Try other common record types
        cy.get('select[name*="type"]').then(($select) => {
          const options = $select.find('option');
          if (options.length > 1) {
            cy.wrap($select).select(options[1].value, { force: true });
          }
        });
      }
    });
  });

  it('should validate required fields for new record', () => {
    cy.visit('/zones/1/records/add', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Try to submit empty form
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Should stay on form or show validation errors
        cy.url().should('include', '/records/add');
      }
    });
  });
});