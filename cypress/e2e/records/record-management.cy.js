import users from '../../fixtures/users.json';

describe('Record Management', () => {
  beforeEach(() => {
    cy.visit('/index.php?page=login');
    cy.login(users.validUser.username, users.validUser.password);
    
    // Set up a test zone if it doesn't exist already
    cy.get('[data-testid="add-master-zone-link"]').click();
    cy.get('[data-testid="zone-name-input"]').type('cypress-test.com');
    cy.get('[data-testid="add-zone-button"]').click();
    
    // Navigate to the zone's records
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'cypress-test.com').within(() => {
      cy.get('[data-testid^="edit-zone-"]').click();
    });
  });

  it('should add an A record successfully', () => {
    cy.get('[data-testid="record-type-select"]').select('A');
    cy.get('[data-testid="record-name-input"]').type('www');
    cy.get('[data-testid="record-content-input"]').type('192.168.1.10');
    cy.get('[data-testid="record-ttl-input"]').clear().type('3600');
    cy.get('[data-testid="add-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added.');
    cy.contains('td', 'www').should('be.visible');
    cy.contains('td', '192.168.1.10').should('be.visible');
  });

  it('should add a CNAME record successfully', () => {
    cy.get('[data-testid="record-type-select"]').select('CNAME');
    cy.get('[data-testid="record-name-input"]').type('mail');
    cy.get('[data-testid="record-content-input"]').type('cypress-test.com.');
    cy.get('[data-testid="record-ttl-input"]').clear().type('3600');
    cy.get('[data-testid="add-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added.');
    cy.contains('td', 'mail').should('be.visible');
    cy.contains('td', 'cypress-test.com.').should('be.visible');
  });

  it('should add an MX record successfully', () => {
    cy.get('[data-testid="record-type-select"]').select('MX');
    cy.get('[data-testid="record-name-input"]').type('@');
    cy.get('[data-testid="record-content-input"]').type('mail.cypress-test.com.');
    cy.get('[data-testid="record-ttl-input"]').clear().type('3600');
    cy.get('[data-testid="record-prio-input"]').clear().type('10');
    cy.get('[data-testid="add-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully added.');
    cy.contains('td', 'mail.cypress-test.com.').should('be.visible');
  });

  it('should edit an existing record', () => {
    // Find the A record we created and edit it
    cy.contains('tr', 'www').within(() => {
      cy.get('[data-testid^="edit-record-"]').click();
    });
    
    // Update the record
    cy.get('[data-testid="record-content-input"]').clear().type('192.168.1.20');
    cy.get('[data-testid="update-record-button"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully updated.');
    cy.contains('td', '192.168.1.20').should('be.visible');
  });

  it('should delete a record', () => {
    // Find the CNAME record we created and delete it
    cy.contains('tr', 'mail').within(() => {
      cy.get('[data-testid^="delete-record-"]').click();
    });
    
    // Confirm deletion
    cy.get('[data-testid="confirm-delete-record"]').click();
    
    cy.get('[data-testid="alert-message"]').should('contain', 'The record was successfully deleted.');
    cy.contains('td', 'mail').should('not.exist');
  });

  // Clean up the test zone after all tests
  after(() => {
    cy.get('[data-testid="list-zones-link"]').click();
    cy.contains('tr', 'cypress-test.com').within(() => {
      cy.get('[data-testid^="delete-zone-"]').click();
    });
    cy.get('[data-testid="confirm-delete-zone"]').click();
  });
});