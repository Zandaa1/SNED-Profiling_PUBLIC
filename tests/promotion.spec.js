const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { createGeneratedLearner } = require('./helpers/learner');
const { currentSchoolYear, learnerData } = require('./helpers/data');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

function nextSchoolYear(schoolYear = currentSchoolYear()) {
  const [start, end] = schoolYear.split('-').map((part) => Number.parseInt(part, 10));
  return `${start + 1}-${end + 1}`;
}

test.describe('promotion.php — Learner Promotion', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('promotion form loads with required elements', async ({ page }) => {
    await page.goto('/promotion.php');
    await expect(page.locator('h1')).toContainText(/promotion/i);
    await expect(page.locator('#promoteSearchInput')).toBeVisible();
    await expect(page.locator('#promoteSelectedLrn')).toBeAttached();
    await expect(page.locator('select[name="school_year"]')).toBeVisible();
    await expect(page.locator('#nextGradeLevel')).toBeVisible();
    await expect(page.locator('#indPlacement')).toBeVisible();
    await expect(page.getByRole('button', { name: /promote learner/i })).toBeVisible();
  });

  test('learner search populates info panel and grade dropdown', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto('/promotion.php');
    await page.locator('#promoteSearchInput').fill(lrn);

    const result = page.locator('#promoteSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await expect(page.locator('#promoteSelectedLrn')).toHaveValue(lrn);
    await expect(page.locator('#promoteLearnerInfo')).not.toHaveClass(/d-none/);
    await expect(page.locator('#infoName')).not.toBeEmpty();
  });

  test('local write: promotes a learner to next grade level', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto('/promotion.php');
    await page.locator('#promoteSearchInput').fill(lrn);

    const result = page.locator('#promoteSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await expect(page.locator('#promoteSelectedLrn')).toHaveValue(lrn);

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });

    await page.locator('#indPlacement').selectOption('Graded');
    await page.locator('#indPlacement').dispatchEvent('change');

    const nextGradeSelect = page.locator('#nextGradeLevel');
    const gradeOptions = page.locator('#nextGradeLevel option');
    const optionCount = await gradeOptions.count();
    if (optionCount > 1) {
      const secondValue = await gradeOptions.nth(1).getAttribute('value');
      if (secondValue) {
        await nextGradeSelect.selectOption(secondValue);
      }
    }

    await page.locator('button[type="submit"]:has-text("Promote Learner")').click();
    await page.locator('#btnConfirmSinglePromote').click();

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    const successOrInfo = page.locator('.alert-success, .alert-info');
    await expect(successOrInfo.first()).toBeVisible({ timeout: 10_000 });
  });

  test('local write: promotes learner with Non-Graded placement', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.nonGradedLrn || learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    test.skip(!lrn, 'No non-graded learner available in fixtures.');

    await page.goto('/promotion.php');
    await page.locator('#promoteSearchInput').fill(lrn);

    const result = page.locator('#promoteSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await expect(page.locator('#promoteSelectedLrn')).toHaveValue(lrn);

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });

    const nextGradeSelect = page.locator('#nextGradeLevel');
    if (await nextGradeSelect.isVisible()) {
      const gradeOptions = page.locator('#nextGradeLevel option');
      const optionCount = await gradeOptions.count();
      if (optionCount > 1) {
        const secondValue = await gradeOptions.nth(1).getAttribute('value');
        if (secondValue) {
          await nextGradeSelect.selectOption(secondValue);
        }
      }
    }

    await page.locator('#indPlacement').selectOption('Non-Graded');
    await page.locator('#indPlacement').dispatchEvent('change');
    await expect(page.locator('#indSelfContainedLevelWrap')).toBeVisible();
    await page.locator('select[name="self_contained_level"]').selectOption('Primary Level 1');

    await page.locator('button[type="submit"]:has-text("Promote Learner")').click();
    await page.locator('#btnConfirmSinglePromote').click();

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    const successOrInfo = page.locator('.alert-success, .alert-info');
    await expect(successOrInfo.first()).toBeVisible({ timeout: 10_000 });
  });

  test('local write: promoted learner is marked as needing profile update', async ({ page }) => {
    requireLocalWrites();
    const data = learnerData();
    const promotedSchoolYear = nextSchoolYear(data.schoolYear);

    await createGeneratedLearner(page, data);
    await page.goto(`/viewLearner.php?id=${encodeURIComponent(data.lrn)}`);
    await expect(page.locator('body')).not.toContainText(/profile needs update/i);

    await page.goto('/promotion.php');
    await page.locator('#promoteSearchInput').fill(data.lrn);

    const result = page.locator('#promoteSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await expect(page.locator('#promoteSelectedLrn')).toHaveValue(data.lrn);
    await page.locator('select[name="school_year"]').selectOption(promotedSchoolYear);
    await page.locator('#indPlacement').selectOption('Non-Graded');
    await page.locator('#indPlacement').dispatchEvent('change');
    await page.locator('select[name="self_contained_level"]').selectOption('Primary Level 1');

    await page.locator('button[type="submit"]:has-text("Promote Learner")').click();
    await Promise.all([
      page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {}),
      page.locator('#btnConfirmSinglePromote').click(),
    ]);

    await expect(page.locator('.alert-success').first()).toContainText(/promoted/i);

    await page.goto(`/viewLearner.php?id=${encodeURIComponent(data.lrn)}`);
    await expect(page.locator('.alert-warning')).toContainText(/profile needs update/i);
    await expect(page.locator('.alert-warning')).toContainText(/current school year/i);
  });

  test('bulk promotion tab loads class filters and rosters', async ({ page }) => {
    requireLocalWrites();
    await page.goto('/promotion.php');
    await page.locator('#bulk-tab').click();

    await expect(page.locator('#btnLoadRoster')).toBeVisible();

    // Select the school if superadmin
    const schoolSelect = page.locator('#bulkSchoolSelect');
    if (await schoolSelect.isVisible()) {
      await schoolSelect.selectOption({ index: 1 });
    }

    await page.locator('#btnLoadRoster').click();
    
    // Roster should either show table or a no enrollees info block
    const isTableVisible = await page.locator('#bulkPromotionForm').isVisible();
    const isPlaceholderVisible = await page.locator('#bulkRosterPlaceholder').isVisible();
    expect(isTableVisible || isPlaceholderVisible).toBe(true);
  });

  test('promotion history log allows reverting a recent promotion', async ({ page }) => {
    requireLocalWrites();
    
    // First, promote a learner to ensure we have a log entry
    const data = learnerData();
    const promotedSchoolYear = nextSchoolYear(data.schoolYear);
    await createGeneratedLearner(page, data);

    await page.goto('/promotion.php');
    await page.locator('#promoteSearchInput').fill(data.lrn);

    const result = page.locator('#promoteSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await page.locator('select[name="school_year"]').selectOption(promotedSchoolYear);
    await page.locator('#indPlacement').selectOption('Graded');
    const nextGradeSelect = page.locator('#nextGradeLevel');
    if (await nextGradeSelect.isVisible()) {
      await nextGradeSelect.selectOption({ index: 1 });
    }
    await page.locator('button[type="submit"]:has-text("Promote Learner")').click();
    await page.locator('#btnConfirmSinglePromote').click();
    await expect(page.locator('.alert-success').first()).toContainText(/promoted/i);

    // Click Log tab and attempt revert
    await page.locator('#log-tab').click();
    await expect(page.locator('#log-pane')).toBeVisible();

    // Revert the top item
    page.once('dialog', dialog => dialog.accept());
    await page.locator('#log-pane tbody tr').first().locator('button:has-text("Revert")').click();

    await expect(page.locator('.alert-success').first()).toContainText(/reverted successfully/i);
  });

  test('not yet promoted tab displays pending enrollees and allows selection', async ({ page }) => {
    requireLocalWrites();
    await page.goto('/promotion.php');
    await page.locator('#unpromoted-tab').click();

    await expect(page.locator('#unpromoted-pane')).toBeVisible();

    // Verify the Promote quick action button redirects and fills single form
    const tbodyText = await page.locator('#unpromoted-pane tbody').innerText();
    const rowCount = await page.locator('#unpromoted-pane tbody tr').count();
    
    if (rowCount > 0 && !tbodyText.includes('All active enrollees have been promoted')) {
      const promoteBtn = page.locator('#unpromoted-pane tbody tr').first().locator('.btn-select-to-promote');
      await promoteBtn.click();
      
      // Should automatically navigate back to Single tab and trigger learner load
      await expect(page.locator('#single-pane')).toBeVisible();
      await expect(page.locator('#promoteSearchInput')).not.toBeEmpty();
    }
  });
});
