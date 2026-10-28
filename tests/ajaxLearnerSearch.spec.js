const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('ajax_learner_search.php — Functional Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('returns matching results for a name search', async ({ page }) => {
    const response = await page.request.get('/ajax_learner_search.php?q=test');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.success).toBe(true);
    expect(Array.isArray(body.results)).toBe(true);

    if (body.results.length > 0) {
      const learner = body.results[0];
      expect(learner).toHaveProperty('lrn');
      expect(learner).toHaveProperty('name');
      expect(learner).toHaveProperty('grade_level');
      expect(learner).toHaveProperty('school_name');
      expect(learner).toHaveProperty('placement');
    }
  });

  test('returns exact match for LRN search', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    const response = await page.request.get(`/ajax_learner_search.php?q=${lrn}`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.results.length).toBeGreaterThanOrEqual(1);

    const match = body.results.find((r) => r.lrn === lrn);
    expect(match).toBeTruthy();
  });

  test('rejects short query (<2 chars)', async ({ page }) => {
    const response = await page.request.get('/ajax_learner_search.php?q=a');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.success).toBe(false);
    expect(body.message).toMatch(/too short/i);
  });

  test('rejects empty query', async ({ page }) => {
    const response = await page.request.get('/ajax_learner_search.php?q=');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.success).toBe(false);
  });

  test('rejects unauthenticated request with 401', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/ajax_learner_search.php?q=test');
    expect(response.status()).toBe(401);
  });

  test('search results have consistent structure', async ({ page }) => {
    const response = await page.request.get('/ajax_learner_search.php?q=test');
    const body = await response.json();

    expect(body.success).toBe(true);

    for (const r of body.results) {
      expect(typeof r.lrn).toBe('string');
      expect(r.lrn.length).toBeGreaterThan(0);
      expect(typeof r.name).toBe('string');
    }
  });
});
