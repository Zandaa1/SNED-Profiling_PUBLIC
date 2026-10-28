const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { requireLocalWrites } = require('./helpers/guards');
const { learnerData } = require('./helpers/data');
const { getRoleCredentials, getLearnership, loadFixtures } = require('./helpers/fixtures');
const { fillCoreLearnerFields, fillPlacementFields, fillFamilyFields, createGeneratedLearner, submitLearnerForm } = require('./helpers/learner');

const MIN_TEST_DURATION_MS = 6_500;

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('learnerForm.php', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }, testInfo) => {
    testInfo.startedAt = Date.now();
    await login(page, getRoleCredentials('superadmin'));
  });

  test.afterEach(async ({}, testInfo) => {
    const elapsed = Date.now() - (testInfo.startedAt || Date.now());
    const waitMs = Math.max(0, MIN_TEST_DURATION_MS - elapsed);
    if (waitMs > 0) {
      await new Promise((resolve) => setTimeout(resolve, waitMs));
    }
  });

  test('create form loads core controls and blocks invalid LRN before advancing', async ({ page }) => {
    await page.goto('/learnerForm.php');

    await expect(page.getByRole('heading', { name: /register new learner/i })).toBeVisible();
    await expect(page.locator('#learnerForm')).toBeVisible();
    await expect(page.locator('#step-1')).toBeVisible();
    await expect(page.locator('#step-2')).toHaveClass(/d-none/);

    await expect(page.locator('#lrn')).toBeVisible();
    await expect(page.locator('#first_name')).toBeVisible();
    await expect(page.locator('#last_name')).toBeVisible();
    await expect(page.locator('#birthday')).toBeVisible();
    await expect(page.locator('#sex')).toBeVisible();

    await page.locator('#lrn').fill('abc123');
    await expect(page.locator('#lrn')).toHaveValue('123');
    await expect(page.locator('#lrnCounter')).toContainText('3 of 12 digits entered');

    await page.locator('#first_name').fill('Playwright');
    await page.locator('#last_name').fill('Validation');
    await page.locator('#birthday').fill('2015-01-15');
    await page.locator('#nextStepBtn').click();

    await expect(page.locator('#step-1')).toBeVisible();
    await expect(page.locator('#step-2')).toHaveClass(/d-none/);
    await expect(page.locator('#lrn')).toHaveClass(/is-invalid/);
    await expect(page.locator('#learnerFormSystemNotice')).toContainText(/please fill in all required fields correctly|validation error/i);
  });

  test('wizard sections expose placement and family controls', async ({ page }) => {
    await page.goto('/learnerForm.php');

    await page.evaluate(() => window.switchTab('placement-tab'));
    await expect(page.locator('#step-2')).toBeVisible();
    await expect(page.locator('#school_id')).toBeVisible();
    await expect(page.locator('#school_year')).toBeVisible();
    await expect(page.locator('#placement_type')).toBeVisible();
    await expect(page.locator('#grade_level')).toBeVisible();
    await expect(page.locator('#assess_mode_yes')).toBeAttached();
    await expect(page.locator('#assess_mode_no')).toBeAttached();

    await page.evaluate(() => window.switchTab('family-tab'));
    await expect(page.locator('#step-3')).toBeVisible();
    await expect(page.locator('#g_first_name')).toBeVisible();
    await expect(page.locator('#g_last_name')).toBeVisible();
    await expect(page.locator('#g_contact_number')).toBeVisible();
    await expect(page.locator('#house_no_street')).toBeVisible();
    await expect(page.locator('#barangay')).toBeVisible();
  });

  test('edit form loads an existing learner', async ({ page }) => {
    const learnership = getLearnership();
    let lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    if (!lrn) {
      requireLocalWrites();
      const data = learnerData();
      await createGeneratedLearner(page, data);
      lrn = data.lrn;
    }

    await page.goto(`/learnerForm.php?id=${encodeURIComponent(lrn)}`);

    await expect(page.getByRole('heading', { name: /edit learner profile/i })).toBeVisible();
    await expect(page.locator('#lrn')).toHaveValue(lrn);
    await expect(page.locator('#first_name')).not.toHaveValue('');
    await expect(page.locator('#last_name')).not.toHaveValue('');
  });

  test('assessment mode radios visually reset when switching yes then no', async ({ page }) => {
    const learnership = getLearnership();
    let lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    if (!lrn) {
      requireLocalWrites();
      const data = learnerData();
      await createGeneratedLearner(page, data);
      lrn = data.lrn;
    }

    const expectAssessmentTileState = async (selected) => {
      const selectedId = selected === 'yes' ? 'assess_mode_yes' : 'assess_mode_no';
      const otherId = selected === 'yes' ? 'assess_mode_no' : 'assess_mode_yes';

      await expect(page.locator(`#${selectedId}`)).toBeChecked();
      await expect(page.locator(`#${otherId}`)).not.toBeChecked();
      await expect(page.locator(`label[for="${selectedId}"] .tile-checkbox-icon-checked`)).toBeVisible();
      await expect(page.locator(`label[for="${selectedId}"] .tile-checkbox-icon-unchecked`)).toBeHidden();
      await expect(page.locator(`label[for="${otherId}"] .tile-checkbox-icon-checked`)).toBeHidden();
      await expect(page.locator(`label[for="${otherId}"] .tile-checkbox-icon-unchecked`)).toBeVisible();
    };

    await page.goto(`/learnerForm.php?id=${encodeURIComponent(lrn)}`);
    await page.evaluate(() => window.switchTab('placement-tab'));
    await expect(page.locator('#step-2')).toBeVisible();

    await page.locator('label[for="assess_mode_no"]').click();
    await expectAssessmentTileState('no');

    const difficultyCheckbox = page.locator('input[name="disabilities[difficulty][]"][value="Hearing"]');
    const difficultyRow = difficultyCheckbox.locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " disability-row-container ")][1]');
    const difficultyPrimary = difficultyRow.locator('.js-primary-radio');
    await difficultyCheckbox.check({ force: true });
    await expect(difficultyPrimary).toBeEnabled();
    await difficultyPrimary.check({ force: true });
    await expect(difficultyPrimary).toBeChecked();

    await page.locator('label[for="assess_mode_yes"]').click();
    await expectAssessmentTileState('yes');
    await expect(difficultyCheckbox).not.toBeChecked();
    await expect(difficultyPrimary).toBeDisabled();
    await expect(difficultyPrimary).not.toBeChecked();

    const diagnosedCheckbox = page.locator('input[name="disabilities[diagnosed][]"][value="Visual Impairment"]');
    const diagnosedRow = diagnosedCheckbox.locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " disability-row-container ")][1]');
    const diagnosedPrimary = diagnosedRow.locator('.js-primary-radio');
    await diagnosedCheckbox.check({ force: true });
    await expect(diagnosedPrimary).toBeEnabled();
    await diagnosedPrimary.check({ force: true });
    await expect(diagnosedPrimary).toBeChecked();

    await page.locator('label[for="assess_mode_no"]').click();
    await expectAssessmentTileState('no');
    await expect(diagnosedCheckbox).not.toBeChecked();
    await expect(diagnosedPrimary).toBeDisabled();
    await expect(diagnosedPrimary).not.toBeChecked();

    await difficultyCheckbox.check({ force: true });
    await expect(difficultyPrimary).toBeEnabled();
    await difficultyPrimary.check({ force: true });
    await expect(difficultyPrimary).toBeChecked();
  });

  test('local write: blocks save when no primary parent or guardian is selected', async ({ page }) => {
    requireLocalWrites();

    const data = learnerData();

    await page.goto('/learnerForm.php');
    await fillCoreLearnerFields(page, data);
    await fillPlacementFields(page, data);

    await page.evaluate(() => window.switchTab('family-tab'));
    await expect(page.locator('#step-3')).toBeVisible();

    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await expect(page.locator('input[name="primary_guardian_source"]:checked')).toHaveCount(0);

    await submitLearnerForm(page);

    await expect(page).toHaveURL(/learnerForm\.php/);
    await expect(page.locator('#learnerFormSystemNotice')).toContainText(/select a primary parent\/guardian for forms/i);
  });

  test('local write: creates learner with mother as primary and blank guardian section', async ({ page }) => {
    requireLocalWrites();

    const data = learnerData();

    await page.goto('/learnerForm.php');
    await fillCoreLearnerFields(page, data);
    await fillPlacementFields(page, data);

    await page.evaluate(() => window.switchTab('family-tab'));
    await expect(page.locator('#step-3')).toBeVisible();

    await page.locator('#no_mother').uncheck({ force: true });
    await page.locator('#mo_first_name').fill('MotherPrimary');
    await page.locator('#mo_last_name').fill(data.guardianLastName);
    await page.locator('#mo_contact_number').fill(data.guardianContact);
    await page.locator('#mo_different_address').uncheck({ force: true });
    await page.locator('#primary_guardian_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });

    await expect(page.locator('#g_first_name')).toHaveValue('');
    await expect(page.locator('#g_last_name')).toHaveValue('');

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      submitLearnerForm(page),
    ]);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: creates then edits a generated learner', async ({ page }) => {
    requireLocalWrites();

    const data = learnerData();

    await createGeneratedLearner(page, data);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);

    await page.goto(`/learnerForm.php?id=${encodeURIComponent(data.lrn)}`);
    await expect(page.getByRole('heading', { name: /edit learner profile/i })).toBeVisible();
    await expect(page.locator('#lrn')).toHaveValue(data.lrn);

    await page.locator('#first_name').fill(`${data.firstName}_EDIT`);
    await fillPlacementFields(page, data);
    await fillFamilyFields(page, { ...data, guardianFirstName: `${data.guardianFirstName} Edit` });

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      submitLearnerForm(page),
    ]);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
