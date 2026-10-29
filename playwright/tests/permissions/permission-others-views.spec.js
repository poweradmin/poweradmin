/**
 * The view and change request permissions on zones the user does not own, checked
 * from both sides on throwaway templates.
 *
 * Each test switches one user between two templates that differ in the permission
 * under test, so the refused and the allowed outcome differ only in that grant.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { assignZoneOwner, createTempZone, deleteZoneById, recordWrites, storedRecords, uniqueZoneName } from '../../helpers/zones.js';
import { cleanUp, csrfTokenFrom, setUserTemplate, withPermTemplate, withTempUser } from '../../helpers/users.js';

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const aContents = async (adminPage, zoneId) =>
  (await storedRecords(adminPage, zoneId)).filter(r => r.type === 'A').map(r => r.content).sort();

/**
 * Ids of the zone's pending change requests, read from the admin's zone editor.
 */
async function pendingRequestIds(adminPage, zoneId) {
  await adminPage.goto(`/zones/${zoneId}/edit`);
  const hrefs = await adminPage.getByTestId('pending-change-requests-card')
    .locator('a[data-testid^="change-request-link-"]').evaluateAll(links => links.map(a => a.getAttribute('href')));
  return hrefs.map(href => href.match(/\/zones\/requests\/(\d+)/)[1]);
}

/**
 * Reject the zone's pending requests as admin. Deleting a zone leaves its requests behind,
 * and SQLite hands the freed zone id to the next zone, which would inherit them.
 */
async function rejectPending(adminPage, zoneId) {
  const token = await csrfTokenFrom(adminPage, `/zones/${zoneId}/edit`);
  for (const id of await pendingRequestIds(adminPage, zoneId)) {
    await adminPage.request.post(`/zones/requests/${id}`, { form: { _token: token, action: 'reject' } });
  }
  expect(await pendingRequestIds(adminPage, zoneId), 'pending requests survived the cleanup').toEqual([]);
}

async function requestStatus(adminPage, requestId) {
  await adminPage.goto(`/zones/requests/${requestId}`);
  return (await adminPage.getByTestId('change-request-status').textContent()).trim();
}

