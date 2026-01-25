/**
 * Global setup for Playwright E2E tests
 *
 * This script runs once before all tests to ensure required test data exists.
 * It creates zones from fixtures if they don't already exist.
 */

import { chromium } from '@playwright/test';
import { loginAndWaitForDashboard } from './helpers/auth.js';
import { ensureZoneExists } from './helpers/zones.js';
import users from './fixtures/users.json' assert { type: 'json' };
import zones from './fixtures/zones.json' assert { type: 'json' };

// Timeout for each zone operation (15 seconds)
const ZONE_OPERATION_TIMEOUT = 15000;

/**
 * Run an async operation with a timeout
 */
async function withTimeout(promise, timeoutMs, errorMessage) {
  let timeoutId;
  const timeoutPromise = new Promise((_, reject) => {
    timeoutId = setTimeout(() => reject(new Error(errorMessage)), timeoutMs);
  });

  try {
    return await Promise.race([promise, timeoutPromise]);
  } finally {
    clearTimeout(timeoutId);
  }
}

async function globalSetup(config) {
  const baseURL = config.projects[0]?.use?.baseURL || 'http://localhost:8080';

  console.log('\n[Global Setup] Starting test data preparation...');
  console.log(`[Global Setup] Base URL: ${baseURL}`);

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  // Set default timeout for page operations
  page.setDefaultTimeout(10000);

  try {
    // Login as admin
    console.log('[Global Setup] Logging in as admin...');
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    console.log('[Global Setup] Login successful');

    // Verify admin has permission to add zones (catches missing Administrator permissions)
    await page.goto('/index.php?page=add_zone_master');
    const pageContent = await page.locator('body').textContent();
    if (pageContent.includes('do not have the permission')) {
      console.error('[Global Setup] CRITICAL: Admin user lacks zone permissions!');
      console.error('[Global Setup] The Administrator template may be missing the user_is_ueberuser permission.');
      console.error('[Global Setup] Run: .devcontainer/scripts/import-test-data.sh --mysql');
      console.error('[Global Setup] Or manually add: INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53);');
      throw new Error('Admin user lacks required permissions - run import-test-data.sh to fix');
    }
    console.log('[Global Setup] Admin permissions verified');

    // Create zones from fixtures
    let existing = 0;
    let failed = 0;

    for (const [key, zone] of Object.entries(zones)) {
      // Skip slave zones as they require a running master
      if (zone.type.toUpperCase() === 'SLAVE') {
        console.log(`[Global Setup] Skipping slave zone: ${zone.name}`);
        continue;
      }

      try {
        const zoneId = await withTimeout(
          ensureZoneExists(page, zone.name, zone.type.toLowerCase()),
          ZONE_OPERATION_TIMEOUT,
          `Timeout after ${ZONE_OPERATION_TIMEOUT / 1000}s`
        );
        if (zoneId) {
          console.log(`[Global Setup] Zone ready: ${zone.name} (ID: ${zoneId})`);
          existing++;
        } else {
          console.warn(`[Global Setup] Could not create zone: ${zone.name}`);
          failed++;
        }
      } catch (error) {
        console.warn(`[Global Setup] Error with zone ${zone.name}: ${error.message}`);
        failed++;
      }
    }

    console.log(`[Global Setup] Complete: ${existing} zones ready, ${failed} failed`);

  } catch (error) {
    console.error('[Global Setup] Failed:', error.message);
    // Don't throw - allow tests to run and handle missing data gracefully
  } finally {
    await context.close();
    await browser.close();
  }
}

export default globalSetup;
