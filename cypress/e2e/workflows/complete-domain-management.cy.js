import users from '../../fixtures/users.json';

describe('Complete Domain Management Workflow', () => {
  const testDomain = `test-domain-${Date.now()}.com`;
  const testEmail = 'admin@example.com';
  let zoneId = null;

  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should complete full domain creation workflow', () => {
    // Step 1: Navigate to add master zone
    cy.visit('/zones/add/master');
    cy.url().should('include', '/zones/add/master');
    
    // Step 2: Fill in domain details
    cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .type(testDomain);
    
    // Fill in admin email if field exists
    cy.get('body').then(($body) => {
      if ($body.find('input[name*="email"], input[type="email"]').length > 0) {
        cy.get('input[name*="email"], input[type="email"]')
          .first()
          .type(testEmail);
      }
    });
    
    // Fill in name servers if fields exist
    cy.get('body').then(($body) => {
      if ($body.find('input[name*="ns"], input[name*="nameserver"]').length > 0) {
        cy.get('input[name*="ns"], input[name*="nameserver"]')
          .first()
          .type('ns1.example.com');
      }
    });
    
    // Step 3: Submit the form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Step 4: Verify zone was created
    cy.get('body').then(($body) => {
      if ($body.text().includes('success') || $body.text().includes('added') || $body.text().includes('created')) {
        cy.get('body').should('contain.text', 'success').or('contain.text', 'added').or('contain.text', 'created');
      } else {
        // Check if we're redirected to zone list
        cy.url().should('match', /zones/);
      }
    });
    
    // Step 5: Navigate to zones list and verify domain exists
    cy.visit('/zones/forward');
    cy.get('body').should('contain.text', testDomain);
    
    // Extract zone ID for later use
    cy.get('body').then(($body) => {
      const domainRow = $body.find(`tr:contains("${testDomain}")`);
      if (domainRow.length > 0) {
        const editLink = domainRow.find('a[href*="/zones/"]').attr('href');
        if (editLink) {
          const match = editLink.match(/\/zones\/(\d+)/);
          if (match) {
            zoneId = match[1];
            cy.wrap(zoneId).as('zoneId');
          }
        }
      }
    });
  });

  it('should add essential DNS records to the domain', () => {
    // Navigate to zones and find our test domain
    cy.visit('/zones/forward');
    
    // Click on the domain to edit records
    cy.contains('tr', testDomain).within(() => {
      cy.get('a').first().click();
    });
    
    // Should be on zone edit page
    cy.url().should('match', /\/zones\/\d+\/edit/);
    
    // Add A record for www
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Look for add record form or button
        if ($body.find('input[name*="name"], input[name*="record"]').length > 0) {
          // Form is directly visible
          cy.get('select[name*="type"]').select('A');
          cy.get('input[name*="name"]').type('www');
          cy.get('input[name*="content"], input[name*="value"]').type('192.168.1.100');
          cy.get('button[type="submit"]').click();
        } else if ($body.find('a, button').filter(':contains("Add"), :contains("Create")').length > 0) {
          // Need to click add record button first
          cy.contains('Add').click();
          cy.get('select[name*="type"]').select('A');
          cy.get('input[name*="name"]').type('www');
          cy.get('input[name*="content"], input[name*="value"]').type('192.168.1.100');
          cy.get('button[type="submit"]').click();
        }
      }
    });
  });

  it('should verify domain resolution and records', () => {
    cy.visit('/zones/forward');
    
    // Find and click on test domain
    cy.contains('tr', testDomain).within(() => {
      cy.get('a').first().click();
    });
    
    // Verify we can see the records we added
    cy.get('body').should('contain.text', 'www');
    cy.get('body').should('contain.text', '192.168.1.100');
  });

  it('should handle domain search functionality', () => {
    cy.visit('/search');
    
    // Search for our test domain
    cy.get('input[type="search"], input[name*="search"], input[name*="query"]')
      .first()
      .type(testDomain);
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should find our domain
    cy.get('body').should('contain.text', testDomain);
  });

  // Cleanup: Delete the test domain
  after(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    cy.visit('/zones/forward');
    
    // Find and delete the test domain
    cy.get('body').then(($body) => {
      if ($body.text().includes(testDomain)) {
        cy.contains('tr', testDomain).within(() => {
          // Look for delete button/link
          cy.get('body').then(($row) => {
            if ($row.find('a, button').filter(':contains("Delete"), :contains("Remove")').length > 0) {
              cy.contains('Delete').click();
              
              // Confirm deletion if needed
              cy.get('body').then(($confirm) => {
                if ($confirm.text().includes('confirm') || $confirm.find('button').filter(':contains("Yes"), :contains("Confirm")').length > 0) {
                  cy.contains('Yes').click();
                }
              });
            }
          });
        });
      }
    });
  });
});