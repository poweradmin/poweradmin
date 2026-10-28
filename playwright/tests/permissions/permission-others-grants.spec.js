/**
 * Permissions no seeded role holds, checked from both sides on throwaway templates.
 *
 * Each test builds two templates that differ in the one permission under test, so
 * the refused posts and the accepted ones come from the same user and differ only
 * in that grant. Every outcome is read back from stored state as admin.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { assignZoneOwner, createTempZone, deleteZoneById, recordWrites, storedRecords, uniqueName, uniqueZoneName } from '../../helpers/zones.js';
import { cleanUp, csrfTokenFrom, findUserIdByName, setUserTemplate, withPermTemplate, withTempUser } from '../../helpers/users.js';

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const byType = (records, type) => records.filter(r => r.type === type);
const contentsOf = (records, type) => byType(records, type).map(r => r.content).sort();

const DNS_EDITOR = [
  'zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client',
  'zone_logs_view_own', 'zone_metadata_view_own', 'zone_ownership_view_own',
];

/**
 * Whether the user can sign in with this password, from a fresh browser context.
 */
async function signsIn(browser, baseURL, username, password) {
  const context = await browser.newContext({ baseURL });
  try {
    await loginAndWaitForDashboard(await context.newPage(), username, password, 1, { fresh: true });
    return true;
  } catch {
    return false;
  } finally {
    await context.close();
  }
}

/**
 * The zone meta writes a user can post: add an owner and replace the metadata.
 */
function metaWrites(page, token, zoneId) {
  const post = (path, form) => page.request.post(path, { form: { _token: token, ...form } });
  return {
    addOwner: userId => post(`/zones/${zoneId}/ownership`, { newowner: userId }),
    removeOwner: userId => post(`/zones/${zoneId}/ownership`, { delete_owner: userId }),
    setAxfrFrom: address => post(`/zones/${zoneId}/metadata`, {
      'metadata[0][kind_key]': 'ALLOW-AXFR-FROM', 'metadata[0][content]': address,
    }),
  };
}

async function isOwner(adminPage, zoneId, userId) {
  await adminPage.goto(`/zones/${zoneId}/ownership`);
  return await adminPage.locator(`input[name="delete_owner"][value="${userId}"]`).count() === 1;
}

async function storedAxfrFrom(adminPage, zoneId) {
  await adminPage.goto(`/zones/${zoneId}/metadata`);
  // API-mode zones also carry PowerDNS's own rows, such as SOA-EDIT-API
  return adminPage.locator('#metadata-rows tr').evaluateAll(rows => rows
    .filter(row => row.querySelector('.metadata-kind-select')?.value === 'ALLOW-AXFR-FROM')
    .map(row => row.querySelector('input.metadata-content').value));
}

