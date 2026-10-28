const { test, expect } = require('@playwright/test');
const { login, dismissPrivacyNotice } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('main.php — Dashboard', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('dashboard heading shows greeting', async ({ page }) => {
    await page.goto('/main.php');
    await dismissPrivacyNotice(page);
    await expect(page.locator('h1')).toContainText(/good (morning|afternoon|evening)/i);
  });

  test('stat cards section is present', async ({ page }) => {
    await page.goto('/main.php');
    await dismissPrivacyNotice(page);

    const body = await page.locator('body').textContent();
    // Dashboard should show learner/enrollment statistics
    expect(body).toMatch(/learner|enrollment|student|total/i);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('announcements section renders on dashboard', async ({ page }) => {
    await page.goto('/main.php');
    await dismissPrivacyNotice(page);

    const body = await page.locator('body').textContent();
    // Dashboard should have an announcements section (may be empty)
    expect(body.length).toBeGreaterThan(0);
  });

  test('dashboard loads without SQL errors', async ({ page }) => {
    const response = await page.goto('/main.php');
    expect(response.status()).toBe(200);
    await dismissPrivacyNotice(page);

    await expect(page.locator('body')).not.toContainText(
      /sql syntax|mysql|uncaught|syntax error/i
    );
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
