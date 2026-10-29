/**
 * Deleting a zone cancels its open change requests, so a later zone that is given the
 * same id (SQLite reuses the highest rowid) does not inherit them.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { assignZoneOwner, createTempZone, deleteZoneById, recordWrites, storedRecords, uniqueZoneName } from '../../helpers/zones.js';
import { cleanUp, csrfTokenFrom, withTempUser } from '../../helpers/users.js';

test.describe.configure({ timeout: 180_000 });

async function pendingRequestIds(adminPage, zoneId) {
  await adminPage.goto(`/zones/${zoneId}/edit`);
  return adminPage.getByTestId('pending-change-requests-card')
    .locator('a[data-testid^="change-request-link-"]')
    .evaluateAll(links => links.map(a => a.getAttribute('href').match(/\/zones\/requests\/(\d+)/)[1]));
}

async function requestStatus(adminPage, requestId) {
  await adminPage.goto(`/zones/requests/${requestId}`);
  return (await adminPage.getByTestId('change-request-status').textContent()).trim();
}

test('deleting a zone cancels its pending requests, which no later zone inherits', async ({ adminPage, browser, baseURL }, testInfo) => {
  const old = await createTempZone(adminPage, { name: uniqueZoneName('cr-gone', testInfo) });
  let oldDeleted = false;
  let fresh = null;
  try {
    expect(await pendingRequestIds(adminPage, old.id), 'a new zone started with pending requests').toEqual([]);
    await withTempUser(adminPage, browser, baseURL, testInfo, 'Change Requester', async (owner, ownerPage) => {
      await assignZoneOwner(adminPage, old.id, owner.username);
      const token = await csrfTokenFrom(ownerPage, `/zones/${old.id}/edit`);
      await recordWrites(ownerPage, token, old.id).inlineAdd('gone', 'A', '192.0.2.70');
      await ownerPage.request.post(`/zones/${old.id}/delete`, { form: { _token: token, request_comment: 'remove it' } });
    });
    const requestIds = await pendingRequestIds(adminPage, old.id);
    expect(requestIds, 'the owner could not file both requests').toHaveLength(2);

    await deleteZoneById(adminPage, old.id);
    oldDeleted = true;
    for (const id of requestIds) {
      expect(await requestStatus(adminPage, id)).toBe('cancelled');
      await expect(adminPage.getByText('The zone was deleted.')).toBeVisible();
    }

    fresh = await createTempZone(adminPage, {
      name: uniqueZoneName('cr-next', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.71' }],
    });
    expect(await pendingRequestIds(adminPage, fresh.id)).toEqual([]);

    // Approving a cancelled request changes nothing, whichever zone now holds the id
    const token = await csrfTokenFrom(adminPage, `/zones/${fresh.id}/edit`);
    for (const id of requestIds) {
      await adminPage.request.post(`/zones/requests/${id}`, { form: { _token: token, action: 'approve' } });
      expect(await requestStatus(adminPage, id)).toBe('cancelled');
    }
    const aRecords = (await storedRecords(adminPage, fresh.id)).filter(r => r.type === 'A').map(r => r.content);
    expect(aRecords).toEqual(['192.0.2.71']);
  } finally {
    await cleanUp(
      () => oldDeleted || deleteZoneById(adminPage, old.id),
      () => fresh && deleteZoneById(adminPage, fresh.id),
    );
  }
});
