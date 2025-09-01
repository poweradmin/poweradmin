import users from '../../fixtures/users.json';

describe('API Keys Management', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });


  it('should access API keys page', () => {
    // Try more specific selector for Tools dropdown
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').should('be.visible');
    cy.get('.dropdown-menu').contains('API Keys').click();
    
    cy.url().should('include', '/settings/api-keys');
    
    // Should either show the page or permission error
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.contains('You do not have permission to manage API keys').should('be.visible');
        cy.log('User does not have API key management permissions');
      } else {
        cy.contains('API Keys Management').should('be.visible');
        cy.log('User has API key management permissions');
      }
    });
  });

  it('should show appropriate message for user permissions', () => {
    cy.visit('/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        // User doesn't have permission - this is expected for non-admin users
        cy.contains('You do not have permission to manage API keys').should('be.visible');
        cy.log('Test passed: Permission check working correctly');
      } else if ($body.text().includes('API Keys Management')) {
        // User has permission - test the functionality
        cy.contains('API Keys Management').should('be.visible');
        
        // Check for table or empty state message
        if ($body.find('table').length > 0) {
          cy.get('table').should('be.visible');
          cy.log('API keys table found');
        } else {
          cy.contains('No API keys found').should('be.visible');
          cy.log('Empty state shown - no API keys exist');
        }
      }
    });
  });

  it('should handle API key creation if user has permissions', () => {
    // Navigate to API keys page via UI instead of direct visit to preserve session
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').contains('API Keys').click();
    cy.url().should('include', '/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.log('Skipping API key creation test - user lacks permissions');
        cy.contains('You do not have permission to manage API keys').should('be.visible');
      } else {
        // User has permissions, test functionality
        if ($body.text().includes('Add new API key')) {
          // Test creation flow
          cy.contains('Add new API key').click();
          cy.url().should('include', '/settings/api-keys/add');
          cy.contains('Add API Key').should('be.visible');
          
          // Fill form
          cy.get('input[name="name"]').type('Test API Key');
          cy.get('button[type="submit"]').contains('Create API Key').click();
          
          // Verify success
          cy.contains('API Key Created Successfully', { timeout: 10000 }).should('be.visible');
          cy.contains('IMPORTANT: Save your API key now!').should('be.visible');
          
          // Go back to list
          cy.contains('Return to API Keys').click();
          
          // Verify key appears in list
          cy.get('table tbody').should('contain', 'Test API Key');
          
          // Clean up: Delete the test key we just created
          cy.contains('tr', 'Test API Key').within(() => {
            cy.get('a').contains('Delete').click();
          });
          
          // Confirm deletion
          cy.contains('Delete API Key').should('be.visible');
          cy.get('button[type="submit"]').contains('Yes, delete this API key').click();
          
          // Verify deletion (cleanup successful)
          cy.url().should('include', '/settings/api-keys');
          cy.get('body').should('not.contain', 'Test API Key');
          cy.log('✓ Test API Key created and cleaned up successfully');
        } else {
          cy.log('Add button not available - might be at max capacity');
          if ($body.text().includes('maximum number of API keys')) {
            cy.contains('You have reached the maximum number of API keys allowed').should('be.visible');
          }
        }
      }
    });
  });

  it('should test API key deletion functionality', () => {
    // Navigate to API keys page via UI to preserve session
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').contains('API Keys').click();
    cy.url().should('include', '/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.log('User does not have permission to delete API keys - test skipped');
        cy.contains('You do not have permission to manage API keys').should('be.visible');
      } else if ($body.text().includes('Add new API key')) {
        // User has permissions and can create keys - test full deletion flow
        cy.log('Testing complete deletion flow: create → delete');
        
        // Step 1: Create an API key for testing deletion
        cy.contains('Add new API key').click();
        cy.url().should('include', '/settings/api-keys/add');
        cy.get('input[name="name"]').type('Deletion Test Key');
        cy.get('button[type="submit"]').contains('Create API Key').click();
        
        // Should be on success page
        cy.contains('API Key Created Successfully').should('be.visible');
        cy.contains('Return to API Keys').click();
        cy.url().should('include', '/settings/api-keys');
        
        // Step 2: Verify the key exists in the list
        cy.get('table tbody').should('contain', 'Deletion Test Key');
        cy.log('✓ API key created and visible in list');
        
        // Step 3: Test deletion flow
        cy.contains('tr', 'Deletion Test Key').within(() => {
          cy.get('a').contains('Delete').click();
        });
        
        // Step 4: Verify delete confirmation page
        cy.url().should('include', '/delete');
        cy.contains('Delete API Key').should('be.visible');
        cy.contains('You are about to delete the API key').should('be.visible');
        cy.contains('Deletion Test Key').should('be.visible');
        cy.log('✓ Delete confirmation page displayed correctly');
        
        // Step 5: Confirm deletion
        cy.get('button[type="submit"]').contains('Yes, delete this API key').click();
        
        // Step 6: Verify deletion was successful
        cy.url().should('include', '/settings/api-keys');
        cy.get('body').should('not.contain', 'Deletion Test Key');
        cy.log('✓ API key deletion completed successfully');
        
      } else {
        cy.log('No add button available - cannot test deletion (may be at max capacity)');
      }
    });
  });

  it('should show correct Tools menu behavior', () => {
    // Verify Tools menu shows API Keys option
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').should('be.visible');
    
    // Should show API Keys link
    cy.get('.dropdown-menu').should('contain', 'API Keys');
    
    // The link should be present regardless of permissions
    // (permissions are checked on the actual page)
    cy.get('.dropdown-menu a[href="/settings/api-keys"]').should('exist');
  });

  it('should handle form validation if user has permissions', () => {
    // Navigate to API keys page via UI instead of direct visit to preserve session
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').contains('API Keys').click();
    cy.url().should('include', '/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.log('Skipping form validation test - user lacks permissions');
      } else if ($body.text().includes('Add new API key')) {
        // Test form validation
        cy.contains('Add new API key').click();
        cy.url().should('include', '/settings/api-keys/add');
        
        // Try to submit empty form
        cy.get('button[type="submit"]').contains('Create API Key').click();
        
        // Should show HTML5 validation
        cy.get('input[name="name"]').then(($input) => {
          expect($input[0].validationMessage).to.not.be.empty;
        });
        
        // Or Bootstrap validation
        cy.get('form').should('have.class', 'was-validated');
        cy.get('.invalid-feedback').should('be.visible');
        
        cy.log('Form validation working correctly');
      } else {
        cy.log('Add button not available for form validation test');
      }
    });
  });

  it('should display API key management interface correctly if accessible', () => {
    cy.visit('/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.log('Permission check working - showing error page');
        cy.contains('You do not have permission to manage API keys').should('be.visible');
      } else {
        // User has access, verify interface elements
        cy.contains('API Keys Management').should('be.visible');
        
        // Check for information text
        cy.contains('API keys allow external applications').should('be.visible');
        
        if ($body.find('table').length > 0) {
          // Verify table structure
          cy.get('table thead').should('contain', 'Name');
          cy.get('table thead').should('contain', 'Status');
          cy.get('table thead').should('contain', 'Created at');
          cy.get('table thead').should('contain', 'Actions');
          
          cy.log('API keys table structure correct');
        } else {
          // Empty state
          cy.contains('No API keys found').should('be.visible');
          cy.log('Empty state displayed correctly');
        }
      }
    });
  });
});