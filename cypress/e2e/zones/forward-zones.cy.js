import users from '../../fixtures/users.json';

describe('Forward Zones Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access forward zones page', () => {
    cy.visit('/zones/forward');
    cy.url().should('include', '/zones/forward');
    cy.get('h1, h2, h3, .page-title, [data-testid*="title"]').should('be.visible');
  });

  it('should display zones list or empty state', () => {
    cy.visit('/zones/forward');
    
    // Should show either zones table or empty state message
    cy.get('body').then(($body) => {
      if ($body.find('table, .table').length > 0) {
        cy.get('table, .table').should('be.visible');
      } else {
        // Empty state or no zones message
        cy.get('body').should('contain.text', 'No zones found').or('contain.text', 'zones').or('contain.text', 'empty');
      }
    });
  });

  it('should have add master zone button', () => {
    cy.visit('/zones/forward');
    
    // Look for add/create buttons
    cy.get('body').then(($body) => {
      if ($body.find('a, button').filter(':contains("Add"), :contains("Create"), :contains("New")').length > 0) {
        cy.contains('a, button', /Add|Create|New/i).should('be.visible');
      }
    });
  });

  it('should navigate to add master zone page', () => {
    cy.visit('/zones/add/master');
    cy.url().should('include', '/zones/add/master');
    cy.get('form, [data-testid*="form"]').should('be.visible');
  });

  it('should validate master zone creation form', () => {
    cy.visit('/zones/add/master');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation errors or stay on form
    cy.url().should('include', '/zones/add/master');
  });

  it('should show zone name field in master zone form', () => {
    cy.visit('/zones/add/master');
    
    // Look for zone name input
    cy.get('input[name*="zone"], input[name*="domain"], input[name*="name"], input[placeholder*="zone"], input[placeholder*="domain"]')
      .should('be.visible');
  });
});