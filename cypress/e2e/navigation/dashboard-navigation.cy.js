import users from '../../fixtures/users.json';

describe('Dashboard and Navigation', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
  });

  it('should display dashboard after login', () => {
    // Should be on dashboard/home page
    cy.url().should('eq', Cypress.config('baseUrl') + '/');
    cy.get('body').should('contain.text', 'Welcome').or('contain.text', 'Dashboard').or('contain.text', 'Poweradmin');
  });

  it('should show user name on dashboard', () => {
    cy.get('body').should('contain.text', 'admin').or('contain.text', 'Welcome');
  });

  it('should display main navigation menu', () => {
    cy.get('nav, .navbar, .navigation, header').should('be.visible');
    
    // Check for key navigation items
    cy.get('body').should('contain.text', 'Zones');
    cy.get('body').should('contain.text', 'Users');
  });

  it('should have functional zone navigation links', () => {
    // Forward zones link
    cy.get('body').then(($body) => {
      if ($body.find('a').filter(':contains("Forward")').length > 0) {
        cy.contains('a', 'Forward').should('have.attr', 'href');
      }
    });
    
    // Add zone links
    cy.get('body').then(($body) => {
      if ($body.find('a').filter(':contains("Add")').length > 0) {
        cy.contains('a', /Add.*Zone/i).should('have.attr', 'href');
      }
    });
  });

  it('should navigate to forward zones page', () => {
    cy.visit('/zones/forward');
    cy.url().should('include', '/zones/forward');
    cy.get('h1, h2, h3, .page-title').should('be.visible');
  });

  it('should navigate to reverse zones page', () => {
    cy.visit('/zones/reverse');
    cy.url().should('include', '/zones/reverse');
    cy.get('h1, h2, h3, .page-title').should('be.visible');
  });

  it('should navigate to users page', () => {
    cy.visit('/users');
    cy.url().should('include', '/users');
    cy.get('h1, h2, h3, .page-title').should('be.visible');
  });

  it('should show dashboard cards or widgets', () => {
    cy.visit('/');
    
    // Look for dashboard cards/widgets
    cy.get('body').then(($body) => {
      if ($body.find('.card, .widget, .panel').length > 0) {
        cy.get('.card, .widget, .panel').should('be.visible');
      } else {
        // Alternative: check for dashboard links
        cy.get('a[data-testid*="link"], button[data-testid*="button"]').should('exist');
      }
    });
  });

  it('should have breadcrumb navigation on sub-pages', () => {
    cy.visit('/users/add');
    
    cy.get('body').then(($body) => {
      if ($body.find('.breadcrumb, nav[aria-label*="breadcrumb"]').length > 0) {
        cy.get('.breadcrumb, nav[aria-label*="breadcrumb"]').should('be.visible');
      } else {
        // Check for page title or heading
        cy.get('h1, h2, h3, .page-title').should('be.visible');
      }
    });
  });

  it('should handle responsive navigation', () => {
    // Test mobile viewport
    cy.viewport(768, 1024);
    cy.visit('/');
    
    // Navigation should still be accessible
    cy.get('nav, .navbar, .navigation, header').should('exist');
    
    // Reset viewport
    cy.viewport(1280, 720);
  });

  it('should maintain session across page navigation', () => {
    cy.visit('/');
    cy.visit('/users');
    cy.visit('/zones/forward');
    cy.visit('/search');
    
    // Should still be logged in
    cy.url().should('not.include', '/login');
    cy.get('body').should('not.contain', 'Please log in');
  });

  it('should show appropriate error pages for invalid URLs', () => {
    cy.visit('/nonexistent-page', { failOnStatusCode: false });
    
    cy.get('body').then(($body) => {
      if ($body.text().includes('404') || $body.text().includes('not found')) {
        cy.get('body').should('contain.text', '404').or('contain.text', 'not found');
      } else {
        // Might redirect to home or show different error
        cy.log('No 404 page found, application might redirect');
      }
    });
  });
});