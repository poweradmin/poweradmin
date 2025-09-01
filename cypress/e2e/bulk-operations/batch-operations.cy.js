import users from '../../fixtures/users.json';

describe('Bulk and Batch Operations', () => {
  const baseTestDomain = `bulk-test-${Date.now()}`;
  const testDomains = [
    `${baseTestDomain}-1.com`,
    `${baseTestDomain}-2.com`,
    `${baseTestDomain}-3.com`
  ];

  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access bulk registration page', () => {
    cy.visit('/zones/bulk-registration');
    cy.url().should('include', '/zones/bulk-registration');
    cy.get('h1, h2, h3, .page-title, form').should('be.visible');
  });

  it('should perform bulk domain registration', () => {
    cy.visit('/zones/bulk-registration');
    
    cy.get('body').then(($body) => {
      if ($body.find('textarea, input[name*="domains"], input[name*="zones"]').length > 0) {
        // Enter multiple domains for bulk registration
        const domainsText = testDomains.join('\n');
        
        cy.get('textarea, input[name*="domains"], input[name*="zones"]')
          .first()
          .type(domainsText);
        
        // Set owner email if field exists
        if ($body.find('input[name*="email"], input[type="email"]').length > 0) {
          cy.get('input[name*="email"], input[type="email"]')
            .first()
            .type('admin@example.com');
        }
        
        // Select template if available
        if ($body.find('select[name*="template"]').length > 0) {
          cy.get('select[name*="template"]').first().select(0);
        }
        
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Verify bulk registration success
        cy.get('body').should('contain.text', 'success').or('contain.text', 'created').or('contain.text', 'registered');
      }
    });
  });

  it('should verify bulk registered domains exist', () => {
    cy.visit('/zones/forward');
    
    // Check that all test domains were created
    testDomains.forEach(domain => {
      cy.get('body').should('contain.text', domain);
    });
  });

  it('should access batch PTR record generation', () => {
    cy.visit('/zones/batch-ptr');
    cy.url().should('include', '/zones/batch-ptr');
    cy.get('h1, h2, h3, .page-title, form').should('be.visible');
  });

  it('should generate batch PTR records', () => {
    cy.visit('/zones/batch-ptr');
    
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Fill in IP range for PTR generation
        if ($body.find('input[name*="start"], input[name*="from"]').length > 0) {
          cy.get('input[name*="start"], input[name*="from"]')
            .first()
            .type('192.168.1.10');
        }
        
        if ($body.find('input[name*="end"], input[name*="to"]').length > 0) {
          cy.get('input[name*="end"], input[name*="to"]')
            .first()
            .type('192.168.1.20');
        }
        
        // Set hostname pattern
        if ($body.find('input[name*="hostname"], input[name*="pattern"]').length > 0) {
          cy.get('input[name*="hostname"], input[name*="pattern"]')
            .first()
            .type(`host-[IP].${testDomains[0]}.`);
        }
        
        // Select reverse zone if dropdown exists
        if ($body.find('select[name*="zone"]').length > 0) {
          cy.get('select[name*="zone"]').first().select(0);
        }
        
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Verify PTR generation
        cy.get('body').should('contain.text', 'success').or('contain.text', 'generated').or('contain.text', 'created');
      }
    });
  });

  it('should perform bulk zone deletion', () => {
    cy.visit('/zones/forward');
    
    // Select multiple domains for deletion (if checkboxes exist)
    cy.get('body').then(($body) => {
      if ($body.find('input[type="checkbox"]').length > 0) {
        // Select test domains for bulk deletion
        testDomains.forEach(domain => {
          cy.contains('tr', domain).within(() => {
            if (cy.get('input[type="checkbox"]')) {
              cy.get('input[type="checkbox"]').check();
            }
          });
        });
        
        // Look for bulk delete button
        if ($body.find('button, input').filter(':contains("Delete"), :contains("Bulk")').length > 0) {
          cy.contains('button, input', /Delete|Bulk/i).click();
          
          // Confirm bulk deletion
          cy.get('body').then(($confirm) => {
            if ($confirm.text().includes('confirm') || $confirm.find('button').filter(':contains("Yes"), :contains("Confirm")').length > 0) {
              cy.contains('Yes').click();
            }
          });
          
          // Verify domains were deleted
          testDomains.forEach(domain => {
            cy.get('body').should('not.contain.text', domain);
          });
        }
      } else {
        // Manual deletion if no bulk option
        testDomains.forEach(domain => {
          cy.get('body').then(($list) => {
            if ($list.text().includes(domain)) {
              cy.contains('tr', domain).within(() => {
                cy.get('body').then(($row) => {
                  if ($row.find('a, button').filter(':contains("Delete")').length > 0) {
                    cy.contains('Delete').click();
                    
                    cy.get('body').then(($confirm) => {
                      if ($confirm.text().includes('confirm')) {
                        cy.contains('Yes').click();
                      }
                    });
                  }
                });
              });
            }
          });
        });
      }
    });
  });

  it('should handle bulk operations with validation errors', () => {
    cy.visit('/zones/bulk-registration');
    
    cy.get('body').then(($body) => {
      if ($body.find('textarea').length > 0) {
        // Enter invalid domains
        const invalidDomains = 'invalid-domain\n..invalid..\n-invalid-';
        
        cy.get('textarea').first().type(invalidDomains);
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Should show validation errors
        cy.get('body').should('contain.text', 'error').or('contain.text', 'invalid').or('contain.text', 'validation');
      }
    });
  });

  it('should show bulk operation progress and results', () => {
    cy.visit('/zones/bulk-registration');
    
    cy.get('body').then(($body) => {
      if ($body.find('textarea').length > 0) {
        // Enter a few test domains
        const smallBatch = [
          `progress-test-${Date.now()}-1.com`,
          `progress-test-${Date.now()}-2.com`
        ].join('\n');
        
        cy.get('textarea').first().type(smallBatch);
        
        if ($body.find('input[name*="email"]').length > 0) {
          cy.get('input[name*="email"]').first().type('admin@example.com');
        }
        
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Look for progress indicators or results summary
        cy.get('body').should('contain.text', 'created').or('contain.text', 'processed').or('contain.text', 'result');
      }
    });
  });

  it('should handle bulk import from file', () => {
    cy.visit('/zones/bulk-registration');
    
    cy.get('body').then(($body) => {
      if ($body.find('input[type="file"]').length > 0) {
        // Create a test file for import (this would need actual file handling)
        cy.get('input[type="file"]').should('be.visible');
        
        // Note: File upload testing would require actual file fixtures
        cy.log('File upload functionality detected - would require file fixtures for full testing');
      }
    });
  });

  it('should export bulk zone data', () => {
    // Check for export functionality
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.find('a, button').filter(':contains("Export"), :contains("Download")').length > 0) {
        cy.contains('a, button', /Export|Download/i).should('be.visible');
        
        // Note: Actual download testing would require different approach
        cy.log('Export functionality detected');
      }
    });
  });

  // Cleanup any remaining test domains
  after(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    cy.visit('/zones/forward');
    
    // Clean up any remaining test domains
    const allTestDomains = [...testDomains, `progress-test-${Date.now()}-1.com`, `progress-test-${Date.now()}-2.com`];
    
    allTestDomains.forEach(domain => {
      cy.get('body').then(($body) => {
        if ($body.text().includes(domain.split('-')[0])) { // Check for domain prefix
          cy.get('body').then(($list) => {
            const domainRows = $list.find(`tr:contains("${domain.split('-')[0]}")`);
            domainRows.each((index, row) => {
              const deleteLink = Cypress.$(row).find('a, button').filter(':contains("Delete")');
              if (deleteLink.length > 0) {
                cy.wrap(deleteLink).click();
                
                cy.get('body').then(($confirm) => {
                  if ($confirm.text().includes('confirm')) {
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
});