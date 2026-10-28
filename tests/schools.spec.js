const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('schools.php', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('directory loads with search input', async ({ page }) => {
    await page.goto('/schools.php');
    await expect(page.locator('h1.page-title')).toContainText(/school directory/i);
    await expect(page.locator('#schoolSearchInput')).toBeVisible();
    await expect(page.locator('#schoolsCardGrid')).toBeVisible();
  });

  test('add school modal opens with form', async ({ page }) => {
    await page.goto('/schools.php');
    await page.locator('button[data-bs-target="#addSchoolModal"]').click();
    await expect(page.locator('#addSchoolModal')).toHaveClass(/show/);
    await expect(page.locator('#addSchoolModal input[name="school_name"]')).toBeVisible();
    await expect(page.locator('#addSchoolModal select[name="district"]')).toBeVisible();
  });

  test('local write: adds a new school', async ({ page }) => {
    requireLocalWrites();

    const token = Date.now().toString(36);
    const schoolName = `PWTEST School ${token}`;

    await page.goto('/schools.php');
    await page.locator('button[data-bs-target="#addSchoolModal"]').click();
    await expect(page.locator('#addSchoolModal')).toHaveClass(/show/);

    await page.locator('#addSchoolModal input[name="school_name"]').fill(schoolName);
    await page.locator('#addSchoolModal input[name="address"]').fill(`${token} Test Street`);
    await page.locator('#addElem').check({ force: true });
    await page.waitForTimeout(300);
    await page.locator('#addDistrict').selectOption('Central District');
    await page.locator('#addSchoolModal button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/school/i);
    await expect(page.locator('#schoolsCardGrid')).toContainText(new RegExp(schoolName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));
  });
});
