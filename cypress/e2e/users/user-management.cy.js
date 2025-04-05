import users from '../../fixtures/users.json';

describe('User Management', () => {
  beforeEach(() => {
    cy.visit('/index.php?page=login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('include', '/index.php');
  });

  it('should list all users', () => {
    cy.get('[data-testid="users-link"]').click();
    cy.url().should('include', '/index.php?page=users');
    cy.get('[data-testid="users-table"]').should('be.visible');
    cy.get('[data-testid="users-table"]').find('tr').should('have.length.at.least', 1);
  });

  it('should add a new user successfully', () => {
    cy.get('[data-testid="users-link"]').click();
    cy.get('[data-testid="add-user-link"]').click();
    
    // Fill user form
    cy.get('[data-testid="username-input"]').type('testuser');
    cy.get('[data-testid="fullname-input"]').type('Test User');
    cy.get('[data-testid="email-input"]').type('test@example.com');
    cy.get('[data-testid="password-input"]').type('SecureP@ss123');
    cy.get('[data-testid="password-confirm-input"]').type('SecureP@ss123');
    
    // Select permissions
    cy.get('[data-testid="user-perm-view-zone"]').check();
    cy.get('[data-testid="user-perm-edit-records"]').check();
    
    // Submit form
    cy.get('[data-testid="add-user-button"]').click();
    
    // Verify success
    cy.get('[data-testid="alert-message"]').should('contain', 'User has been added successfully.');
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