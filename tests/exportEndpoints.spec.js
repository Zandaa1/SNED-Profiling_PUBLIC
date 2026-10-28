const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Export Endpoints — Download Validation', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  // ── Enrollment Macro ─────────────────────────────────────────────────────

  test.describe('export_enrollment_macro.php', () => {
    test('downloads Excel enrollment macro', async ({ page }) => {
      const response = await page.request.get('/export_enrollment_macro.php');
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');

      const cd = response.headers()['content-disposition'] || '';
      expect(cd).toContain('ENROLMENT');
    });

    test('downloads with school_year param', async ({ page }) => {
      const response = await page.request.get(
        '/export_enrollment_macro.php?school_year=2025-2026'
      );
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');
    });
  });

  // ── LWD List ─────────────────────────────────────────────────────────────

  test.describe('export_lwd_list.php', () => {
    test('downloads Excel LWD list', async ({ page }) => {
      const response = await page.request.get('/export_lwd_list.php');
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');
    });
  });

  // ── LWD Adults ───────────────────────────────────────────────────────────

  test.describe('export_lwd_adults.php', () => {
    test('renders HTML page for adult LWD list', async ({ page }) => {
      const response = await page.request.get('/export_lwd_adults.php');
      expect(response.status()).toBe(200);

      const text = await response.text();
      expect(text).toContain('LWD');
    });
  });

  // ── SNED School Report ───────────────────────────────────────────────────

  test.describe('export_sned_school.php', () => {
    test('downloads Excel for valid school and year', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.request.get(
        `/export_sned_school.php?school_id=${schoolId}&school_year=2025-2026`
      );
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');
    });

    test('rejects missing school_id', async ({ page }) => {
      const response = await page.request.get('/export_sned_school.php?school_year=2025-2026');
      const text = await response.text();
      // Endpoint returns descriptive error text
      expect(text.length).toBeGreaterThan(0);
      expect(response.status()).toBeLessThan(500);
    });

    test('rejects missing school_year', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.request.get(
        `/export_sned_school.php?school_id=${schoolId}`
      );
      const text = await response.text();
      expect(text.length).toBeGreaterThan(0);
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── School Roster Export ─────────────────────────────────────────────────

  test.describe('export_school_roster.php', () => {
    test('downloads Excel roster for valid school', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.request.get(
        `/export_school_roster.php?school_id=${schoolId}`
      );
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');
    });

    test('downloads with sex filter', async ({ page }) => {
      const learnership = getLearnership();
      const schoolId = learnership.schoolId;
      test.skip(!schoolId, 'No school ID available in fixtures.');

      const response = await page.request.get(
        `/export_school_roster.php?school_id=${schoolId}&sex=Male`
      );
      expect(response.status()).toBe(200);

      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toContain('spreadsheet');
    });
  });

  // ── IEP JSON Export ──────────────────────────────────────────────────────

  test.describe('export_iep_json.php', () => {
    test('downloads JSON or 404 for valid learner', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(
        `/export_iep_json.php?learner_id=${lrn}`
      );

      // May be 200 (with data) or 404 (no IEP data)
      expect([200, 404]).toContain(response.status());

      if (response.status() === 200) {
        const body = await response.json();
        expect(body).toHaveProperty('meta');
        expect(body).toHaveProperty('learner');
      }
    });

    test('rejects invalid LRN', async ({ page }) => {
      const response = await page.request.get('/export_iep_json.php?learner_id=123');
      expect(response.status()).toBe(400);
    });

    test('returns 404 for nonexistent learner', async ({ page }) => {
      const response = await page.request.get(
        '/export_iep_json.php?learner_id=999999999999'
      );
      expect(response.status()).toBe(404);
    });
  });
});