test.describe('Viewing other users\' zones', () => {
  test('zone_content_view_others opens the records of a zone the user does not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('view-others', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.40' }],
    });
    const record = 'input[value="192.0.2.40"]';
    try {
      await withPermTemplate(adminPage, testInfo, 'none', ['search'], async plain => {
        await withPermTemplate(adminPage, testInfo, 'view', ['search', 'zone_content_view_others'], async viewer => {
          await withTempUser(adminPage, browser, baseURL, testInfo, plain, async (user, userPage) => {
            await userPage.goto(`/zones/${zone.id}/edit`);
            await expectAccessDenied(userPage, record);

            await setUserTemplate(adminPage, user.id, viewer);
            await userPage.goto(`/zones/${zone.id}/edit`);
            await expect(userPage.locator(record)).toHaveCount(1);
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('zone_metadata_view_others shows the metadata of a zone the user does not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('meta-view', testInfo) });
    const axfrFrom = 'input.metadata-content[value="192.0.2.41"]';
    try {
      await adminPage.request.post(`/zones/${zone.id}/metadata`, {
        form: {
          _token: await csrfTokenFrom(adminPage, `/zones/${zone.id}/metadata`),
          'metadata[0][kind_key]': 'ALLOW-AXFR-FROM', 'metadata[0][content]': '192.0.2.41',
        },
      });
      await adminPage.goto(`/zones/${zone.id}/metadata`);
      await expect(adminPage.locator(axfrFrom), 'admin could not set the metadata').toHaveCount(1);

      const base = ['zone_content_view_others'];
      await withPermTemplate(adminPage, testInfo, 'content', base, async contentOnly => {
        await withPermTemplate(adminPage, testInfo, 'meta', [...base, 'zone_metadata_view_others'], async metaViewer => {
          await withTempUser(adminPage, browser, baseURL, testInfo, contentOnly, async (user, userPage) => {
            await userPage.goto(`/zones/${zone.id}/metadata`);
            await expectAccessDenied(userPage, axfrFrom);

            await setUserTemplate(adminPage, user.id, metaViewer);
            await userPage.goto(`/zones/${zone.id}/metadata`);
            await expect(userPage.locator(axfrFrom)).toHaveCount(1);
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('zone_ownership_view_others lists the owners of a zone the user does not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, { name: uniqueZoneName('owner-view', testInfo) });
    try {
      const base = ['zone_content_view_others'];
      await withPermTemplate(adminPage, testInfo, 'content', base, async contentOnly => {
        await withPermTemplate(adminPage, testInfo, 'owners', [...base, 'zone_ownership_view_others'], async ownerViewer => {
          await withTempUser(adminPage, browser, baseURL, testInfo, 'Read Only', async owner => {
            await assignZoneOwner(adminPage, zone.id, owner.username);
            await withTempUser(adminPage, browser, baseURL, testInfo, contentOnly, async (user, userPage) => {
              const ownerEntry = userPage.getByText(owner.username, { exact: false });

              await userPage.goto(`/zones/${zone.id}/ownership`);
              await expectAccessDenied(userPage);
              await expect(ownerEntry).toHaveCount(0);

              await setUserTemplate(adminPage, user.id, ownerViewer);
              await userPage.goto(`/zones/${zone.id}/ownership`);
              await expect(ownerEntry.first()).toBeVisible();
              // Viewing the owners does not offer to change them
              await expect(userPage.locator('input[name="delete_owner"], input[name="newowner"]')).toHaveCount(0);
            });
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });

  test('zone_logs_view_others shows the log entries of a zone the user does not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('log-view', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.42' }],
    });
    const entries = page => page.locator('td.log-event-cell');
    const openLogs = page => page.goto(`/zones/logs?name=${encodeURIComponent(zone.name)}`);
    try {
      await openLogs(adminPage);
      await expect(entries(adminPage).first(), 'creating the zone logged nothing').toBeVisible();

      await withPermTemplate(adminPage, testInfo, 'own', ['zone_logs_view_own'], async ownLogs => {
        await withPermTemplate(adminPage, testInfo, 'others', ['zone_logs_view_own', 'zone_logs_view_others'], async allLogs => {
          await withTempUser(adminPage, browser, baseURL, testInfo, ownLogs, async (user, userPage) => {
            // The own level opens the page but leaves out zones the user does not own
            await openLogs(userPage);
            await expect(userPage.locator('[data-testid="system-message"].alert-danger')).toHaveCount(0);
            await expect(userPage.locator('input[name="name"]')).toBeVisible();
            await expect(entries(userPage)).toHaveCount(0);

            await setUserTemplate(adminPage, user.id, allLogs);
            await openLogs(userPage);
            await expect(entries(userPage).first()).toBeVisible();
          });
        });
      });
    } finally {
      await deleteZoneById(adminPage, zone.id);
    }
  });
});

test.describe('Change requests on other users\' zones', () => {
  test('zone_change_request_others lets a user file a request for a zone they do not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('cr-others', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.50' }],
    });
    try {
      const base = ['zone_content_view_others'];
      await withPermTemplate(adminPage, testInfo, 'view', base, async viewOnly => {
        await withPermTemplate(adminPage, testInfo, 'request', [...base, 'zone_change_request_others'], async requester => {
          await withTempUser(adminPage, browser, baseURL, testInfo, viewOnly, async (user, userPage) => {
            const writes = recordWrites(userPage, await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`), zone.id);
            const [a] = (await storedRecords(adminPage, zone.id)).filter(r => r.type === 'A');

            await writes.editorSave(a, '192.0.2.51');
            expect(await pendingRequestIds(adminPage, zone.id)).toEqual([]);
            expect(await aContents(adminPage, zone.id)).toEqual(['192.0.2.50']);

            // The same save files a request instead of changing the record
            await setUserTemplate(adminPage, user.id, requester);
            await writes.editorSave(a, '192.0.2.51');
            const pending = await pendingRequestIds(adminPage, zone.id);
            expect(pending).toHaveLength(1);
            expect(await aContents(adminPage, zone.id)).toEqual(['192.0.2.50']);
          });
        });
      });
    } finally {
      await cleanUp(() => rejectPending(adminPage, zone.id), () => deleteZoneById(adminPage, zone.id));
    }
  });

  test('zone_change_approve_others lets an editor of others\' zones approve a request for a zone they do not own', async ({ adminPage, browser, baseURL }, testInfo) => {
    const zone = await createTempZone(adminPage, {
      name: uniqueZoneName('cr-approve', testInfo),
      records: [{ name: 'www', type: 'A', content: '192.0.2.60' }],
    });
    try {
      // Reviewing also needs the edit right, so both templates carry it
      const base = ['zone_content_view_others', 'zone_content_edit_others'];
      await withPermTemplate(adminPage, testInfo, 'edit', base, async editor => {
        await withPermTemplate(adminPage, testInfo, 'approve', [...base, 'zone_change_approve_others'], async approver => {
          await withTempUser(adminPage, browser, baseURL, testInfo, 'Change Requester', async (owner, ownerPage) => {
            await assignZoneOwner(adminPage, zone.id, owner.username);
            const [a] = (await storedRecords(adminPage, zone.id)).filter(r => r.type === 'A');
            await recordWrites(ownerPage, await csrfTokenFrom(ownerPage, `/zones/${zone.id}/edit`), zone.id).editorSave(a, '192.0.2.61');
            const [requestId] = await pendingRequestIds(adminPage, zone.id);
            expect(requestId, 'the owner could not file a request').toBeTruthy();

            await withTempUser(adminPage, browser, baseURL, testInfo, editor, async (user, userPage) => {
              const token = await csrfTokenFrom(userPage, `/zones/${zone.id}/edit`);
              const approve = () => userPage.request.post(`/zones/requests/${requestId}`, {
                form: { _token: token, action: 'approve', review_comment: 'approved' },
              });

              await approve();
              expect(await requestStatus(adminPage, requestId)).toBe('pending');
              expect(await aContents(adminPage, zone.id)).toEqual(['192.0.2.60']);

              await setUserTemplate(adminPage, user.id, approver);
              await approve();
              expect(await requestStatus(adminPage, requestId)).toBe('approved');
              expect(await aContents(adminPage, zone.id)).toEqual(['192.0.2.61']);
            });
          });
        });
      });
    } finally {
      await cleanUp(() => rejectPending(adminPage, zone.id), () => deleteZoneById(adminPage, zone.id));
    }
  });
});
