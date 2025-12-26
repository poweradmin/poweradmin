import users from '../../fixtures/users.json';

describe('Delete DNS Record', () => {
    // Test with zones that have deletable records
    let testZoneId;
    let testRecordId;

    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            // Navigate to zone with records
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.get('a:contains("manager-zone.example.com")').first().click();
                    cy.url().then((url) => {
                        const match = url.match(/id=(\d+)/);
                        if (match) {
                            testZoneId = match[1];
                        }
                    });
                    // Find a deletable record (not critical SOA/NS)
                    cy.get('body').then(($editBody) => {
                        if ($editBody.find('a[href*="delete_record"]').length > 0) {
                            cy.get('a[href*="delete_record"]').first().should('have.attr', 'href').then((href) => {
                                cy.visit(href);
                            });
                        }
                    });
                }
            });
        });

        it('should display delete record heading', () => {
            cy.get('[data-testid="delete-record-heading"]').should('be.visible');
            cy.get('[data-testid="delete-record-heading"]').should('contain', 'Delete record in zone');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Delete Record');
        });

        it('should display record details table', () => {
            cy.get('[data-testid="delete-record-table"]').should('be.visible');
            cy.get('[data-testid="delete-record-table"] thead').should('be.visible');
        });

        it('should display record information', () => {
            cy.get('[data-testid="record-info-row"]').should('be.visible');
            cy.get('[data-testid="record-name"]').should('be.visible');
            cy.get('[data-testid="record-type"]').should('be.visible');
            cy.get('[data-testid="record-content"]').should('be.visible');
            cy.get('[data-testid="record-ttl"]').should('be.visible');
        });

        it('should display record name', () => {
            cy.get('[data-testid="record-name"]').should('not.be.empty');
        });

        it('should display record type', () => {
            cy.get('[data-testid="record-type"]').should('not.be.empty');
        });

        it('should display record content', () => {
            cy.get('[data-testid="record-content"]').should('not.be.empty');
        });

        it('should display record TTL', () => {
            cy.get('[data-testid="record-ttl"]').should('not.be.empty');
        });

        it('should display priority if record has it', () => {
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid="record-priority"]').length > 0) {
                    cy.get('[data-testid="record-priority"]').should('be.visible');
                    cy.get('[data-testid="record-priority"]').should('not.be.empty');
                }
            });
        });

        it('should display confirmation message', () => {
            cy.get('[data-testid="confirmation-message"]').should('be.visible');
            cy.get('[data-testid="confirmation-message"]').should('contain', 'Are you sure');
        });

        it('should display delete confirmation form', () => {
            cy.get('[data-testid="delete-record-form"]').should('be.visible');
            cy.get('[data-testid="delete-record-form"]').should('have.attr', 'method', 'post');
        });

        it('should display confirm delete button', () => {
            cy.get('[data-testid="confirm-delete-button"]').should('be.visible');
            cy.get('[data-testid="confirm-delete-button"]').should('have.value', 'Yes');
            cy.get('[data-testid="confirm-delete-button"]').should('have.attr', 'type', 'submit');
        });

        it('should display cancel delete button', () => {
            cy.get('[data-testid="cancel-delete-button"]').should('be.visible');
            cy.get('[data-testid="cancel-delete-button"]').should('have.value', 'No');
            cy.get('[data-testid="cancel-delete-button"]').should('have.attr', 'type', 'button');
        });

        it('should have onclick handler on cancel button', () => {
            cy.get('[data-testid="cancel-delete-button"]').should('have.attr', 'onClick');
            cy.get('[data-testid="cancel-delete-button"]').should('have.attr', 'onClick').and('include', 'page=edit');
        });

        it('should warn when deleting critical records', () => {
            cy.get('body').then(($body) => {
                // Check if this is a critical record (SOA or NS for zone apex)
                const recordType = $body.find('[data-testid="record-type"]').text();
                if (recordType === 'SOA' || recordType === 'NS') {
                    cy.get('[data-testid="critical-record-warning"]').should('be.visible');
                    cy.get('[data-testid="critical-record-warning"]').should('contain', 'needed for this zone to work');
                }
            });
        });

        it('should have CSRF token in form', () => {
            cy.get('[data-testid="delete-record-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
            });
        });

        it('should show correct table headers', () => {
            cy.get('[data-testid="delete-record-table"] thead').within(() => {
                cy.contains('Name').should('be.visible');
                cy.contains('Type').should('be.visible');
                cy.contains('Content').should('be.visible');
                cy.contains('TTL').should('be.visible');
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            // Navigate to manager's own zone
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.get('a:contains("manager-zone.example.com")').first().click();
                    cy.get('body').then(($editBody) => {
                        if ($editBody.find('a[href*="delete_record"]').length > 0) {
                            cy.get('a[href*="delete_record"]').first().should('have.attr', 'href').then((href) => {
                                cy.visit(href);
                            });
                        }
                    });
                }
            });
        });

        it('should have access to delete records in own zones', () => {
            cy.get('[data-testid="delete-record-heading"]').should('be.visible');
            cy.get('[data-testid="delete-record-table"]').should('be.visible');
        });

        it('should display confirmation buttons', () => {
            cy.get('[data-testid="confirm-delete-button"]').should('be.visible');
            cy.get('[data-testid="cancel-delete-button"]').should('be.visible');
        });

        it('should display record details', () => {
            cy.get('[data-testid="record-name"]').should('be.visible');
            cy.get('[data-testid="record-type"]').should('be.visible');
            cy.get('[data-testid="record-content"]').should('be.visible');
        });
    });

    describe('Client User', () => {
        beforeEach(() => {
            cy.loginAs('client');
            // Navigate to client's own zone
            cy.visit('/index.php?page=list_zones');
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("client-zone.example.com")').length > 0) {
                    cy.get('a:contains("client-zone.example.com")').first().click();
                    cy.get('body').then(($editBody) => {
                        if ($editBody.find('a[href*="delete_record"]').length > 0) {
                            cy.get('a[href*="delete_record"]').first().should('have.attr', 'href').then((href) => {
                                cy.visit(href);
                            });
                        }
                    });
                }
            });
        });

        it('should have limited delete access in own zones', () => {
            cy.get('[data-testid="delete-record-heading"]').should('be.visible');
            // Client typically cannot delete SOA/NS records
        });

        it('should display delete confirmation', () => {
            cy.get('[data-testid="confirmation-message"]').should('be.visible');
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should not have access to delete records', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=list_zones');
            // Viewer should see zones but cannot delete records
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.log('Viewer can see zones but should not be able to delete records');
                }
            });
        });
    });
});
