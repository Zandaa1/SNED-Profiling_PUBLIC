const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { learnerData } = require('./helpers/data');
const { fillCustomAddress } = require('./helpers/forms');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('trash.php — Archived Record Recovery', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('trash page loads with tab switcher', async ({ page }) => {
    await page.goto('/trash.php');
    await expect(page.locator('h5')).toContainText(/trash/i);
    await expect(page.locator('a.trash-tab[href*="type=learners"]')).toBeVisible();
    await expect(page.locator('a.trash-tab[href*="type=enrollments"]')).toBeVisible();
  });

  test('enrollments tab loads', async ({ page }) => {
    await page.goto('/trash.php?type=enrollments');
    await expect(page.locator('a.trash-tab.active')).toContainText(/enrollment/i);
  });

  test('local write: archives then restores a learner', async ({ page }) => {
    requireLocalWrites();

    const data = learnerData();
    const lrn = data.lrn;

    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(data.lrn);
    await page.locator('#first_name').fill(data.firstName);
    await page.locator('#last_name').fill(data.lastName);
    await page.locator('#middle_name').fill(data.middleName);
    await page.locator('#birthday').fill(data.birthday);
    await page.locator('#sex').selectOption(data.sex);

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');
    await page.locator('#assess_mode_no').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"]').first().check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await page.locator('#g_first_name').fill(data.guardianFirstName);
    await page.locator('#g_last_name').fill(data.guardianLastName);
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill(data.guardianContact);
    await page.locator('#house_no_street').fill(data.houseNoStreet);
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    await page.goto('/learners.php');
    await page.evaluate((lrnVal) => window.confirmArchive(lrnVal, 'Test Name'), lrn);
    await page.locator('#archiveConfirmBtn').click();
    await expect(page.locator('.alert-success')).toContainText(/archived/i);

    await page.goto('/trash.php');
    await expect(page.locator('body')).toContainText(lrn);

    const restoreForm = page.locator(`form:has(input[value="${lrn}"])`);
    await expect(restoreForm.locator('button[type="submit"]')).toContainText(/restore/i);

    await restoreForm.locator('button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/restored successfully/i);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: archive and restore via enrollments tab', async ({ page }) => {
    requireLocalWrites();

    const data = learnerData();
    const lrn = data.lrn;

    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(data.lrn);
    await page.locator('#first_name').fill(data.firstName);
    await page.locator('#last_name').fill(data.lastName);
    await page.locator('#middle_name').fill(data.middleName);
    await page.locator('#birthday').fill(data.birthday);
    await page.locator('#sex').selectOption(data.sex);

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');
    await page.locator('#assess_mode_no').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"]').first().check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await page.locator('#g_first_name').fill(data.guardianFirstName);
    await page.locator('#g_last_name').fill(data.guardianLastName);
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill(data.guardianContact);
    await page.locator('#house_no_street').fill(data.houseNoStreet);
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    await page.goto('/learners.php');
    await page.evaluate((lrnVal) => window.confirmArchive(lrnVal, 'Test Name'), lrn);
    await page.locator('#archiveConfirmBtn').click();
    await expect(page.locator('.alert-success')).toContainText(/archived/i);

    await page.goto('/trash.php?type=enrollments');
    await expect(page.locator('body')).toContainText(data.firstName);

    const restoreForm = page.locator(`form:has(button:has-text("Restore"))`).first();
    await expect(restoreForm).toBeVisible();

    await restoreForm.locator('button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/restored successfully/i);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
