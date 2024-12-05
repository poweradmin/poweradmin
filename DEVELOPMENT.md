# Documentation

Some documentation can be generated from the source code using phpDocumentor.

To generate the documentation, run the following command:

```bash
phive install phpDocumentor
composer run docs
```

The documentation will be generated in the `docs` directory.

# Unit Tests

Project has a set of tests that can be run using PHPUnit.

To run the tests, run the following command:

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

Integration tests are located in the tests/integration directory.

## Cypress Tests

Cypress is used for end-to-end testing. To run the Cypress tests, follow these steps:
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

The Cypress tests are located in the cypress/e2e directory.
