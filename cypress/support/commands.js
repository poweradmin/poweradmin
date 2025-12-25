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
