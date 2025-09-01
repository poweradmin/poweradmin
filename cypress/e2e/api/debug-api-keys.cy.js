import users from '../../fixtures/users.json';

describe('Debug API Keys', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should debug the API keys page in detail', () => {
    // Navigate to API keys page
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').contains('API Keys').click();
    
    // Take a screenshot
    cy.screenshot('api-keys-page-debug');
    
    // Log what we find on the page
    cy.get('body').then(($body) => {
      const bodyText = $body.text();
      
      cy.log('=== PAGE CONTENT DEBUG ===');
      cy.log('Page URL:', window.location.href);
      cy.log('Page contains "API Keys Management":', bodyText.includes('API Keys Management'));
      cy.log('Page contains "Add new API key":', bodyText.includes('Add new API key'));
      cy.log('Page contains "No API keys found":', bodyText.includes('No API keys found'));
      cy.log('Page contains "permission":', bodyText.includes('permission'));
      cy.log('Page contains "maximum number":', bodyText.includes('maximum number'));
      
      // Check for specific elements
      cy.log('Table exists:', $body.find('table').length > 0);
      cy.log('Card footer exists:', $body.find('.card-footer').length > 0);
      cy.log('Add button exists:', $body.find('a:contains("Add new API key")').length > 0);
      
      // Log first 500 characters of page content
      cy.log('First 500 chars:', bodyText.substring(0, 500));
    });
    
    // Check what elements exist
    cy.get('.card').should('exist').then(($card) => {
      cy.log('Found cards:', $card.length);
    });
    
    // Check if footer with add button exists
    cy.get('body').then(($body) => {
      if ($body.find('.card-footer').length > 0) {
        cy.log('Card footer found - checking contents');
        cy.get('.card-footer').then(($footer) => {
          cy.log('Footer text:', $footer.text());
        });
      } else {
        cy.log('No card footer found - this means can_add_more is false');
        
        // Check if there's a warning about max keys
        if ($body.text().includes('maximum number')) {
          cy.log('Max keys reached warning found');
        } else {
          cy.log('No max keys warning - might be permission issue');
        }
      }
    });
  });

  it('should check user permissions by testing direct access', () => {
    // Try to access the add page directly
    cy.visit('/settings/api-keys?action=add');
    
    cy.get('body').then(($body) => {
      const bodyText = $body.text();
      
      cy.log('=== ADD PAGE ACCESS DEBUG ===');
      cy.log('Direct add page access URL:', window.location.href);
      cy.log('Contains "Add API Key":', bodyText.includes('Add API Key'));
      cy.log('Contains "permission":', bodyText.includes('permission'));
      cy.log('Contains form elements:', $body.find('input[name="name"]').length > 0);
      
      if (bodyText.includes('permission')) {
        cy.log('Permission denied on add page');
      } else if (bodyText.includes('Add API Key')) {
        cy.log('Add page accessible - user has permissions');
        
        // Test the form
        cy.get('input[name="name"]').should('exist');
        cy.get('button[type="submit"]').should('exist');
      } else {
        cy.log('Unexpected page content:', bodyText.substring(0, 200));
      }
    });
    
    cy.screenshot('api-keys-add-page-debug');
  });
});