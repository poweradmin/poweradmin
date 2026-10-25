/**
 * Throwaway users and groups for permission-change tests. Every function needs an
 * admin page and throws when the app refuses the change, so a setup step that
 * silently failed cannot turn a grant/revoke assertion into a false pass.
 */

import { expect } from '@playwright/test';
import { errorMessages } from './zones.js';

export const TEMP_USER_PASSWORD = 'TestP@ssw0rd123';

/**
 * Find a user's id on the user list.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} username
 * @returns {Promise<string|null>}
 */
export async function findUserIdByName(page, username) {
  await page.goto(`/users?search=${encodeURIComponent(username)}`);
  // The list renders usernames as inline-edit inputs, so match on the value, not the text
  const row = page.locator(`tr:has(input[value="${username}"])`);
  if (await row.count() === 0) {
    return null;
  }
  const href = await row.first().locator('a[href$="/edit"]').first().getAttribute('href');
  const match = href?.match(/\/users\/(\d+)\/edit/);
  return match ? match[1] : null;
}

/**
 * Create an active local user with the given permission template.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} username
 * @param {string} templateName - Label of a user permission template, e.g. 'No Access'
 * @returns {Promise<{id: string, username: string, password: string}>}
 */
export async function createUser(page, username, templateName) {
  await page.goto('/users/add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="fullname"]').fill(username);
  await page.locator('input[name="email"]').fill(`${username}@example.com`);
  await page.locator('input[name="password"]').fill(TEMP_USER_PASSWORD);
  await page.locator('select#perm_templ').selectOption({ label: templateName });
  await page.locator('button[name="commit"]').click();

  try {
    await page.waitForURL(url => url.pathname.endsWith('/users'), { timeout: 15000 });
  } catch {
    throw new Error(`createUser(${username}) was refused: ${await errorMessages(page) || 'still on the add form'}`);
  }

  const id = await findUserIdByName(page, username);
  if (!id) {
    throw new Error(`createUser(${username}) was accepted but the user was not found afterwards`);
  }
  return { id, username, password: TEMP_USER_PASSWORD };
}

/**
 * Change a user's permission template through the edit form.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} userId
 * @param {string} templateName
 */
export async function setUserTemplate(page, userId, templateName) {
  await page.goto(`/users/${userId}/edit`);
  await page.locator('select#perm_templ').selectOption({ label: templateName });
  await page.locator('button[name="commit"]').click();

  try {
    await page.waitForURL(url => url.pathname.endsWith('/users'), { timeout: 15000 });
  } catch {
    throw new Error(`setUserTemplate(${userId}, ${templateName}) was refused: ${await errorMessages(page) || 'still on the edit form'}`);
  }
}

/**
 * Label of the template currently stored for a user.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} userId
 * @returns {Promise<string>}
 */
export async function storedUserTemplate(page, userId) {
  await page.goto(`/users/${userId}/edit`);
  return (await page.locator('select#perm_templ option:checked').textContent()).trim();
}

/**
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} userId
 */
export async function deleteUserById(page, userId) {
  await page.goto(`/users/${userId}/delete`);
  await page.locator('button[name="commit"]').click();
  try {
    await page.waitForURL(url => url.pathname.endsWith('/users'), { timeout: 15000 });
  } catch {
    throw new Error(`deleting user ${userId} was refused: ${await errorMessages(page) || 'still on the confirmation page'}`);
  }
}

/**
 * Create a group with the given group permission template.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} name
 * @param {string} templateName - Label of a group template, e.g. 'Viewers'
 * @returns {Promise<{id: string, name: string}>}
 */
export async function createGroup(page, name, templateName) {
  await page.goto('/groups/add');
  await page.locator('input#name').fill(name);
  await page.locator('select#perm_templ').selectOption({ label: templateName });
  await page.locator('form[action$="/groups/add"] button[type="submit"]').click();

  try {
    await page.waitForURL(url => url.pathname.endsWith('/groups'), { timeout: 15000 });
  } catch {
    throw new Error(`createGroup(${name}) was refused: ${await errorMessages(page) || 'still on the add form'}`);
  }

  const href = await page.locator(`tr:has-text("${name}") a[href$="/members"]`).first().getAttribute('href');
  const id = href?.match(/\/groups\/(\d+)\/members/)?.[1];
  if (!id) {
    throw new Error(`createGroup(${name}) was accepted but the group was not listed afterwards`);
  }
  return { id, name };
}

/**
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} groupId
 */
export async function deleteGroupById(page, groupId) {
  await page.goto(`/groups/${groupId}/delete`);
  await page.locator(`form[action$="/groups/${groupId}/delete"] button[type="submit"]`).click();
  await page.waitForURL(url => url.pathname.endsWith('/groups'), { timeout: 15000 });
}

/**
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} groupId
 * @param {string} userId
 */
export async function addGroupMember(page, groupId, userId) {
  await page.goto(`/groups/${groupId}/members`);
  await page.locator(`#add-form .available-checkbox[value="${userId}"]`).check();
  await page.locator('#add-btn').click();
  await expect(page.locator(`#remove-form .member-checkbox[value="${userId}"]`),
    `adding user ${userId} to group ${groupId} failed: ${await errorMessages(page)}`).toHaveCount(1);
}

/**
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} groupId
 * @param {string} userId
 */
export async function removeGroupMember(page, groupId, userId) {
  await page.goto(`/groups/${groupId}/members`);
  await page.locator(`#remove-form .member-checkbox[value="${userId}"]`).check();
  await page.locator('#remove-btn').click();
  await page.locator('#confirmRemoveMembersBtn').click();
  await expect(page.locator(`#remove-form .member-checkbox[value="${userId}"]`)).toHaveCount(0);
  await expect(page.locator(`#add-form .available-checkbox[value="${userId}"]`)).toHaveCount(1);
}

/**
 * Give a group ownership of a zone through /zones/{id}/ownership.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} zoneId
 * @param {string} groupId
 */
export async function assignZoneGroup(page, zoneId, groupId) {
  await page.goto(`/zones/${zoneId}/ownership`);
  await page.locator(`input[name="newgroup"][value="${groupId}"]`).check();
  await page.locator('#add-group-btn').click();
  await expect(page.locator(`input[name="delete_group"][value="${groupId}"]`),
    `assigning group ${groupId} to zone ${zoneId} failed: ${await errorMessages(page)}`).toHaveCount(1);
}

/**
 * Remove a user from a zone's owners through /zones/{id}/ownership.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {string} zoneId
 * @param {string} userId
 */
export async function removeZoneOwner(page, zoneId, userId) {
  await page.goto(`/zones/${zoneId}/ownership`);
  await page.locator(`form:has(input[name="delete_owner"][value="${userId}"]) button[type="submit"]`).click();
  await expect(page.locator(`input[name="delete_owner"][value="${userId}"]`)).toHaveCount(0);
}

/**
 * The CSRF token rendered on a page, for posts that tamper with the other fields.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @returns {Promise<string>}
 */
export async function csrfTokenFrom(page, path) {
  await page.goto(path);
  const token = await page.locator('input[name="_token"]').first().inputValue();
  expect(token, `no CSRF token rendered on ${path}`).not.toBe('');
  return token;
}
