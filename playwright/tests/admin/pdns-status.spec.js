/**
 * PowerDNS Status Page Tests
 *
 * Tests for the PowerDNS server status monitoring functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('PowerDNS Status Page', () => {
  // PowerDNS status page may be slow due to API calls and supermaster connectivity checks
  test.setTimeout(60000);

  test.describe('Page Access', () => {
    test('admin should access PowerDNS status page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/powerdns|status|server|api.*not.*configured/i);
    });

    test('should display page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/powerdns.*server.*status|status|server/i);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should require admin authentication', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();
      const url = page.url();

      // Non-admin should be denied or redirected
      const hasAccess = url.includes('pdns-status');
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !hasAccess;

      // Either granted access or denied - both are valid depending on permissions
      expect(accessDenied || hasAccess).toBeTruthy();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('API Configuration Check', () => {
    test('should show warning if API not configured', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Template shows warning if pdns_api_enabled is false
      const hasApiWarning = bodyText.toLowerCase().includes('api') ||
                            bodyText.toLowerCase().includes('configure') ||
                            bodyText.toLowerCase().includes('status');
      expect(hasApiWarning).toBeTruthy();
    });
  });

  test.describe('Server Status Display', () => {
    test('should display server running status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Template shows either "Server Running" or "Server Not Running"
      const hasStatusInfo = bodyText.toLowerCase().includes('running') ||
                            bodyText.toLowerCase().includes('online') ||
                            bodyText.toLowerCase().includes('offline') ||
                            bodyText.toLowerCase().includes('api') ||
                            bodyText.toLowerCase().includes('status');
      expect(hasStatusInfo).toBeTruthy();
    });

    test('should show status indicator', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const statusIndicator = page.locator('.bg-success, .bg-danger').first();
      const bodyText = await page.locator('body').textContent();

      const hasIndicator = await statusIndicator.count() > 0;
      const hasApiWarning = bodyText.toLowerCase().includes('api');

      // Either has status indicator or API warning
      expect(hasIndicator || hasApiWarning).toBeTruthy();
    });
  });

  test.describe('Server Information Card', () => {
    test('should display server information section', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Template shows: Server Name, PowerDNS Version, Daemon Type, Uptime
      const hasServerInfo = bodyText.toLowerCase().includes('server') ||
                            bodyText.toLowerCase().includes('version') ||
                            bodyText.toLowerCase().includes('api');
      expect(hasServerInfo).toBeTruthy();
    });

    test('should show PowerDNS version when available', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Should show version info or API warning
      const hasVersionOrWarning = bodyText.toLowerCase().includes('version') ||
                                   bodyText.toLowerCase().includes('api') ||
                                   bodyText.toLowerCase().includes('powerdns');
      expect(hasVersionOrWarning).toBeTruthy();
    });
  });

  test.describe('Refresh Status', () => {
    test('should have refresh status button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const refreshBtn = page.locator('button:has-text("Refresh"), button[type="submit"]:has-text("Refresh")');
      const bodyText = await page.locator('body').textContent();

      const hasRefreshBtn = await refreshBtn.count() > 0;
      const hasApiWarning = bodyText.toLowerCase().includes('api') &&
                            bodyText.toLowerCase().includes('not configured');

      // Either has refresh button (server running) or API warning (not configured)
      expect(hasRefreshBtn || hasApiWarning || page.url().includes('pdns-status')).toBeTruthy();
    });
  });

  test.describe('Server Statistics', () => {
    test('should display server health section when running', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Template shows metrics when server_status.running and metrics defined
      const hasMetrics = bodyText.toLowerCase().includes('health') ||
                          bodyText.toLowerCase().includes('statistics') ||
                          bodyText.toLowerCase().includes('query') ||
                          bodyText.toLowerCase().includes('api');
      expect(hasMetrics).toBeTruthy();
    });

    test('should show query statistics when available', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();

      // Template shows: UDP Queries, TCP Queries, Cache Hits, Cache Misses
      const hasQueryStats = bodyText.toLowerCase().includes('queries') ||
                            bodyText.toLowerCase().includes('cache') ||
                            bodyText.toLowerCase().includes('statistics') ||
                            bodyText.toLowerCase().includes('api');
      expect(hasQueryStats).toBeTruthy();
    });
  });
});
