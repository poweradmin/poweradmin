import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Language Selector', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should display language selector on login page', async ({ page }) => {
    const switcher = page.locator('#langSwitcher');
    await expect(switcher).toBeVisible();
  });

  test('should have English selected by default', async ({ page }) => {
    const currentLabel = page.locator('#currentLangLabel');
    await expect(currentLabel).toHaveText('English');
  });

  test('should always include English locale', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));
    expect(values).toContain('en_EN');
  });

  test('should have at least 2 languages available', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const count = await items.count();
    expect(count).toBeGreaterThanOrEqual(2);
  });

  test('should list languages in alphabetical order by name', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const labels = await items.evaluateAll(els => els.map(el => el.textContent.trim()));
    const sorted = [...labels].sort((a, b) => a.localeCompare(b));
    expect(labels).toEqual(sorted);
  });

  test('should have non-empty labels for all options', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const labels = await items.evaluateAll(els => els.map(el => el.textContent.trim()));
    for (const label of labels) {
      expect(label.length).toBeGreaterThan(0);
    }
  });

  test('should have unique locale values', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));
    const unique = new Set(values);
    expect(unique.size).toBe(values.length);
  });

  test('should change interface language to German after login', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));
    test.skip(!values.includes('de_DE'), 'German not in enabled languages');

    // Open dropdown and click German
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="de_DE"]').click();
    await page.waitForURL(/lang=de_DE/, { timeout: 10000 });

    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zonen|Suche|Abmelden|Benutzer/i);
  });

  test('should change interface language to French after login', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));
    test.skip(!values.includes('fr_FR'), 'French not in enabled languages');

    // Open dropdown and click French
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="fr_FR"]').click();
    await page.waitForURL(/lang=fr_FR/, { timeout: 10000 });

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
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));
    test.skip(!values.includes('de_DE'), 'German not in enabled languages');

    // Open dropdown and click German
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="de_DE"]').click();
    await page.waitForURL(/lang=de_DE/, { timeout: 10000 });

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

    // Navigate directly to logout for reliable logout
    await page.goto('/logout');
    await page.waitForURL(/login/, { timeout: 10000 });

    const switcher = page.locator('#langSwitcher');
    await expect(switcher).toBeVisible();
  });
});
