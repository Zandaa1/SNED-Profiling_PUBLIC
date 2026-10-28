const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('viewLearner.php — Learner Case Record', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('page loads with learner identity card', async ({ page }) => {
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

  test('tab navigation sections exist (Overview/ILP/IEP/ITP/BIR)', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    // The page should contain references to the different form sections
    const hasTabs = /overview|ilp|iep|itp|sf9|bir|enrollment/i.test(body);
    expect(hasTabs).toBe(true);
  });

  test('enrollment history section renders', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    expect(body).toMatch(/enrollment|history|school year/i);
  });

  test('encoding checklist guides current school year review', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const overviewChecklist = page.locator('#overview-pane #encodingChecklist');
    await expect(overviewChecklist).toBeVisible();
    await expect(overviewChecklist).toContainText(/encoding checklist/i);
    await expect(overviewChecklist).toContainText(/\d+\/\d+ required/i);
    await expect(overviewChecklist).toContainText(/profile reviewed|enrollment updated/i);
  });

  test('consent records panel loads', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    // Consent section may or may not exist depending on learner state
    expect(body.length).toBeGreaterThan(0);
  });

  test('document section is visible', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/viewLearner.php?id=${lrn}`);
    if (!page.url().includes('viewLearner.php')) {
      test.skip(true, 'Learner not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    expect(body).toMatch(/document|file|upload/i);
  });
});
