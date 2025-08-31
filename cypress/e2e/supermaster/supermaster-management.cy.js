import users from '../../fixtures/users.json';

describe('Supermaster Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access supermaster list from dashboard', () => {
    // Click on Supermasters card from dashboard
    cy.contains('List supermasters').click();
    cy.url().should('include', '/supermaster');
  });

  it('should show supermaster list page', () => {
    cy.contains('List supermasters').click();
    
    // Should show supermaster table or list
    cy.get('table, .table, [class*="supermaster"]').should('be.visible');
  });

  it('should add a new supermaster', () => {
    // Navigate to add supermaster page
    cy.get('body').then(($body) => {
      if ($body.text().includes('Add supermaster')) {
        cy.contains('Add supermaster').click();
      } else {
        cy.contains('List supermasters').click();
        cy.contains('Add', { timeout: 5000 }).click();
      }
    });
    
    // Fill in supermaster details
    cy.get('input[name*="ip"], input[placeholder*="ip"]').type('192.168.1.100');
    cy.get('input[name*="nameserver"], input[placeholder*="nameserver"]').type('ns1.example.com');
    cy.get('input[name*="account"], input[placeholder*="account"]').type('test-account');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify success
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
  });

  it('should list the created supermaster', () => {
    cy.contains('List supermasters').click();
    
    // Should show the test supermaster we created
    cy.contains('192.168.1.100').should('be.visible');
    cy.contains('ns1.example.com').should('be.visible');
  });

  it('should edit a supermaster', () => {
    cy.contains('List supermasters').click();
    
    // Find the test supermaster and edit it
    cy.contains('tr', '192.168.1.100').within(() => {
      cy.get('a, button').filter(':contains("Edit"), :contains("Modify")').click();
    });
    
    // Update supermaster details
    cy.get('input[name*="nameserver"], input[placeholder*="nameserver"]').clear().type('ns2.example.com');
    
    // Submit form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Verify success
    cy.get('.alert, .message, [class*="success"]', { timeout: 10000 }).should('be.visible');
  });

  it('should delete a supermaster', () => {
    cy.contains('List supermasters').click();
    
    // Find the test supermaster and delete it
    cy.contains('tr', '192.168.1.100').within(() => {
      cy.get('a, button').filter(':contains("Delete"), :contains("Remove")').click();
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

  it('should validate supermaster form', () => {
    cy.get('body').then(($body) => {
      if ($body.text().includes('Add supermaster')) {
        cy.contains('Add supermaster').click();
      } else {
        cy.contains('List supermasters').click();
        cy.contains('Add', { timeout: 5000 }).click();
      }
    });
    
    // Submit empty form
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show validation error
    cy.get('.error, .invalid, [class*="error"]', { timeout: 5000 }).should('exist');
  });
});