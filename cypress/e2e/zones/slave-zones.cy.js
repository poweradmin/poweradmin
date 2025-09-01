import users from '../../fixtures/users.json';

describe('Slave Zones Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access add slave zone page', () => {
    cy.visit('/zones/add/slave');
    cy.url().should('include', '/zones/add/slave');
    cy.get('form, [data-testid*="form"]').should('be.visible');
  });

  it('should show slave zone form fields', () => {
    cy.visit('/zones/add/slave');
    
    // Should have zone name field
    cy.get('input[name*="zone"], input[name*="domain"], input[name*="name"]')
      .should('be.visible');
    
    // Should have master server field
    cy.get('input[name*="master"], input[name*="server"], input[placeholder*="master"], textarea[name*="master"]')
      .should('be.visible');
  });

  it('should validate slave zone creation form', () => {
    cy.visit('/zones/add/slave');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation errors or stay on form
    cy.url().should('include', '/zones/add/slave');
  });

  it('should require master server for slave zone', () => {
    cy.visit('/zones/add/slave');
    
    // Fill zone name but leave master empty
    cy.get('input[name*="zone"], input[name*="domain"], input[name*="name"]')
      .first()
      .type('test-slave.example.com');
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation error or stay on form
    cy.url().should('include', '/zones/add/slave');
  });
});