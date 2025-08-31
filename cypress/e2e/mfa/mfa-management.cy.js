import users from '../../fixtures/users.json';

describe('MFA Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access MFA setup page', () => {
    // Try to navigate to MFA setup
    cy.get('body').then(($body) => {
      if ($body.text().includes('Account')) {
        cy.contains('Account').click();
        cy.contains('MFA', { timeout: 5000 }).click();
      } else {
        // Direct navigation to MFA setup route
        cy.visit('/mfa/setup');
      }
    });

    cy.url().should('include', '/mfa');
  });

  it('should show MFA setup form when MFA is not enabled', () => {
    cy.visit('/mfa/setup');
    
    // Should show setup form or QR code
    cy.get('body').should('contain.text', 'Multi-Factor Authentication');
    
    // Look for QR code or setup elements
    cy.get('img[src*="qr"], [class*="qr"], canvas, svg').should('be.visible');
  });

  it('should display MFA setup instructions', () => {
    cy.visit('/mfa/setup');
    
    // Should contain setup instructions
    cy.get('body').should('contain.text', 'Google Authenticator');
    cy.get('body').should('contain.text', 'scan');
  });

  it('should handle MFA verification form', () => {
    cy.visit('/mfa/verify');
    
    // Should show verification form
    cy.get('input[name*="code"], input[placeholder*="code"]').should('be.visible');
    cy.get('button[type="submit"], input[type="submit"]').should('be.visible');
  });

  it('should validate MFA code format', () => {
    cy.visit('/mfa/verify');
    
    // Enter invalid code format
    cy.get('input[name*="code"], input[placeholder*="code"]').type('abc');
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show validation error or remain on page
    cy.url().should('include', '/mfa/verify');
  });

  it('should handle empty MFA code submission', () => {
    cy.visit('/mfa/verify');
    
    // Submit without entering code
    cy.get('button[type="submit"], input[type="submit"]').click();
    
    // Should show validation error or remain on page
    cy.url().should('include', '/mfa/verify');
  });
});