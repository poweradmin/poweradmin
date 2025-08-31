import users from '../../fixtures/users.json';

describe('User Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should list all users', () => {
    // Click on Users dropdown in navigation
    cy.contains('Users').click();
    cy.url().should('include', '/users');
    // Look for user table or user list content
    cy.get('table, .table, [class*="user"]').should('be.visible');
  });

  it('should add a new user successfully', () => {
    // Navigate to Users page and look for Add User link/button
    cy.contains('Users').click();
    cy.contains('Add', { timeout: 5000 }).click();
    
    // Fill user form - look for input fields by common attributes
    cy.get('input[name*="username"], input[placeholder*="username"]').type('testuser');
    cy.get('input[name*="fullname"], input[placeholder*="name"]').type('Test User');
    cy.get('input[name*="email"], input[type="email"]').type('test@example.com');
    cy.get('input[name*="password"], input[type="password"]').first().type('Admin123!');
    cy.get('input[name*="confirm"], input[type="password"]').last().type('Admin123!');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify success - look for any success message
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
  });

  it('should edit an existing user', () => {
    cy.get('[data-testid="users-link"]').click();
    
    // Find the test user and click edit
    cy.contains('tr', 'testuser').within(() => {
      cy.get('[data-testid^="edit-user-"]').click();
    });
    
    // Edit user details
    cy.get('[data-testid="fullname-input"]').clear().type('Updated Test User');
    cy.get('[data-testid="email-input"]').clear().type('updated@example.com');
    
    // Submit form
    cy.get('[data-testid="update-user-button"]').click();
    
    // Verify success
    cy.get('[data-testid="alert-message"]').should('contain', 'User has been updated successfully.');
  });

  it('should delete a user', () => {
    cy.get('[data-testid="users-link"]').click();
    
    // Find the test user and click delete
    cy.contains('tr', 'testuser').within(() => {
      cy.get('[data-testid^="delete-user-"]').click();
    });
    
    // Confirm deletion
    cy.get('[data-testid="confirm-delete-user"]').click();
    
    // Verify success
    cy.get('[data-testid="alert-message"]').should('contain', 'User has been deleted successfully.');
  });
});