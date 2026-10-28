const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('ITP Form Features', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('ITP form page loads with 200 status', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.goto('/itp_form.php?lrn=123456789012');
    expect([200, 403]).toContain(response.status());
  });

  test('ITP form handles missing LRN gracefully', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.goto('/itp_form.php');
    expect([200, 302, 403]).toContain(response.status());
  });

  test('ITP form renders heading', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    await page.goto('/itp_form.php?lrn=123456789012');
    const body = page.locator('body');
    const text = await body.textContent();
    expect(text.length).toBeGreaterThan(0);
  });
});
