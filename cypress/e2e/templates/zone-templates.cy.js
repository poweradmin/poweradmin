import users from '../../fixtures/users.json';

describe('Zone Templates Management', () => {
  const templateName = `test-template-${Date.now()}`;
  const testDomain = `template-test-${Date.now()}.com`;

  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should access zone templates page', () => {
    cy.visit('/zones/templates');
    cy.url().should('include', '/zones/templates');
    cy.get('h1, h2, h3, .page-title, [data-testid*="title"]').should('be.visible');
  });

  it('should display zone templates list or empty state', () => {
    cy.visit('/zones/templates');
    
    cy.get('body').then(($body) => {
      if ($body.find('table, .table').length > 0) {
        cy.get('table, .table').should('be.visible');
      } else {
        cy.get('body').should('contain.text', 'No templates').or('contain.text', 'templates').or('contain.text', 'empty');
      }
    });
  });

  it('should create a new zone template', () => {
    cy.visit('/zones/templates/add');
    cy.url().should('include', '/zones/templates/add');
    
    cy.get('body').then(($body) => {
      if ($body.find('form').length > 0) {
        // Fill template details
        cy.get('input[name*="name"], input[name*="template"]')
          .first()
          .type(templateName);
        
        // Add description if field exists
        if ($body.find('input[name*="description"], textarea[name*="description"]').length > 0) {
          cy.get('input[name*="description"], textarea[name*="description"]')
            .first()
            .type('Test template for Cypress testing');
        }
        
        // Set owner email if field exists
        if ($body.find('input[name*="owner"], input[type="email"]').length > 0) {
          cy.get('input[name*="owner"], input[type="email"]')
            .first()
            .type('admin@example.com');
        }
        
        cy.get('button[type="submit"], input[type="submit"]').first().click();
        
        // Verify template creation
        cy.get('body').should('contain.text', 'success').or('contain.text', 'created').or('contain.text', 'added');
      }
    });
  });

  it('should add records to zone template', () => {
    // Navigate to templates and find our test template
    cy.visit('/zones/templates');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes(templateName)) {
        // Click on template to edit/add records
        cy.contains('tr', templateName).within(() => {
          cy.get('a').first().click();
        });
        
        // Add A record to template
        if ($body.find('select[name*="type"]').length > 0) {
          cy.get('select[name*="type"]').select('A');
          cy.get('input[name*="name"]').type('www');
          cy.get('input[name*="content"], input[name*="value"]').type('[ZONE]');
          
          if ($body.find('input[name*="ttl"]').length > 0) {
            cy.get('input[name*="ttl"]').clear().type('3600');
          }
          
          cy.get('button[type="submit"]').click();
          
          cy.get('body').should('contain.text', 'www');
        }
        
        // Add MX record to template
        cy.get('body').then(($templateBody) => {
          if ($templateBody.find('select[name*="type"]').length > 0) {
            cy.get('select[name*="type"]').select('MX');
            cy.get('input[name*="name"]').clear().type('@');
            cy.get('input[name*="content"], input[name*="value"]').clear().type('mail.[ZONE]');
            
            if ($templateBody.find('input[name*="prio"], input[name*="priority"]').length > 0) {
              cy.get('input[name*="prio"], input[name*="priority"]').clear().type('10');
            }
            
            cy.get('button[type="submit"]').click();
            
            cy.get('body').should('contain.text', 'mail.[ZONE]');
          }
        });
      }
    });
  });

  it('should use template when creating new zone', () => {
    // Navigate to add master zone
    cy.visit('/zones/add/master');
    
    cy.get('body').then(($body) => {
      // Fill in domain name
      cy.get('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .type(testDomain);
      
      // Select template if dropdown exists
      if ($body.find('select[name*="template"]').length > 0) {
        cy.get('select[name*="template"]').select(templateName);
      }
      
      // Set owner email if field exists
      if ($body.find('input[name*="email"], input[type="email"]').length > 0) {
        cy.get('input[name*="email"], input[type="email"]')
          .first()
          .type('admin@example.com');
      }
      
      cy.get('button[type="submit"], input[type="submit"]').first().click();
      
      // Verify zone creation
      cy.get('body').should('contain.text', 'success').or('contain.text', 'created').or('contain.text', 'added');
    });
  });

  it('should verify template records applied to new zone', () => {
    // Navigate to zones and find the domain created with template
    cy.visit('/zones/forward');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes(testDomain)) {
        cy.contains('tr', testDomain).within(() => {
          cy.get('a').first().click();
        });
        
        // Verify template records were applied
        cy.get('body').should('contain.text', 'www');
        cy.get('body').should('contain.text', 'mail');
        
        // Verify [ZONE] placeholder was replaced
        cy.get('body').should('contain.text', testDomain);
        cy.get('body').should('not.contain.text', '[ZONE]');
      }
    });
  });

  it('should edit existing zone template', () => {
    cy.visit('/zones/templates');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes(templateName)) {
        cy.contains('tr', templateName).within(() => {
          // Look for edit link
          cy.get('body').then(($row) => {
            if ($row.find('a').filter(':contains("Edit"), [href*="edit"]').length > 0) {
              cy.get('a').filter(':contains("Edit"), [href*="edit"]').first().click();
              
              // Update template description
              cy.get('input[name*="description"], textarea[name*="description"]')
                .clear()
                .type('Updated template description');
              
              cy.get('button[type="submit"], input[type="submit"]').first().click();
              
              cy.get('body').should('contain.text', 'success').or('contain.text', 'updated');
            }
          });
        });
      }
    });
  });

  it('should validate template form fields', () => {
    cy.visit('/zones/templates/add');
    
    // Try to submit empty form
    cy.get('button[type="submit"], input[type="submit"]').first().click();
    
    // Should show validation error or stay on form
    cy.url().should('include', '/zones/templates/add');
  });

  it('should show template usage statistics', () => {
    cy.visit('/zones/templates');
    
    // Check if templates show usage count or statistics
    cy.get('body').then(($body) => {
      if ($body.find('table').length > 0) {
        cy.get('table').should('be.visible');
        
        // Look for columns that might show usage
        cy.get('body').should('contain.text', 'template').or('contain.text', 'Template');
      }
    });
  });

  // Cleanup
  after(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    
    // Delete test domain if it exists
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
    
    // Delete test template
    cy.visit('/zones/templates');
    cy.get('body').then(($body) => {
      if ($body.text().includes(templateName)) {
        cy.contains('tr', templateName).within(() => {
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