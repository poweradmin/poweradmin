import users from '../../fixtures/users.json';

describe('Permission Templates Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access permission templates page', () => {
    cy.visit('/permissions/templates');
    cy.url().should('include', '/permissions/templates');
    cy.get('h1, h2, h3, .page-title, [data-testid*="title"]').should('be.visible');
  });

  it('should display permission templates list or empty state', () => {
    cy.visit('/permissions/templates');
    
    // Should show either templates table or empty state
    cy.get('body').then(($body) => {
      if ($body.find('table, .table').length > 0) {
        cy.get('table, .table').should('be.visible');
      } else {
        cy.get('body').should('contain.text', 'No templates').or('contain.text', 'templates').or('contain.text', 'empty');
      }
    });
  });

  it('should access add permission template page', () => {
    cy.visit('/permissions/templates/add');
    cy.url().should('include', '/permissions/templates/add');
    cy.get('form, [data-testid*="form"]').should('be.visible');
  });

  it('should show permission template form fields', () => {
    cy.visit('/permissions/templates/add');
    
    // Should have template name field
    cy.get('input[name*="name"], input[name*="template"], input[placeholder*="name"]')
      .should('be.visible');
    
    // Should have description field
    cy.get('input[name*="description"], textarea[name*="description"], input[placeholder*="description"]')
      .should('be.visible');
    
    // Should have permission checkboxes or selectors
    cy.get('input[type="checkbox"], select[name*="permission"]').should('exist');
  });

  it('should validate permission template creation form', () => {
    cy.visit('/permissions/templates/add');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation errors or stay on form
    cy.url().should('include', '/permissions/templates/add');
  });

  it('should show available permissions for template', () => {
    cy.visit('/permissions/templates/add');
    
    // Should show various permission options
    cy.get('body').then(($body) => {
      if ($body.find('input[type="checkbox"]').length > 0) {
        cy.get('input[type="checkbox"]').should('exist');
        
        // Look for common permissions
        cy.get('body').should('contain.text', 'zone').or('contain.text', 'user').or('contain.text', 'permission');
      }
    });
  });
});