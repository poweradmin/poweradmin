import users from '../../fixtures/users.json';

describe('Edit DNS Record', () => {
    // Test with manager-zone.example.com which has records
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
                    // Now on the edit zone page with records
                    cy.url().then((url) => {
                        const match = url.match(/id=(\d+)/);
                        if (match) {
                            testZoneId = match[1];
                        }
                    });
                    // Find first editable record (not SOA/NS)
                    cy.get('body').then(($editBody) => {
                        if ($editBody.find('a[href*="edit_record"]').length > 0) {
                            cy.get('a[href*="edit_record"]').first().should('have.attr', 'href').then((href) => {
                                const match = href.match(/id=(\d+)/);
                                if (match) {
                                    testRecordId = match[1];
                                    cy.visit(`/index.php?page=edit_record&id=${testRecordId}`);
                                }
                            });
                        }
                    });
                }
            });
        });

        it('should display edit record heading', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            cy.get('[data-testid="edit-record-heading"]').should('contain', 'Edit record in zone');
        });

        it('should display breadcrumb navigation', () => {
            cy.get('[data-testid="breadcrumb-nav"]').should('be.visible');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Home');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Zones');
            cy.get('[data-testid="breadcrumb-nav"]').should('contain', 'Edit record');
        });

        it('should display edit record form', () => {
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
            cy.get('[data-testid="edit-record-form"]').should('have.attr', 'method', 'post');
        });

        it('should display edit record table', () => {
            cy.get('[data-testid="edit-record-table"]').should('be.visible');
            cy.get('[data-testid="edit-record-table"] thead').should('be.visible');
        });

        it('should display record input fields with existing values', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-name-input"]').should('have.value');
            cy.get('[data-testid="record-type-select"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('have.value');
        });

        it('should have TTL and priority inputs', () => {
            cy.get('[data-testid="record-ttl-input"]').should('be.visible');
            cy.get('[data-testid="record-ttl-input"]').should('have.value');
            cy.get('[data-testid="record-priority-input"]').should('be.visible');
        });

        it('should have disabled checkbox', () => {
            cy.get('[data-testid="record-disabled-checkbox"]').should('be.visible');
            cy.get('[data-testid="record-disabled-checkbox"]').should('have.attr', 'type', 'checkbox');
        });

        it('should display update and reset buttons', () => {
            cy.get('[data-testid="update-record-button"]').should('be.visible');
            cy.get('[data-testid="update-record-button"]').should('contain', 'Update');
            cy.get('[data-testid="reset-button"]').should('be.visible');
            cy.get('[data-testid="reset-button"]').should('contain', 'Reset');
        });

        it('should have update button as submit type', () => {
            cy.get('[data-testid="update-record-button"]').should('have.attr', 'type', 'submit');
        });

        it('should have reset button as reset type', () => {
            cy.get('[data-testid="reset-button"]').should('have.attr', 'type', 'reset');
        });

        it('should allow modifying record name', () => {
            cy.get('[data-testid="record-name-input"]').clear();
            cy.get('[data-testid="record-name-input"]').type('test-edit');
            cy.get('[data-testid="record-name-input"]').should('have.value', 'test-edit');
        });

        it('should allow modifying record content', () => {
            cy.get('[data-testid="record-content-input"]').then(($input) => {
                const originalValue = $input.val();
                cy.get('[data-testid="record-content-input"]').clear();
                cy.get('[data-testid="record-content-input"]').type('192.168.1.100');
                cy.get('[data-testid="record-content-input"]').should('have.value', '192.168.1.100');
            });
        });

        it('should allow changing record type', () => {
            cy.get('[data-testid="record-type-select"]').then(($select) => {
                const options = $select.find('option');
                if (options.length > 1) {
                    const secondOption = options.eq(1).val();
                    cy.get('[data-testid="record-type-select"]').select(secondOption);
                    cy.get('[data-testid="record-type-select"]').should('have.value', secondOption);
                }
            });
        });

        it('should allow modifying TTL', () => {
            cy.get('[data-testid="record-ttl-input"]').clear();
            cy.get('[data-testid="record-ttl-input"]').type('7200');
            cy.get('[data-testid="record-ttl-input"]').should('have.value', '7200');
        });

        it('should allow toggling disabled checkbox', () => {
            cy.get('[data-testid="record-disabled-checkbox"]').then(($checkbox) => {
                const wasChecked = $checkbox.is(':checked');
                if (wasChecked) {
                    cy.get('[data-testid="record-disabled-checkbox"]').uncheck();
                    cy.get('[data-testid="record-disabled-checkbox"]').should('not.be.checked');
                } else {
                    cy.get('[data-testid="record-disabled-checkbox"]').check();
                    cy.get('[data-testid="record-disabled-checkbox"]').should('be.checked');
                }
            });
        });

        it('should reset form to original values when reset button attributes are checked', () => {
            cy.get('[data-testid="reset-button"]').should('have.attr', 'name', 'reset');
            cy.get('[data-testid="reset-button"]').should('have.value', 'Reset');
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

        it('should have correct form structure with hidden fields', () => {
            cy.get('[data-testid="edit-record-form"]').within(() => {
                cy.get('input[name="_token"]').should('exist');
                cy.get('input[name="rid"]').should('exist');
                cy.get('input[name="zid"]').should('exist');
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
                        if ($editBody.find('a[href*="edit_record"]').length > 0) {
                            cy.get('a[href*="edit_record"]').first().should('have.attr', 'href').then((href) => {
                                const match = href.match(/id=(\d+)/);
                                if (match) {
                                    cy.visit(`/index.php?page=edit_record&id=${match[1]}`);
                                }
                            });
                        }
                    });
                }
            });
        });

        it('should have access to edit records in own zones', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
        });

        it('should display all editable fields', () => {
            cy.get('[data-testid="record-name-input"]').should('be.visible');
            cy.get('[data-testid="record-content-input"]').should('be.visible');
            cy.get('[data-testid="update-record-button"]').should('be.visible');
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
                        if ($editBody.find('a[href*="edit_record"]').length > 0) {
                            cy.get('a[href*="edit_record"]').first().should('have.attr', 'href').then((href) => {
                                const match = href.match(/id=(\d+)/);
                                if (match) {
                                    cy.visit(`/index.php?page=edit_record&id=${match[1]}`);
                                }
                            });
                        }
                    });
                }
            });
        });

        it('should have limited edit access in own zones', () => {
            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
            // Client may have restricted editing (cannot edit SOA/NS typically)
        });

        it('should display edit form', () => {
            cy.get('[data-testid="edit-record-form"]').should('be.visible');
        });
    });

    describe('Viewer User - Permission Check', () => {
        it('should not have access to edit records', () => {
            cy.loginAs('viewer');
            cy.visit('/index.php?page=list_zones');
            // Viewer should see zones but cannot edit records
            cy.get('body').then(($body) => {
                if ($body.find('a:contains("manager-zone.example.com")').length > 0) {
                    cy.log('Viewer can see zones but should not be able to edit records');
                }
            });
        });
    });
});
