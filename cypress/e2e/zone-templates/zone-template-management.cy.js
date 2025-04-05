import users from '../../fixtures/users.json';

describe('Zone Template Management', () => {
  beforeEach(() => {
    cy.visit('/index.php?page=login');
    cy.login(users.validUser.username, users.validUser.password);
  });

  it('should list zone templates', () => {
    cy.get('[data-testid="zone-templ-link"]').click();
    cy.url().should('include', '/index.php?page=list_zone_templ');
    cy.get('[data-testid="zone-templates-table"]').should('be.visible');
  });

  it('should add a new zone template', () => {
    cy.get('[data-testid="zone-templ-link"]').click();
    cy.get('[data-testid="add-zone-templ-link"]').click();
    
    // Fill template form
    cy.get('[data-testid="zone-templ-name-input"]').type('Cypress Test Template');
    cy.get('[data-testid="zone-templ-desc-input"]').type('Template created by Cypress tests');
    
    // Submit form
    cy.get('[data-testid="add-zone-templ-button"]').click();
    
    // Verify success
    cy.get('[data-testid="alert-message"]').should('contain', 'The zone template has been added successfully.');
  });

  it('should add records to a zone template', () => {
    cy.get('[data-testid="zone-templ-link"]').click();
    
    // Find and select the template we created
    cy.contains('tr', 'Cypress Test Template').within(() => {
      cy.get('[data-testid^="edit-zone-templ-"]').click();
    });
    
    // Add A record to template
    cy.get('[data-testid="add-zone-templ-record-link"]').click();
    cy.get('[data-testid="record-type-select"]').select('A');
    cy.get('[data-testid="record-name-input"]').type('www');
    cy.get('[data-testid="record-content-input"]').type('192.168.1.10');
    cy.get('[data-testid="record-ttl-input"]').clear().type('3600');
    cy.get('[data-testid="add-templ-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added to the template.');
    
    // Add MX record to template
    cy.get('[data-testid="add-zone-templ-record-link"]').click();
    cy.get('[data-testid="record-type-select"]').select('MX');
    cy.get('[data-testid="record-name-input"]').type('@');
    cy.get('[data-testid="record-content-input"]').type('mail.$DOMAIN');
    cy.get('[data-testid="record-ttl-input"]').clear().type('3600');
    cy.get('[data-testid="record-prio-input"]').clear().type('10');
    cy.get('[data-testid="add-templ-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added to the template.');
  });

  it('should apply a zone template when creating a zone', () => {
    // Create a new zone with template
    cy.get('[data-testid="add-master-zone-link"]').click();
    cy.get('[data-testid="zone-name-input"]').type('template-test.com');
    cy.get('[data-testid="zone-template-select"]').select('Cypress Test Template');
    cy.get('[data-testid="add-zone-button"]').click();
    
    // Verify zone creation
    cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been added successfully.');
    
    // Check that template records were applied
    cy.contains('tr', 'template-test.com').within(() => {
      cy.get('[data-testid^="edit-zone-"]').click();
    });
    
    cy.contains('td', 'www').should('be.visible');
    cy.contains('td', '192.168.1.10').should('be.visible');
    cy.contains('td', 'mail.template-test.com').should('be.visible');
  });

  it('should edit a zone template', () => {
    cy.get('[data-testid="zone-templ-link"]').click();
    
    // Find and edit the template
    cy.contains('tr', 'Cypress Test Template').within(() => {
      cy.get('[data-testid^="edit-zone-templ-"]').click();
    });
    
    // Edit template details
    cy.get('[data-testid="edit-zone-templ-link"]').click();
    cy.get('[data-testid="zone-templ-desc-input"]').clear().type('Updated template description');
    cy.get('[data-testid="update-zone-templ-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The zone template has been updated successfully.');
  });

  it('should delete a zone template', () => {
    // First delete the test zone that uses the template
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'template-test.com').within(() => {
      cy.get('[data-testid^="delete-zone-"]').click();
    });
    cy.get('[data-testid="confirm-delete-zone"]').click();
    
    // Now delete the template
    cy.get('[data-testid="zone-templ-link"]').click();
    cy.contains('tr', 'Cypress Test Template').within(() => {
      cy.get('[data-testid^="delete-zone-templ-"]').click();
    });
    cy.get('[data-testid="confirm-delete-zone-templ"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The zone template has been deleted successfully.');
  });
});