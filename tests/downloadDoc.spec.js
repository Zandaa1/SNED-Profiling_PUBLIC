const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('download_doc.php — Document Download', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('invalid document ID returns error text', async ({ page }) => {
    const response = await page.request.get('/download_doc.php?id=0');
    expect(response.status()).toBe(200);

    const text = await response.text();
    expect(text).toMatch(/invalid/i);
  });

  test('non-existent document returns not found', async ({ page }) => {
    const response = await page.request.get('/download_doc.php?id=999999');
    expect(response.status()).toBe(200);

    const text = await response.text();
    expect(text).toMatch(/not found/i);
  });

  test('unauthenticated request redirects to login', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/download_doc.php?id=1');
    const url = response.url();
    expect(url.includes('index.php') || response.status() >= 400).toBe(true);
  });
});
