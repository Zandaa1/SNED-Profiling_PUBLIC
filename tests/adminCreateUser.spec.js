const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('adminCreateUser.php', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('create form loads with expected elements', async ({ page }) => {
    await page.goto('/adminCreateUser.php');
    await expect(page.getByRole('heading', { name: /manage users/i })).toBeVisible();
    await expect(page.locator('#createUserForm')).toBeVisible();
    await expect(page.locator('input[name="new_full_name"]')).toBeVisible();
    await expect(page.locator('input[name="new_username"]')).toBeVisible();
    await expect(page.locator('input[name="new_password"]')).toBeVisible();
    await expect(page.locator('select[name="new_role"]')).toBeVisible();
  });

  test('local write: creates a new admin_office user', async ({ page }) => {
    requireLocalWrites();

    const token = Date.now().toString(36);
    const username = `pwtest_auto_${token}`;

    await page.goto('/adminCreateUser.php');
    await page.locator('input[name="new_full_name"]').fill(`PWTEST Auto ${token}`);
    await page.locator('input[name="new_username"]').fill(username);
    await page.locator('input[name="new_password"]').fill('PWTest123!');
    await page.locator('select[name="new_role"]').selectOption('admin_office');
    await page.locator('#createUserForm button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/user created/i);
    await expect(page.locator('table')).toContainText(username);
  });

  test('local write: duplicate username shows error', async ({ page }) => {
    requireLocalWrites();

    await page.goto('/adminCreateUser.php');
    await page.locator('input[name="new_full_name"]').fill('PWTEST Duplicate');
    await page.locator('input[name="new_username"]').fill('pwtest_superadmin');
    await page.locator('input[name="new_password"]').fill('PWTest123!');
    await page.locator('select[name="new_role"]').selectOption('admin_office');
    await page.locator('#createUserForm button[type="submit"]').click();

    await expect(page.locator('.alert-danger').first()).toContainText(/already exists/i);
  });
});
