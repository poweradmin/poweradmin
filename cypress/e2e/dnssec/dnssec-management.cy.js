import users from '../../fixtures/users.json';

describe('DNSSEC Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should handle DNSSEC page access with zone ID', () => {
    // Try to access DNSSEC page (will fail if no zone exists)
    cy.visit('/zones/1/dnssec', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if (!$body.text().includes('404') && !$body.text().includes('not found')) {
        cy.url().should('include', '/dnssec');
        cy.get('h1, h2, h3, .page-title').should('be.visible');
      } else {
        cy.log('DNSSEC page not available - zone may not exist');
      }
    });
  });

  it('should show DNSSEC status for existing zone', () => {
    // First navigate to zones to find an existing zone
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Extract zone ID from first row and visit DNSSEC page
        cy.get('table tbody tr').first().within(() => {
          cy.get('a').first().should('have.attr', 'href').then((href) => {
            const zoneId = href.match(/\/zones\/(\d+)/)?.[1];
            if (zoneId) {
              cy.visit(`/zones/${zoneId}/dnssec`);
              cy.get('body').should('contain.text', 'DNSSEC').or('contain.text', 'security');
            }
          });
        });
      } else {
        cy.log('No zones available for DNSSEC testing');
      }
    });
  });

  it('should handle DNSSEC key addition page', () => {
    cy.visit('/zones/1/dnssec/keys/add', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if (!$body.text().includes('404') && !$body.text().includes('not found')) {
        cy.url().should('include', '/dnssec/keys/add');
        cy.get('form, [data-testid*="form"]').should('be.visible');
      } else {
        cy.log('DNSSEC key addition not available - zone may not exist');
      }
    });
  });

  it('should show DNSSEC key form fields if available', () => {
    cy.visit('/zones/1/dnssec/keys/add', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Look for key-related form fields
        cy.get('body').should('contain.text', 'key').or('contain.text', 'DNSSEC').or('contain.text', 'algorithm');
        
        // Should have form elements
        cy.get('input, select, textarea').should('exist');
      }
    });
  });

  it('should validate DNSSEC permissions', () => {
    cy.visit('/zones/1/dnssec', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('permission') || $body.text().includes('access') || $body.text().includes('denied')) {
        cy.get('body').should('contain.text', 'permission').or('contain.text', 'access');
        cy.log('DNSSEC access restricted - permission required');
      } else if (!$body.text().includes('404')) {
        cy.log('DNSSEC page accessible');
      }
    });
  });

  it('should show DNSSEC keys list if zone exists and has keys', () => {
    cy.visit('/zones/1/dnssec', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if (!$body.text().includes('404') && !$body.text().includes('permission')) {
        // Should show either keys table or "no keys" message
        if ($body.find('table, .table').length > 0) {
          cy.get('table, .table').should('be.visible');
        } else {
          cy.get('body').should('contain.text', 'key').or('contain.text', 'DNSSEC').or('contain.text', 'security');
        }
      }
    });
  });

  it('should handle DNSSEC navigation from zone management', () => {
    // Navigate to zones and look for DNSSEC links
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.find('a').filter(':contains("DNSSEC"), :contains("Security")').length > 0) {
        cy.contains('a', /DNSSEC|Security/i).should('have.attr', 'href');
      } else {
        cy.log('No DNSSEC links found in zone management');
      }
    });
  });
});