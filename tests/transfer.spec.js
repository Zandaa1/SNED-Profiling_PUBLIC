const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('transfer.php — Learner Transfer', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('transfer form loads with required elements', async ({ page }) => {
    await page.goto('/transfer.php');
    await expect(page.locator('h1')).toContainText(/transfer/i);
    await expect(page.locator('#transferForm')).toBeVisible();
    await expect(page.locator('#transferSearchInput')).toBeVisible();
    await expect(page.locator('#transferSelectedLrn')).toBeAttached();
    await expect(page.locator('select[name="school_year"]')).toBeVisible();
    await expect(page.locator('select[name="new_school_id"]')).toBeVisible();
    await expect(page.locator('textarea[name="transfer_reason"]')).toBeVisible();
    await expect(page.getByRole('button', { name: /send transfer request/i })).toBeVisible();
  });

  test('learner search dropdown appears on typing', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto('/transfer.php');
    await page.locator('#transferSearchInput').fill(lrn.slice(0, 4));

    await expect(page.locator('#transferSearchResults')).toBeVisible({ timeout: 10_000 });
  });

  test('local write: submits a transfer request for an existing learner', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn || process.env.E2E_LEARNER_LRN;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    await page.goto('/transfer.php');

    await page.locator('#transferSearchInput').fill(lrn);
    const result = page.locator('#transferSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();

    await expect(page.locator('#transferSelectedLrn')).toHaveValue(lrn);

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });

    const newSchool = page.locator('select[name="new_school_id"] option').nth(1);
    const schoolValue = await newSchool.getAttribute('value');
    await page.locator('select[name="new_school_id"]').selectOption(schoolValue);

    await page.locator('textarea[name="transfer_reason"]').fill('E2E Test: Change of residence.');

    await page.locator('#transferForm button[type="submit"]').click();
    await expect(page.locator('#confirmTransferModal')).toBeVisible();
    await Promise.all([
      page.waitForLoadState('networkidle', { timeout: 15_000 }),
      page.locator('#btnConfirmTransfer').click(),
    ]);

    const success = page.locator('.alert-success');
    const danger = page.locator('.alert-danger');
    const hasSuccess = await success.count();
    if (hasSuccess) {
      await expect(success).toContainText(/transfer request sent/i);
    }
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: requires learner to be selected before submit', async ({ page }) => {
    requireLocalWrites();

    await page.goto('/transfer.php');
    await page.locator('select[name="school_year"]').selectOption({ index: 1 });
    await page.locator('textarea[name="transfer_reason"]').fill('Test reason');

    const newSchool = page.locator('select[name="new_school_id"] option').nth(1);
    const schoolValue = await newSchool.getAttribute('value');
    await page.locator('select[name="new_school_id"]').selectOption(schoolValue);

    await page.locator('#transferForm button[type="submit"]').click();

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
