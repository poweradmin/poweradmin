import users from '../../fixtures/users.json';

describe('Debug Authentication for API Keys', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should debug authentication flow step by step', () => {
    // Step 1: Check if we can access the main API keys page
    cy.visit('/settings/api-keys');
    cy.screenshot('step1-api-keys-main');
    
    cy.url().then((url) => {
      cy.log('Step 1 - Main page URL:', url);
      
      if (url.includes('/login')) {
        cy.log('❌ Redirected to login from main API keys page');
      } else {
        cy.log('✅ Main API keys page accessible');
      }
    });
    
    // Step 2: Check if we can access the add page directly
    cy.visit('/settings/api-keys/add');
    cy.screenshot('step2-api-keys-add');
    
    cy.url().then((url) => {
      cy.log('Step 2 - Add page URL:', url);
      
      if (url.includes('/login')) {
        cy.log('❌ Redirected to login from add page');
      } else {
        cy.log('✅ Add page accessible');
      }
    });
    
    // Step 3: Check what happens when we click through navigation
    cy.visit('/');
    cy.get('.dropdown-toggle').contains('Tools').click();
    cy.get('.dropdown-menu').contains('API Keys').click();
    cy.screenshot('step3-after-navigation');
    
    cy.url().then((url) => {
      cy.log('Step 3 - After navigation URL:', url);
      
      if (url.includes('/login')) {
        cy.log('❌ Redirected to login after navigation');
      } else {
        cy.log('✅ Navigation successful');
        
        // Now try to access add page from here
        cy.get('body').then(($body) => {
          if ($body.find('a:contains("Add new API key")').length > 0) {
            cy.log('✅ Add button found, testing click');
            cy.contains('Add new API key').click();
            cy.screenshot('step4-after-add-click');
            
            cy.url().then((clickUrl) => {
              cy.log('Step 4 - After clicking add button:', clickUrl);
              
              if (clickUrl.includes('/login')) {
                cy.log('❌ Redirected to login after clicking add button');
              } else {
                cy.log('✅ Add form accessible via button click');
              }
            });
          } else {
            cy.log('⚠️ Add button not found - permission issue');
          }
        });
      }
    });
  });

  it('should check session persistence', () => {
    // Check if session data is available
    cy.window().then((win) => {
      cy.log('Current hostname:', win.location.hostname);
      cy.log('Current protocol:', win.location.protocol);
    });
    
    // Navigate to API keys and check session
    cy.visit('/settings/api-keys');
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('You do not have permission')) {
        cy.log('⚠️ User has session but lacks API key permissions');
      } else if ($body.text().includes('API Keys Management')) {
        cy.log('✅ User has API key access permissions');
      } else if ($body.text().includes('login') || $body.find('form[action*="login"]').length > 0) {
        cy.log('❌ User session lost - back to login');
      } else {
        cy.log('❓ Unexpected page content');
      }
    });
  });

  it('should test direct authentication check', () => {
    // Check what the current user permissions are
    cy.visit('/');
    
    // Look for user indicator or menu items that show permissions
    cy.get('body').then(($body) => {
      const hasToolsMenu = $body.find('a:contains("Tools")').length > 0;
      const hasUsersMenu = $body.find('a:contains("Users")').length > 0;
      const hasAccountMenu = $body.find('a:contains("Account")').length > 0;
      
      cy.log('Permission indicators:');
      cy.log('- Has Tools menu:', hasToolsMenu);
      cy.log('- Has Users menu:', hasUsersMenu);
      cy.log('- Has Account menu:', hasAccountMenu);
      
      if (hasToolsMenu) {
        cy.get('a:contains("Tools")').click();
        
        cy.get('.dropdown-menu').then(($menu) => {
          const hasApiKeys = $menu.text().includes('API Keys');
          const hasWhois = $menu.text().includes('WHOIS');
          const hasDatabaseConsistency = $menu.text().includes('Database Consistency');
          
          cy.log('Tools menu contents:');
          cy.log('- Has API Keys:', hasApiKeys);
          cy.log('- Has WHOIS:', hasWhois);
          cy.log('- Has Database Consistency:', hasDatabaseConsistency);
        });
      }
    });
  });
});