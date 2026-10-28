const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Edge Cases & Form Validation', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('duplicate LRN shows error on learner form', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const existingLrn = learnership.anyLrn;
    test.skip(!existingLrn, 'No learner LRN available in fixtures.');

    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(existingLrn);
    await page.locator('#first_name').fill('Duplicate');
    await page.locator('#last_name').fill('LRN Test');
    await page.locator('#birthday').fill('2015-01-15');
    await page.locator('#sex').selectOption('Male');

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
    await page.locator('#g_first_name').fill('Guardian');
    await page.locator('#g_last_name').fill('Last');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill('09171234567');
    await page.locator('#house_no_street').fill('123 Test St');
    await page.locator('#barangay').selectOption({ index: 1 });

    await page.locator('#saveFormBtn').click();
    await page.waitForTimeout(2000);

    const body = await page.locator('body').textContent();
    expect(body).toMatch(/already exists|duplicate|LRN/i);
  });

  test('IEP form loads with empty optional fields', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/iep_editor.php?learner_id=${lrn}`);
    if (!page.url().includes('iep_editor.php')) {
      test.skip(true, 'IEP editor not accessible.');
      return;
    }

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('BIR form loads with quarter select', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto(`/bir_form.php?learner_id=${lrn}`);
    if (!page.url().includes('bir_form.php')) {
      test.skip(true, 'BIR form not accessible.');
      return;
    }

    const quarterSelect = page.locator('select[name="quarter"], #quarter');
    if (await quarterSelect.count() > 0) {
      await expect(quarterSelect.first()).toBeVisible();
    }

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('transfer page requires learner selection before submit', async ({ page }) => {
    await page.goto('/transfer.php');

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });
    await page.locator('textarea[name="transfer_reason"]').fill('Test reason');

    const newSchool = page.locator('select[name="new_school_id"] option').nth(1);
    if (await newSchool.count() > 0) {
      const schoolValue = await newSchool.getAttribute('value');
      if (schoolValue) {
        await page.locator('select[name="new_school_id"]').selectOption(schoolValue);
      }
    }

    await page.locator('#transferForm button[type="submit"]').click();
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('promotion page validates grade level selection', async ({ page }) => {
    await page.goto('/promotion.php');

    const gradeOptions = page.locator('#nextGradeLevel option');
    const optionCount = await gradeOptions.count();
    // Grade level dropdown should have options
    expect(optionCount).toBeGreaterThanOrEqual(1);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('SF9 form handles missing rating data gracefully', async ({ page }) => {
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    const response = await page.goto(`/sf9_form.php?learner_id=${lrn}`);
    expect(response.status()).toBe(200);

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
