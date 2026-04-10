import { test, expect } from '../../fixtures/test-fixtures.js';

/**
 * Tests for full language configuration (all 20 languages enabled).
 *
 * These tests verify the complete set of supported languages when
 * `enabled_languages` uses the default (all languages). Designed for
 * the MySQL devcontainer instance (port 8080).
 *
 * Run with: BASE_URL=http://localhost:8080 npx playwright test language-selector-full
 */

const ORIGINAL_LOCALES = [
  'en_EN', 'de_DE', 'fr_FR', 'es_ES', 'nl_NL', 'pl_PL',
  'cs_CZ', 'it_IT', 'ja_JP', 'ru_RU', 'zh_CN', 'tr_TR',
  'lt_LT', 'nb_NO', 'pt_PT',
];

const NEW_LOCALES = ['id_ID', 'ko_KR', 'sv_SE', 'uk_UA', 'vi_VN'];

const ALL_LOCALES = [...ORIGINAL_LOCALES, ...NEW_LOCALES];

test.describe('Language Selector - Full Configuration', () => {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8080';
  test.skip(baseUrl.includes('8082'), 'This test requires a non-SQLite instance with all languages enabled');

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should have all 20 supported languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const count = await items.count();
    expect(count).toBe(ALL_LOCALES.length);
  });

  test('should contain all original languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const locale of ORIGINAL_LOCALES) {
      expect(values).toContain(locale);
    }
  });

  test('should contain all newly added languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const locale of NEW_LOCALES) {
      expect(values).toContain(locale);
    }
  });

  test('should have no extra unexpected locales', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const value of values) {
      expect(ALL_LOCALES).toContain(value);
    }
  });
});
