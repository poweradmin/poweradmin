/**
 * Success assertion shared by the record specs that submit the add form.
 * A refusal (duplicate, second CNAME at a name, equivalent AAAA form) keeps the
 * user on /records/add, so "no fatal error" alone cannot tell it from success.
 */

import { expect } from '@playwright/test';

/**
 * Assert that the add form submit was accepted and the record is listed.
 * Call right after clicking the submit button.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @param {{name?: string, content?: string, search?: string}} expected
 *        name/content are substrings of the stored values; search defaults to name
 *        (use unique content as the search term for apex records)
 */
export async function expectRecordAdded(page, zoneId, { name, content, search }) {
  const term = search ?? name ?? content;

  await expect(page, 'the add form must be accepted and leave /records/add').not.toHaveURL(/\/records\/add/);
  await expect(page).toHaveURL(/\/zones\/\d+\/edit/);
  await expect(page.locator('.alert-danger')).toHaveCount(0);

  // Record values render as input value attributes, so match on those
  await page.goto(`/zones/${zoneId}/edit?search=${encodeURIComponent(term)}`);
  const rows = page.locator('tr:has(input[name$="][name]"])');
  await expect.poll(
    async () => rows.evaluateAll((trs, want) => trs.some((tr) => {
      const val = (suffix) => tr.querySelector(`[name$="][${suffix}]"]`)?.value ?? '';
      return (!want.name || val('name').includes(want.name))
        && (!want.content || val('content').includes(want.content));
    }), { name, content }),
    { message: `record ${name ?? ''} ${content ?? ''} must be listed on the zone edit page` }
  ).toBe(true);
}
