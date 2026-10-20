import { test, expect, users, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { assignZoneOwner, uniqueName } from '../../helpers/zones.js';
import { ensureZoneSigned, listDnssecKeyIds, addDnssecKey } from '../../helpers/dnssec.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// The key tests run on this file's throwaway zone, signed in beforeAll; it is
// deleted with its keys after the last test.
const zone = useFileZone('dnsmgmt');
let zoneId = null;

test.beforeAll(async ({ browser }) => {
  zoneId = zone.id;
  const page = await browser.newPage();
  try {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await ensureZoneSigned(page, zoneId);
  } finally {
    await page.close();
  }
});

async function firstKeyId(page) {
  const keyIds = await listDnssecKeyIds(page, zoneId);
  expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);
  return keyIds[0];
}

const noDangerAlert = page => expect(page.locator('[data-testid="system-message"].alert-danger')).toHaveCount(0);

test.describe('DNSSEC Management', () => {
  test('should load the DNSSEC page of the zone', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/dnssec`);

    await expect(page).toHaveURL(new RegExp(`/zones/${zoneId}/dnssec$`));
    await noDangerAlert(page);
    await expect(page.locator('.card-header').first()).toContainText(zone.name);
  });

  test('should show DNSSEC status and the key table', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/dnssec`);

    await expect(page.locator('body')).toContainText(/DNSSEC/i);
    await expect(page.locator('table a[href*="/dnssec/keys/"][href*="/delete"]').first()).toBeVisible();
  });

  test('should load the key addition page with its form', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

    await expect(page).toHaveURL(/.*\/zones\/\d+\/dnssec\/keys\/add/);
    await noDangerAlert(page);
    await expect(page.locator('form select[name="key_type"]')).toBeVisible();
  });

  test('should show the key form fields', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

    await expect(page.locator('select[name="key_type"]')).toBeVisible();
    await expect(page.locator('select[name*="algo"]').first()).toBeVisible();
    await expect(page.locator('select[name*="bits"], select[name*="size"]').first()).toBeVisible();
  });

  test('should give admin access to the DNSSEC page', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/dnssec`);

    await noDangerAlert(page);
    await expect(page.locator('body')).not.toContainText(/you do not have|access denied|not authorized/i);
    await expect(page.locator('a[href*="/dnssec/keys/add"]').first()).toBeVisible();
  });

  test('should list the keys of the signed zone', async ({ adminPage: page }) => {
    const keyIds = await listDnssecKeyIds(page, zoneId);
    expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

    await expect(page.locator('table').first()).toBeVisible();
    await expect(page.locator('table a[href*="/dnssec/keys/"][href$="/edit"]')).toHaveCount(keyIds.length);
  });

  test('should link to DNSSEC from the zone edit page', async ({ adminPage: page }) => {
    await page.goto(`/zones/${zoneId}/edit`);

    await expect(page.locator(`a[href$="/zones/${zoneId}/dnssec"]`).first()).toBeVisible();
  });
});

test.describe('Add DNSSEC Key', () => {
  test.describe('Admin User', () => {
    test('should display add key page', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      await expect(page).toHaveURL(/.*\/dnssec\/keys\/add/);
      await noDangerAlert(page);
    });

    test('should display add key form', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const form = page.locator('form').first();
      await expect(form.first()).toBeVisible();
    });

    test('should display key type select', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const keyTypeSelect = page.locator('select[name="key_type"]');
      await expect(keyTypeSelect.first()).toBeVisible();
    });

    test('should display bits select', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const bitsSelect = page.locator('select[name*="bits"], select[name*="size"]').first();
      await expect(bitsSelect.first()).toBeVisible();
    });

    test('should display algorithm select', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const algoSelect = page.locator('select[name*="algo"], select[name*="algorithm"]').first();
      await expect(algoSelect.first()).toBeVisible();
    });

    test('should display submit button', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const submitBtn = page.locator('input[type="submit"], button[type="submit"]').first();
      await expect(submitBtn.first()).toBeVisible();
    });

    test('should allow selecting key type', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const keyTypeSelect = page.locator('select[name="key_type"]');
      await expect(keyTypeSelect.first()).toBeVisible();
      const options = await keyTypeSelect.locator('option').count();
      expect(options).toBeGreaterThan(0);
    });
  });
});

test.describe('Edit DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test('should display edit key page if key exists', async ({ adminPage: page }) => {
      const keyId = await firstKeyId(page);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/edit`);
      await expect(page).toHaveURL(new RegExp(`/dnssec/keys/${keyId}/edit$`));
      await noDangerAlert(page);
      await expect(page.locator('form[action$="/toggle"]')).toBeVisible();
    });

    test('should display key information if page loads', async ({ adminPage: page }) => {
      const keyId = await firstKeyId(page);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/edit`);
      await noDangerAlert(page);
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('body')).toContainText(new RegExp(`\\b${keyId}\\b`));
    });

    test('should display confirmation buttons if page loads', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyIds[0]}/edit`);
      await expect(page.locator('button[type="submit"], input[type="submit"]').first()).toBeVisible();
      await expect(page.locator('a:has-text("Cancel"), button:has-text("Cancel")').first()).toBeVisible();
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have edit key link if keys exist', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await expect(page.locator('a[href*="/keys/"][href*="/edit"]').first()).toBeVisible();
    });
  });
});

