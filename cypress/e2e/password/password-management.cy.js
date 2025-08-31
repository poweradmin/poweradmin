import users from '../../fixtures/users.json';

describe('Password Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access change password page', () => {
    // Look for Account dropdown or direct link to change password
    cy.get('body').then(($body) => {
      if ($body.text().includes('Account')) {
        cy.contains('Account').click();
        cy.contains('Change Password', { timeout: 5000 }).click();
      } else {
        // Direct navigation to change password route
        cy.visit('/password/change');
      }
    });

    cy.url().should('include', '/password/change');
  });

  it('should change password successfully', () => {
    cy.visit('/password/change');

    // Fill in current password
    cy.get('input[name*="current"], input[placeholder*="current"]').type(users.validUser.password);
    
    // Fill in new password
    const newPassword = 'NewAdmin456!';
    cy.get('input[name*="new"], input[name*="password"]').first().type(newPassword);
    
    // Confirm new password
    cy.get('input[name*="confirm"], input[name*="password"]').last().type(newPassword);
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify success message
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
    
    // Test login with new password
    cy.visit('/logout');
    cy.visit('/login');
    cy.get('input[name*="username"]').type(users.validUser.username);
    cy.get('input[name*="password"]').type(newPassword);
    cy.get('button[type="submit"], input[type="submit"]').click();
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
    
    // Change back to original password for other tests
    cy.visit('/password/change');
    cy.get('input[name*="current"], input[placeholder*="current"]').type(newPassword);
    cy.get('input[name*="new"], input[name*="password"]').first().type(users.validUser.password);
    cy.get('input[name*="confirm"], input[name*="password"]').last().type(users.validUser.password);
    cy.get('button[type="submit"], input[type="submit"]').click();
  });

  it('should validate password requirements', () => {
    cy.visit('/password/change');
    
    // Fill in current password
    cy.get('input[name*="current"], input[placeholder*="current"]').type(users.validUser.password);
    
    // Try weak password
    cy.get('input[name*="new"], input[name*="password"]').first().type('weak');
    cy.get('input[name*="confirm"], input[name*="password"]').last().type('weak');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show validation error
    cy.get('.error, .invalid, [class*="error"]', { timeout: 5000 }).should('be.visible');
  });

  it('should handle password mismatch', () => {
    cy.visit('/password/change');
    
    // Fill in current password
    cy.get('input[name*="current"], input[placeholder*="current"]').type(users.validUser.password);
    
    // Enter mismatched passwords
    cy.get('input[name*="new"], input[name*="password"]').first().type('ValidPass123!');
    cy.get('input[name*="confirm"], input[name*="password"]').last().type('DifferentPass123!');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show mismatch error
    cy.get('.error, .invalid, [class*="error"]', { timeout: 5000 }).should('be.visible');
  });
});