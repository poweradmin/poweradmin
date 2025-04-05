# Documentation

Some documentation can be generated from the source code using phpDocumentor.

To generate the documentation, run the following command:

```bash
phive install phpDocumentor
composer run docs
```

The documentation will be generated in the `docs` directory.

# Unit Tests

Project has a comprehensive set of unit tests that can be run using PHPUnit. The tests cover various aspects of the
application:

- Configuration management
- DNS record handling and formatting
- Routing functionality
- IP address validation and handling
- User authentication and password encryption
- Various utility and helper functions

To run the unit tests, use the following command:

```bash
composer install
composer run tests
```

To restore the project to its original state, run the following command:

```bash
composer install --no-dev
```

## Integration Tests

Integration tests can be run using the following command:

```bash
composer run tests:integration
```

Integration tests are located in the tests/integration directory and focus on testing functionality that interacts with
external dependencies.

## Cypress Tests

Cypress is used for end-to-end testing. The E2E test suite includes tests covering the following functionality:

### Main Feature Tests
- **Authentication** - Login and form validation
- **User Management** - Creating, editing, and deleting users
- **Zone Management** - Adding master/slave zones and records
- **Record Management** - Adding, editing, and deleting different record types
- **Zone Templates** - Template creation and application
- **Search** - Zone and record searching

### Corner Case Tests
- **Input Validation** - Testing edge cases in form validation
- **Error Handling** - Session management, security, and UI edge cases

To run the Cypress tests, follow these steps:

1. Install the necessary dependencies:

```bash
npm install
```

2. Open the Cypress test runner:

```bash
npm run cypress:open
```

3. To run the tests in headless mode, use the following command:

```bash
npm run cypress:run
```

The Cypress tests are located in the `cypress/e2e` directory organized by feature.

A complete test plan for UI testing is available in `tests/plans/cypress-ui-test-plan.md`.

## Installer Testing

See the comprehensive installer testing plan in `tests/plans/installer-test-plan.md` that covers both regular installation flows
and corner cases for properly testing the PowerAdmin installation process.
