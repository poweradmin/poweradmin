/**
 * Review, admin-area and API key writes refused to users without the permission.
 *
 * Each test posts directly as a throwaway user who lacks the permission, reads the
 * stored state, then repeats the same posts from a position that must be accepted.
 * The control proves the posts reach the handler.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { assignZoneOwner, createTempZone, deleteZoneById, storedRecords, uniqueName, uniqueZoneName } from '../../helpers/zones.js';
import { findPermTemplateIdByName } from '../../helpers/templates.js';
import { cleanUp, csrfTokenFrom, withTempUser } from '../../helpers/users.js';

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const form = (page, token) => (path, fields = {}) => page.request.post(path, { form: { _token: token, ...fields } });

test.describe('Change request review', () => {
  test('only a reviewer of the zone can approve a request, not another requester, the requester or a non-owner', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('cr-review', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.30', ttl: 3600 }],
    });
    const wwwTtl = async () => {
      // storedRecords leaves the admin page on the zone editor
      const [www] = (await storedRecords(adminPage, zone.id)).filter(r => r.type === 'A');
      return adminPage.locator(`input[name="record[${www.id}][ttl]"]`).inputValue();
    };
    const status = async id => {
      await adminPage.goto(`/zones/requests/${id}`);
      return (await adminPage.getByTestId('change-request-status').textContent()).trim();
    };
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Change Requester', async (requester, requesterPage) => {
        await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (reviewer, reviewerPage) => {
          await withTempUser(adminPage, browser, baseURL, testInfo, 'Change Requester', async (coOwner, coOwnerPage) => {
            try {
              await assignZoneOwner(adminPage, zone.id, requester.username);
              await assignZoneOwner(adminPage, zone.id, coOwner.username);

              // The requester's editor is in request mode, so saving files a request
              const [www] = (await storedRecords(adminPage, zone.id)).filter(r => r.type === 'A');
              await requesterPage.goto(`/zones/${zone.id}/edit`);
              await requesterPage.locator(`input[name="record[${www.id}][ttl]"]`).fill('7200');
              await requesterPage.getByTestId('request-comment-input').fill('raise the ttl');
              await requesterPage.getByTestId('save-changes-button').click();
              await expect(requesterPage.getByTestId('system-message').filter({ hasText: 'submitted for approval' })).toBeVisible();
              const href = await requesterPage.getByTestId('pending-change-requests-card')
                .locator('a[data-testid^="change-request-link-"]').first().getAttribute('href');
              const requestId = href.match(/\/zones\/requests\/(\d+)/)[1];

              const approveAs = async (page, tokenPath) => form(page, await csrfTokenFrom(page, tokenPath))(
                `/zones/requests/${requestId}`, { action: 'approve', review_comment: 'forged' });

              // A co-owner may view the request but not review it, so only the approve check refuses it
              await approveAs(coOwnerPage, `/zones/${zone.id}/edit`);
              expect(await status(requestId)).toBe('pending');
              // The requester is refused by the same check (allow_self_approval only matters to reviewers)
              await approveAs(requesterPage, `/zones/${zone.id}/edit`);
              // A manager who does not own the zone cannot even view the request
              await approveAs(reviewerPage, `/users/${reviewer.id}/edit`);
              expect(await status(requestId)).toBe('pending');
              expect(await wwwTtl()).toBe('3600');

              // Control: owning the zone makes the same manager its reviewer
              await assignZoneOwner(adminPage, zone.id, reviewer.username);
              await approveAs(reviewerPage, `/users/${reviewer.id}/edit`);
              expect(await status(requestId)).toBe('approved');
              expect(await wwwTtl()).toBe('7200');
            } finally {
              // Remove the zone before the users so a failed assertion leaves nothing behind
              await deleteZoneById(adminPage, zone.id);
            }
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id).catch(() => {});
    }
  });
});

test.describe('Admin-area writes', () => {
  test('a zone manager cannot add, edit or delete supermasters', async ({ adminPage, browser, baseURL }, testInfo) => {
    const label = uniqueName('sm', testInfo);
    const kept = { master_ip: '198.51.100.10', ns_name: `${label}-kept.example.com` };
    const added = { master_ip: '198.51.100.11', ns_name: `${label}-added.example.com` };
    const row = server => adminPage.locator(`tr.supermaster-row[data-ns="${server.ns_name}"]`);
    const listed = async server => {
      await adminPage.goto('/supermasters');
      return row(server).count();
    };
    const adminPost = form(adminPage, await csrfTokenFrom(adminPage, '/supermasters/add'));
    // A non-admin may only name their own account, so the forged posts use it to get past that check
    const writes = (post, account) => ({
      add: () => post('/supermasters/add', { ...added, account, submit: '' }),
      edit: () => post(`/supermasters/edit?master_ip=${kept.master_ip}&ns_name=${kept.ns_name}`, { ...kept, account, submit: '' }),
      remove: server => post('/supermasters/delete', { ...server, confirm: '' }),
    });

    await adminPost('/supermasters/add', { ...kept, account: 'admin', submit: '' });
    try {
      expect(await listed(kept)).toBe(1);
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (user, userPage) => {
        const userWrites = writes(form(userPage, await csrfTokenFrom(userPage, `/users/${user.id}/edit`)), user.username);
        await userWrites.add();
        await userWrites.edit();
        await userWrites.remove(kept);

        expect(await listed(added)).toBe(0);
        expect(await listed(kept)).toBe(1);
        await expect(row(kept)).toHaveAttribute('data-account', 'admin');
      });

      // Control: the same posts from admin take effect
      const adminWrites = writes(adminPost, 'hijacked');
      await adminWrites.add();
      expect(await listed(added)).toBe(1);
      await adminWrites.edit();
      await adminPage.goto('/supermasters');
      await expect(row(kept)).toHaveAttribute('data-account', 'hijacked');
      await adminWrites.remove(kept);
      expect(await listed(kept)).toBe(0);
    } finally {
      await cleanUp(() => adminPost('/supermasters/delete', { ...kept, confirm: '' }), () => adminPost('/supermasters/delete', { ...added, confirm: '' }));
    }
  });

  test('a zone manager cannot add, edit or delete permission templates', async ({ adminPage, browser, baseURL }, testInfo) => {
    const name = uniqueName('pt', testInfo);
    const adminPost = form(adminPage, await csrfTokenFrom(adminPage, '/permissions/templates/add'));
    const template = { templ_descr: '', template_type: 'user', commit: '' };
    const writes = (post, id) => ({
      add: () => post('/permissions/templates/add', { ...template, templ_name: `${name}-added` }),
      edit: () => post(`/permissions/templates/${id}/edit`, { ...template, templ_id: id, templ_name: `${name}-renamed` }),
      remove: () => post(`/permissions/templates/${id}/delete`, { confirm: '' }),
    });

    await adminPost('/permissions/templates/add', { ...template, templ_name: name });
    const id = await findPermTemplateIdByName(adminPage, name);
    expect(id, 'admin must be able to create the template').toBeTruthy();
    try {
      await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (user, userPage) => {
        const userWrites = writes(form(userPage, await csrfTokenFrom(userPage, `/users/${user.id}/edit`)), id);
        await userWrites.add();
        await userWrites.edit();
        await userWrites.remove();

        expect(await findPermTemplateIdByName(adminPage, `${name}-added`)).toBeNull();
        expect(await findPermTemplateIdByName(adminPage, `${name}-renamed`)).toBeNull();
        expect(await findPermTemplateIdByName(adminPage, name)).toBe(id);
      });

      // Control: the same posts from admin take effect
      const adminWrites = writes(adminPost, id);
      await adminWrites.add();
      expect(await findPermTemplateIdByName(adminPage, `${name}-added`)).toBeTruthy();
      await adminWrites.edit();
      expect(await findPermTemplateIdByName(adminPage, `${name}-renamed`)).toBe(id);
      await adminWrites.remove();
      // The lookup matches on a substring, so check the renamed template, not the shorter name
      expect(await findPermTemplateIdByName(adminPage, `${name}-renamed`)).toBeNull();
    } finally {
      const added = await findPermTemplateIdByName(adminPage, `${name}-added`);
      await cleanUp(
        () => adminPost(`/permissions/templates/${id}/delete`, { confirm: '' }),
        () => added ? adminPost(`/permissions/templates/${added}/delete`, { confirm: '' }) : null,
      );
    }
  });
});

test.describe('API key isolation', () => {
  test('one user cannot see, change, disable or delete another user\'s API key', async ({ adminPage, browser, baseURL }, testInfo) => {
    const keyName = uniqueName('key', testInfo);
    await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (owner, ownerPage) => {
      const ownerPost = form(ownerPage, await csrfTokenFrom(ownerPage, '/settings/api-keys/add'));
      await ownerPost('/settings/api-keys/add', { name: keyName, expires_at: '' });
      const keyRow = name => ownerPage.locator('tr', { hasText: name });
      await ownerPage.goto('/settings/api-keys');
      const editHref = await keyRow(keyName).locator('a[href$="/edit"]').first().getAttribute('href');
      const keyId = editHref.match(/\/settings\/api-keys\/(\d+)\/edit/)[1];
      const writes = post => ({
        edit: () => post(`/settings/api-keys/${keyId}/edit`, { name: `${keyName}-renamed`, expires_at: '' }),
        disable: () => post(`/settings/api-keys/${keyId}/toggle`, { disable: '1' }),
        remove: () => post(`/settings/api-keys/${keyId}/delete`),
      });

      await withTempUser(adminPage, browser, baseURL, testInfo, 'Zone Manager', async (other, otherPage) => {
        await otherPage.goto('/settings/api-keys');
        await expect(otherPage.locator('tr', { hasText: keyName })).toHaveCount(0);
        await otherPage.goto(`/settings/api-keys/${keyId}/edit`);
        await expect(otherPage.locator(`input[name="name"][value="${keyName}"]`)).toHaveCount(0);

        const otherWrites = writes(form(otherPage, await csrfTokenFrom(otherPage, '/settings/api-keys/add')));
        await otherWrites.edit();
        await otherWrites.disable();
        await otherWrites.remove();
      });

      await ownerPage.goto('/settings/api-keys');
      await expect(keyRow(keyName)).toHaveCount(1);
      await expect(keyRow(`${keyName}-renamed`)).toHaveCount(0);
      await expect(keyRow(keyName).locator('.badge')).toHaveText(/Active/);

      // Control: the owner's same posts take effect
      const ownerWrites = writes(ownerPost);
      await ownerWrites.disable();
      await ownerPage.goto('/settings/api-keys');
      await expect(keyRow(keyName).locator('.badge')).toHaveText(/Disabled/);
      await ownerWrites.edit();
      await ownerPage.goto('/settings/api-keys');
      await expect(keyRow(`${keyName}-renamed`)).toHaveCount(1);
      await ownerWrites.remove();
      await ownerPage.goto('/settings/api-keys');
      await expect(keyRow(keyName)).toHaveCount(0);
    });
  });
});
