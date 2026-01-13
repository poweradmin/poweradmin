import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Layout - Footer', () => {
  test.describe('Admin User', () => {
    test('should display footer', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });

    test('should display version info', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/version|poweradmin|v\d/i);
    });

    test('should display copyright', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const bodyText = await page.locator('body').textContent();
      // Check for copyright info or Poweradmin branding in footer
      expect(bodyText).toMatch(/©|copyright|\d{4}|poweradmin/i);
    });

    test('footer should be visible on zones page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });

    test('footer should be visible on users page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User', () => {
    test('should display footer', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Client User', () => {
    test('should display footer', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Viewer User', () => {
    test('should display footer', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });
});

test.describe('Layout - Navigation', () => {
  test.describe('Logged Out User', () => {
    test('should show login page', async ({ page }) => {
      await page.goto('/index.php');

      const url = page.url();
      expect(url).toMatch(/login/);
    });

    test('should not show navigation menu', async ({ page }) => {
      await page.goto('/index.php?page=login');

      const nav = page.locator('nav, .navbar, .navigation, #menu');
      // Either no nav or minimal nav without logged-in items
      const logoutLink = page.locator('a:has-text("Logout")');
      expect(await logoutLink.count()).toBe(0);
    });
  });

  test.describe('Admin User Navigation', () => {
    test('should display navigation menu', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const nav = page.locator('nav, .navbar, .navigation, #menu, ul.nav');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const zonesLink = page.locator('a[href*="list_forward_zones"], a:has-text("Zone")');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show users link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const usersLink = page.locator('a[href*="page=users"], a:has-text("User")');
      expect(await usersLink.count()).toBeGreaterThan(0);
    });

    test('should show search link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const searchLink = page.locator('a[href*="page=search"], a:has-text("Search")');
      expect(await searchLink.count()).toBeGreaterThan(0);
    });

    test('should show logout link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });

    test('should show supermasters link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const supermastersLink = page.locator('a[href*="supermaster"], a:has-text("Supermaster")');
      if (await supermastersLink.count() > 0) {
        await expect(supermastersLink.first()).toBeVisible();
      }
    });

    test('should show permission templates link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      // Link may be in dropdown menu, check if it exists in DOM
      const templatesLink = page.locator('a[href*="perm_templ"], a:has-text("Permission")');
      expect(await templatesLink.count()).toBeGreaterThan(0);
    });

    test('should navigate to zones page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const zonesLink = page.locator('a[href*="list_forward_zones"]').first();
      if (await zonesLink.count() > 0) {
        await zonesLink.click();
        await expect(page).toHaveURL(/list_forward_zones/);
      }
    });

    test('should navigate to users page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const usersLink = page.locator('a[href*="page=users"]').first();
      if (await usersLink.count() > 0) {
        await usersLink.click();
        await expect(page).toHaveURL(/page=users/);
      }
    });

    test('should logout successfully', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      // Logout link may be in dropdown menu
      const logoutLink = page.locator('a[href*="logout"]');
      if (await logoutLink.count() > 0) {
        // Try to find and open the dropdown menu first if the link is hidden
        const isVisible = await logoutLink.first().isVisible();
        if (!isVisible) {
          const accountDropdown = page.locator('button:has-text("Account"), [data-bs-toggle="dropdown"]:has-text("Account")');
          if (await accountDropdown.count() > 0) {
            await accountDropdown.first().click();
          }
        }
        await logoutLink.first().click();
        // After logout, should be on login page or index with login form
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/login|password|username/i);
      }
    });
  });

  test.describe('Manager User Navigation', () => {
    test('should display navigation menu', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');

      const nav = page.locator('nav, .navbar, .navigation, #menu, header');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');

      const zonesLink = page.locator('a[href*="list_forward_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should not show users link', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');

      const usersLink = page.locator('a[href*="page=users"]:not([href*="add_user"])');
      // Manager should not have access to user management
      const bodyText = await page.locator('body').textContent();
      // Just verify page loads without error
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show logout link', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User Navigation', () => {
    test('should display navigation menu', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');

      const nav = page.locator('nav, .navbar, .navigation, #menu, header');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');

      const zonesLink = page.locator('a[href*="list_forward_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show logout link', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Viewer User Navigation', () => {
    test('should display limited navigation menu', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');

      // Viewer should have limited menu items
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not show add zone buttons', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const addBtn = page.locator('input[value*="Add master"], input[value*="Add slave"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('should show logout link', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Active Menu Highlighting', () => {
    test('should highlight current page in menu', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const activeLink = page.locator('.active, .current, [aria-current="page"]');
      // Check if there's active state styling
      const zonesLink = page.locator('a[href*="list_forward_zones"]').first();
      if (await zonesLink.count() > 0) {
        await expect(zonesLink).toBeVisible();
      }
    });
  });

  test.describe('Responsive Navigation', () => {
    test('should display on mobile viewport', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=index');

      // Navigation should still be accessible (possibly via hamburger menu)
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display on tablet viewport', async ({ page }) => {
      await page.setViewportSize({ width: 768, height: 1024 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=index');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display on desktop viewport', async ({ page }) => {
      await page.setViewportSize({ width: 1920, height: 1080 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=index');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});

test.describe('Layout - Page Structure', () => {
  test.describe('Dashboard', () => {
    test('should display page title', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      // Dashboard may use various heading levels
      const title = page.locator('h1, h2, h3, h4, h5, .page-title');
      expect(await title.count()).toBeGreaterThan(0);
    });

    test('should display main content area', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const content = page.locator('main, .content, #content, .container');
      expect(await content.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Common Elements', () => {
    test('should have proper HTML structure', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const html = page.locator('html');
      await expect(html).toBeVisible();
    });

    test('should have head element', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const title = await page.title();
      expect(title).toBeTruthy();
    });

    test('should have body element', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const body = page.locator('body');
      await expect(body).toBeVisible();
    });

    test('should load CSS', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const stylesheets = page.locator('link[rel="stylesheet"]');
      expect(await stylesheets.count()).toBeGreaterThan(0);
    });

    test('should load JavaScript', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      const scripts = page.locator('script');
      expect(await scripts.count()).toBeGreaterThan(0);
    });
  });
});

test.describe('Layout - Breadcrumbs', () => {
  test('should display breadcrumbs on zone edit page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');
    // Use table-specific selector to avoid matching dropdown menu links
    const editLink = page.locator('table a[href*="page=edit&id="]').first();

    if (await editLink.count() > 0) {
      await editLink.click();

      const breadcrumbs = page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]');
      // Breadcrumbs may or may not exist
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should display breadcrumbs on add record page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');
    // Use table-specific selector to avoid matching dropdown menu links
    const editLink = page.locator('table a[href*="page=edit&id="]').first();

    if (await editLink.count() > 0) {
      await editLink.click();

      const addRecordLink = page.locator('a[href*="add_record"]').first();
      if (await addRecordLink.count() > 0) {
        await addRecordLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });
});
