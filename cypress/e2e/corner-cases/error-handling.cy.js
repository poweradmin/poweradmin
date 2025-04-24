import users from '../../fixtures/users.json';

describe('Error Handling and Edge Cases', () => {
  beforeEach(() => {
    cy.visit('/index.php?page=login');
    cy.login(users.validUser.username, users.validUser.password);
  });

  context('Session Management', () => {
    it('should handle forced logout and session expiration', () => {
      // Force expire the session
      cy.clearCookies();
      
      // Try to access a protected page
      cy.visit('/index.php?page=list_forward_zones');
      
      // Should redirect to login
      cy.url().should('include', '/index.php?page=login');
      cy.get('[data-testid="session-error"]').should('be.visible');
    });

    it('should prevent CSRF attacks with token validation', () => {
      // Visit a page with a form
      cy.get('[data-testid="add-master-zone-link"]').click();
      
      // Tamper with the CSRF token
      cy.get('[name="csrf_token"]').invoke('val', 'invalid-token');
      
      // Try to submit the form
      cy.get('[data-testid="zone-name-input"]').type('csrf-test.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      // Should show CSRF error
      cy.get('[data-testid="csrf-error"]').should('be.visible');
    });
  });

  context('Concurrent Actions', () => {
    it('should handle rapid sequential form submissions', () => {
      cy.get('[data-testid="add-master-zone-link"]').click();
      cy.get('[data-testid="zone-name-input"]').type('concurrent-test.com');
      
      // Attempt double-click on submit button
      cy.get('[data-testid="add-zone-button"]').dblclick();
      
      // Check we end up on the correct page
      cy.url().should('include', '/index.php?page=list_forward_zones');
      
      // Clean up
      cy.contains('tr', 'concurrent-test.com').within(() => {
        cy.get('[data-testid^="delete-zone-"]').click();
      });
      cy.get('[data-testid="confirm-delete-zone"]').click();
    });
  });

  context('Pagination Edge Cases', () => {
    it('should handle navigation to non-existent pages', () => {
      // Navigate to zones list
      cy.get('[data-testid="list-zones-link"]').click();
      
      // Try to access an invalid page number
      cy.visit('/index.php?page=list_forward_zones&letter=all&start=9999');
      
      // Should show first page or error message
      cy.get('[data-testid="zones-table"]').should('be.visible');
    });
  });

  context('Browser Navigation', () => {
    it('should handle browser back button correctly', () => {
      // Navigate to a page
      cy.get('[data-testid="add-master-zone-link"]').click();
      
      // Fill out the form
      cy.get('[data-testid="zone-name-input"]').type('navigation-test.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      // Back button
      cy.go('back');
      
      // Check form state
      cy.get('[data-testid="zone-name-input"]').should('exist');
      
      // Go forward
      cy.go('forward');
      
      // Should be on forward zones list
      cy.url().should('include', '/index.php?page=list_forward_zones');
      
      // Clean up
      cy.contains('tr', 'navigation-test.com').within(() => {
        cy.get('[data-testid^="delete-zone-"]').click();
      });
      cy.get('[data-testid="confirm-delete-zone"]').click();
    });
  });

  context('Direct URL Access', () => {
    it('should handle direct access to edit pages with invalid IDs', () => {
      // Try to access a non-existent record
      cy.visit('/index.php?page=edit_record&id=999999999');
      
      // Should show error or redirect
      cy.get('[data-testid="error-message"]').should('be.visible');
    });

    it('should prevent unauthorized access to admin functions', () => {
      // First logout
      cy.get('[data-testid="logout-link"]').click();
      
      // Try to access admin page directly
      cy.visit('/index.php?page=users');
      
      // Should redirect to login
      cy.url().should('include', '/index.php?page=login');
    });
  });

  context('Special Characters Handling', () => {
    it('should properly escape HTML in user input display', () => {
      // Create a zone with HTML tags
      cy.get('[data-testid="add-master-zone-link"]').click();
      cy.get('[data-testid="zone-name-input"]').type('special-char-test.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      // Navigate to records
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', 'special-char-test.com').within(() => {
        cy.get('[data-testid^="edit-zone-"]').click();
      });
      
      // Add a TXT record with HTML
      cy.get('[data-testid="record-type-select"]').select('TXT');
      cy.get('[data-testid="record-name-input"]').type('html-test');
      cy.get('[data-testid="record-content-input"]').type('<script>alert("XSS")</script>');
      cy.get('[data-testid="add-record-button"]').click();
      
      // HTML should be escaped in the display
      cy.contains('td', '<script>').should('be.visible');
      
      // Clean up
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', 'special-char-test.com').within(() => {
        cy.get('[data-testid^="delete-zone-"]').click();
      });
      cy.get('[data-testid="confirm-delete-zone"]').click();
    });
  });
});