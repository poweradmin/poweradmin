/**
 * Permission changes, checked from the affected user's side.
 *
 * An admin changes a throwaway user's template, group membership or zone
 * ownership, and a second browser context logged in as that user checks the
 * grant takes effect and the revoke takes it away again, within the same session.
 * Also covers direct-id access to an unowned zone, self-escalation through the
 * own-profile form, and bad-token posts against the forms that change access.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { assignZoneOwner, createTempZone, deleteZoneById, uniqueName, uniqueZoneName } from '../../helpers/zones.js';
import {
  addGroupMember,
  assignZoneGroup,
  cleanUp,
  createGroup,
  csrfTokenFrom,
  deleteGroupById,
  removeGroupMember,
  removeZoneOwner,
  setUserTemplate,
  storedUserTemplate,
  withTempUser,
} from '../../helpers/users.js';

const DENIAL_ALERT = '[data-testid="system-message"].alert-danger';
// The editor heading names the zone; a denied page carries only the alert
const zoneHeading = zone => `text=Edit zone "${zone.name}"`;

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 120_000 });

async function expectZoneVisible(page, zone) {
  await page.goto(`/zones/${zone.id}/edit`);
  await expect(page.locator(DENIAL_ALERT)).toHaveCount(0);
  await expect(page.locator(zoneHeading(zone))).toBeVisible();
}

async function expectZoneDenied(page, zone) {
  await page.goto(`/zones/${zone.id}/edit`);
  await expectAccessDenied(page, zoneHeading(zone));
}

test.describe('Permission changes take effect for the affected user', () => {
  test('template change grants and then revokes zone creation', async ({ adminPage, browser, baseURL }, testInfo) => {
    await withTempUser(adminPage, browser, baseURL, testInfo, 'No Access', async (user, userPage) => {
      await userPage.goto('/zones/add/master');
      await expectAccessDenied(userPage, '[data-testid="zone-name-input"]');

      await setUserTemplate(adminPage, user.id, 'Zone Manager');
      await userPage.goto('/zones/add/master');
      await expect(userPage.locator(DENIAL_ALERT)).toHaveCount(0);
      await expect(userPage.locator('[data-testid="zone-name-input"]')).toBeVisible();

      await setUserTemplate(adminPage, user.id, 'No Access');
      await userPage.goto('/zones/add/master');
      await expectAccessDenied(userPage, '[data-testid="zone-name-input"]');
    });
  });

  test('zone ownership grants and then revokes access to the zone', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('own', testInfo) });
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async (user, userPage) => {
        await expectZoneDenied(userPage, zone);

        await assignZoneOwner(adminPage, zone.id, user.username);
        await expectZoneVisible(userPage, zone);

        await removeZoneOwner(adminPage, zone.id, user.id);
        await expectZoneDenied(userPage, zone);
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('group membership grants and then revokes access to a group-owned zone', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('grp', testInfo) });
    const group = await createGroup(adminPage, uniqueName('grp', testInfo), 'Viewers');
    try {
      await assignZoneGroup(adminPage, zone.id, group.id);

      // No Access: whatever the user can see here comes from the group alone
      await withTempUser(adminPage, browser, baseURL, testInfo, 'No Access', async (user, userPage) => {
        await expectZoneDenied(userPage, zone);

        await addGroupMember(adminPage, group.id, user.id);
        await expectZoneVisible(userPage, zone);

        await removeGroupMember(adminPage, group.id, user.id);
        await expectZoneDenied(userPage, zone);
      });
    } finally {
      await cleanUp(() => deleteZoneById(adminPage, zone.id), () => deleteGroupById(adminPage, group.id));
    }
  });
});

test.describe('Direct access to a zone the user does not own', () => {
  test('a zone manager cannot open, delete or add records to another user\'s zone by id', async ({ adminPage, browser, baseURL }, testInfo) => {
    const own = await createTempZone(adminPage, { name: uniqueZoneName('idor-own', testInfo) });
    const other = await createTempZone(adminPage, { name: uniqueZoneName('idor-other', testInfo) });
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (user, userPage) => {
        await assignZoneOwner(adminPage, own.id, user.username);
        // Pins the id to a live zone: a missing zone renders the same alert-only page as a denial
        await expectZoneVisible(adminPage, other);

        await expectZoneDenied(userPage, other);
        await userPage.goto(`/zones/${other.id}/delete`);
        await expectAccessDenied(userPage, '[data-testid="confirm-delete-zone"]');
        await userPage.goto(`/zones/${other.id}/records/add`);
        await expectAccessDenied(userPage, 'select[name="records[0][type]"]');

        const label = uniqueName('idor', testInfo);
        const token = await csrfTokenFrom(userPage, `/zones/${own.id}/records/add`);
        const post = zoneId => userPage.request.post(`/zones/${zoneId}/records/add`, {
          form: { _token: token, name: label, type: 'A', content: '192.0.2.66', ttl: '3600', commit: '' },
        });

        // The same post on the user's own zone is the control: it must add the record
        await post(own.id);
        await adminPage.goto(`/zones/${own.id}/edit?search=${label}`);
        await expect(adminPage.locator(`input[value^="${label}"]`)).not.toHaveCount(0);

        await post(other.id);
        await adminPage.goto(`/zones/${other.id}/edit?search=${label}`);
        await expect(adminPage.locator(`input[value^="${label}"]`)).toHaveCount(0);
      });
    } finally {
      await cleanUp(() => deleteZoneById(adminPage, own.id), () => deleteZoneById(adminPage, other.id));
    }
  });
});

test.describe('Self-escalation through the own profile', () => {
  test('a zone manager cannot give themselves another template or an admin group', async ({ adminPage, browser, baseURL }, testInfo) => {
    await adminPage.goto('/users/add');
    const adminTemplateId = await adminPage.locator('select#perm_templ option', { hasText: /^Administrator$/ }).getAttribute('value');
    await adminPage.goto('/groups');
    const adminGroupHref = await adminPage.locator('tr:has-text("Administrators") a[href$="/members"]').first().getAttribute('href');
    const adminGroupId = adminGroupHref.match(/\/groups\/(\d+)\/members/)[1];

    await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (user, userPage) => {
      const token = await csrfTokenFrom(userPage, `/users/${user.id}/edit`);
      // The own-profile form has no template picker; the field is forged on purpose
      await userPage.request.post(`/users/${user.id}/edit`, {
        form: {
          _token: token, user_id: user.id, username: user.username, fullname: 'Renamed Self',
          email: `${user.username}@example.com`, description: '', perm_templ: adminTemplateId, commit: '',
        },
      });
      const addGroups = await userPage.request.post(`/users/${user.id}/edit`, {
        form: { _token: token, action: 'add_groups', 'add_to_groups[]': adminGroupId },
      });
      // Proves the post reached the group handler rather than failing on a misnamed field
      expect(await addGroups.text()).toContain('You do not have permission to manage group memberships.');

      expect(await storedUserTemplate(adminPage, user.id)).toBe('Zone Manager');
      // The control: the rest of the forged post was accepted, only the template was dropped
      await expect(adminPage.locator('input[name="fullname"]')).toHaveValue('Renamed Self');
      await adminPage.goto(`/groups/${adminGroupId}/members`);
      await expect(adminPage.locator(`#remove-form .member-checkbox[value="${user.id}"]`)).toHaveCount(0);

      await userPage.goto('/users/add');
      await expectAccessDenied(userPage, 'select#perm_templ');
    });
  });
});

test.describe('Access-changing forms reject a bad CSRF token', () => {
  test('template, group membership and zone ownership change only with a valid token', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('csrf', testInfo) });
    const group = await createGroup(adminPage, uniqueName('csrf', testInfo), 'Viewers');
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'No Access', async (user) => {
        await adminPage.goto('/users/add');
        const managerTemplateId = await adminPage.locator('select#perm_templ option', { hasText: /^Zone Manager$/ }).getAttribute('value');

        const postAll = async token => {
          await adminPage.request.post(`/users/${user.id}/edit`, {
            form: {
              _token: token, user_id: user.id, username: user.username, fullname: user.username,
              email: `${user.username}@example.com`, description: '', perm_templ: managerTemplateId, active: '1', commit: '',
            },
          });
          await adminPage.request.post(`/groups/${group.id}/members`, {
            form: { _token: token, action: 'add', 'user_ids[]': user.id },
          });
          await adminPage.request.post(`/zones/${zone.id}/ownership`, {
            form: { _token: token, newowner: user.id },
          });
        };
        const expectState = async (template, members) => {
          expect(await storedUserTemplate(adminPage, user.id)).toBe(template);
          await adminPage.goto(`/groups/${group.id}/members`);
          await expect(adminPage.locator(`#remove-form .member-checkbox[value="${user.id}"]`)).toHaveCount(members);
          await adminPage.goto(`/zones/${zone.id}/ownership`);
          await expect(adminPage.locator(`input[name="delete_owner"][value="${user.id}"]`)).toHaveCount(members);
        };

        await postAll('invalid-token');
        await expectState('No Access', 0);

        // The same posts with the real token are the control: each must take effect
        await postAll(await csrfTokenFrom(adminPage, `/users/${user.id}/edit`));
        await expectState('Zone Manager', 1);

        await removeZoneOwner(adminPage, zone.id, user.id);
      });
    } finally {
      await cleanUp(() => deleteZoneById(adminPage, zone.id), () => deleteGroupById(adminPage, group.id));
    }
  });
});