test.describe('Delete DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test('should display delete key page if key exists', async ({ adminPage: page }) => {
      const keyId = await firstKeyId(page);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/delete`);
      await expect(page).toHaveURL(new RegExp(`/dnssec/keys/${keyId}/delete$`));
      await noDangerAlert(page);
      await expect(page.locator('form[action*="/delete"] button[type="submit"]')).toBeVisible();
    });

    test('should display key information on delete page', async ({ adminPage: page }) => {
      const keyId = await firstKeyId(page);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/delete`);
      await noDangerAlert(page);
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('body')).toContainText(new RegExp(`\\b${keyId}\\b`));
    });

    test('should display confirmation message', async ({ adminPage: page }) => {
      const keyId = await firstKeyId(page);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/delete`);
      await expect(page.locator('main')).toContainText(/sure|confirm|delete/i);
    });

    test('should display delete form', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyIds[0]}/delete`);
      await expect(page.locator('form').first()).toBeVisible();
    });

    test('should display confirm and cancel buttons', async ({ adminPage: page }) => {
      test.setTimeout(60000);

      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyIds[0]}/delete`, { timeout: 30000 });

      await expect(page.locator('button[type="submit"]:has-text("Delete"), button:has-text("Delete")').first()).toBeVisible();
      await expect(page.locator('a:has-text("Cancel"), button:has-text("Cancel")').first()).toBeVisible();
    });

    test('should use correct CSRF token field name', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await page.goto(`/zones/${zoneId}/dnssec/keys/${keyIds[0]}/delete`);
      await expect(page.locator('form').first()).toBeVisible();
      // Should use _token (correct)
      const correctToken = page.locator('input[name="_token"]');
      expect(await correctToken.count()).toBe(1);

      // Should NOT use csrf_token (incorrect)
      const wrongToken = page.locator('input[name="csrf_token"]');
      expect(await wrongToken.count()).toBe(0);
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have delete key link if keys exist', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await expect(page.locator('a[href*="/keys/"][href*="/delete"]').first()).toBeVisible();
    });
  });
});

test.describe('DNSSEC DS and DNSKEY Records', () => {
  test.describe('Admin User', () => {
    test('should display DS/DNSKEY page', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      await expect(page).toHaveURL(/.*\/dnssec\/ds-dnskey/);
      await noDangerAlert(page);
    });

    test('should display DNSSEC public records heading', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      await expect(page.locator('main')).toContainText(/dnssec|public|records|ds|dnskey/i);
    });

    test('should display DNSKEY section', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      await expect(page.locator('main')).toContainText(/DNSKEY/);
    });

    test('should display DS section', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      await expect(page.locator('main')).toContainText(/\bDS\b/);
    });

    test('should display records containers', async ({ adminPage: page }) => {
      const keyIds = await listDnssecKeyIds(page, zoneId);
      expect(keyIds, 'beforeAll must have left the zone with a DNSSEC key').not.toHaveLength(0);

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      await expect(page.locator('pre, code, .records').first()).toBeVisible();
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have DS/DNSKEY link', async ({ adminPage: page }) => {
      await page.goto(`/zones/${zoneId}/dnssec`);
      const dsLink = page.locator('a[href*="ds-dnskey"]');
      await expect(dsLink.first()).toBeVisible();
    });
  });
});

// Owned by the manager through the ownership page, so the owner path is really exercised.
test.describe('Manager User on an owned zone', () => {
  const managerZone = useFileZone('dnsmgr');

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await assignZoneOwner(page, managerZone.id, users.manager.username);
    } finally {
      await page.close();
    }
  });

  test('should have access to add DNSSEC key for own zone', async ({ managerPage: page }) => {
    await page.goto(`/zones/${managerZone.id}/dnssec/keys/add`);

    await expect(page).toHaveURL(/.*\/dnssec\/keys\/add/);
    await noDangerAlert(page);
    await expect(page.locator('form select[name="key_type"]')).toBeVisible();
  });

  test('should have access to DS/DNSKEY page for own zone', async ({ managerPage: page }) => {
    await page.goto(`/zones/${managerZone.id}/dnssec/ds-dnskey`);

    await expect(page).toHaveURL(/.*\/dnssec\/ds-dnskey/);
    await noDangerAlert(page);
    await expect(page.locator('main')).toContainText(/DNSKEY|DS/);
  });
});

// Owning and editing a zone is not enough: key management needs its own permission.
test.describe('DNSSEC key permission on an owned zone', () => {
  const ownedZone = useFileZone('dnsperm');
  const password = 'TestP@ssw0rd123';
  const tag = uniqueName('dnsperm');
  const granted = { template: `${tag}-grant`, username: `${tag}-g`, id: null };
  const withheld = { template: `${tag}-deny`, username: `${tag}-d`, id: null };
  const basePerms = ['zone_content_view_own', 'zone_content_edit_own'];

  async function createPermissionTemplate(page, name, permNames) {
    await page.goto('/permissions/templates/add');
    await page.locator('input[name="templ_name"]').fill(name);
    for (const permName of permNames) {
      const row = page.locator('tr.permission-row', { has: page.getByText(permName, { exact: true }) });
      await expect(row, `permission ${permName} must be listed`).toHaveCount(1);
      await row.locator('input[name="perm_id[]"]').check();
    }
    await page.locator('button[name="commit"]').click();
    await page.goto('/permissions/templates');
    await expect(page.locator('tbody tr', { hasText: name }), `template ${name} must be listed`).toHaveCount(1);
  }

  async function createUser(page, username, templateName) {
    await page.goto('/users/add');
    await page.locator('input[name="username"]').fill(username);
    await page.locator('input[name="fullname"]').fill(username);
    await page.locator('input[name="email"]').fill(`${username}@example.com`);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('select[name="perm_templ"]').selectOption({ label: templateName });
    await page.locator('button[name="commit"]').click();
    await page.goto(`/users?search=${username}`);
    await expect(page.locator(`tr:has(input[value="${username}"])`), `user ${username} must be listed`).toHaveCount(1);
  }

  async function deleteUser(page, username) {
    await page.goto(`/users?search=${username}`);
    const row = page.locator(`tr:has(input[value="${username}"])`);
    if (await row.count() > 0) {
      await row.locator('a[href*="/delete"]').first().click();
      await page.locator('button[type="submit"][name="commit"]').click();
      await page.waitForLoadState('networkidle');
    }
  }

  async function deletePermissionTemplate(page, name) {
    await page.goto('/permissions/templates');
    const link = page.locator('tbody tr', { hasText: name }).locator('a[href$="/edit"]').first();
    if (await link.count() > 0) {
      const id = (await link.getAttribute('href')).match(/templates\/(\d+)\/edit/)?.[1];
      await page.goto(`/permissions/templates/${id}/delete`);
      await page.locator('button[name="confirm"]').click();
      await page.waitForLoadState('networkidle');
    }
  }

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await createPermissionTemplate(page, granted.template, [...basePerms, 'zone_dnssec_manage_own']);
      await createPermissionTemplate(page, withheld.template, basePerms);
      await createUser(page, granted.username, granted.template);
      await createUser(page, withheld.username, withheld.template);
      await assignZoneOwner(page, ownedZone.id, granted.username);
      await assignZoneOwner(page, ownedZone.id, withheld.username);
    } finally {
      await page.close();
    }
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      for (const item of [granted, withheld]) {
        await deleteUser(page, item.username);
      }
      for (const item of [granted, withheld]) {
        await deletePermissionTemplate(page, item.template);
      }
    } finally {
      await page.close();
    }
  });

  async function asUser(browser, baseURL, username) {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    await loginAndWaitForDashboard(page, username, password, 3, { fresh: true });
    return { context, page };
  }

  test('should refuse an owner whose template lacks zone_dnssec_manage_own', async ({ browser, baseURL }) => {
    const { context, page } = await asUser(browser, baseURL, withheld.username);
    try {
      await page.goto(`/zones/${ownedZone.id}/dnssec/keys/add`);

      await expect(page.locator('[data-testid="system-message"].alert-danger'))
        .toContainText('You do not have permission to manage DNSSEC for this zone.');
      await expect(page.locator('form select[name="key_type"]')).toHaveCount(0);
    } finally {
      await context.close();
    }
  });

  test('should let an owner with zone_dnssec_manage_own add a key', async ({ browser, baseURL }) => {
    const { context, page } = await asUser(browser, baseURL, granted.username);
    try {
      await page.goto(`/zones/${ownedZone.id}/dnssec/keys/add`);
      await noDangerAlert(page);
      await expect(page.locator('form select[name="key_type"]')).toBeVisible();

      const before = await listDnssecKeyIds(page, ownedZone.id);
      const keyId = await addDnssecKey(page, ownedZone.id);
      const after = await listDnssecKeyIds(page, ownedZone.id);
      expect(after).toHaveLength(before.length + 1);
      expect(after).toContain(keyId);
    } finally {
      await context.close();
    }
  });
});
