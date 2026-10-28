const { test, expect } = require('@playwright/test');
const { login, dismissPrivacyNotice } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { ensureAnyLrn, createGeneratedLearner } = require('./helpers/learner');
const { learnerData } = require('./helpers/data');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Pages & Forms smoke', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  let sharedLrn = null;

  async function getCleanLrn(page) {
    if (!sharedLrn) {
      requireLocalWrites();
      const data = learnerData();
      await createGeneratedLearner(page, data);
      sharedLrn = data.lrn;
    }
    return sharedLrn;
  }

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('main.php dashboard shows stats', async ({ page }) => {
    await page.goto('/main.php');
    await dismissPrivacyNotice(page);
    await expect(page.locator('h1')).toContainText(/good (morning|afternoon|evening)/i);
    await expect(page.locator('body')).not.toContainText(/access denied/i);
  });

  test('learners.php directory loads with search', async ({ page }) => {
    await page.goto('/learners.php');
    await expect(page.locator('h1.page-title')).toContainText(/learner directory/i);
    await expect(page.locator('body')).not.toContainText(/access denied/i);
  });

  test('schools.php directory loads for admin', async ({ page }) => {
    await page.goto('/schools.php');
    await expect(page.locator('h1.page-title')).toContainText(/school directory/i);
    await expect(page.locator('#schoolSearchInput')).toBeVisible();
  });

  test('adminCreateUser.php user management loads for admin', async ({ page }) => {
    await page.goto('/adminCreateUser.php');
    await expect(page.getByRole('heading', { name: /manage users/i })).toBeVisible();
    await expect(page.locator('#createUserForm')).toBeVisible();
  });

  test.describe('learner-dependent pages', () => {
    test('viewLearner.php shows learner profile', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/viewLearner.php?id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('h2.fw-bold.mb-0')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(/access denied/i);
    });

    test('ilp_form.php editor loads', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/ilp_form.php?learner_id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('h4')).toContainText(/Individual Learner Profile Editor/i);
      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    });

    test('iep_editor.php editor loads', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('h4')).toContainText(/Individualized Education Plan Editor/i);
      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    });

    test('itp_form.php editor loads', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/itp_form.php?learner_id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('body')).not.toContainText(/access denied|fatal error|argumentcounterror|bind_param/i);
    });

    test('sf9_form.php form loads', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/sf9_form.php?learner_id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('body')).not.toContainText(/access denied|fatal error|argumentcounterror|bind_param/i);
    });

    test('bir_form.php editor loads', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/bir_form.php?learner_id=${encodeURIComponent(lrn)}`);
      await expect(page.locator('body')).not.toContainText(/access denied|fatal error|argumentcounterror|bind_param/i);
    });

    test('printIEP.php prints landscape IEP and contains sections', async ({ page }) => {
      requireLocalWrites();
      const lrn = await getCleanLrn(page);
      await page.goto(`/printIEP.php?learner_id=${encodeURIComponent(lrn)}`);
      
      const bodyText = await page.locator('body').textContent();
      if (bodyText.includes('No IEP on file')) {
        // Create a quick IEP
        await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);
        await page.locator('input[name="meeting_date"]').fill('2026-06-15');
        await page.locator('select[name="purpose_of_meeting"]').selectOption('Initial IEP');
        await page.evaluate(() => window.goToStep(2));
        await page.locator('textarea[name="present_eval_results"]').fill('IEP Evaluative results');
        await page.locator('textarea[name="academic_strengths"]').fill('Strengths value');
        await page.evaluate(() => window.goToStep(3));
        await page.locator('.difficulty-check').first().check({ force: true });
        await page.evaluate(() => window.goToStep(5));
        await Promise.all([
          page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
          page.locator('#saveBtn').click()
        ]);
        // Revisit print page
        await page.goto(`/printIEP.php?learner_id=${encodeURIComponent(lrn)}`);
      }

      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
      await expect(page.locator('body')).toContainText(/SECTION A: PERSONAL INFORMATION/i);
      await expect(page.locator('body')).toContainText(/SECTION I: PRESENT LEVELS/i);
      await expect(page.locator('body')).toContainText(/SECTION II: CONSIDERATION OF SPECIAL FACTORS/i);
      await expect(page.locator('body')).toContainText(/SECTION B: DIFFICULTIES/i);
      await expect(page.locator('body')).toContainText(/SECTION C: LEARNER GOALS/i);
      await expect(page.locator('body')).toContainText(/SECTION D: LEARNER TRANSITION/i);
      await expect(page.locator('body')).toContainText(/IEP TEAM MEMBERS AND ATTENDANCE/i);
    });
  });
});
