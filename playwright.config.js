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

  // Run tests in files in parallel
  fullyParallel: true,

  // Fail the build on CI if you accidentally left test.only in the source code
  forbidOnly: !!process.env.CI,

  // Retry on CI only
  retries: process.env.CI ? 2 : 0,

  // Run tests serially to avoid database/backend race conditions
  workers: 1,

  // Reporter to use
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['list'],
    ['json', { outputFile: 'playwright-report/results.json' }]
  ],

  // Shared settings for all the projects below
  use: {
    // Base URL to use in actions like `await page.goto('/')`
    baseURL: 'http://localhost:8080',

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
  // Use npm run test:e2e:firefox or npm run test:e2e:webkit for other browsers
  // Use npm run test:e2e:all to run all browsers
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },

    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },

    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },

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
