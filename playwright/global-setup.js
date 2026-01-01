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

async function globalSetup(config) {
  const baseURL = config.projects[0]?.use?.baseURL || 'http://localhost:8080';

  console.log('\n[Global Setup] Starting test data preparation...');
  console.log(`[Global Setup] Base URL: ${baseURL}`);

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  try {
    // Login as admin
    console.log('[Global Setup] Logging in as admin...');
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    console.log('[Global Setup] Login successful');

    // Create zones from fixtures
    let created = 0;
    let existing = 0;
    let failed = 0;

    for (const [key, zone] of Object.entries(zones)) {
      // Skip slave zones as they require a running master
      if (zone.type.toUpperCase() === 'SLAVE') {
        console.log(`[Global Setup] Skipping slave zone: ${zone.name}`);
        continue;
      }

      try {
        const zoneId = await ensureZoneExists(page, zone.name, zone.type.toLowerCase());
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
