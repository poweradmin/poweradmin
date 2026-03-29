import { test, expect } from '@playwright/test';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Subfolder deployment navigation tests.
 *
 * These tests verify that all navigation links, pagination, and letter filters
 * include the base_url_prefix when Poweradmin is deployed in a subfolder.
 *
 * Run with: npm run test:e2e:subfolder
 * Requires the subfolder devcontainer instance on port 8086.
 */

const PREFIX = '/poweradmin';

async function subfolderLogin(page, username, password, maxRetries = 3) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      await page.goto(`${PREFIX}/login`);
      await page.fill('[data-testid="username-input"]', username);
      await page.fill('[data-testid="password-input"]', password);
      await Promise.all([
        page.waitForURL(url => !url.toString().includes('/login'), { timeout: 10000 }),
        page.click('[data-testid="login-button"]'),
      ]);
      return;
    } catch {
      if (attempt === maxRetries) {
        throw new Error(`Subfolder login failed after ${maxRetries} attempts for user: ${username}`);
      }
      await page.waitForTimeout(1000 * attempt);
    }
  }
}

test.describe('Subfolder Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await subfolderLogin(page, users.admin.username, users.admin.password);
  });

  test('should load dashboard with subfolder prefix', async ({ page }) => {
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should have navigation links with subfolder prefix', async ({ page }) => {
    const navLinks = page.locator('nav a[href]');
    const count = await navLinks.count();

    for (let i = 0; i < count; i++) {
      const href = await navLinks.nth(i).getAttribute('href');
      if (href && href.startsWith('/') && !href.startsWith('//')) {
        expect(href.startsWith(PREFIX), `Nav link should start with ${PREFIX}: ${href}`).toBeTruthy();
      }
    }
  });

  test('should navigate to forward zones page', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/forward`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/forward`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to reverse zones page', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/reverse`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/reverse`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to users page', async ({ page }) => {
    await page.goto(`${PREFIX}/users`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/users`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to zone logs page', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/logs`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/logs`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to user logs page', async ({ page }) => {
    await page.goto(`${PREFIX}/users/logs`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/users/logs`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to search page', async ({ page }) => {
    await page.goto(`${PREFIX}/search`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/search`));
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|not found/i);
  });

  test('should navigate to add master zone page', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/add/master`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/add/master`));
    await expect(page.locator('form')).toBeVisible();
  });

  test('should navigate to add slave zone page', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/add/slave`);
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/add/slave`));
    await expect(page.locator('form')).toBeVisible();
  });

  test('should have letter filter links with subfolder prefix', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/forward`);

    const letterLinks = page.locator('.pagination a.page-link[href]');
    const count = await letterLinks.count();

    if (count > 0) {
      for (let i = 0; i < count; i++) {
        const href = await letterLinks.nth(i).getAttribute('href');
        if (href && href.startsWith('/')) {
          expect(href.startsWith(PREFIX), `Letter filter link should start with ${PREFIX}: ${href}`).toBeTruthy();
        }
      }
    }
  });

  test('should click letter filter and stay in subfolder', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/forward`);

    // Find any clickable letter filter link
    const letterLink = page.locator('.pagination a.page-link[href*="letter="]').first();
    const linkExists = await letterLink.count() > 0;

    if (linkExists) {
      await letterLink.click();
      await expect(page).toHaveURL(new RegExp(`${PREFIX}/zones/forward\\?letter=`));
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|not found/i);
    }
  });

  test('should load static assets from subfolder path', async ({ page }) => {
    await page.goto(`${PREFIX}/`);

    // Check that CSS loaded (page should have styled elements)
    const bootstrapLoaded = await page.evaluate(() => {
      const link = document.querySelector('link[href*="bootstrap"]');
      return link !== null;
    });
    expect(bootstrapLoaded).toBeTruthy();

    // Check that JS loaded
    const jsLoaded = await page.evaluate(() => {
      return typeof window.BASE_URL_PREFIX !== 'undefined';
    });
    expect(jsLoaded).toBeTruthy();

    // Verify BASE_URL_PREFIX is set correctly
    const prefix = await page.evaluate(() => window.BASE_URL_PREFIX);
    expect(prefix).toBe(PREFIX);
  });

  test('should have all page links with subfolder prefix', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/forward`);

    // Check all links on the page that start with /
    const allLinks = page.locator('a[href^="/"]');
    const count = await allLinks.count();

    const brokenLinks = [];
    for (let i = 0; i < count; i++) {
      const href = await allLinks.nth(i).getAttribute('href');
      if (href && href.startsWith('/') && !href.startsWith('//') && !href.startsWith(PREFIX)) {
        brokenLinks.push(href);
      }
    }

    expect(brokenLinks, `Links missing subfolder prefix: ${brokenLinks.join(', ')}`).toHaveLength(0);
  });

  test('should have form actions with subfolder prefix', async ({ page }) => {
    await page.goto(`${PREFIX}/zones/forward`);

    const forms = page.locator('form[action^="/"]');
    const count = await forms.count();

    for (let i = 0; i < count; i++) {
      const action = await forms.nth(i).getAttribute('action');
      if (action && action.startsWith('/') && !action.startsWith('//')) {
        expect(action.startsWith(PREFIX), `Form action should start with ${PREFIX}: ${action}`).toBeTruthy();
      }
    }
  });

  test('should login and logout within subfolder', async ({ page }) => {
    // Already logged in from beforeEach, verify we're in subfolder
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/`));

    // Logout
    await page.goto(`${PREFIX}/logout`);

    // Should redirect to login page within subfolder
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/login`));
  });
});
