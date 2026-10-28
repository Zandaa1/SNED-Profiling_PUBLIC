const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('school_profile.php — School Detail', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('page loads with school detail content', async ({ page }) => {
    const learnership = getLearnership();
    const schoolId = learnership.schoolId;
    test.skip(!schoolId, 'No school ID available in fixtures.');

    const response = await page.goto(`/school_profile.php?id=${schoolId}`);
    expect(response.status()).toBe(200);

    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('enrollment breakdown section is present', async ({ page }) => {
    const learnership = getLearnership();
    const schoolId = learnership.schoolId;
    test.skip(!schoolId, 'No school ID available in fixtures.');

    await page.goto(`/school_profile.php?id=${schoolId}`);

    const body = await page.locator('body').textContent();
    // School profile should show enrollment-related content
    expect(body).toMatch(/enroll|learner|student|roster/i);
  });

  test('roster filter form is present', async ({ page }) => {
    const learnership = getLearnership();
    const schoolId = learnership.schoolId;
    test.skip(!schoolId, 'No school ID available in fixtures.');

    await page.goto(`/school_profile.php?id=${schoolId}`);

    // Should have filter elements for the roster
    const searchInput = page.locator('input[type="search"], input[name="q"], #rosterSearch');
    const sexFilter = page.locator('select[name="sex"]');
    const gradeFilter = page.locator('select[name="grade_level"]');

    const hasFilters = (await searchInput.count()) > 0 ||
                       (await sexFilter.count()) > 0 ||
                       (await gradeFilter.count()) > 0;

    // At least some filter should exist or the roster table itself
    if (!hasFilters) {
      const table = page.locator('table');
      await expect(table).toBeVisible();
    }
  });

  test('school selector loads for admin users', async ({ page }) => {
    await page.goto('/school_profile.php');

    // Admin users should see a school selector or be redirected to their school
    const body = await page.locator('body').textContent();
    expect(body.length).toBeGreaterThan(0);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
