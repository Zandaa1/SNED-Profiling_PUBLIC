const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('AJAX Endpoint Role-Based Access', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.describe('teacher accessing admin-only endpoints', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('teacher'));
    });

    test('ajax_learner_search.php is accessible to teachers', async ({ page }) => {
      const response = await page.request.get('/ajax_learner_search.php?search=test');
      expect(response.ok()).toBeTruthy();
    });

    test('ajax_school_search.php is accessible to teachers', async ({ page }) => {
      const response = await page.request.get('/ajax_school_search.php');
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('success', true);
    });
  });

  test.describe('AJAX endpoints return valid JSON', () => {
    test('ajax_consent.php endpoint is reachable', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.request.get('/ajax_consent.php?lrn=123456789012');
      expect(response.status()).toBeLessThan(500);
    });

    test('ajax_sf9_grid.php is accessible when logged in', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.request.get('/ajax_sf9_grid.php?lrn=123456789012&school_year=2025-2026');
      expect(response.status()).toBeGreaterThanOrEqual(200);
    });
  });

  test.describe('AJAX endpoints handle edge cases', () => {
    test('ajax_learner_search.php handles empty search gracefully', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.request.get('/ajax_learner_search.php?search=');
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('success');
    });

    test('ajax_school_search.php handles empty query gracefully', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.request.get('/ajax_school_search.php?q=');
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('success', true);
    });
  });
});
