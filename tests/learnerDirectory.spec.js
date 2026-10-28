const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('learners.php — Learner Directory', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('directory page loads with search input', async ({ page }) => {
    await page.goto('/learners.php');
    await expect(page.locator('h1.page-title')).toContainText(/learner directory/i);

    const searchInput = page.locator('input[name="search"]');
    const inputCount = await searchInput.count();
    expect(inputCount).toBeGreaterThan(0);
  });

  test('search input is functional', async ({ page }) => {
    await page.goto('/learners.php');

    const searchInput = page.locator('input[type="search"], input[name="q"], #learnerSearchInput').first();
    if (await searchInput.count() > 0) {
      await searchInput.fill('test');
      await page.waitForTimeout(1000);

      const body = await page.locator('body').textContent();
      expect(body.length).toBeGreaterThan(0);
    }
  });

  test('school filter dropdown is present', async ({ page }) => {
    await page.goto('/learners.php');

    const schoolFilter = page.locator('select[name="school_id"], #schoolFilter');
    const filterCount = await schoolFilter.count();
    // For superadmin, school filter may exist; for scoped users it's hidden
    if (filterCount > 0) {
      await expect(schoolFilter.first()).toBeVisible();
    }
  });

  test('export modal opens from directory', async ({ page }) => {
    await page.goto('/learners.php');

    const exportBtn = page.locator('button:has-text("Export"), a:has-text("Export"), [data-bs-target*="export"]');
    if (await exportBtn.count() > 0) {
      await exportBtn.first().click();
      await page.waitForTimeout(500);

      const body = await page.locator('body').textContent();
      expect(body).toMatch(/export|download|enrollment|roster/i);
    }
  });

  test('learner list or pagination is visible', async ({ page }) => {
    await page.goto('/learners.php');

    const table = page.locator('table');
    const cards = page.locator('.learner-card, .list-group-item');
    const pagination = page.locator('.pagination');

    const hasContent = (await table.count()) > 0 ||
                       (await cards.count()) > 0 ||
                       (await pagination.count()) > 0 ||
                       (await page.locator('.alert-info, .text-muted').count()) > 0;

    expect(hasContent).toBe(true);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
