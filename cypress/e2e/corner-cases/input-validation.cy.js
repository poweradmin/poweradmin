import users from '../../fixtures/users.json';

describe('Input Validation Edge Cases', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.login(users.validUser.username, users.validUser.password);
  });

  context('Zone Name Validation', () => {
    beforeEach(() => {
      cy.get('[data-testid="add-master-zone-link"]').click();
    });

    it('should reject zone names with invalid characters', () => {
      cy.get('[data-testid="zone-name-input"]').type('invalid!domain.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      cy.get('[data-testid="zone-name-error"]').should('be.visible');
      cy.get('[data-testid="zone-name-error"]').should('contain', 'contains invalid characters');
    });

    it('should reject zone names that are too long', () => {
      // Generate a very long domain name (over 255 characters)
      const longPrefix = 'a'.repeat(245);
      cy.get('[data-testid="zone-name-input"]').type(`${longPrefix}.com`);
      cy.get('[data-testid="add-zone-button"]').click();
      
      cy.get('[data-testid="zone-name-error"]').should('be.visible');
      cy.get('[data-testid="zone-name-error"]').should('contain', 'too long');
    });

    it('should reject zone names with double dots', () => {
      cy.get('[data-testid="zone-name-input"]').type('invalid..domain.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      cy.get('[data-testid="zone-name-error"]').should('be.visible');
      cy.get('[data-testid="zone-name-error"]').should('contain', 'consecutive dots');
    });

    it('should handle unicode IDN zone names correctly', () => {
      cy.get('[data-testid="zone-name-input"]').type('xn--80aswg.xn--p1ai');
      cy.get('[data-testid="add-zone-button"]').click();
      
      // This should succeed or fail based on whether IDN is supported
      // We check for either success or a specific IDN-related error
      cy.get('body').then(($body) => {
        if ($body.find('[data-testid="alert-message"]').length > 0) {
          cy.get('[data-testid="alert-message"]').should('contain', 'Zone has been added successfully');
          
          // Clean up
          cy.get('[data-testid="list-zones-link"]').click();
          cy.contains('tr', 'xn--80aswg.xn--p1ai').within(() => {
            cy.get('[data-testid^="delete-zone-"]').click();
          });
          cy.get('[data-testid="confirm-delete-zone"]').click();
        } else {
          cy.get('[data-testid="zone-name-error"]').should('contain', 'IDN');
        }
      });
    });
  });

  context('Record Validation', () => {
    beforeEach(() => {
      // Create a test zone
      cy.get('[data-testid="add-master-zone-link"]').click();
      cy.get('[data-testid="zone-name-input"]').type('validation-test.com');
      cy.get('[data-testid="add-zone-button"]').click();
      
      // Navigate to records
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', 'validation-test.com').within(() => {
        cy.get('[data-testid^="edit-zone-"]').click();
      });
    });

    it('should reject invalid IP addresses for A records', () => {
      cy.get('[data-testid="record-type-select"]').select('A');
      cy.get('[data-testid="record-name-input"]').type('www');
      cy.get('[data-testid="record-content-input"]').type('256.256.256.256');
      cy.get('[data-testid="add-record-button"]').click();
      
      cy.get('[data-testid="record-content-error"]').should('be.visible');
      cy.get('[data-testid="record-content-error"]').should('contain', 'invalid IP address');
    });

    it('should reject invalid hostnames for CNAME records', () => {
      cy.get('[data-testid="record-type-select"]').select('CNAME');
      cy.get('[data-testid="record-name-input"]').type('mail');
      cy.get('[data-testid="record-content-input"]').type('invalid..hostname.com');
      cy.get('[data-testid="add-record-button"]').click();
      
      cy.get('[data-testid="record-content-error"]').should('be.visible');
    });

    it('should reject very long record content', () => {
      cy.get('[data-testid="record-type-select"]').select('TXT');
      cy.get('[data-testid="record-name-input"]').type('txt');
      
      // Generate a very long TXT record
      const longContent = 'a'.repeat(2000);
      cy.get('[data-testid="record-content-input"]').type(longContent);
      cy.get('[data-testid="add-record-button"]').click();
      
      cy.get('[data-testid="record-content-error"]').should('be.visible');
      cy.get('[data-testid="record-content-error"]').should('contain', 'too long');
    });

    it('should handle invalid TTL values', () => {
      cy.get('[data-testid="record-type-select"]').select('A');
      cy.get('[data-testid="record-name-input"]').type('www');
      cy.get('[data-testid="record-content-input"]').type('192.168.1.1');
      cy.get('[data-testid="record-ttl-input"]').clear().type('-100');
      cy.get('[data-testid="add-record-button"]').click();
      
      cy.get('[data-testid="record-ttl-error"]').should('be.visible');
      cy.get('[data-testid="record-ttl-error"]').should('contain', 'must be positive');
    });

    after(() => {
      // Clean up test zone
      cy.get('[data-testid="list-zones-link"]').click();
      cy.contains('tr', 'validation-test.com').within(() => {
        cy.get('[data-testid^="delete-zone-"]').click();
      });
      cy.get('[data-testid="confirm-delete-zone"]').click();
    });
  });

  context('User Input Validation', () => {
    it('should reject invalid email addresses for users', () => {
      cy.get('[data-testid="users-link"]').click();
      cy.get('[data-testid="add-user-link"]').click();
      
      cy.get('[data-testid="username-input"]').type('testuser123');
      cy.get('[data-testid="fullname-input"]').type('Test User');
      cy.get('[data-testid="email-input"]').type('notanemail@');
      cy.get('[data-testid="password-input"]').type('password123');
      cy.get('[data-testid="password-confirm-input"]').type('password123');
      
      cy.get('[data-testid="add-user-button"]').click();
      
      cy.get('[data-testid="email-error"]').should('be.visible');
      cy.get('[data-testid="email-error"]').should('contain', 'valid email');
    });

    it('should reject mismatched passwords', () => {
      cy.get('[data-testid="users-link"]').click();
      cy.get('[data-testid="add-user-link"]').click();
      
      cy.get('[data-testid="username-input"]').type('testuser123');
      cy.get('[data-testid="fullname-input"]').type('Test User');
      cy.get('[data-testid="email-input"]').type('test@example.com');
      cy.get('[data-testid="password-input"]').type('password123');
      cy.get('[data-testid="password-confirm-input"]').type('different123');
      
      cy.get('[data-testid="add-user-button"]').click();
      
      cy.get('[data-testid="password-confirm-error"]').should('be.visible');
      cy.get('[data-testid="password-confirm-error"]').should('contain', 'match');
    });

    it('should enforce password policy if configured', () => {
      cy.get('[data-testid="users-link"]').click();
      cy.get('[data-testid="add-user-link"]').click();
      
      cy.get('[data-testid="username-input"]').type('testuser123');
      cy.get('[data-testid="fullname-input"]').type('Test User');
      cy.get('[data-testid="email-input"]').type('test@example.com');
      cy.get('[data-testid="password-input"]').type('weak');
      cy.get('[data-testid="password-confirm-input"]').type('weak');
      
      cy.get('[data-testid="add-user-button"]').click();
      
      // Check if password policy is enforced
      cy.get('body').then(($body) => {
        if ($body.find('[data-testid="password-error"]').length > 0) {
          cy.get('[data-testid="password-error"]').should('contain', 'policy');
        }
      });
    });
  });
});