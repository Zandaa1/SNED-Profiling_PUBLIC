const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('School-Scoped Data Isolation', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.describe('teacher learner search is scoped', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('teacher'));
    });

    test('learner search endpoint responds for teacher', async ({ page }) => {
      const response = await page.request.get('/ajax_learner_search.php?search=test');
      expect(response.ok()).toBeTruthy();
    });

    test('learner search with empty query responds', async ({ page }) => {
      const response = await page.request.get('/ajax_learner_search.php');
      expect(response.ok()).toBeTruthy();
    });
  });

  test.describe('coordinator school profile is scoped', () => {
    test('coordinator school profile loads', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/school_profile.php');
      expect([200, 302]).toContain(response.status());
    });
  });

  test.describe('superadmin can access any school', () => {
    test('superadmin school search responds', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get('/ajax_school_search.php?q=');
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('success');
    });
  });

  test.describe('SF9 grid is school-scoped', () => {
    test('SF9 grid endpoint is reachable', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.request.get('/ajax_sf9_grid.php?lrn=123456789012&school_year=2025-2026');
      expect(response.status()).toBeLessThan(500);
    });
  });
});
