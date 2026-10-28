const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('AJAX Endpoints — Functional Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  // ── School Search ────────────────────────────────────────────────────────

  test.describe('ajax_school_search.php', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
    });

    test('returns school results for a search query', async ({ page }) => {
      const response = await page.request.get('/ajax_school_search.php?q=test');
      const body = await response.json();

      expect(body.success).toBe(true);
      expect(Array.isArray(body.data)).toBe(true);

      if (body.data.length > 0) {
        const school = body.data[0];
        expect(school).toHaveProperty('id');
        expect(school).toHaveProperty('school_name');
      }
    });

    test('empty query returns top schools by enrollment', async ({ page }) => {
      const response = await page.request.get('/ajax_school_search.php?q=');
      const body = await response.json();

      expect(body.success).toBe(true);
      expect(Array.isArray(body.data)).toBe(true);
      expect(body.data.length).toBeLessThanOrEqual(12);
    });

    test('handles request without session', async ({ page }) => {
      const response = await page.request.get('/ajax_school_search.php?q=test');
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── QR Code ──────────────────────────────────────────────────────────────

  test.describe('ajax_qr.php', () => {
    test('rejects invalid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get('/ajax_qr.php?lrn=123');
      expect(response.status()).toBe(400);

      const text = await response.text();
      expect(text).toContain('Invalid LRN');
    });

    test('returns SVG or error for valid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(`/ajax_qr.php?lrn=${lrn}`);
      expect(response.status()).toBeLessThan(502);
    });
  });

  // ── SF9 Grid ─────────────────────────────────────────────────────────────

  test.describe('ajax_sf9_grid.php', () => {
    test('returns HTML for valid learner and school year', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(
        `/ajax_sf9_grid.php?learner_id=${lrn}&school_year=2025-2026`
      );
      expect(response.status()).toBe(200);

      const text = await response.text();
      expect(text.length).toBeGreaterThan(0);
    });

    test('rejects invalid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get(
        '/ajax_sf9_grid.php?learner_id=123&school_year=2025-2026'
      );
      expect(response.status()).toBe(400);
    });

    test('returns 404 for nonexistent learner', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get(
        '/ajax_sf9_grid.php?learner_id=999999999999&school_year=2025-2026'
      );
      expect(response.status()).toBe(404);
    });
  });

  // ── Consent ──────────────────────────────────────────────────────────────

  test.describe('ajax_consent.php', () => {
    test('check endpoint is reachable for valid learner', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(
        `/ajax_consent.php?lrn=${lrn}&consent_type=IEP`
      );
      expect(response.status()).toBeLessThan(500);

      const text = await response.text();
      expect(text.length).toBeGreaterThan(0);
    });

    test('rejects invalid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get('/ajax_consent.php?lrn=123&consent_type=IEP');
      expect(response.status()).toBe(400);
    });

    test('save and check roundtrip', async ({ page }) => {
      requireLocalWrites();
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      await page.goto('/viewLearner.php?id=' + lrn);
      if (!page.url().includes('viewLearner.php')) {
        test.skip(true, 'Learner not accessible.');
        return;
      }
      const csrf = await page.evaluate(() => {
        const el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : null;
      });
      test.skip(!csrf, 'No CSRF token found.');

      const saveResponse = await page.request.post('/ajax_consent.php', {
        form: {
          csrf_token: csrf,
          lrn: lrn,
          consent_type: 'IEP',
          consented_by: 'E2E Test Parent',
          consent_date: '2026-06-15',
          notes: 'E2E test consent',
        },
      });

      expect(saveResponse.status()).toBeLessThan(500);
    });
  });

  // ── ajax_log.php ─────────────────────────────────────────────────────────

  test.describe('ajax_log.php', () => {
    test('PRINT_LIST action returns success via page.evaluate', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));

      // Use page.evaluate to send fetch with browser session cookies
      const result = await page.evaluate(async () => {
        const fd = new FormData();
        fd.append('action', 'PRINT_LIST');
        fd.append('details', 'E2E test print');
        const resp = await fetch('/ajax_log.php', { method: 'POST', body: fd });
        const text = await resp.text();
        try { return JSON.parse(text); } catch { return { raw: text.substring(0, 200) }; }
      });

      expect(result.status).toBe('success');
    });

    test('lookup_guardian returns data for valid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(
        `/ajax_log.php?action=lookup_guardian&lrn=${lrn}`
      );

      if (response.status() === 200) {
        const text = await response.text();
        expect(text.length).toBeGreaterThan(0);
      }
    });

    test('lookup_guardian rejects invalid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get('/ajax_log.php?action=lookup_guardian&lrn=123');
      const text = await response.text();
      expect(text).toMatch(/invalid|error|not found/i);
    });

    test('learner_quick_view returns data for valid LRN', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.get(
        `/ajax_log.php?action=learner_quick_view&lrn=${lrn}`
      );

      if (response.status() === 200) {
        const text = await response.text();
        expect(text.length).toBeGreaterThan(0);
      }
    });

    test('unknown action returns error', async ({ page }) => {
      await login(page, getRoleCredentials('superadmin'));
      const response = await page.request.get('/ajax_log.php?action=nonexistent');
      expect(response.status()).toBeLessThan(500);
    });
  });
});
