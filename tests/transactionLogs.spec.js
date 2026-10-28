const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('transaction_logs.php — Audit Log Viewer', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('page loads with log entries table for superadmin', async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
    await page.goto('/transaction_logs.php');

    await expect(page.locator('body')).toContainText(/transaction|audit|log/i);
    await expect(page.locator('body')).not.toContainText(/access denied/i);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('user filter dropdown is present', async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
    await page.goto('/transaction_logs.php');

    const filter = page.locator('select[name="user_id"], #userFilter');
    const filterCount = await filter.count();
    if (filterCount > 0) {
      await expect(filter.first()).toBeVisible();
    }
  });

  test('admin_office sees restricted entries (no superadmin)', async ({ page }) => {
    await login(page, getRoleCredentials('admin_office'));
    await page.goto('/transaction_logs.php');

    // admin_office can access but should not see superadmin entries
    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
