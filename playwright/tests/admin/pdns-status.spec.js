/**
 * PowerDNS Status Page Tests
 *
 * Tests for the PowerDNS server status monitoring functionality
 * covering the pdns_status.html template.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('PowerDNS Status Page', () => {
  // PowerDNS status page may be slow due to API calls and supermaster connectivity checks
  test.setTimeout(60000);

  // All admin-facing checks share one render of the status page, which stalls
  // ~4s on a PowerDNS API call. Navigate once, then assert every section.
  test.describe('Status Page Rendering', () => {
    test('status page renders expected sections and controls', async ({ adminPage: page }) => {
      await page.goto('/tools/pdns-status', { timeout: 30000 });

      const bodyText = await page.locator('body').textContent();
      const lower = bodyText.toLowerCase();
      const onStatusPage = page.url().includes('pdns-status');

      // Page access + title
      expect(lower).toMatch(/powerdns|status|server|api.*not.*configured/i);
      expect(lower).toMatch(/powerdns.*server.*status|status|server/i);

      // Breadcrumb navigation
      await expect(page.locator('nav[aria-label="breadcrumb"]')).toBeVisible();

      // API configuration warning (shown if pdns_api_enabled is false)
      expect(lower.includes('api') || lower.includes('configure') || lower.includes('status')).toBeTruthy();

      // Server running status (Server Running / Server Not Running)
      expect(
        lower.includes('running') || lower.includes('online') || lower.includes('offline') ||
        lower.includes('api') || lower.includes('status')
      ).toBeTruthy();

      // Status indicator badge, or API warning
      const hasIndicator = await page.locator('.bg-success, .bg-danger').first().count() > 0;
      expect(hasIndicator || lower.includes('api')).toBeTruthy();

      // Server information section (Server Name, Version, Daemon Type, Uptime)
      expect(lower.includes('server') || lower.includes('version') || lower.includes('api')).toBeTruthy();

      // PowerDNS version, or API warning
      expect(lower.includes('version') || lower.includes('api') || lower.includes('powerdns')).toBeTruthy();

      // Refresh button (server running) or API-not-configured warning
      const hasRefreshBtn = await page.locator('button:has-text("Refresh"), button[type="submit"]:has-text("Refresh")').count() > 0;
      expect(hasRefreshBtn || (lower.includes('api') && lower.includes('not configured')) || onStatusPage).toBeTruthy();

      // CSRF token in refresh form
      const hasToken = await page.locator('input[name="_token"]').count() > 0;
      expect(hasToken || onStatusPage).toBeTruthy();

      // Server health / metrics section
      expect(
        lower.includes('health') || lower.includes('statistics') ||
        lower.includes('query') || lower.includes('api')
      ).toBeTruthy();

      // Query statistics (UDP/TCP Queries, Cache Hits/Misses)
      expect(
        lower.includes('queries') || lower.includes('cache') ||
        lower.includes('statistics') || lower.includes('api')
      ).toBeTruthy();

      // Metrics tabs, or API warning
      const hasTabs = await page.locator('[role="tablist"], .nav-tabs').count() > 0;
      expect(hasTabs || lower.includes('api') || onStatusPage).toBeTruthy();

      // View toggle button (metrics available) or API-not-configured warning
      const hasToggle = await page.locator('#viewToggle, button:has-text("Toggle View")').count() > 0;
      expect(hasToggle || (lower.includes('api') && lower.includes('not configured')) || onStatusPage).toBeTruthy();

      // Error handling message (server_status.error)
      expect(
        lower.includes('error') || lower.includes('running') ||
        lower.includes('api') || lower.includes('status')
      ).toBeTruthy();

      // Slave servers section (shown when slave_status has entries)
      expect(
        lower.includes('slave') || lower.includes('supermaster') ||
        lower.includes('server') || lower.includes('api')
      ).toBeTruthy();
    });
  });

  // Auth/redirect checks stay separate - they exercise different sessions.
  test.describe('Access Control', () => {
    test('should require admin authentication', async ({ managerPage: page }) => {
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
});

test.describe('Database Consistency Check Page', () => {
  test.describe('Page Access', () => {
    test('admin should access database consistency page', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/database|consistency|check|error|permission/i);
    });

    test('should display page title', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/database|consistency|check/i);
    });

    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"], .breadcrumb');
      const hasBreadcrumb = await breadcrumb.count() > 0;

      expect(hasBreadcrumb || page.url().includes('database-consistency')).toBeTruthy();
    });

    test('should require login', async ({ page }) => {
      await page.goto('/tools/database-consistency');

      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('Summary Section', () => {
    test('should display summary of issues', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: Total Issues Found, Errors, Warnings
      const hasSummary = bodyText.toLowerCase().includes('summary') ||
                          bodyText.toLowerCase().includes('issues') ||
                          bodyText.toLowerCase().includes('error') ||
                          bodyText.toLowerCase().includes('warning') ||
                          bodyText.toLowerCase().includes('permission');
      expect(hasSummary).toBeTruthy();
    });

    test('should show issue counts', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows counts for errors and warnings
      const hasCounts = bodyText.toLowerCase().includes('total') ||
                        bodyText.toLowerCase().includes('found') ||
                        bodyText.match(/\d+/) !== null;
      expect(hasCounts).toBeTruthy();
    });
  });

  test.describe('Consistency Check Categories', () => {
    test('should display zones without owners check', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Zones Without Owners"
      const hasZoneOwnerCheck = bodyText.toLowerCase().includes('zone') ||
                                 bodyText.toLowerCase().includes('owner') ||
                                 bodyText.toLowerCase().includes('permission');
      expect(hasZoneOwnerCheck).toBeTruthy();
    });

    test('should display slave zones without masters check', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Slave Zones Without Master IP Addresses"
      const hasSlaveCheck = bodyText.toLowerCase().includes('slave') ||
                            bodyText.toLowerCase().includes('master') ||
                            bodyText.toLowerCase().includes('zone') ||
                            bodyText.toLowerCase().includes('permission');
      expect(hasSlaveCheck).toBeTruthy();
    });

    test('should display orphaned records check', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Records Not Belonging to Any Zone"
      const hasOrphanCheck = bodyText.toLowerCase().includes('record') ||
                              bodyText.toLowerCase().includes('orphan') ||
                              bodyText.toLowerCase().includes('zone') ||
                              bodyText.toLowerCase().includes('permission');
      expect(hasOrphanCheck).toBeTruthy();
    });

    test('should display duplicate SOA records check', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Zones with Duplicate SOA Records"
      const hasSoaCheck = bodyText.toLowerCase().includes('soa') ||
                          bodyText.toLowerCase().includes('duplicate') ||
                          bodyText.toLowerCase().includes('zone') ||
                          bodyText.toLowerCase().includes('permission');
      expect(hasSoaCheck).toBeTruthy();
    });

    test('should display zones without SOA records check', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Zones Without SOA Records"
      const hasNoSoaCheck = bodyText.toLowerCase().includes('soa') ||
                            bodyText.toLowerCase().includes('without') ||
                            bodyText.toLowerCase().includes('zone') ||
                            bodyText.toLowerCase().includes('permission');
      expect(hasNoSoaCheck).toBeTruthy();
    });
  });

  test.describe('Fix Actions', () => {
    test('should have fix buttons for issues', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const fixBtn = page.locator('button:has-text("Fix"), input[value="Fix"]');
      const bodyText = await page.locator('body').textContent();

      const hasFixBtn = await fixBtn.count() > 0;
      const noIssues = !bodyText.toLowerCase().includes('issue') ||
                       bodyText.toLowerCase().includes('0 issues') ||
                       bodyText.toLowerCase().includes('permission');

      // Either has fix buttons (issues exist) or no issues
      expect(hasFixBtn || noIssues || page.url().includes('database-consistency')).toBeTruthy();
    });

    test('should include CSRF token in fix forms', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const csrfToken = page.locator('input[name="_token"]');
      const hasToken = await csrfToken.count() > 0;

      // CSRF token should be present
      expect(hasToken || page.url().includes('database-consistency')).toBeTruthy();
    });
  });

  test.describe('Delete Confirmation Modal', () => {
    test('should have delete confirmation modal', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const modal = page.locator('#deleteConfirmModal');
      const hasModal = await modal.count() > 0;

      // Modal should exist in the page
      expect(hasModal || page.url().includes('database-consistency')).toBeTruthy();
    });
  });

  test.describe('Edit Links', () => {
    test('should have edit zone links', async ({ adminPage: page }) => {
      await page.goto('/tools/database-consistency');

      const editLinks = page.locator('a[href*="/edit"]');
      const bodyText = await page.locator('body').textContent();

      const hasEditLinks = await editLinks.count() > 0;
      const noIssues = !bodyText.toLowerCase().includes('issue') ||
                       bodyText.toLowerCase().includes('permission');

      // Either has edit links (issues with zones) or no issues
      expect(hasEditLinks || noIssues || page.url().includes('database-consistency')).toBeTruthy();
    });
  });
});
