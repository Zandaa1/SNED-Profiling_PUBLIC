const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Save Endpoints — CSRF & Method Security', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  // ── saveIEP.php ──────────────────────────────────────────────────────────

  test.describe('saveIEP.php', () => {
    test('GET request does not crash (redirects or 405)', async ({ page }) => {
      const response = await page.request.get('/saveIEP.php');
      expect(response.status()).toBeLessThan(500);
    });

    test('POST without CSRF token shows error', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.post('/saveIEP.php', {
        form: {
          learner_id: lrn,
          school_year: '2025-2026',
        },
      });

      const text = await response.text();
      expect(text).toMatch(/csrf|invalid/i);
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── saveILP.php ──────────────────────────────────────────────────────────

  test.describe('saveILP.php', () => {
    test('GET request does not crash', async ({ page }) => {
      const response = await page.request.get('/saveILP.php');
      expect(response.status()).toBeLessThan(500);
    });

    test('POST without CSRF token shows error', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.post('/saveILP.php', {
        form: {
          learner_id: lrn,
          school_year: '2025-2026',
          document_type: 'ILP',
        },
      });

      const text = await response.text();
      expect(text).toMatch(/csrf|invalid/i);
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── saveITP.php ──────────────────────────────────────────────────────────

  test.describe('saveITP.php', () => {
    test('GET request does not crash', async ({ page }) => {
      const response = await page.request.get('/saveITP.php');
      expect(response.status()).toBeLessThan(500);
    });

    test('POST without CSRF token shows error', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.post('/saveITP.php', {
        form: {
          learner_id: lrn,
          school_year: '2025-2026',
        },
      });

      const text = await response.text();
      expect(text).toMatch(/csrf|invalid/i);
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── save_iep_annex6.php ──────────────────────────────────────────────────

  test.describe('save_iep_annex6.php', () => {
    test('GET request does not crash', async ({ page }) => {
      const response = await page.request.get('/save_iep_annex6.php');
      expect(response.status()).toBeLessThan(500);
    });

    test('POST without CSRF token shows error', async ({ page }) => {
      const learnership = getLearnership();
      const lrn = learnership.anyLrn;
      test.skip(!lrn, 'No learner LRN available in fixtures.');

      const response = await page.request.post('/save_iep_annex6.php', {
        form: {
          learner_id: lrn,
          school_year: '2025-2026',
        },
      });

      const text = await response.text();
      expect(text).toMatch(/csrf|invalid/i);
      expect(response.status()).toBeLessThan(500);
    });
  });

  // ── submitTransferRequest.php ────────────────────────────────────────────

  test.describe('submitTransferRequest.php', () => {
    test('rejects unauthenticated request', async ({ page }) => {
      await page.context().clearCookies();
      const response = await page.request.post('/submitTransferRequest.php', {
        form: { action: 'submit' },
      });

      expect(response.status()).toBe(401);
    });

    test('POST without CSRF returns 403', async ({ page }) => {
      const response = await page.request.post('/submitTransferRequest.php', {
        form: {
          action: 'submit',
          learner_id: '000000000000',
          transfer_type: 'internal',
          effective_sy: '2025-2026',
          reason: 'Test',
        },
      });

      expect(response.status()).toBe(403);
    });

    test('rejects invalid LRN format', async ({ page }) => {
      // Navigate to transfer page to get CSRF token
      await page.goto('/transfer.php');

      const response = await page.evaluate(async () => {
        const csrfEl = document.querySelector('input[name="csrf_token"]');
        const csrf = csrfEl ? csrfEl.value : '';

        const fd = new FormData();
        fd.append('action', 'submit');
        fd.append('csrf_token', csrf);
        fd.append('learner_id', '123');
        fd.append('transfer_type', 'internal');
        fd.append('effective_sy', '2025-2026');
        fd.append('reason', 'Test');
        const resp = await fetch('/submitTransferRequest.php', { method: 'POST', body: fd });
        return { status: resp.status, body: await resp.json() };
      });

      expect(response.status).toBe(400);
    });
  });

  // ── approveTransfer.php ──────────────────────────────────────────────────

  test.describe('approveTransfer.php', () => {
    test('GET redirects to transferRequests page', async ({ page }) => {
      const response = await page.request.get('/approveTransfer.php');
      const url = response.url();
      expect(url).toMatch(/transferRequests\.php/);
    });

    test('POST without CSRF shows error', async ({ page }) => {
      const response = await page.evaluate(async () => {
        const fd = new FormData();
        fd.append('request_id', '1');
        fd.append('action', 'approve');
        const resp = await fetch('/approveTransfer.php', { method: 'POST', body: fd });
        return { status: resp.status, text: await resp.text() };
      });

      expect(response.text).toMatch(/csrf|invalid/i);
    });
  });
});
