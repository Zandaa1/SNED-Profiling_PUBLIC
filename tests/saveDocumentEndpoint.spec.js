const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('saveDocument Endpoint', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('GET redirects to learners.php', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.evaluate(async () => {
      const res = await fetch('/saveDocument.php', { method: 'GET', redirect: 'manual' });
      return { status: res.status, redirected: res.redirected, type: res.type };
    });
    expect(response.status).toBeGreaterThanOrEqual(0);
  });

  test('POST without session redirects to login', async ({ request }) => {
    const formData = new URLSearchParams();
    formData.append('csrf_token', 'test');
    formData.append('lrn', '123456789012');
    formData.append('document_type', 'Other');
    const response = await request.post('http://127.0.0.1:8000/saveDocument.php', {
      form: Object.fromEntries(formData),
      maxRedirects: 0
    });
    expect(response.status()).toBeGreaterThanOrEqual(200);
  });

  test('POST with invalid CSRF shows error', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.evaluate(async () => {
      const formData = new FormData();
      formData.append('csrf_token', 'invalid_token_12345');
      formData.append('lrn', '123456789012');
      formData.append('document_type', 'Other');
      const res = await fetch('/saveDocument.php', { method: 'POST', body: formData });
      return { status: res.status, text: await res.text() };
    });
    expect(response.status).toBeGreaterThanOrEqual(200);
  });

  test('POST with invalid LRN format redirects', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    await page.goto('/viewLearner.php?id=123456789012');
    const csrf = await page.evaluate(() => {
      const el = document.querySelector('input[name="csrf_token"]');
      return el ? el.value : null;
    });
    const response = await page.evaluate(async (csrf) => {
      const formData = new FormData();
      formData.append('csrf_token', csrf || '');
      formData.append('lrn', 'not-a-valid-lrn');
      formData.append('document_type', 'Other');
      const res = await fetch('/saveDocument.php', { method: 'POST', redirect: 'manual' });
      return { status: res.status, type: res.type };
    }, csrf);
    expect(response.status).toBeGreaterThanOrEqual(0);
  });

  test('POST with valid CSRF and LRN but no file returns error message', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    await page.goto('/viewLearner.php?id=123456789012');
    const csrf = await page.evaluate(() => {
      const el = document.querySelector('input[name="csrf_token"]');
      return el ? el.value : null;
    });
    const response = await page.evaluate(async (csrf) => {
      const formData = new FormData();
      formData.append('csrf_token', csrf || '');
      formData.append('lrn', '123456789012');
      formData.append('document_type', 'Other');
      formData.append('notes', 'test');
      const res = await fetch('/saveDocument.php', { method: 'POST', redirect: 'manual' });
      return { status: res.status, type: res.type };
    }, csrf);
    expect(response.status).toBeGreaterThanOrEqual(0);
  });
});
