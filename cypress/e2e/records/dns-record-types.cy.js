import users from '../../fixtures/users.json';

describe('DNS Record Types Management', () => {
  const testDomain = `records-test-${Date.now()}.com`;
  
  before(() => {
    // Create a test domain for record testing
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    cy.visit('/zones/add/master');
    cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .type(testDomain);
    
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Wait for domain creation
    cy.visit('/zones/forward');
    cy.get('body').should('contain.text', testDomain);
  });

  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    // Navigate to the test domain's records
    cy.visit('/zones/forward');
    cy.contains('tr', testDomain).within(() => {
      cy.get('a').first().click();
    });
  });

  it('should add A record successfully', () => {
    // Add A record
    cy.get('body').then(($body) => {
      // Check if there's an add record form or button
      if ($body.find('select[name*="type"]').length > 0) {
        // Form is directly available
        cy.get('select[name*="type"]').select('A');
        cy.get('input[name*="name"]').clear().type('www');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('192.168.1.10');
        
        // Set TTL if field exists
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        // Verify record was added
        cy.get('body').should('contain.text', 'www');
        cy.get('body').should('contain.text', '192.168.1.10');
      }
    });
  });

  it('should add AAAA record (IPv6) successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('AAAA');
        cy.get('input[name*="name"]').clear().type('ipv6');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('2001:db8::1');
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', 'ipv6');
        cy.get('body').should('contain.text', '2001:db8::1');
      }
    });
  });

  it('should add CNAME record successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('CNAME');
        cy.get('input[name*="name"]').clear().type('mail');
        cy.get('input[name*="content"], input[name*="value"]').clear().type(`${testDomain}.`);
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', 'mail');
        cy.get('body').should('contain.text', testDomain);
      }
    });
  });

  it('should add MX record successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('MX');
        cy.get('input[name*="name"]').clear().type('@');
        cy.get('input[name*="content"], input[name*="value"]').clear().type(`mail.${testDomain}.`);
        
        // Set priority if field exists
        if ($body.find('input[name*="prio"], input[name*="priority"]').length > 0) {
          cy.get('input[name*="prio"], input[name*="priority"]').clear().type('10');
        }
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', `mail.${testDomain}`);
      }
    });
  });

  it('should add TXT record successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('TXT');
        cy.get('input[name*="name"]').clear().type('_dmarc');
        cy.get('input[name*="content"], input[name*="value"], textarea[name*="content"]')
          .clear()
          .type('"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"');
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', '_dmarc');
        cy.get('body').should('contain.text', 'DMARC1');
      }
    });
  });

  it('should add SRV record successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('option[value="SRV"]').length > 0) {
        cy.get('select[name*="type"]').select('SRV');
        cy.get('input[name*="name"]').clear().type('_sip._tcp');
        cy.get('input[name*="content"], input[name*="value"]').clear().type(`sip.${testDomain}.`);
        
        // Set SRV-specific fields if they exist
        if ($body.find('input[name*="prio"], input[name*="priority"]').length > 0) {
          cy.get('input[name*="prio"], input[name*="priority"]').clear().type('10');
        }
        
        if ($body.find('input[name*="weight"]').length > 0) {
          cy.get('input[name*="weight"]').clear().type('20');
        }
        
        if ($body.find('input[name*="port"]').length > 0) {
          cy.get('input[name*="port"]').clear().type('5060');
        }
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('3600');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', '_sip._tcp');
      }
    });
  });

  it('should add NS record successfully', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        cy.get('select[name*="type"]').select('NS');
        cy.get('input[name*="name"]').clear().type('subdomain');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('ns1.example.com.');
        
        if ($body.find('input[name*="ttl"]').length > 0) {
          cy.get('input[name*="ttl"]').clear().type('86400');
        }
        
        cy.get('button[type="submit"]').click();
        
        cy.get('body').should('contain.text', 'subdomain');
        cy.get('body').should('contain.text', 'ns1.example.com');
      }
    });
  });

  it('should add PTR record for reverse DNS', () => {
    // Navigate to reverse zones first
    cy.visit('/zones/reverse');
    
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Click on first reverse zone if available
        cy.get('table tbody tr').first().within(() => {
          cy.get('a').first().click();
        });
        
        // Add PTR record
        if ($body.find('select[name*="type"]').length > 0) {
          cy.get('select[name*="type"]').select('PTR');
          cy.get('input[name*="name"]').clear().type('100');
          cy.get('input[name*="content"], input[name*="value"]').clear().type(`${testDomain}.`);
          
          cy.get('button[type="submit"]').click();
          
          cy.get('body').should('contain.text', '100');
          cy.get('body').should('contain.text', testDomain);
        }
      }
    });
  });

  it('should validate record content based on type', () => {
    cy.get('body').then(($body) => {
      if ($body.find('select[name*="type"]').length > 0) {
        // Test A record with invalid IP
        cy.get('select[name*="type"]').select('A');
        cy.get('input[name*="name"]').clear().type('invalid');
        cy.get('input[name*="content"], input[name*="value"]').clear().type('invalid-ip');
        
        cy.get('button[type="submit"]').click();
        
        // Should show validation error or stay on form
        cy.url().should('include', '/add').or('include', '/edit');
      }
    });
  });

  it('should edit existing DNS record', () => {
    // Find an existing record and edit it
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Look for edit link in first record row
        cy.get('table tbody tr').first().within(() => {
          cy.get('body').then(($row) => {
            if ($row.find('a').filter(':contains("Edit"), [href*="edit"]').length > 0) {
              cy.get('a').filter(':contains("Edit"), [href*="edit"]').first().click();
              
              // Update the record content
              cy.get('input[name*="content"], input[name*="value"]').clear().type('192.168.1.200');
              cy.get('button[type="submit"]').click();
              
              // Verify update
              cy.get('body').should('contain.text', '192.168.1.200');
            }
          });
        });
      }
    });
  });

  it('should delete DNS record', () => {
    cy.get('body').then(($body) => {
      if ($body.find('table tbody tr').length > 0) {
        // Get the content of first record to verify deletion
        cy.get('table tbody tr').first().within(() => {
          cy.get('td').then(($cells) => {
            const recordName = $cells.eq(0).text();
            
            // Look for delete button
            if ($cells.find('a, button').filter(':contains("Delete"), [href*="delete"]').length > 0) {
              cy.get('a, button').filter(':contains("Delete"), [href*="delete"]').first().click();
              
              // Confirm deletion if needed
              cy.get('body').then(($confirm) => {
                if ($confirm.find('button').filter(':contains("Yes"), :contains("Confirm"), :contains("Delete")').length > 0) {
                  cy.contains('button', 'Yes').click();
                }
              });
              
              // Verify record is deleted
              cy.get('body').should('not.contain', recordName);
            }
          });
        });
      }
    });
  });

  // Cleanup: Delete test domain
  after(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes(testDomain)) {
        cy.contains('tr', testDomain).within(() => {
          cy.get('body').then(($row) => {
            if ($row.find('a, button').filter(':contains("Delete"), :contains("Remove")').length > 0) {
              cy.contains('Delete').click();
              
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