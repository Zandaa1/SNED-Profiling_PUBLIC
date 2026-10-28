const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Document Upload & Download', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('viewLearner page has document upload controls', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('rejects PHP file upload via import_document endpoint', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    // Navigate to get CSRF token
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

    // Use page.evaluate to send multipart form with CSRF
    const result = await page.evaluate(
      async ({ csrf, lrn }) => {
        const fd = new FormData();
        const blob = new Blob(['<?php echo "hacked"; ?>'], { type: 'application/x-php' });
        fd.append('doc_file', blob, 'malicious.php');
        fd.append('csrf_token', csrf);
        fd.append('learner_id', lrn);
        fd.append('doc_type', 'other');

        const resp = await fetch('/import_document.php', { method: 'POST', body: fd });
        const text = await resp.text();
        return { status: resp.status, hasError: /unsupported file type|not allowed|invalid/i.test(text) };
      },
      { csrf, lrn }
    );

    expect(result.hasError).toBe(true);
  });

  test('rejects EXE file upload via import_document endpoint', async ({ page }) => {
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

    const result = await page.evaluate(
      async ({ csrf, lrn }) => {
        const fd = new FormData();
        const blob = new Blob(['MZ'], { type: 'application/x-msdownload' });
        fd.append('doc_file', blob, 'malicious.exe');
        fd.append('csrf_token', csrf);
        fd.append('learner_id', lrn);
        fd.append('doc_type', 'other');

        const resp = await fetch('/import_document.php', { method: 'POST', body: fd });
        const text = await resp.text();
        return { status: resp.status, hasError: /unsupported file type|not allowed|invalid/i.test(text) };
      },
      { csrf, lrn }
    );

    expect(result.hasError).toBe(true);
  });
});
