/**
 * Editing stored records of each common type through /zones/{id}/records/{rid}/edit,
 * and the SOA serial bump that an edit or a delete must cause.
 *
 * Every edit re-reads the stored values afterwards, so a refused save that leaves
 * the form or a save that drops a field (priority) cannot pass.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { addRecord, uniqueName } from '../../helpers/zones.js';

test.describe.configure({ mode: 'serial' });

const zone = useFileZone('recedit');

/**
 * Stored name/type/content/prio of the zone's records whose name contains label.
 */
async function storedRecords(page, zoneId, label) {
  await page.goto(`/zones/${zoneId}/edit?search=${encodeURIComponent(label)}`);
  return page.locator('[name^="record["][name$="[name]"]').evaluateAll(nodes => nodes.map(node => {
    const id = node.getAttribute('name').match(/^record\[([^\]]+)\]/)[1];
    const field = suffix => document.querySelector(`[name="record[${id}][${suffix}]"]`)?.value ?? '';
    return { id, name: node.value, type: field('type'), content: field('content'), prio: field('prio') };
  }));
}

async function recordIdOf(page, zoneId, label, type) {
  const record = (await storedRecords(page, zoneId, label)).find(r => r.type === type);
  expect(record, `the ${type} record ${label} must be listed`).toBeTruthy();
  return record.id;
}

/**
 * Submit the record edit form and assert it saved (left the form, no error alert).
 */
async function saveEdit(page, zoneId, recordId, { content, prio }) {
  await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
  // TXT-like types render the content as a textarea
  await page.locator('[name="content"]').fill(content);
  if (prio !== undefined) {
    await page.locator('input[name="prio"]').fill(String(prio));
  }
  await page.locator('button[name="commit"]').click();
  await expect(page, 'the edit form must be accepted').not.toHaveURL(/\/records\/[^/]+\/edit/);
  await expect(page.locator('[data-testid="system-message"].alert-danger')).toHaveCount(0);
}

async function soaSerial(page, zoneId) {
  await page.goto(`/zones/${zoneId}/edit`);
  return Number(await page.locator('input[name="serial"]').inputValue());
}

const EDITS = [
  { type: 'MX', name: l => l, before: { content: 'mail1.example.net', prio: 10 }, after: { content: 'mail2.example.net', prio: 20 } },
  { type: 'CNAME', name: l => l, before: { content: 'target1.example.net' }, after: { content: 'target2.example.net' } },
  { type: 'SRV', name: l => `_sip._tcp.${l}`, before: { content: '5 5060 sip1.example.net', prio: 10 }, after: { content: '7 5061 sip2.example.net', prio: 30 } },
  { type: 'CAA', name: l => l, before: { content: '0 issue "letsencrypt.org"' }, after: { content: '0 issue "pki.goog"' } },
  { type: 'TXT', name: l => l, before: { content: '"v=spf1 -all"' }, after: { content: '"edited text value"' } },
  { type: 'NS', name: l => l, before: { content: 'ns1.example.net' }, after: { content: 'ns2.example.net' } },
];

test.describe('Edit records by type', () => {
  for (const edit of EDITS) {
    test(`edits a ${edit.type} record${edit.after.prio !== undefined ? ' and its priority' : ''}`, async ({ adminPage }, testInfo) => {
      const label = uniqueName(edit.type.toLowerCase(), testInfo);
      await addRecord(adminPage, zone.id, { name: edit.name(label), type: edit.type, ...edit.before });
      const recordId = await recordIdOf(adminPage, zone.id, label, edit.type);

      await saveEdit(adminPage, zone.id, recordId, edit.after);

      const records = (await storedRecords(adminPage, zone.id, label)).filter(r => r.type === edit.type);
      expect(records, `exactly one ${edit.type} record must remain`).toHaveLength(1);
      expect(records[0].content).toBe(edit.after.content);
      if (edit.after.prio !== undefined) {
        expect(records[0].prio).toBe(String(edit.after.prio));
      }
    });
  }
});

test.describe('Edit the SOA record', () => {
  test('saves hostmaster and refresh from the SOA fields', async ({ adminPage, tempZone }) => {
    const soaId = await recordIdOf(adminPage, tempZone.id, tempZone.name, 'SOA');
    const before = await soaSerial(adminPage, tempZone.id);

    await adminPage.goto(`/zones/${tempZone.id}/records/${soaId}/edit`);
    await adminPage.locator('#soaHostmaster').fill('dnsadmin.example.net');
    await adminPage.locator('[data-soa-part="3"]').fill('14400');
    await adminPage.locator('button[name="commit"]').click();
    await expect(adminPage).not.toHaveURL(/\/records\/[^/]+\/edit/);
    await expect(adminPage.locator('[data-testid="system-message"].alert-danger')).toHaveCount(0);

    // Re-resolve: on the API backend the record id encodes the content, which just changed
    const soa = (await storedRecords(adminPage, tempZone.id, tempZone.name)).find(r => r.type === 'SOA');
    const [primary, hostmaster, serial, refresh] = soa.content.split(/\s+/);
    expect(primary).toBe('ns1.example.com');
    expect(hostmaster).toBe('dnsadmin.example.net');
    expect(refresh).toBe('14400');
    expect(Number(serial)).toBeGreaterThan(before);
  });
});

test.describe('SOA serial after record changes', () => {
  test('a record edit and a record delete each bump the serial by one', async ({ adminPage, tempZone }, testInfo) => {
    const label = uniqueName('serial', testInfo);
    await addRecord(adminPage, tempZone.id, { name: label, type: 'A', content: '192.0.2.10' });
    const recordId = await recordIdOf(adminPage, tempZone.id, label, 'A');

    const beforeEdit = await soaSerial(adminPage, tempZone.id);
    await saveEdit(adminPage, tempZone.id, recordId, { content: '192.0.2.11' });
    // An unchanged save also bumps (bump_serial_on_unchanged_save), so prove the edit landed
    const edited = (await storedRecords(adminPage, tempZone.id, label)).filter(r => r.type === 'A');
    expect(edited.map(r => r.content)).toEqual(['192.0.2.11']);
    const afterEdit = await soaSerial(adminPage, tempZone.id);
    expect(afterEdit - beforeEdit).toBe(1);

    const editedId = await recordIdOf(adminPage, tempZone.id, label, 'A');
    await adminPage.goto(`/zones/${tempZone.id}/records/${editedId}/delete`);
    await adminPage.locator('[data-testid="confirm-delete-record"]').click();
    await expect(adminPage).not.toHaveURL(/\/delete/);
    expect(await storedRecords(adminPage, tempZone.id, label)).toHaveLength(0);
    expect(await soaSerial(adminPage, tempZone.id) - afterEdit).toBe(1);
  });
});
