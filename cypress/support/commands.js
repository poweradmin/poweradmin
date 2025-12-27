/**
 * Login with username and password
 * Waits for successful redirect to dashboard
 */
Cypress.Commands.add('login', (username, password) => {
    cy.visit('/index.php?page=login')
    cy.get('[data-testid="username-input"]').type(username)
    cy.get('[data-testid="password-input"]').type(password)
    cy.get('[data-testid="login-button"]').click()
    // Wait for login to complete - either redirect to index or stay on login with error
    cy.url().should('satisfy', (url) => {
        return url.includes('page=index') || url.includes('page=login')
    })
})

/**
 * Login as a specific user role from fixtures with session caching
 * @param {string} userKey - Key from users.json (admin, manager, client, viewer, noperm, inactive)
 */
Cypress.Commands.add('loginAs', (userKey) => {
    cy.session(userKey, () => {
        cy.fixture('users.json').then((users) => {
            const user = users[userKey]
            if (!user) {
                throw new Error(`User "${userKey}" not found in fixtures/users.json`)
            }
            cy.visit('/index.php?page=login')
            cy.get('[data-testid="username-input"]').type(user.username)
            cy.get('[data-testid="password-input"]').type(user.password)
            cy.get('[data-testid="login-button"]').click()
            // Wait for successful login (redirect to dashboard)
            cy.url().should('include', 'page=index')
        })
    }, {
        validate() {
            // Validate session by checking we can access a protected page
            cy.visit('/index.php?page=index')
            cy.url().should('include', 'page=index')
        }
    })
    // After session restore, navigate to home page
    cy.visit('/index.php?page=index')
})

/**
 * Logout the current user
 */
Cypress.Commands.add('logout', () => {
    cy.visit('/index.php?page=logout')
})

/**
 * Navigate to permission templates list
 */
Cypress.Commands.add('goToPermissionTemplates', () => {
    cy.visit('/index.php?page=list_perm_templ')
})

/**
 * Navigate to add permission template page
 */
Cypress.Commands.add('goToAddPermissionTemplate', () => {
    cy.visit('/index.php?page=add_perm_templ')
})

/**
 * Navigate to users list page
 */
Cypress.Commands.add('goToUsers', () => {
    cy.visit('/index.php?page=users')
})

/**
 * Navigate to add user page
 */
Cypress.Commands.add('goToAddUser', () => {
    cy.visit('/index.php?page=add_user')
})

/**
 * Navigate to edit user page
 * @param {number} userId - The user ID to edit
 */
Cypress.Commands.add('goToEditUser', (userId) => {
    cy.visit(`/index.php?page=edit_user&id=${userId}`)
})

/**
 * Navigate to delete user page
 * @param {number} userId - The user ID to delete
 */
Cypress.Commands.add('goToDeleteUser', (userId) => {
    cy.visit(`/index.php?page=delete_user&id=${userId}`)
})

/**
 * Navigate to supermasters list page
 */
Cypress.Commands.add('goToSupermasters', () => {
    cy.visit('/index.php?page=list_supermasters')
})

/**
 * Navigate to add supermaster page
 */
Cypress.Commands.add('goToAddSupermaster', () => {
    cy.visit('/index.php?page=add_supermaster')
})

/**
 * Navigate to delete supermaster page
 * @param {string} masterIp - The supermaster IP address
 * @param {string} nsName - The NS hostname
 */
Cypress.Commands.add('goToDeleteSupermaster', (masterIp, nsName) => {
    cy.visit(`/index.php?page=delete_supermaster&master_ip=${masterIp}&ns_name=${nsName}`)
})

/**
 * Navigate to zones list page
 */
Cypress.Commands.add('goToZones', () => {
    cy.visit('/index.php?page=list_zones')
})

/**
 * Navigate to add slave zone page
 */
Cypress.Commands.add('goToAddSlaveZone', () => {
    cy.visit('/index.php?page=add_zone_slave')
})

/**
 * Navigate to delete zone page
 * @param {number} zoneId - The zone ID to delete
 */
Cypress.Commands.add('goToDeleteZone', (zoneId) => {
    cy.visit(`/index.php?page=delete_domain&id=${zoneId}`)
})

/**
 * Navigate to edit zone comment page
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToEditZoneComment', (zoneId) => {
    cy.visit(`/index.php?page=edit_comment&id=${zoneId}`)
})

/**
 * Navigate to dashboard (index) page
 */
Cypress.Commands.add('goToDashboard', () => {
    cy.visit('/index.php?page=index')
})

/**
 * Navigate to search page
 */
Cypress.Commands.add('goToSearch', () => {
    cy.visit('/index.php?page=search')
})

/**
 * Navigate to zone logs page
 */
Cypress.Commands.add('goToZoneLogs', () => {
    cy.visit('/index.php?page=list_log_zones')
})

/**
 * Navigate to user logs page
 */
Cypress.Commands.add('goToUserLogs', () => {
    cy.visit('/index.php?page=list_log_users')
})

/**
 * Navigate to add DNS record page
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToAddRecord', (zoneId) => {
    cy.visit(`/index.php?page=add_record&id=${zoneId}`)
})

/**
 * Navigate to edit DNS record page
 * @param {number} recordId - The record ID
 */
Cypress.Commands.add('goToEditRecord', (recordId) => {
    cy.visit(`/index.php?page=edit_record&id=${recordId}`)
})

/**
 * Navigate to delete DNS record page
 * @param {number} recordId - The record ID
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToDeleteRecord', (recordId, zoneId) => {
    cy.visit(`/index.php?page=delete_record&id=${recordId}&zone_id=${zoneId}`)
})

/**
 * Navigate to bulk registration page
 */
