import users from '../../fixtures/users.json';

describe('Search and Utility Tools', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access search page', () => {
    cy.visit('/search');
    cy.url().should('include', '/search');
    cy.get('form, input[type="search"], input[name*="search"]').should('be.visible');
  });

  it('should show search form with query input', () => {
    cy.visit('/search');
    
    // Should have search input field
    cy.get('input[type="search"], input[name*="search"], input[name*="query"], input[placeholder*="search"]')
      .should('be.visible');
    
    // Should have search button
    cy.get('button[type="submit"], input[type="submit"]').should('be.visible');
  });

  it('should handle empty search query', () => {
    cy.visit('/search');
    
    // Submit empty search
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should stay on search page or show validation
    cy.url().should('include', '/search');
  });

  it('should perform search with query', () => {
    cy.visit('/search');
    
    // Enter search query
    cy.get('input[type="search"], input[name*="search"], input[name*="query"]')
      .first()
      .type('example.com');
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show search results or "no results" message
    cy.get('body').should('contain.text', 'results').or('contain.text', 'found').or('contain.text', 'search');
  });

  it('should access WHOIS tool', () => {
    cy.visit('/whois');
    cy.url().should('include', '/whois');
    cy.get('form, input[name*="domain"], input[placeholder*="domain"]').should('be.visible');
  });

  it('should show WHOIS form fields', () => {
    cy.visit('/whois');
    
    // Should have domain input field
    cy.get('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]')
      .should('be.visible');
    
    // Should have lookup button
    cy.get('button[type="submit"], input[type="submit"]').should('be.visible');
  });

  it('should validate WHOIS domain input', () => {
    cy.visit('/whois');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should stay on form or show validation
    cy.url().should('include', '/whois');
  });

  it('should perform WHOIS lookup', () => {
    cy.visit('/whois');
    
    // Enter domain
    cy.get('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]')
      .first()
      .type('example.com');
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show WHOIS results or error message
    cy.get('body').should('contain.text', 'whois').or('contain.text', 'domain').or('contain.text', 'lookup');
  });

  it('should access RDAP tool if enabled', () => {
    cy.visit('/rdap', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if (!$body.text().includes('404') && !$body.text().includes('not found')) {
        cy.url().should('include', '/rdap');
        cy.get('form, input').should('be.visible');
      } else {
        cy.log('RDAP tool not available or disabled');
      }
    });
  });

  it('should access database consistency tool if available', () => {
    cy.visit('/tools/database-consistency', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if (!$body.text().includes('404') && !$body.text().includes('not found') && !$body.text().includes('permission')) {
        cy.url().should('include', '/tools/database-consistency');
        cy.get('body').should('contain.text', 'consistency').or('contain.text', 'database').or('contain.text', 'check');
      } else {
        cy.log('Database consistency tool not available or no permission');
      }
    });
  });

  it('should show navigation menu items', () => {
    cy.visit('/');
    
    // Check for main navigation elements
    cy.get('nav, .navbar, .navigation, header').should('be.visible');
    
    // Should have various menu items
    cy.get('body').should('contain.text', 'Zones').or('contain.text', 'DNS');
    cy.get('body').should('contain.text', 'Users').or('contain.text', 'Administration');
  });

  it('should have working logout functionality', () => {
    cy.visit('/');
    
    // Look for logout link/button
    cy.get('body').then(($body) => {
      if ($body.find('a, button').filter(':contains("Logout"), :contains("Sign out")').length > 0) {
        cy.contains('a, button', /Logout|Sign out/i).click();
        
        // Should redirect to login page
        cy.url().should('include', '/login');
      } else {
        // Try direct logout URL
        cy.visit('/logout');
        cy.url().should('include', '/login');
      }
    });
  });
});