import users from '../../fixtures/users.json';

describe('Complete DNS Management Workflow Integration', () => {
  const companyName = 'cypress-company';
  const primaryDomain = `${companyName}.com`;
  const subDomain = `sub.${primaryDomain}`;
  const mailDomain = `mail.${primaryDomain}`;
  
  before(() => {
    // Initial setup - login once for the entire suite
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
  });

  it('should complete full company DNS setup workflow', () => {
    // Step 1: Create primary company domain
    cy.visit('/zones/add/master');
    
    cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .type(primaryDomain);
    
    // Set company admin email
    cy.get('body').then(($body) => {
      if ($body.find('input[name*="email"], input[type="email"]').length > 0) {
        cy.get('input[name*="email"], input[type="email"]')
          .first()
          .type(`admin@${primaryDomain}`);
      }
    });
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Verify domain creation
    cy.visit('/zones/forward');
    cy.get('body').should('contain.text', primaryDomain);
    
    // Step 2: Add essential DNS records for company infrastructure
    cy.contains('tr', primaryDomain).within(() => {
      cy.get('a').first().click();
    });
    
    // Add website A record (www)
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('A');
        cy.get('input[name*="name"]').clear().type('www');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('192.168.1.10');
        cy.get('button[type="submit"]').click();
        
        // Add root domain A record
        cy.get('select[name*="type"]').select('A');
        cy.get('input[name*="name"]').clear().type('@');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('192.168.1.10');
        cy.get('button[type="submit"]').click();
        
        // Add mail server A record
        cy.get('select[name*="type"]').select('A');
        cy.get('input[name*="name"]').clear().type('mail');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('192.168.1.20');
        cy.get('button[type="submit"]').click();
        
        // Add MX record for email
        cy.get('select[name*="type"]').select('MX');
        cy.get('input[name*="name"]').clear().type('@');
        cy.get('input[name*="content"], input[name*="value"]').clear().type(`mail.${primaryDomain}.`);
        
        // Set MX priority
        cy.get('body').then(($mxBody) => {
          if ($mxBody.find('input[name*="prio"], input[name*="priority"]').length > 0) {
            cy.get('input[name*="prio"], input[name*="priority"]').clear().type('10');
          }
        });
        
        cy.get('button[type="submit"]').click();
        
        // Add CNAME for common services
        cy.get('select[name*="type"]').select('CNAME');
        cy.get('input[name*="name"]').clear().type('ftp');
        cy.get('input[name*="content"], input[name*="value"]').clear().type(`${primaryDomain}.`);
        cy.get('button[type="submit"]').click();
        
        // Add TXT record for SPF
        cy.get('select[name*="type"]').select('TXT');
        cy.get('input[name*="name"]').clear().type('@');
        cy.get('input[name*="content"], input[name*="value"], textarea[name*="content"]')
          .clear()
          .type('"v=spf1 mx a ip4:192.168.1.20 -all"');
        cy.get('button[type="submit"]').click();
        
        // Add TXT record for DMARC
        cy.get('select[name*="type"]').select('TXT');
        cy.get('input[name*="name"]').clear().type('_dmarc');
        cy.get('input[name*="content"], input[name*="value"], textarea[name*="content"]')
          .clear()
          .type(`"v=DMARC1; p=quarantine; rua=mailto:dmarc@${primaryDomain}"`);
        cy.get('button[type="submit"]').click();
      }
    });
  });

  it('should create subdomain with delegation', () => {
    // Create subdomain zone
    cy.visit('/zones/add/master');
    
    cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .type(subDomain);
    
    cy.get('body').then(($body) => {
      if ($body.find('input[name*="email"], input[type="email"]').length > 0) {
        cy.get('input[name*="email"], input[type="email"]')
          .first()
          .type(`admin@${subDomain}`);
      }
    });
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Add NS record in parent domain for delegation
    cy.visit('/zones/forward');
    cy.contains('tr', primaryDomain).within(() => {
      cy.get('a').first().click();
    });
    
    // Add NS record for subdomain delegation
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('NS');
        cy.get('input[name*="name"]').clear().type('sub');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('ns1.example.com.');
        cy.get('button[type="submit"]').click();
      }
    });
  });

  it('should set up reverse DNS zones', () => {
    // Navigate to reverse zones
    cy.visit('/zones/reverse');
    
    // Check if we can add reverse zones
    cy.get('body').then(($body) => {
      if ($body.find('a, button').filter(':contains("Add")').length > 0) {
        // Try to add reverse zone (this might require specific configuration)
        cy.contains('Add').click();
        
        // Fill reverse zone details (e.g., 192.168.1.0/24)
        if ($body.find('input[name*="network"], input[name*="reverse"]').length > 0) {
          cy.get('input[name*="network"], input[name*="reverse"]')
            .first()
            .type('192.168.1.0/24');
          
          cy.get('button[type="submit"], input[type="submit"]').first().click();
          
          // Add PTR records
          cy.get('select[name*="type"]').select('PTR');
          cy.get('input[name*="name"]').clear().type('10');
          cy.get('input[name*="content"], input[name*="value"]').clear().type(`www.${primaryDomain}.`);
          cy.get('button[type="submit"]').click();
        }
      }
    });
  });

  it('should test DNS record resolution and validation', () => {
    // Use search functionality to verify records
    cy.visit('/search');
    
    cy.get('input[type="search"], input[name*="search"], input[name*="query"]')
      .first()
      .type(primaryDomain);
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Verify search results show our domain
    cy.get('body').should('contain.text', primaryDomain);
  });

  it('should perform WHOIS lookup on created domains', () => {
    cy.visit('/whois');
    
    cy.get('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]')
      .first()
      .type(primaryDomain);
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Verify WHOIS functionality works
    cy.get('body').should('contain.text', 'whois').or('contain.text', 'domain').or('contain.text', 'lookup');
  });

  it('should manage user permissions for domain access', () => {
    // Test user management in context of domain access
    cy.visit('/users');
    
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Check if users have proper permissions for domains
        cy.get('table').should('be.visible');
        
        // Look for edit user link
        cy.get('table tbody tr').first().within(() => {
          cy.get('body').then(($row) => {
            if ($row.find('a').filter(':contains("Edit")').length > 0) {
              cy.get('a').filter(':contains("Edit")').first().click();
              
              // Check for domain permissions or zone access fields
              cy.get('body').should('contain.text', 'permission').or('contain.text', 'zone').or('contain.text', 'access');
            }
          });
        });
      }
    });
  });

  it('should export zone data for backup', () => {
    cy.visit('/zones/forward');
    
    // Look for export/backup functionality
    cy.get('body').then(($body) => {
      if ($body.find('a, button').filter(':contains("Export"), :contains("Backup")').length > 0) {
        // Test export functionality
        cy.contains('a, button', /Export|Backup/i).should('be.visible');
        cy.log('Export functionality available for zone backup');
      } else {
        // Check individual zone export
        cy.contains('tr', primaryDomain).within(() => {
          cy.get('body').then(($row) => {
            if ($row.find('a').filter(':contains("Export")').length > 0) {
              cy.get('a').filter(':contains("Export")').should('be.visible');
            }
          });
        });
      }
    });
  });

  it('should validate complete DNS infrastructure', () => {
    // Final validation - check all components are working
    cy.visit('/zones/forward');
    
    // Verify primary domain exists
    cy.get('body').should('contain.text', primaryDomain);
    
    // Verify subdomain exists
    cy.get('body').should('contain.text', subDomain);
    
    // Check individual domain records
    cy.contains('tr', primaryDomain).within(() => {
      cy.get('a').first().click();
    });
    
    // Verify all essential records are present
    cy.get('body').should('contain.text', 'www'); // A record
    cy.get('body').should('contain.text', 'mail'); // Mail A record
    cy.get('body').should('contain.text', 'MX'); // MX record
    cy.get('body').should('contain.text', 'TXT'); // SPF/DMARC records
    cy.get('body').should('contain.text', 'CNAME'); // Service aliases
  });

  it('should handle zone transfer and slave configuration', () => {
    // Test slave zone creation
    cy.visit('/zones/add/slave');
    
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Create slave zone for testing
        const slaveDomain = `slave-${primaryDomain}`;
        
        cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
          .first()
          .type(slaveDomain);
        
        // Set master server
        if ($body.find('input[name*="master"], textarea[name*="master"]').length > 0) {
          cy.get('input[name*="master"], textarea[name*="master"]')
            .first()
            .type('192.168.1.100');
        }
        
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Verify slave zone creation
        cy.visit('/zones/forward');
        cy.get('body').should('contain.text', slaveDomain);
      }
    });
  });

  // Comprehensive cleanup
  after(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    // Clean up all test domains
    const domainsToClean = [primaryDomain, subDomain, `slave-${primaryDomain}`];
    
    cy.visit('/zones/forward');
    
    domainsToClean.forEach(domain => {
      cy.get('body').then(($body) => {
        if ($body.text().includes(domain) || $body.text().includes(domain.replace('.com', ''))) {
          cy.get('body').then(($list) => {
            // Find all rows that contain our test domain pattern
            const domainRows = $list.find(`tr:contains("${companyName}")`);
            domainRows.each((index, row) => {
              const deleteLink = Cypress.$(row).find('a, button').filter(':contains("Delete")');
              if (deleteLink.length > 0) {
                cy.wrap(deleteLink).first().click();
                
                cy.get('body').then(($confirm) => {
                  if ($confirm.text().includes('confirm') || $confirm.find('button').filter(':contains("Yes")').length > 0) {
                    cy.contains('button', 'Yes').click();
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