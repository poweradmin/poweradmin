import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Issue #1032 - dns.ttl_reverse pre-fills the TTL field for PTR records in
// reverse zones. The behavioral matrix is covered by ReverseTtlResolverTest;
// this spec is a smoke test ensuring the forms render with sensible TTL values
// across configurations (including dns.ttl_reverse=0).
test.describe.configure({ mode: 'serial' });

test.describe('Reverse zone PTR default TTL', () => {
  const forwardZone = 'manager-zone.example.com';
  const reverseZone = '2.0.192.in-addr.arpa';

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('reverse-zone add-record form renders a non-negative TTL value', async ({ page }) => {
    const reverseZoneId = await findZoneIdByName(page, reverseZone);
    if (!reverseZoneId) {
      test.skip(true, `Reverse zone ${reverseZone} not found - load test data first`);
      return;
    }

    await page.goto(`/zones/${reverseZoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const ttlInput = page.locator('input[name="records[0][ttl]"]');
    await expect(ttlInput).toHaveCount(1);

    const value = await ttlInput.inputValue();
    expect(value).toMatch(/^\d+$/);
    expect(parseInt(value, 10)).toBeGreaterThanOrEqual(0);
  });

  test('forward-zone add-record form renders a non-negative TTL value', async ({ page }) => {
    const forwardZoneId = await findZoneIdByName(page, forwardZone);
    if (!forwardZoneId) {
      test.skip(true, `Forward zone ${forwardZone} not found - load test data first`);
      return;
    }

    await page.goto(`/zones/${forwardZoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const ttlInput = page.locator('input[name="records[0][ttl]"]');
    await expect(ttlInput).toHaveCount(1);

    const value = await ttlInput.inputValue();
    expect(value).toMatch(/^\d+$/);
    expect(parseInt(value, 10)).toBeGreaterThanOrEqual(0);
  });

  test('batch PTR form renders without errors and shows the default-TTL notice', async ({ page }) => {
    // BatchPtrRecordController rejects reverse-zone ids in run(); navigate without id
    // so the controller renders the standalone form that exposes the default-TTL notice.
    await page.goto('/zones/batch-ptr');
    await page.waitForLoadState('networkidle');

    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/fatal|exception/i);
    expect(body).toMatch(/default TTL value from configuration/i);
  });
});
