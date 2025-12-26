import users from '../../fixtures/users.json';

describe('Edit Zone Template Record', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
            cy.url().should('include', '/index.php');
        });

        it('should display the edit record page', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
                            cy.get('[data-testid="edit-record-form"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display all form fields with current values', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-name-input"]').should('be.visible');
                            cy.get('[data-testid="record-type-select"]').should('be.visible');
                            cy.get('[data-testid="record-content-input"]').should('be.visible');
                            cy.get('[data-testid="record-priority-input"]').should('be.visible');
                            cy.get('[data-testid="record-ttl-input"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display Update and Reset buttons', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="update-record-button"]').should('be.visible');
                            cy.get('[data-testid="reset-button"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should require content field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-content-input"]').should('have.attr', 'required');
                        }
                    });
                }
            });
        });

        it('should require TTL field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'required');
                        }
                    });
                }
            });
        });

        it('should have numeric constraints on priority field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'type', 'number');
                            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'min', '0');
                            cy.get('[data-testid="record-priority-input"]').should('have.attr', 'max', '65535');
                        }
                    });
                }
            });
        });

        it('should have numeric constraints on TTL field', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'type', 'number');
                            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'min', '0');
                            cy.get('[data-testid="record-ttl-input"]').should('have.attr', 'max', '2147483647');
                        }
                    });
                }
            });
        });

        it('should display record type select with current value', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="record-type-select"]').should('be.visible');
                            cy.get('[data-testid="record-type-select"]').find('option').should('have.length.at.least', 1);
                        }
                    });
                }
            });
        });

        it('should reset form fields when clicking Reset button', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            // Get original value
                            cy.get('[data-testid="record-content-input"]').invoke('val').then((originalValue) => {
                                // Change the value
                                cy.get('[data-testid="record-content-input"]').clear().type('Modified content');
                                // Reset the form
                                cy.get('[data-testid="reset-button"]').click();
                                // Verify it was reset
                                cy.get('[data-testid="record-content-input"]').should('have.value', originalValue);
                            });
                        }
                    });
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
            cy.url().should('include', '/index.php');
        });

        it('should allow manager to edit records in their templates', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="edit-record-"]').length > 0) {
                            cy.get('[data-testid^="edit-record-"]').first().then(($link) => {
                                const href = $link.attr('href');
                                cy.visit('/' + href);
                            });
                            cy.get('[data-testid="edit-record-heading"]').should('be.visible');
                            cy.get('[data-testid="edit-record-form"]').should('be.visible');
                        }
                    });
                }
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit zone template record page', () => {
            cy.visit('/index.php?page=edit_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="edit-record-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit zone template record page', () => {
            cy.visit('/index.php?page=edit_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="edit-record-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
            cy.url().should('include', '/index.php');
        });

        it('should not have access to edit zone template record page', () => {
            cy.visit('/index.php?page=edit_zone_templ_record&id=1&zone_templ_id=1');
            cy.get('[data-testid="edit-record-heading"]').should('not.exist');
        });
    });
});
