const { expect } = require('@playwright/test');
const { learnerData } = require('./data');
const { getLearnership } = require('./fixtures');
const { fillCustomAddress, selectFirstRealOption, selectIfOptionExists } = require('./forms');

async function fillCoreLearnerFields(page, data) {
  if (data.lrn !== undefined && data.lrn !== '') {
    const noLrn = page.locator('#no_lrn_yet');
    if (await noLrn.count() && await noLrn.isChecked()) {
      await noLrn.uncheck();
    }
    await page.locator('#lrn').fill(data.lrn);
  }
  await page.locator('#first_name').fill(data.firstName);
  await page.locator('#middle_name').fill(data.middleName);
  await page.locator('#last_name').fill(data.lastName);
  await page.locator('#birthday').fill(data.birthday);
  await page.locator('#sex').selectOption(data.sex);
}

async function fillPlacementFields(page, data) {
  await page.evaluate(() => window.switchTab('placement-tab'));
  await expect(page.locator('#step-2')).toBeVisible();

  if (!(await selectIfOptionExists(page.locator('#school_id'), data.schoolId))) {
    await selectFirstRealOption(page.locator('#school_id'));
  }
  await page.locator('#school_year').selectOption(data.schoolYear);
  await page.locator('#placement_type').selectOption('Non-Graded');
  await page.locator('#placement_type').dispatchEvent('change');
  await page.locator('#self_contained_level').selectOption('Primary Level 1');
  await page.locator('#assess_mode_no').check({ force: true });

  const difficulty = page.locator('input[name="disabilities[difficulty][]"]').first();
  await expect(difficulty).toBeAttached();
  await difficulty.check({ force: true });
}

async function fillFamilyFields(page, data) {
  await page.evaluate(() => window.switchTab('family-tab'));
  await expect(page.locator('#step-3')).toBeVisible();

  await page.locator('#no_mother').check({ force: true });
  await page.locator('#no_father').check({ force: true });

  await page.locator('#g_first_name').fill(data.guardianFirstName);
  await page.locator('#g_last_name').fill(data.guardianLastName);
  await page.locator('#g_relationship').selectOption('Parent');
  await page.locator('#g_contact_number').fill(data.guardianContact);
  await page.locator('#primary_guardian_guardian').check({ force: true });
  await page.locator('#house_no_street').fill(data.houseNoStreet);
  await fillCustomAddress(page, 'g');
}

async function submitLearnerForm(page) {
  await page.locator('#saveFormBtn').click();
}

async function createGeneratedLearner(page, data) {
  await page.goto('/learnerForm.php');
  await fillCoreLearnerFields(page, data);
  await fillPlacementFields(page, data);
  await fillFamilyFields(page, data);

  await Promise.all([
    page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
    submitLearnerForm(page),
  ]);
}

async function ensureAnyLrn(page) {
  const lrn = getLearnership().anyLrn;
  if (lrn) return lrn;
  const data = learnerData();
  await createGeneratedLearner(page, data);
  return data.lrn;
}

module.exports = { createGeneratedLearner, ensureAnyLrn, fillCoreLearnerFields, fillPlacementFields, fillFamilyFields, submitLearnerForm };
