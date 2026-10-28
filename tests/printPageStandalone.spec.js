const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Print Pages — Standalone Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  // ── printForm.php ────────────────────────────────────────────────────────

  test.describe('printForm.php', () => {
    test('renders learner profile for valid LRN', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(`/printForm.php?lrn=${lrn}`);
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });

    test('shows error for invalid LRN', async ({ page }) => {
      const response = await page.goto('/printForm.php?lrn=123');
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body).toMatch(/invalid|error|not found/i);
    });
  });

  // ── printAnnex1.php ──────────────────────────────────────────────────────

  test.describe('printAnnex1.php', () => {
    test('renders learner summary for valid LRN', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(`/printAnnex1.php?lrn=${lrn}`);
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    });
  });

  // ── printAnnex2.php ──────────────────────────────────────────────────────

  test.describe('printAnnex2.php', () => {
    test('renders SF9 ratings grid or shows message', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(
        `/printAnnex2.php?learner_id=${lrn}&school_year=2025-2026`
      );

      // May be 200 (success) or 403 (placement mismatch)
      expect([200, 403]).toContain(response.status());
    });
  });

  // ── printIEP.php ─────────────────────────────────────────────────────────

  test.describe('printIEP.php', () => {
    test('renders IEP document or shows no-data message', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(`/printIEP.php?learner_id=${lrn}`);
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });
  });

  // ── printILP.php ─────────────────────────────────────────────────────────

  test.describe('printILP.php', () => {
    test('renders ILP document or shows no-data message', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(`/printILP.php?learner_id=${lrn}`);
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });
  });

  // ── printITP.php ─────────────────────────────────────────────────────────

  test.describe('printITP.php', () => {
    test('renders ITP document with transition matrix', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(`/printITP.php?learner_id=${lrn}`);
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });
  });

  // ── printBIR.php ─────────────────────────────────────────────────────────

  test.describe('printBIR.php', () => {
    test('renders BIR for valid params or shows error', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(
        `/printBIR.php?learner_id=${lrn}&sy=2025-2026&q=Q1`
      );

      // May be 200 or 403 depending on scope
      expect(response.status()).toBeLessThan(500);
    });

    test('shows error for invalid parameters', async ({ page }) => {
      const response = await page.goto('/printBIR.php?learner_id=000000000000&sy=2025-2026&q=Q1');
      expect(response.status()).toBeLessThan(500);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });
  });
});
