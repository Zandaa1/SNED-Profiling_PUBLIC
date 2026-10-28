const { test, expect } = require('@playwright/test');
const { login, dismissPrivacyNotice } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Sidebar Navigation', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.describe('superadmin sees all nav sections', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
    });

    test('shows Dashboard link', async ({ page }) => {
      const nav = page.locator('.app-sidebar');
      await expect(nav).toContainText('Dashboard');
    });

    test('shows Learners link', async ({ page }) => {
      const nav = page.locator('.app-sidebar');
      await expect(nav).toContainText('Learners');
    });

    test('shows Management section', async ({ page }) => {
      const label = page.locator('.sidebar-section-label', { hasText: 'Management' });
      await expect(label).toBeVisible();
    });

    test('shows Administration section', async ({ page }) => {
      const label = page.locator('.sidebar-section-label', { hasText: 'Administration' });
      await expect(label).toBeVisible();
    });

    test('shows Announcements (superadmin only)', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="announcements.php"]');
      await expect(link).toBeVisible();
    });

    test('shows Users link', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="adminCreateUser.php"]');
      await expect(link).toBeVisible();
    });

    test('shows Logs link', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="transaction_logs.php"]');
      await expect(link).toBeVisible();
    });

    test('shows Archived link', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="trash.php"]');
      await expect(link).toBeVisible();
    });
  });

  test.describe('admin_office sees admin sections but not announcements', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('admin_office'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
    });

    test('shows Administration section', async ({ page }) => {
      const label = page.locator('.sidebar-section-label', { hasText: 'Administration' });
      await expect(label).toBeVisible();
    });

    test('does NOT show Announcements', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="announcements.php"]');
      await expect(link).toBeHidden();
    });
  });

  test.describe('teacher sees limited nav', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('teacher'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
    });

    test('shows Dashboard link', async ({ page }) => {
      const nav = page.locator('.app-sidebar');
      await expect(nav).toContainText('Dashboard');
    });

    test('does NOT show Management section', async ({ page }) => {
      const label = page.locator('.sidebar-section-label', { hasText: 'Management' });
      await expect(label).toBeHidden();
    });

    test('does NOT show Administration section', async ({ page }) => {
      const label = page.locator('.sidebar-section-label', { hasText: 'Administration' });
      await expect(label).toBeHidden();
    });

    test('does NOT show Teachers link', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="teacherManagement.php"]');
      await expect(link).toBeHidden();
    });

    test('does NOT show Schools link', async ({ page }) => {
      const link = page.locator('.app-sidebar a[href="schools.php"]');
      await expect(link).toBeHidden();
    });
  });

  test.describe('sidebar toggle works', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
    });

    test('sidebar collapses when toggle is clicked', async ({ page }) => {
      const shell = page.locator('.app-shell');
      const toggle = page.locator('[data-sidebar-toggle]');
      await toggle.click();
      await expect(shell).toHaveClass(/sidebar-hidden/);
    });

    test('sidebar expands when toggle is clicked again', async ({ page }) => {
      const shell = page.locator('.app-shell');
      const toggle = page.locator('[data-sidebar-toggle]');
      await toggle.click();
      await page.waitForTimeout(300);
      await toggle.click();
      await page.waitForTimeout(300);
      await expect(shell).not.toHaveClass(/sidebar-hidden/);
    });
  });

  test.describe('active state highlights current page', () => {
    test('Dashboard is active on main.php', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
      const activeLink = page.locator('.app-sidebar .sidebar-nav-link.active');
      await expect(activeLink).toContainText('Dashboard');
    });

    test('Learners is active on learners.php', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/learners.php');
      const activeLinks = page.locator('.app-sidebar .sidebar-nav-link.active');
      const count = await activeLinks.count();
      expect(count).toBeGreaterThanOrEqual(1);
      const allText = await activeLinks.allTextContents();
      const hasLearners = allText.some(t => /learners/i.test(t));
      expect(hasLearners).toBe(true);
    });
  });

  test.describe('profile section displays correctly', () => {
    test('shows username in profile area', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
      const profileArea = page.locator('.sidebar-profile-name');
      await expect(profileArea).toBeVisible();
      const text = await profileArea.textContent();
      expect(text.length).toBeGreaterThan(0);
    });

    test('sign out link is present in dropdown', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      await page.goto('/main.php');
      await dismissPrivacyNotice(page);
      await page.locator('#profileMenuButton').click();
      const signOut = page.locator('.dropdown-menu a[href="logout.php"]');
      await expect(signOut).toBeVisible();
    });
  });
});
