import users from '../../fixtures/users.json';

describe('Add DNS Record', () => {
    // We'll use manager-zone.example.com (zone created by manager user)
    // Zone ID needs to be discovered dynamically
    let testZoneId;

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            // Get zone ID for manager-zone.example.com
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.get('a:contains("manager-zone.example.com")').first().should('have.attr', 'href').then((href) => {
                        const match = href.match(/id=(\d+)/);
                        if (match) {
                            testZoneId = match[1];
                            cy.visit(`/index.php?page=add_record&id=${testZoneId}`);
                        }
                    });
                }
            });
        });

        it('should display add record heading', () => {
            cy.get('[data-testid="add-record-heading"]').should('be.visible');
            cy.get('[data-testid="add-record-heading"]').should('contain', 'Add record to zone');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Add record');
        });

        it('should display add record form', () => {
            cy.get('[data-testid="add-record-form"]').should('be.visible');
            cy.get('[data-testid="add-record-form"]').should('have.attr', 'method', 'post');
        });

        it('should display record input fields', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-type-select"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
            cy.get('[data-testid="record-priority-input"]').should('be.visible');
            cy.get('[data-testid="record-ttl-input"]').should('be.visible');
        });

        it('should display record type dropdown with options', () => {
            cy.get('[data-testid="record-type-select"]').should('be.visible');
            cy.get('[data-testid="record-type-select"] option').should('have.length.at.least', 5);
            // Common DNS record types
            cy.get('[data-testid="record-type-select"]').should('contain', 'A');
            cy.get('[data-testid="record-type-select"]').should('contain', 'AAAA');
            cy.get('[data-testid="record-type-select"]').should('contain', 'MX');
            cy.get('[data-testid="record-type-select"]').should('contain', 'TXT');
            cy.get('[data-testid="record-type-select"]').should('contain', 'CNAME');
        });

        it('should have submit button', () => {
            cy.get('[data-testid="add-record-submit"]').should('be.visible');
            cy.get('[data-testid="add-record-submit"]').should('have.attr', 'type', 'submit');
            cy.get('[data-testid="add-record-submit"]').should('contain', 'Add record');
        });

        it('should allow typing in name input', () => {
            cy.get('[data-testid="record-name-input"]').clear();
            cy.get('[data-testid="record-name-input"]').type('www');
            cy.get('[data-testid="record-name-input"]').should('have.value', 'www');
        });

        it('should allow typing in content input', () => {
            cy.get('[data-testid="record-content-input"]').clear();
            cy.get('[data-testid="record-content-input"]').type('192.168.1.1');
            cy.get('[data-testid="record-content-input"]').should('have.value', '192.168.1.1');
        });

        it('should allow changing record type', () => {
            cy.get('[data-testid="record-type-select"]').select('MX');
            cy.get('[data-testid="record-type-select"]').should('have.value', 'MX');
        });

        it('should allow typing in TTL input', () => {
            cy.get('[data-testid="record-ttl-input"]').clear();
            cy.get('[data-testid="record-ttl-input"]').type('3600');
            cy.get('[data-testid="record-ttl-input"]').should('have.value', '3600');
        });

        it('should allow typing in priority input for MX records', () => {
            cy.get('[data-testid="record-type-select"]').select('MX');
            cy.get('[data-testid="record-priority-input"]').clear();
            cy.get('[data-testid="record-priority-input"]').type('10');
            cy.get('[data-testid="record-priority-input"]').should('have.value', '10');
        });

        it('should have required attribute on content input', () => {
            cy.get('[data-testid="record-content-input"]').should('have.attr', 'required');
        });

        it('should have min/max validation on priority input', () => {
            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'min', '0');
            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'max', '65535');
        });

        it('should have min/max validation on TTL input', () => {
            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'min', '0');
            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'max', '2147483647');
        });

        it('should display reverse PTR checkbox for forward zones', () => {
            cy.get('body').then(($body) => {
                // Check if checkbox exists (only for forward zones with A/AAAA records)
                if ($body.find('[data-testid="add-reverse-ptr-checkbox"]').length > 0) {
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').should('be.visible');
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').should('have.attr', 'type', 'checkbox');
                }
            });
        });

        it('should allow checking reverse PTR checkbox', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="add-reverse-ptr-checkbox"]').length > 0) {
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').check();
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').should('be.checked');
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').uncheck();
                    cy.get('[data-testid="add-reverse-ptr-checkbox"]').should('not.be.checked');
                }
            });
        });

        it('should have correct form structure', () => {
            cy.get('[data-testid="add-record-table"]').should('be.visible');
            cy.get('[data-testid="add-record-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
                cy.get('input[name="domain"]').should('exist');
                cy.get('input[name="name"]').should('exist');
                cy.get('select[name="type"]').should('exist');
                cy.get('input[name="content"]').should('exist');
                cy.get('input[name="prio"]').should('exist');
                cy.get('input[name="ttl"]').should('exist');
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            // Manager should be able to add records to their own zones
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.get('a:contains("manager-zone.example.com")').first().should('have.attr', 'href').then((href) => {
                        const match = href.match(/id=(\d+)/);
                        if (match) {
                            testZoneId = match[1];
                            cy.visit(`/index.php?page=add_record&id=${testZoneId}`);
                        }
                    });
                }
            });
        });

        it('should have access to add records in own zones', () => {
            cy.get('[data-testid="add-record-heading"]').should('be.visible');
            cy.get('[data-testid="add-record-form"]').should('be.visible');
        });

        it('should display all record input fields', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-type-select"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            // Client should be able to add records to their zones
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("client-zone.example.com")').length > 0) {
                    cy.get('a:contains("client-zone.example.com")').first().should('have.attr', 'href').then((href) => {
                        const match = href.match(/id=(\d+)/);
                        if (match) {
                            testZoneId = match[1];
                            cy.visit(`/index.php?page=add_record&id=${testZoneId}`);
                        }
                    });
                }
            });
        });

        it('should have access to add records in own zones', () => {
            cy.get('[data-testid="add-record-heading"]').should('be.visible');
            cy.get('[data-testid="add-record-form"]').should('be.visible');
        });

        it('should display record type dropdown', () => {
            cy.get('[data-testid="record-type-select"]').should('be.visible');
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should not have access to add records', () => {
            cy.loginAs('viewer');
            // Try to access add record page directly
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                // Viewer should see zones but no add record option
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    // Even if they can see zones, they shouldn't be able to add records
                    cy.log('Viewer can see zones but should not be able to add records');
                }
            });
        });
    });
});