Cypress.Commands.add('goToBulkRegistration', () => {
    cy.visit('/index.php?page=bulk_registration')
})

/**
 * Navigate to Change Password page
 */
Cypress.Commands.add('goToChangePassword', () => {
    cy.visit('/index.php?page=change_password')
})

/**
 * Navigate to Edit Zone page
 */
Cypress.Commands.add('goToEditZone', (zoneId) => {
    cy.visit(`/index.php?page=edit&id=${zoneId}`)
})

/**
 * Get zone ID by zone name from the list zones page
 * Returns the zone ID if found, null otherwise
 *
 * Note: Zone names are displayed in td elements, not as links.
 * We find the zone name in the table and then get the ID from the edit link in the same row.
 */
Cypress.Commands.add('getZoneIdByName', (zoneName) => {
    return cy.visit('/index.php?page=list_zones').then(() => {
        return cy.get('body').then(($body) => {
            // Find the td that contains exactly the zone name (zone names are in span inside td)
            const zoneTd = $body.find(`td:contains("${zoneName}")`).filter(function() {
                // Match the EXACT zone name, not a partial match
                const text = Cypress.$(this).text().trim();
                return text === zoneName;
            }).first();

            if (zoneTd.length > 0) {
                // Find the edit link in the same row (has data-testid="edit-zone-{id}")
                const editLink = zoneTd.closest('tr').find('a[data-testid^="edit-zone-"]');
                if (editLink.length > 0) {
                    const href = editLink.attr('href');
                    const match = href.match(/id=(\d+)/);
                    return match ? match[1] : null;
                }
            }
            return null;
        });
    });
})

/**
 * Get first record ID from a zone's edit page
 * Navigates to edit page and extracts first record ID
 */
Cypress.Commands.add('getFirstRecordIdFromZone', (zoneId) => {
    return cy.visit(`/index.php?page=edit&id=${zoneId}`).then(() => {
        return cy.get('body').then(($body) => {
            const editLink = $body.find('a[href*="page=edit_record"]').first();
            if (editLink.length > 0) {
                const href = editLink.attr('href');
                const match = href.match(/id=(\d+)/);
                return match ? match[1] : null;
            }
            return null;
        });
    });
})

/**
 * Navigate to DNSSEC keys listing page
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToDNSSEC', (zoneId) => {
    cy.visit(`/index.php?page=dnssec&id=${zoneId}`)
})

/**
 * Navigate to add DNSSEC key page
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToAddDNSSECKey', (zoneId) => {
    cy.visit(`/index.php?page=dnssec_add_key&id=${zoneId}`)
})

/**
 * Navigate to edit/activate/deactivate DNSSEC key page
 * @param {number} zoneId - The zone ID
 * @param {number} keyId - The DNSSEC key ID
 */
Cypress.Commands.add('goToEditDNSSECKey', (zoneId, keyId) => {
    cy.visit(`/index.php?page=dnssec_edit_key&id=${zoneId}&key_id=${keyId}`)
})

/**
 * Navigate to delete DNSSEC key page
 * @param {number} zoneId - The zone ID
 * @param {number} keyId - The DNSSEC key ID
 */
Cypress.Commands.add('goToDeleteDNSSECKey', (zoneId, keyId) => {
    cy.visit(`/index.php?page=dnssec_delete_key&id=${zoneId}&key_id=${keyId}`)
})

/**
 * Navigate to DNSSEC DS and DNSKEY records page
 * @param {number} zoneId - The zone ID
 */
Cypress.Commands.add('goToDNSSECDSDnskey', (zoneId) => {
    cy.visit(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`)
})

/**
 * Navigate to Zone Templates list page
 */
Cypress.Commands.add('goToZoneTemplates', () => {
    cy.visit('/index.php?page=list_zone_templ')
})

/**
 * Navigate to Add Zone Template page
 */
Cypress.Commands.add('goToAddZoneTemplate', () => {
    cy.visit('/index.php?page=add_zone_templ')
})

/**
 * Navigate to Edit Zone Template page
 * @param {number} templateId - The template ID
 */
Cypress.Commands.add('goToEditZoneTemplate', (templateId) => {
    cy.visit(`/index.php?page=edit_zone_templ&id=${templateId}`)
})

/**
 * Navigate to Delete Zone Template page
 * @param {number} templateId - The template ID
 */
Cypress.Commands.add('goToDeleteZoneTemplate', (templateId) => {
    cy.visit(`/index.php?page=delete_zone_templ&id=${templateId}`)
})

/**
 * Navigate to Add Zone Template Record page
 * @param {number} templateId - The template ID
 */
Cypress.Commands.add('goToAddZoneTemplateRecord', (templateId) => {
    cy.visit(`/index.php?page=add_zone_templ_record&id=${templateId}`)
})

/**
 * Navigate to Edit Zone Template Record page
 * @param {number} recordId - The record ID
 * @param {number} templateId - The template ID
 */
Cypress.Commands.add('goToEditZoneTemplateRecord', (recordId, templateId) => {
    cy.visit(`/index.php?page=edit_zone_templ_record&id=${recordId}&zone_templ_id=${templateId}`)
})

/**
 * Navigate to Delete Zone Template Record page
 * @param {number} recordId - The record ID
 * @param {number} templateId - The template ID
 */
Cypress.Commands.add('goToDeleteZoneTemplateRecord', (recordId, templateId) => {
    cy.visit(`/index.php?page=delete_zone_templ_record&id=${recordId}&zone_templ_id=${templateId}`)
})
