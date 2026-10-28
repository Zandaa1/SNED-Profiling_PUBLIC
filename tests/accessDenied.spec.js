const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('403 Access Denied Page', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('403 page loads for unauthenticated user', async ({ page }) => {
    const response = await page.goto('/403.php');
    expect(response.status()).toBe(200);
    await expect(page.locator('text=Access Denied')).toBeVisible();
  });

  test('403 page has dashboard return link', async ({ page }) => {
    await page.goto('/403.php');
    const link = page.locator('a[href="main.php"]');
    await expect(link).toBeVisible();
    await expect(link).toContainText('Return to Dashboard');
  });

  test('403 page shows role for authenticated user', async ({ page }) => {
    await login(page, getRoleCredentials('teacher'));
    await page.goto('/403.php');
    const body = page.locator('body');
    await expect(body).toContainText('Access Denied');
  });

  test('403 page shows incident logged message', async ({ page }) => {
    await page.goto('/403.php');
    const body = page.locator('body');
    await expect(body).toContainText('incident has been logged');
  });

  test('403 page title contains SNED', async ({ page }) => {
    await page.goto('/403.php');
    await expect(page).toHaveTitle(/SNED/i);
  });
});
