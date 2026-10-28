/**
 * Write paths a user without the permission must not reach, checked by what was stored.
 *
 * Each test posts directly, bypassing hidden buttons, as a user who lacks the
 * permission, reads the stored state as admin, then repeats the posts from a
 * position that must be accepted. The control proves the posts reach the handler.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { assignZoneOwner, createTempZone, deleteZoneById, recordWrites, storedRecords, uniqueName, uniqueZoneName } from '../../helpers/zones.js';
import { cleanUp, csrfTokenFrom, setUserTemplate, withTempUser } from '../../helpers/users.js';

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const byType = (records, type) => records.filter(r => r.type === type);

test.describe('Other users\' accounts', () => {
  test('a user without user_edit_others cannot change another user by its edit form or the user list', async ({ adminPage, browser, baseURL }, testInfo) => {
    await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async (victim) => {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (actor, actorPage) => {
        await actorPage.goto('/users');
        await expect(actorPage).not.toHaveURL(/\/users$/);
        await expect(actorPage.locator(`input[value="${victim.username}"]`)).toHaveCount(0);
        await actorPage.goto(`/users/${victim.id}/edit`);
        await expectAccessDenied(actorPage, 'input[name="fullname"]');

        const token = await csrfTokenFrom(actorPage, `/users/${actor.id}/edit`);
        const editPost = (user, extra = {}) => actorPage.request.post(`/users/${user.id}/edit`, {
          form: {
            _token: token, user_id: user.id, username: user.username, fullname: 'Hijacked',
            email: `${user.username}@example.com`, description: '', active: '1', commit: '', ...extra,
          },
        });

        await editPost(victim, { password: 'Hijack3d!Pass' });
        await actorPage.request.post('/users', {
          form: {
            _token: token, commit: '',
            'user[0][uid]': victim.id, 'user[0][username]': victim.username, 'user[0][fullname]': 'Hijacked',
            'user[0][email]': `${victim.username}@example.com`, 'user[0][active]': 'on',
          },
        });

        await adminPage.goto(`/users/${victim.id}/edit`);
        await expect(adminPage.locator('input[name="fullname"]')).toHaveValue(victim.username);
        // The old password still signing in shows the posted one was not stored
        const context = await browser.newContext({ baseURL });
        try {
          await loginAndWaitForDashboard(await context.newPage(), victim.username, victim.password, 1, { fresh: true });
        } finally {
          await context.close();
        }

        // Control: the same post against the actor's own account is accepted
        await editPost(actor);
        await adminPage.goto(`/users/${actor.id}/edit`);
        await expect(adminPage.locator('input[name="fullname"]')).toHaveValue('Hijacked');

        // Control for the bulk list post: the same row shape from admin is stored
        await adminPage.request.post('/users', {
          form: {
            _token: await csrfTokenFrom(adminPage, '/users'), commit: '',
            'user[0][uid]': actor.id, 'user[0][username]': actor.username, 'user[0][fullname]': 'Bulk edited',
            'user[0][email]': `${actor.username}@example.com`, 'user[0][active]': 'on',
          },
        });
        await adminPage.goto(`/users/${actor.id}/edit`);
        await expect(adminPage.locator('input[name="fullname"]')).toHaveValue('Bulk edited');
      });
    });
  });
});

test.describe('Record writes in an owned zone', () => {
  test('a read-only owner cannot add, edit or delete records by any write path', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('ro-write', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.10' }],
    });
    const label = uniqueName('ro', testInfo);
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async (user, userPage) => {
        try {
          await assignZoneOwner(adminPage, zone.id, user.username);
          const writes = recordWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);
          const before = await storedRecords(adminPage, zone.id);
          const [a] = byType(before, 'A');

          await writes.add(label, 'A', '192.0.2.66');
          await writes.inlineAdd(`${label}-inline`, 'A', '192.0.2.67');
          await writes.edit(a, '192.0.2.11');
          await writes.editorSave(a, '192.0.2.12');
          await writes.remove(a);
          await writes.bulkRemove(a);

          const after = await storedRecords(adminPage, zone.id);
          expect(after.map(r => `${r.type} ${r.content}`).sort()).toEqual(before.map(r => `${r.type} ${r.content}`).sort());

          // Control: the same user with edit rights gets every path through
          await setUserTemplate(adminPage, user.id, 'Zone Manager');
          const contents = async () => byType(await storedRecords(adminPage, zone.id), 'A').map(r => r.content).sort();

          await writes.add(label, 'A', '192.0.2.66');
          await writes.inlineAdd(`${label}-inline`, 'A', '192.0.2.67');
          expect(await contents()).toEqual(['192.0.2.10', '192.0.2.66', '192.0.2.67']);

          await writes.edit(a, '192.0.2.11');
          expect(await contents()).toEqual(['192.0.2.11', '192.0.2.66', '192.0.2.67']);

          // API mode re-keys a record when its content changes, so look the ids up again
          const find = async content => byType(await storedRecords(adminPage, zone.id), 'A').find(r => r.content === content);
          await writes.editorSave(await find('192.0.2.11'), '192.0.2.12');
          expect(await contents()).toEqual(['192.0.2.12', '192.0.2.66', '192.0.2.67']);

          await writes.remove(await find('192.0.2.12'));
          await writes.bulkRemove(await find('192.0.2.66'));
          expect(await contents()).toEqual(['192.0.2.67']);
        } finally {
          // Remove the zones before the user so a failed assertion leaves nothing behind
          await deleteZoneById(adminPage, zone.id);
        }
      });
    } finally {
      await deleteZoneById(adminPage, zone.id).catch(() => {});
    }
  });

  test('a DNS Editor owner can change ordinary records but not the apex NS', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('client-ns', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.20' }],
    });
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'DNS Editor', async (user, userPage) => {
        try {
          await assignZoneOwner(adminPage, zone.id, user.username);
          const writes = recordWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);
          const before = await storedRecords(adminPage, zone.id);
          const [ns] = byType(before, 'NS');
          const [a] = byType(before, 'A');

          await writes.add('', 'NS', 'ns3.example.com');
          await writes.inlineAdd('', 'NS', 'ns4.example.com');
          await writes.edit(ns, 'ns2.example.com');
          await writes.editorSave(ns, 'ns5.example.com');
          await writes.remove(ns);
          await writes.bulkRemove(ns);

          expect(byType(await storedRecords(adminPage, zone.id), 'NS').map(r => r.content)).toEqual([ns.content]);

          // Control: an ordinary record in the same zone is the user's to change
          await writes.edit(a, '192.0.2.21');
          expect(byType(await storedRecords(adminPage, zone.id), 'A').map(r => r.content)).toEqual(['192.0.2.21']);

          // Control: the same NS edit from a user who may change NS is stored
          await setUserTemplate(adminPage, user.id, 'Zone Manager');
          await writes.edit(ns, 'ns2.example.com');
          expect(byType(await storedRecords(adminPage, zone.id), 'NS').map(r => r.content)).toEqual(['ns2.example.com']);
        } finally {
          await deleteZoneById(adminPage, zone.id);
        }
      });
    } finally {
      await deleteZoneById(adminPage, zone.id).catch(() => {});
    }
  });
});

test.describe('Zone deletion', () => {
  test('deleting a zone needs zone_delete_own for an owner and zone_delete_others for anyone else', async ({ adminPage, browser, baseURL }, testInfo) => {
    const own = await createTempZone(adminPage, { name: uniqueZoneName('del-own', testInfo) });
    const ownBulk = await createTempZone(adminPage, { name: uniqueZoneName('del-own-bulk', testInfo) });
    const other = await createTempZone(adminPage, { name: uniqueZoneName('del-other', testInfo) });
    const zoneStored = async zone => (await storedRecords(adminPage, zone.id)).length > 0;
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async (user, userPage) => {
        try {
          await assignZoneOwner(adminPage, own.id, user.username);
          await assignZoneOwner(adminPage, ownBulk.id, user.username);
          const token = await csrfTokenFrom(userPage, `/zones/${own.id}/edit`);
          const remove = zone => userPage.request.post(`/zones/${zone.id}/delete`, { form: { _token: token } });
          const bulkRemove = zone => userPage.request.post('/zones/delete', { form: { _token: token, 'zone_id[]': zone.id, confirm: '' } });

          await remove(own);
          await bulkRemove(ownBulk);
          expect(await zoneStored(own)).toBe(true);
          expect(await zoneStored(ownBulk)).toBe(true);

          // Zone Manager may delete its own zones but not another user's
          await setUserTemplate(adminPage, user.id, 'Zone Manager');
          await remove(other);
          await bulkRemove(other);
          expect(await zoneStored(other)).toBe(true);

          // Control: the same posts on the user's own zones now delete them
          await remove(own);
          await bulkRemove(ownBulk);
          expect(await zoneStored(own)).toBe(false);
          expect(await zoneStored(ownBulk)).toBe(false);
        } finally {
          // Remove the zones before the user so a failed assertion leaves nothing behind
          await cleanUp(() => deleteZoneById(adminPage, own.id), () => deleteZoneById(adminPage, ownBulk.id));
        }
      });
    } finally {
      await deleteZoneById(adminPage, other.id).catch(() => {});
    }
  });
});
