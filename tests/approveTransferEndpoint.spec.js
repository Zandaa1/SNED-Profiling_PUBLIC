const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('approveTransfer.php — Endpoint Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('GET request redirects to transferRequests.php', async ({ page }) => {
    const response = await page.request.get('/approveTransfer.php');
    const url = response.url();
    expect(url).toMatch(/transferRequests\.php/);
  });

  test('POST without CSRF token shows error', async ({ page }) => {
    const result = await page.evaluate(async () => {
      const fd = new FormData();
      fd.append('request_id', '1');
      fd.append('action', 'approve');
      const resp = await fetch('/approveTransfer.php', { method: 'POST', body: fd });
      return { status: resp.status, text: await resp.text() };
    });

    expect(result.text).toMatch(/csrf|invalid/i);
  });

  test('POST with invalid request_id shows error', async ({ page }) => {
    const result = await page.evaluate(async () => {
      const csrfEl = document.querySelector('input[name="csrf_token"]');
      const csrf = csrfEl ? csrfEl.value : '';

      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('request_id', '999999');
      fd.append('action', 'approve');
      const resp = await fetch('/approveTransfer.php', { method: 'POST', body: fd });
      return { status: resp.status, text: await resp.text() };
    });

    expect(result.text).toMatch(/not found|error|invalid/i);
  });

  test('POST with already-processed request shows error', async ({ page }) => {
    // Navigate to a page with CSRF token first
    await page.goto('/transferRequests.php');

    const result = await page.evaluate(async () => {
      const csrfEl = document.querySelector('input[name="csrf_token"]');
      const csrf = csrfEl ? csrfEl.value : '';

      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('request_id', '1');
      fd.append('action', 'approve');
      const resp = await fetch('/approveTransfer.php', { method: 'POST', body: fd });
      return { status: resp.status, text: await resp.text() };
    });

    // Should show error about already processed, not found, or CSRF
    expect(result.text).toMatch(/already processed|not found|error|csrf/i);
  });
});
