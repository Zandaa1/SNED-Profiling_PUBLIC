const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Print Pages — Smoke Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  // ── Annex 4 ──────────────────────────────────────────────────────────────

  test.describe('printAnnex4.php', () => {
    test('renders adaptive skills competencies', async ({ page }) => {
      const response = await page.goto('/printAnnex4.php');
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();

      // Verify key competencies from the reference table
      expect(body).toContain('Self-Care');
      expect(body).toContain('Communication');
      expect(body).toContain('Self-direction');
      expect(body).toContain('Social Skills');
      expect(body).toContain('Functional Academics');
      expect(body).toContain('Community Use');
      expect(body).toContain('Work');
      expect(body).toContain('Health and Safety');
    });

    test('has print toolbar', async ({ page }) => {
      await page.goto('/printAnnex4.php');
      const toolbar = page.locator('.print-toolbar, #printToolbar, .a4-toolbar');
      await expect(toolbar).toBeVisible();
    });
  });

  // ── Form 137 ─────────────────────────────────────────────────────────────

  test.describe('printForm137.php', () => {
    test('renders or shows message for valid LRN', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(
        `/printForm137.php?lrn=${lrn}`
      );

      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });

    test('shows error for invalid LRN', async ({ page }) => {
      const response = await page.goto('/printForm137.php?lrn=123');
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body).toMatch(/invalid|error|not found/i);
    });
  });

  // ── School Roster Print ──────────────────────────────────────────────────

  test.describe('print_school_roster.php', () => {
    test('renders roster for valid school', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.goto(
        `/print_school_roster.php?school_id=${schoolId}`
      );
      expect(response.status()).toBe(200);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    });

    test('renders with sex filter', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.goto(
        `/print_school_roster.php?school_id=${schoolId}&sex=Male`
      );
      expect(response.status()).toBe(200);

      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    });
  });

  // ── Print SF9 Checklist ──────────────────────────────────────────────────

  test.describe('printSF9Checklist.php', () => {
    test('loads or returns 403 for valid learner', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.goto(
        `/printSF9Checklist.php?learner_id=${lrn}&school_year=2025-2026`
      );

      // May be 200 (success), 403 (placement type mismatch), or redirect
      expect([200, 403]).toContain(response.status());
    });
  });
});
