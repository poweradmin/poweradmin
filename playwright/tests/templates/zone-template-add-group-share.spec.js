/**
 * Sharing a zone template with groups from the add form.
 *
 * The creator becomes the owner, so the owner's rules apply: only groups they belong
 * to are offered and accepted, and only with zone_templ_edit. Every outcome is read
 * back from what another group member and the admin see.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { uniqueName } from '../../helpers/zones.js';
import { deleteTemplate } from '../../helpers/templates.js';
import {
  addGroupMember, cleanUp, createGroup, csrfTokenFrom, deleteGroupById, withPermTemplate, withTempUser,
} from '../../helpers/users.js';

// Teardown runs inside the test body, so the budget covers it too
test.describe.configure({ mode: 'serial', timeout: 240_000 });

const OWNER = ['zone_templ_add', 'zone_templ_edit'];

async function templateIdsNamed(page, name) {
  await page.goto('/zones/templates');
  const hrefs = await page.locator(`tr:has(td:text-is("${name}")) a[href$="/edit"]`).evaluateAll(links => links.map(a => a.getAttribute('href')));
  return hrefs.map(href => href.match(/\/zones\/templates\/(\d+)\/edit/)?.[1]).filter(Boolean);
}

async function sharedGroupIds(adminPage, templateId) {
  await adminPage.goto(`/zones/templates/${templateId}/edit`);
  return adminPage.locator('#templ-groups input[name="templ_groups[]"]:checked').evaluateAll(boxes => boxes.map(b => b.value).sort());
}

test.describe('Sharing a new zone template with groups', () => {
  test('the add form shares with the creator\'s own groups and refuses any other', async ({ adminPage, browser, baseURL }, testInfo) => {
    const mine = await createGroup(adminPage, uniqueName('own', testInfo), 'Viewers');
    const other = await createGroup(adminPage, uniqueName('other', testInfo), 'Viewers');
    const shared = uniqueName('shared', testInfo);
    const forged = uniqueName('forged', testInfo);
    const created = [];
    try {
      await withPermTemplate(adminPage, testInfo, 'owner', OWNER, async ownerTemplate => {
        await withPermTemplate(adminPage, testInfo, 'member', ['zone_templ_add'], async memberTemplate => {
          await withTempUser(adminPage, browser, baseURL, testInfo, memberTemplate, async (member, memberPage) => {
            await addGroupMember(adminPage, mine.id, member.id);
            await withTempUser(adminPage, browser, baseURL, testInfo, ownerTemplate, async (owner, ownerPage) => {
              await addGroupMember(adminPage, mine.id, owner.id);

              await ownerPage.goto('/zones/templates/add');
              const offered = ownerPage.locator('#templ-groups input[name="templ_groups[]"]');
              await expect(offered.and(ownerPage.locator(`[value="${mine.id}"]`))).toHaveCount(1);
              await expect(offered.and(ownerPage.locator(`[value="${other.id}"]`))).toHaveCount(0);

              await memberPage.goto('/zones/templates');
              await expect(memberPage.locator(`td:text-is("${shared}")`)).toHaveCount(0);

              await ownerPage.locator('input#templ_name').fill(shared);
              await offered.and(ownerPage.locator(`[value="${mine.id}"]`)).check();
              await ownerPage.locator('button[name="commit"]').click();
              await ownerPage.waitForURL(url => url.pathname.endsWith('/zones/templates'));

              const [sharedId] = await templateIdsNamed(adminPage, shared);
              expect(sharedId, 'the shared template was not created').toBeTruthy();
              created.push(sharedId);
              expect(await sharedGroupIds(adminPage, sharedId)).toEqual([mine.id]);
              await memberPage.goto('/zones/templates');
              await expect(memberPage.locator(`td:text-is("${shared}")`)).toHaveCount(1);

              const token = await csrfTokenFrom(ownerPage, '/zones/templates/add');
              await ownerPage.request.post('/zones/templates/add', {
                form: { _token: token, templ_name: forged, templ_descr: '', 'templ_groups[]': other.id },
              });
              created.push(...await templateIdsNamed(adminPage, forged));
              expect(created, 'a template shared with a group the owner is not in was created').toEqual([sharedId]);
            });
          });

          // Without zone_templ_edit the creator cannot manage shares, so the picker is not shown
          await withTempUser(adminPage, browser, baseURL, testInfo, memberTemplate, async (adder, adderPage) => {
            await addGroupMember(adminPage, mine.id, adder.id);
            await adderPage.goto('/zones/templates/add');
            await expect(adderPage.locator('input#templ_name')).toBeVisible();
            await expect(adderPage.locator('#templ-groups')).toHaveCount(0);

            const token = await csrfTokenFrom(adderPage, '/zones/templates/add');
            await adderPage.request.post('/zones/templates/add', {
              form: { _token: token, templ_name: forged, templ_descr: '', 'templ_groups[]': mine.id },
            });
            const leaked = await templateIdsNamed(adminPage, forged);
            created.push(...leaked);
            expect(leaked, 'a creator without zone_templ_edit shared a template').toEqual([]);
          });
        });
      });
    } finally {
      await cleanUp(
        ...created.map(id => () => deleteTemplate(adminPage, id)),
        () => deleteGroupById(adminPage, mine.id),
        () => deleteGroupById(adminPage, other.id),
      );
    }
  });

  test('ticking Global template takes the group picker out of the form', async ({ adminPage }) => {
    await adminPage.goto('/zones/templates/add');
    const picker = adminPage.locator('#templ-groups');
    const boxes = picker.locator('input[name="templ_groups[]"]');
    await expect(picker).toBeVisible();
    expect(await boxes.count(), 'the admin is offered no groups to share with').toBeGreaterThan(0);

    await adminPage.locator('#templ_global').check();
    await expect(picker).toBeHidden();
    expect(await boxes.evaluateAll(list => list.every(box => box.disabled))).toBe(true);

    await adminPage.locator('#templ_global').uncheck();
    await expect(picker).toBeVisible();
    expect(await boxes.evaluateAll(list => list.some(box => box.disabled))).toBe(false);
  });
});
