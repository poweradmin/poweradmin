import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Language Selector', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should display language selector on login page', async ({ page }) => {
    const selector = page.locator('select[name="userlang"]');
    await expect(selector).toBeVisible();
  });

  test('should have English selected by default', async ({ page }) => {
    const selector = page.locator('select[name="userlang"]');
    const selectedValue = await selector.inputValue();
    expect(selectedValue).toBe('en_EN');
  });

  test('should always include English locale', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    expect(values).toContain('en_EN');
  });

  test('should have at least 2 languages available', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const count = await options.count();
    expect(count).toBeGreaterThanOrEqual(2);
  });

  test('should list languages in alphabetical order by name', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const labels = await options.evaluateAll(opts => opts.map(o => o.textContent.trim()));
    const sorted = [...labels].sort((a, b) => a.localeCompare(b));
    expect(labels).toEqual(sorted);
  });

  test('should have non-empty labels for all options', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const labels = await options.evaluateAll(opts => opts.map(o => o.textContent.trim()));
    for (const label of labels) {
      expect(label.length).toBeGreaterThan(0);
    }
  });

  test('should have unique locale values', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    const unique = new Set(values);
    expect(unique.size).toBe(values.length);
  });

  test('should change interface language to German after login', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    test.skip(!values.includes('de_DE'), 'German not in enabled languages');

    await page.locator('select[name="userlang"]').selectOption('de_DE');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zonen|Suche|Abmelden|Benutzer/i);
  });

  test('should change interface language to French after login', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    test.skip(!values.includes('fr_FR'), 'French not in enabled languages');

    await page.locator('select[name="userlang"]').selectOption('fr_FR');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Recherche|Déconnexion|Utilisateurs/i);
  });

  test('should display English interface with default language', async ({ page }) => {
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Search|Log out|Users/i);
  });

  test('should persist language selection across page navigation', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    test.skip(!values.includes('de_DE'), 'German not in enabled languages');

    await page.locator('select[name="userlang"]').selectOption('de_DE');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    await page.goto('/search');
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Suche|Suchergebnis/i);
  });

  test('should show language selector after logout', async ({ page }) => {
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    // Log out
    await page.locator('a[href*="logout"]').first().click();
    await page.waitForURL(/login/, { timeout: 10000 });

    const selector = page.locator('select[name="userlang"]');
    await expect(selector).toBeVisible();
  });
});
