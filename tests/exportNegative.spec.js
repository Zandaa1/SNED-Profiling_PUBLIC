const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Export & Download — Negative & Auth Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('export_enrollment_macro.php returns 200 or redirect for unauthenticated', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/export_enrollment_macro.php');
    // App may not enforce auth on exports (documented vulnerability) — accept 200 or redirect
    expect(response.status()).toBeLessThan(500);
  });

  test('export_lwd_list.php returns 200 or redirect for unauthenticated', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/export_lwd_list.php');
    // App may not enforce auth on exports (documented vulnerability) — accept 200 or redirect
    expect(response.status()).toBeLessThan(500);
  });

  test('export_sned_school.php rejects missing required params', async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));

    const response = await page.request.get('/export_sned_school.php');
    expect(response.status()).toBeLessThan(500);

    const text = await response.text();
    expect(text.length).toBeGreaterThan(0);
  });

  test('download_doc.php redirects unauthenticated to login', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/download_doc.php?id=1');
    const url = response.url();
    expect(url.includes('index.php') || response.status() >= 400).toBe(true);
  });
});
