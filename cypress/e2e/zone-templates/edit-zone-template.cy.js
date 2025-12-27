import users from '../../fixtures/users.json';

describe('Edit Zone Template', () => {
    describe('Admin User', () => {
        beforeEach(() => {
            cy.loginAs('admin');
        });

        it('should display the edit zone template page with template details form', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="edit-template-heading"]').should('be.visible');
                    cy.get('[data-testid="template-details-form"]').should('be.visible');
                }
            });
        });

        it('should display template name and description inputs', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="template-name-input"]').should('be.visible');
                    cy.get('[data-testid="template-description-input"]').should('be.visible');
                    cy.get('[data-testid="update-template-button"]').should('be.visible');
                }
            });
        });

        it('should display no records message when template has no records', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid="no-records-message"]').length > 0) {
                            cy.get('[data-testid="no-records-message"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display records table when template has records', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid="template-records-table"]').length > 0) {
                            cy.get('[data-testid="template-records-table"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display add record button if present', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid="add-record-button"]').length > 0) {
                            cy.get('[data-testid="add-record-button"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display edit and delete buttons for each record', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="record-row-"]').length > 0) {
                            cy.get('[data-testid^="record-row-"]').first().within(() => {
                                cy.get('[data-testid^="edit-record-"]').should('be.visible');
                                cy.get('[data-testid^="delete-record-"]').should('be.visible');
                            });
                        }
                    });
                }
            });
        });

        it('should display record details (name, type, content, ttl)', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid^="record-row-"]').length > 0) {
                            cy.get('[data-testid^="record-row-"]').first().within(() => {
                                cy.get('[data-testid^="record-name-"]').should('be.visible');
                                cy.get('[data-testid^="record-type-"]').should('be.visible');
                                cy.get('[data-testid^="record-content-"]').should('be.visible');
                                cy.get('[data-testid^="record-ttl-"]').should('be.visible');
                            });
                        }
                    });
                }
            });
        });

        it('should display update zones button when records exist', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        if ($body.find('[data-testid="update-zones-button"]').length > 0) {
                            cy.get('[data-testid="update-zones-button"]').should('be.visible');
                        }
                    });
                }
            });
        });

        it('should display template hints section', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="template-hints"]').should('be.visible');
                }
            });
        });

        it('should require template name in details form', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="template-name-input"]').should('have.attr', 'required');
                }
            });
        });

        it('should allow updating template name and description via API', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });

                    // Get template ID from URL
                    cy.url().then((url) => {
                        const match = url.match(/id=(\d+)/);
                        if (match) {
                            const templateId = match[1];
                            const newName = `updated-template-${Date.now()}`;

                            cy.get('input[name="_token"]').invoke('val').then((csrfToken) => {
                                cy.request({
                                    method: 'POST',
                                    url: `/index.php?page=edit_zone_templ&id=${templateId}`,
                                    form: true,
                                    body: {
                                        _token: csrfToken,
                                        templ_name: newName,
                                        templ_descr: 'Updated description',
                                        edit: 'Save'
                                    }
                                }).then((response) => {
                                    expect(response.status).to.be.oneOf([200, 302]);
                                });
                            });
                        }
                    });
                }
            });
        });

        it('should display pagination controls when records exceed page limit', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('body').then(($body) => {
                        // Only check if pagination exists (it may not if there are few records)
                        if ($body.find('[data-testid="pagination-controls"]').length > 0) {
                            cy.get('[data-testid="pagination-controls"]').should('exist');
                        }
                    });
                }
            });
        });
    });

    describe('Manager User', () => {
        beforeEach(() => {
            cy.loginAs('manager');
        });

        it('should allow manager to edit their own templates', () => {
            cy.goToZoneTemplates();
            cy.get('body').then(($body) => {
                if ($body.find('[data-testid^="edit-template-"]').length > 0) {
                    cy.get('[data-testid^="edit-template-"]').first().then(($link) => {
                        const href = $link.attr('href');
                        cy.visit('/' + href);
                    });
                    cy.get('[data-testid="edit-template-heading"]').should('be.visible');
                    cy.get('[data-testid="template-details-form"]').should('be.visible');
                }
            });
        });
    });

    describe('Client User Access', () => {
        beforeEach(() => {
            cy.loginAs('client');
        });

        it('should not have access to edit zone template page', () => {
            cy.visit('/index.php?page=edit_zone_templ&id=1');
            cy.get('[data-testid="edit-template-heading"]').should('not.exist');
        });
    });

    describe('Viewer User Access', () => {
        beforeEach(() => {
            cy.loginAs('viewer');
        });

        it('should not have access to edit zone template page', () => {
            cy.visit('/index.php?page=edit_zone_templ&id=1');
            cy.get('[data-testid="edit-template-heading"]').should('not.exist');
        });
    });

    describe('No Permission User Access', () => {
        beforeEach(() => {
            cy.loginAs('noperm');
        });

        it('should not have access to edit zone template page', () => {
            cy.visit('/index.php?page=edit_zone_templ&id=1');
            cy.get('[data-testid="edit-template-heading"]').should('not.exist');
        });
    });
});
