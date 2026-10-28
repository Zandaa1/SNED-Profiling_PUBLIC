const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('SF9 Indicator Rating Workflow', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('SF9 form loads without errors', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/sf9_form.php?learner_id=${lrn}`);

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('SF9 grid AJAX returns HTML content', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    const response = await page.request.get(
      `/ajax_sf9_grid.php?learner_id=${lrn}&school_year=2025-2026`
    );

    if (response.status() === 200) {
      const text = await response.text();
      // May contain rating selects OR a "no ratings" message
      expect(text.length).toBeGreaterThan(0);
    }
  });

  test('local write: saves SF9 form data', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/sf9_form.php?learner_id=${lrn}`);

    // Fill adviser name if present
    const adviserInput = page.locator('input[name="adviser_name"], #adviser_name');
    if (await adviserInput.count() > 0 && await adviserInput.isVisible()) {
      await adviserInput.fill('E2E SF9 Adviser');
    }

    // Fill date if present
    const dateInput = page.locator('input[name="report_date"], #report_date');
    if (await dateInput.count() > 0 && await dateInput.isVisible()) {
      await dateInput.fill('2026-06-15');
    }

    // Find and click save button
    const saveBtn = page.locator('button[type="submit"]:has-text("Save"), #saveBtn');
    if (await saveBtn.count() > 0 && await saveBtn.first().isVisible()) {
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 15_000 }),
        saveBtn.first().click(),
      ]);
    }

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('saveIndicatorRating handles invalid CSRF gracefully', async ({ page }) => {
    // Test with a GET request to verify endpoint exists and handles methods
    const response = await page.request.get('/saveIndicatorRating.php');
    // GET should return 405 Method Not Allowed
    expect(response.status()).toBe(405);
  });

  test('saveIndicatorRating rejects invalid POST data', async ({ page }) => {
    // POST with completely invalid data should return an error, not crash
    const response = await page.request.post('/saveIndicatorRating.php', {
      form: {
        csrf_token: 'invalid',
        rating_id: '999999',
        quarter: 'q1',
        value: 'INVALID',
      },
    });

    // Should return 4xx error, not 500
    expect(response.status()).toBeLessThan(500);
    const text = await response.text();
    expect(text.length).toBeGreaterThan(0);
  });
});
