import users from '../../fixtures/users.json';

describe('API Keys Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access API keys page', () => {
    // Navigate to API keys page
    cy.visit('/settings/api-keys');
    cy.url().should('include', '/settings/api-keys');
  });

  it('should list existing API keys', () => {
    cy.visit('/settings/api-keys');
    
    // Should show API keys table or list
    cy.get('table, .table, [class*="api"], [class*="key"]').should('be.visible');
  });

  it('should create a new API key', () => {
    cy.visit('/settings/api-keys');
    
    // Look for create/add button
    cy.get('body').then(($body) => {
      if ($body.find('button, input[type="submit"], a').filter(':contains("Add"), :contains("Create"), :contains("New")').length > 0) {
        cy.contains('Add', { timeout: 5000 }).click();
      } else {
        // Might be a form directly on the page
        cy.get('input[name*="name"], input[placeholder*="name"]').should('be.visible');
      }
    });
    
    // Fill in API key details
    cy.get('input[name*="name"], input[placeholder*="name"]').type('Test API Key');
    cy.get('textarea[name*="description"], input[name*="description"]').type('API key for testing');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify success
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
  });

  it('should show API key after creation', () => {
    cy.visit('/settings/api-keys');
    
    // Should show the test API key we created
    cy.contains('Test API Key').should('be.visible');
  });

  it('should delete an API key', () => {
    cy.visit('/settings/api-keys');
    
    // Find the test API key and delete it
    cy.contains('tr', 'Test API Key').within(() => {
      cy.get('button, a').filter(':contains("Delete"), :contains("Remove")').click();
    });
    
    // Confirm deletion if needed
    cy.get('body').then(($body) => {
      if ($body.text().includes('confirm') || $body.text().includes('sure')) {
        cy.contains('Yes').click();
      }
    });
    
    // Verify deletion
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
  });

  it('should validate API key creation form', () => {
    cy.visit('/settings/api-keys');
    
    // Try to create API key without required fields
    cy.get('body').then(($body) => {
      if ($body.find('button, input[type="submit"], a').filter(':contains("Add"), :contains("Create"), :contains("New")').length > 0) {
        cy.contains('Add', { timeout: 5000 }).click();
      }
    });
    
    // Submit empty form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show validation error
    cy.get('.error, .invalid, [class*="error"]', { timeout: 5000 }).should('exist');
  });
});