test.describe('Other users\' zones', () => {
  test('zone_content_edit_others lets a user change records in a zone they do not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('edit-others', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.30' }],
    });
    const label = uniqueName('eo', testInfo);
    try {
      await withPermTemplate(adminPage, testInfo, 'view', ['zone_content_view_others'], async viewOnly => {
        await withPermTemplate(adminPage, testInfo, 'edit', ['zone_content_view_others', 'zone_content_edit_others'], async editor => {
          await withTempUser(adminPage, browser, baseURL, testInfo, viewOnly, async (user, userPage) => {
            const writes = recordWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);
            const [a] = byType(await storedRecords(adminPage, zone.id), 'A');

            await writes.add(label, 'A', '192.0.2.31');
            await writes.edit(a, '192.0.2.32');
            await writes.editorSave(a, '192.0.2.33');
            await writes.remove(a);
            expect(contentsOf(await storedRecords(adminPage, zone.id), 'A')).toEqual(['192.0.2.30']);

            await setUserTemplate(adminPage, user.id, editor);
            await writes.add(label, 'A', '192.0.2.31');
            expect(contentsOf(await storedRecords(adminPage, zone.id), 'A')).toEqual(['192.0.2.30', '192.0.2.31']);
            await writes.edit(a, '192.0.2.32');
            expect(contentsOf(await storedRecords(adminPage, zone.id), 'A')).toEqual(['192.0.2.31', '192.0.2.32']);
            // An API-mode edit re-keys the record, so delete it by its stored id
            const [edited] = byType(await storedRecords(adminPage, zone.id), 'A').filter(r => r.content === '192.0.2.32');
            await writes.remove(edited);
            expect(contentsOf(await storedRecords(adminPage, zone.id), 'A')).toEqual(['192.0.2.31']);
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('zone_delete_others lets a user delete a zone they do not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const single = await createTempZone(adminPage, { name: uniqueZoneName('del-others', testInfo) });
    const bulk = await createTempZone(adminPage, { name: uniqueZoneName('del-others-bulk', testInfo) });
    const zoneStored = async zone => (await storedRecords(adminPage, zone.id)).length > 0;
    try {
      await withPermTemplate(adminPage, testInfo, 'view', ['zone_content_view_others'], async viewOnly => {
        await withPermTemplate(adminPage, testInfo, 'delete', ['zone_content_view_others', 'zone_delete_others'], async deleter => {
          await withTempUser(adminPage, browser, baseURL, testInfo, viewOnly, async (user, userPage) => {
            const token = await csrfTokenFrom(userPage, `/zones/${single.id}/edit`);
            const remove = zone => userPage.request.post(`/zones/${zone.id}/delete`, { form: { _token: token } });
            const bulkRemove = zone => userPage.request.post('/zones/delete', { form: { _token: token, 'zone_id[]': zone.id, confirm: '' } });

            await remove(single);
            await bulkRemove(bulk);
            expect(await zoneStored(single)).toBe(true);
            expect(await zoneStored(bulk)).toBe(true);

            await setUserTemplate(adminPage, user.id, deleter);
            await remove(single);
            await bulkRemove(bulk);
            expect(await zoneStored(single)).toBe(false);
            expect(await zoneStored(bulk)).toBe(false);
          });
        });
      });
    } finally {
      // A passing run already deleted both, and deleting a missing zone is an error
      await cleanUp(
        async () => await zoneStored(single) && deleteZoneById(adminPage, single.id),
        async () => await zoneStored(bulk) && deleteZoneById(adminPage, bulk.id),
      );
    }
  });

  test('zone_meta_edit_others lets a user change the owners and metadata of a zone they do not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('meta-others', testInfo) });
    const viewer = ['zone_content_view_others', 'zone_ownership_view_others', 'zone_metadata_view_others'];
    try {
      await withPermTemplate(adminPage, testInfo, 'view', viewer, async viewOnly => {
        await withPermTemplate(adminPage, testInfo, 'meta', [...viewer, 'zone_meta_edit_others'], async metaEditor => {
          await withTempUser(adminPage, browser, baseURL, testInfo, viewOnly, async (user, userPage) => {
            const writes = metaWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);

            await writes.addOwner(user.id);
            await writes.setAxfrFrom('192.0.2.53');
            expect(await isOwner(adminPage, zone.id, user.id)).toBe(false);
            expect(await storedAxfrFrom(adminPage, zone.id)).toEqual([]);

            await setUserTemplate(adminPage, user.id, metaEditor);
            await writes.addOwner(user.id);
            await writes.setAxfrFrom('192.0.2.53');
            expect(await isOwner(adminPage, zone.id, user.id)).toBe(true);
            expect(await storedAxfrFrom(adminPage, zone.id)).toEqual(['192.0.2.53']);
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('zone_meta_edit_own lets an owner change the owners and metadata of their zone', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('meta-own', testInfo) });
    const adminId = await findUserIdByName(adminPage, 'admin');
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async (user, userPage) => {
        await assignZoneOwner(adminPage, zone.id, user.username);
        const writes = metaWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);

        await writes.removeOwner(adminId);
        await writes.setAxfrFrom('192.0.2.54');
        expect(await isOwner(adminPage, zone.id, adminId)).toBe(true);
        expect(await storedAxfrFrom(adminPage, zone.id)).toEqual([]);

        // Control: Zone Manager adds zone_meta_edit_own to the same view rights
        await setUserTemplate(adminPage, user.id, 'Zone Manager');
        await writes.removeOwner(adminId);
        await writes.setAxfrFrom('192.0.2.54');
        expect(await isOwner(adminPage, zone.id, adminId)).toBe(false);
        expect(await storedAxfrFrom(adminPage, zone.id)).toEqual(['192.0.2.54']);
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });
});

test.describe('Other users\' accounts', () => {
  test('user_view_others lets a user list other users but not edit them', async ({ adminPage, browser, baseURL }, testInfo) => {
    await withPermTemplate(adminPage, testInfo, 'none', ['search'], async plain => {
      await withPermTemplate(adminPage, testInfo, 'view', ['user_view_others'], async viewer => {
        await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async victim => {
          await withTempUser(adminPage, browser, baseURL, testInfo, plain, async (user, userPage) => {
            // The full name defaults to the username, so match the row, not the cell
            const victimRow = userPage.locator(`tr:has(td:text-is("${victim.username}")), tr:has(input[value="${victim.username}"])`);

            await userPage.goto('/users');
            await expect(userPage).not.toHaveURL(/\/users$/);
            await expect(victimRow).toHaveCount(0);

            await setUserTemplate(adminPage, user.id, viewer);
            await userPage.goto(`/users?search=${encodeURIComponent(victim.username)}`);
            await expect(victimRow).toHaveCount(1);
            await userPage.goto(`/users/${victim.id}/edit`);
            await expectAccessDenied(userPage, 'input[name="fullname"]');
          });
        });
      });
    });
  });

  test('user_passwd_edit_others decides whether an edit of another user also sets the password', async ({ adminPage, browser, baseURL }, testInfo) => {
    const base = ['user_view_others'];
    await withPermTemplate(adminPage, testInfo, 'passwd', [...base, 'user_passwd_edit_others'], async passwdOnly => {
      await withPermTemplate(adminPage, testInfo, 'edit', [...base, 'user_edit_others'], async editor => {
        await withPermTemplate(adminPage, testInfo, 'full', [...base, 'user_edit_others', 'user_passwd_edit_others'], async fullEditor => {
          await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async victim => {
            await withTempUser(adminPage, browser, baseURL, testInfo, passwdOnly, async (user, userPage) => {
              const token = await csrfTokenFrom(userPage, '/users');
              const edit = (fullname, password) => userPage.request.post(`/users/${victim.id}/edit`, {
                form: {
                  _token: token, user_id: victim.id, username: victim.username, fullname, password,
                  email: `${victim.username}@example.com`, description: '', active: '1', commit: '',
                },
              });
              const storedFullname = async () => {
                await adminPage.goto(`/users/${victim.id}/edit`);
                return adminPage.locator('input[name="fullname"]').inputValue();
              };

              // The password grant alone does not open another user's edit form
              await edit('Passwd only', 'Passwd0nly!Pass');
              expect(await storedFullname()).toBe(victim.username);
              expect(await signsIn(browser, baseURL, victim.username, victim.password)).toBe(true);

              // Editing others without the password grant saves the rest and drops the password
              await setUserTemplate(adminPage, user.id, editor);
              await edit('Edited', 'Ed1ted!Password');
              expect(await storedFullname()).toBe('Edited');
              expect(await signsIn(browser, baseURL, victim.username, victim.password)).toBe(true);

              await setUserTemplate(adminPage, user.id, fullEditor);
              await edit('Edited again', 'Ch4nged!Password');
              expect(await storedFullname()).toBe('Edited again');
              expect(await signsIn(browser, baseURL, victim.username, 'Ch4nged!Password')).toBe(true);
            });
          });
        });
      });
    });
  });
});

test.describe('NS records below the apex', () => {
  test('zone_content_edit_ns_subzone lets a limited editor delegate a subzone but not touch the apex NS', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('ns-sub', testInfo),
      records: [{ name: 'stored', type: 'NS', content: 'ns1.stored.example.net' }],
    });
    // The editor shows names relative to the zone when display_hostname_only is on
    const fqdn = name => (name === '' || name === '@' ? zone.name : name.endsWith(zone.name) ? name : `${name}.${zone.name}`);
    const ns = records => byType(records, 'NS').map(r => ({ ...r, name: fqdn(r.name) }));
    const apexNs = records => ns(records).filter(r => r.name === zone.name);
    const subNs = records => ns(records).filter(r => r.name !== zone.name).map(r => `${r.name} ${r.content}`).sort();
    try {
      await withPermTemplate(adminPage, testInfo, 'client', DNS_EDITOR, async client => {
        await withPermTemplate(adminPage, testInfo, 'delegate', [...DNS_EDITOR, 'zone_content_edit_ns_subzone'], async delegator => {
          await withTempUser(adminPage, browser, baseURL, testInfo, client, async (user, userPage) => {
            await assignZoneOwner(adminPage, zone.id, user.username);
            const writes = recordWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);
            const before = await storedRecords(adminPage, zone.id);
            const [stored] = ns(before).filter(r => r.name !== zone.name);
            const [apex] = apexNs(before);

            await writes.add('deleg', 'NS', 'ns1.deleg.example.net');
            await writes.edit(stored, 'ns2.stored.example.net');
            await writes.remove(stored);
            expect(subNs(await storedRecords(adminPage, zone.id))).toEqual([`stored.${zone.name} ns1.stored.example.net`]);

            await setUserTemplate(adminPage, user.id, delegator);
            await writes.add('deleg', 'NS', 'ns1.deleg.example.net');
            await writes.edit(stored, 'ns2.stored.example.net');
            expect(subNs(await storedRecords(adminPage, zone.id))).toEqual([
              `deleg.${zone.name} ns1.deleg.example.net`,
              `stored.${zone.name} ns2.stored.example.net`,
            ]);

            // The apex NS stays out of reach, and so does turning a delegation into one
            const [delegated] = ns(await storedRecords(adminPage, zone.id)).filter(r => r.name === `deleg.${zone.name}`);
            await writes.add('', 'NS', 'ns9.example.com');
            await writes.edit(apex, 'ns8.example.com');
            await writes.remove(apex);
            await writes.edit({ ...delegated, name: zone.name }, 'ns7.example.com');
            const after = await storedRecords(adminPage, zone.id);
            expect(apexNs(after).map(r => r.content)).toEqual([apex.content]);
            expect(subNs(after)).toContain(`deleg.${zone.name} ns1.deleg.example.net`);

            await writes.remove(delegated);
            expect(subNs(await storedRecords(adminPage, zone.id))).toEqual([`stored.${zone.name} ns2.stored.example.net`]);
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });
});
