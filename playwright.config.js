import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Poweradmin E2E tests
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  // Global setup to ensure test data exists before all tests run
  globalSetup: './playwright/global-setup.js',

  testDir: './playwright/tests',

  // Maximum time one test can run for
  timeout: 30 * 1000,

  // Test match pattern
  testMatch: /.*\.spec\.js/,

  // Allow parallel test execution - read-only tests benefit from this
  // Write tests are marked with test.describe.configure({ mode: 'serial' }) in their files
  fullyParallel: true,

  // Fail the build on CI if you accidentally left test.only in the source code
  forbidOnly: !!process.env.CI,

  // One local retry absorbs rare load flakes in parallel runs
  retries: process.env.CI ? 2 : 1,

  // 2 workers locally for parallel file execution, 1 on CI for stability.
  // Workers never share sessions (each has its own cookie jar and PHP session);
  // the constraint is shared zone data, contained by per-file serial mode.
  workers: process.env.CI ? 1 : 2,

  // Reporter to use
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['list'],
    ['json', { outputFile: 'playwright-report/results.json' }]
  ],

  // Shared settings for all the projects below
  use: {
    // Base URL to use in actions like `await page.goto('/')`
    // Default to MySQL instance (port 8080). Override with BASE_URL env var for other databases:
    // - MySQL:      BASE_URL=http://localhost:8080 (default)
    // - PostgreSQL: BASE_URL=http://localhost:8081
    // - SQLite:     BASE_URL=http://localhost:8082
    baseURL: process.env.BASE_URL || 'http://localhost:8080',

    // Disable heavy features on CI for performance
    // Re-enable locally by setting CI=false or when investigating failures
    trace: process.env.CI ? 'off' : 'on-first-retry',
    screenshot: process.env.CI ? 'off' : 'only-on-failure',
    video: process.env.CI ? 'off' : 'retain-on-failure',

    // Maximum time each action such as `click()` can take
    actionTimeout: 10 * 1000,

    // Navigation timeout
    navigationTimeout: 15 * 1000,
  },

  // Configure projects for major browsers
  // Default command (npm run test:e2e) runs Chromium only for fast local feedback
  // Use --project=firefox or --project=webkit to test other browsers
  // Uncomment additional browsers below to run them by default
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },

    // Uncomment to enable Firefox tests by default
    // {
    //   name: 'firefox',
    //   use: { ...devices['Desktop Firefox'] },
    // },

    // Uncomment to enable WebKit tests by default
    // {
    //   name: 'webkit',
    //   use: { ...devices['Desktop Safari'] },
    // },

    // Mobile viewports for responsive testing
    // {
    //   name: 'Mobile Chrome',
    //   use: { ...devices['Pixel 5'] },
    // },
    // {
    //   name: 'Mobile Safari',
    //   use: { ...devices['iPhone 12'] },
    // },
  ],

  // Run your local dev server before starting the tests
  // webServer: {
  //   command: 'npm run start',
  //   url: 'http://localhost:8080',
  //   reuseExistingServer: !process.env.CI,
  // },
});
