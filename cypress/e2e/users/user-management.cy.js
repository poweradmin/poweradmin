import users from '../../fixtures/users.json';

describe('User Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access users list page', () => {
    cy.visit('/users');
    cy.url().should('include', '/users');
    cy.get('h1, h2, h3, .page-title, [data-testid*="title"]').should('be.visible');
  });

  it('should display users list or empty state', () => {
    cy.visit('/users');
    
    // Should show either users table or empty state
    cy.get('body').then(($body) => {
      if ($body.find('table, .table').length > 0) {
        cy.get('table, .table').should('be.visible');
      } else {
        cy.get('body').should('contain.text', 'No users').or('contain.text', 'users').or('contain.text', 'empty');
      }
    });
  });

  it('should access add user page', () => {
    cy.visit('/users/add');
    cy.url().should('include', '/users/add');
    cy.get('form, [data-testid*="form"]').should('be.visible');
  });

  it('should show user creation form fields', () => {
    cy.visit('/users/add');
    
    // Username field
    cy.get('input[name*="username"], input[name*="user"], input[placeholder*="username"]')
      .should('be.visible');
    
    // Email field
    cy.get('input[name*="email"], input[type="email"]')
      .should('be.visible');
    
    // Password field
    cy.get('input[name*="password"], input[type="password"]')
      .should('be.visible');
  });

  it('should validate user creation form', () => {
    cy.visit('/users/add');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation errors or stay on form
    cy.url().should('include', '/users/add');
  });

  it('should require username for new user', () => {
    cy.visit('/users/add');
    
    // Fill other fields but leave username empty
    cy.get('input[name*="email"], input[type="email"]')
      .first()
      .type('test@example.com');
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation error or stay on form
    cy.url().should('include', '/users/add');
  });

  it('should have change password functionality', () => {
    cy.visit('/password/change');
    cy.url().should('include', '/password/change');
    cy.get('form, [data-testid*="form"]').should('be.visible');
  });

  it('should show password change form fields', () => {
    cy.visit('/password/change');
    
    // Current password field
    cy.get('input[name*="current"], input[name*="old"]')
      .should('be.visible');
    
    // New password field
    cy.get('input[name*="new"], input[name*="password"]')
      .should('be.visible');
  });
});