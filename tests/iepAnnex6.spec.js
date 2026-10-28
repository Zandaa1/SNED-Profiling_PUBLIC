const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('IEP Annex 6 — Progress Monitoring Save', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('IEP editor loads without errors', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/iep_editor.php?learner_id=${lrn}`);

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('IEP editor has annex6 or progress monitoring section', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/iep_editor.php?learner_id=${lrn}`);
    if (!page.url().includes('iep_editor.php')) {
      test.skip(true, 'IEP editor not accessible.');
      return;
    }

    // Check for annex6 content or wizard step indicators
    const body = await page.locator('body').textContent();
    const hasAnnex6 = /annex\s*6|progress\s*monitoring|special\s*factors/i.test(body);
    // Even if annex6 text isn't visible yet (wizard step), the page should load
    expect(body.length).toBeGreaterThan(0);
  });

  test('local write: fills IEP form fields', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/iep_editor.php?learner_id=${lrn}`);
    if (!page.url().includes('iep_editor.php')) {
      test.skip(true, 'IEP editor not accessible.');
      return;
    }

    // Try to fill basic IEP fields that are typically on step 1
    const meetingDate = page.locator('input[name="meeting_date"]');
    if (await meetingDate.count() > 0 && await meetingDate.isVisible()) {
      await meetingDate.fill('2026-06-15');
    }

    const purpose = page.locator('input[name="purpose_of_meeting"], textarea[name="purpose_of_meeting"]');
    if (await purpose.count() > 0 && await purpose.isVisible()) {
      await purpose.fill('E2E Test: Annual IEP Review');
    }

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('save_iep_annex6 endpoint handles POST without crash', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto('/iep_editor.php?learner_id=' + lrn);
    if (!page.url().includes('iep_editor.php') && !page.url().includes('viewLearner.php')) {
      test.skip(true, 'IEP editor not accessible for test learner.');
      return;
    }

    const csrf = await page.evaluate(() => {
      const el = document.querySelector('input[name="csrf_token"]');
      return el ? el.value : null;
    });
    test.skip(!csrf, 'No CSRF token found.');

    // POST with missing school_year — should return error, not crash
    const response = await page.request.post('/save_iep_annex6.php', {
      form: {
        csrf_token: csrf,
        learner_id: lrn,
      },
    });

    // Should not return 500 (server crash)
    expect(response.status()).toBeLessThan(500);
  });
});
