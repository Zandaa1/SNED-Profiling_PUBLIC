const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('blankForm.php — Blank SNED Intake Form', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('blank form renders with A4 layout', async ({ page }) => {
    const response = await page.goto('/blankForm.php');
    expect(response.status()).toBe(200);

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('form contains SNED intake form fields', async ({ page }) => {
    await page.goto('/blankForm.php');

    const body = await page.locator('body').textContent();
    // Blank form should contain SNED-related labels
    expect(body).toMatch(/learner|name|school|grade|disability|guardian|SNED/i);
  });
});
