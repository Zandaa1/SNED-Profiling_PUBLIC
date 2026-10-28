const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('teacherManagement.php — Teacher CRUD', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('page loads with teacher management UI', async ({ page }) => {
    await page.goto('/teacherManagement.php');
    await expect(page.getByRole('heading', { name: /teacher management/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /add teacher/i })).toBeVisible();
  });

  test('local write: opens create teacher modal', async ({ page }) => {
    requireLocalWrites();

    await page.goto('/teacherManagement.php');
    await page.getByRole('button', { name: /add teacher/i }).click();

    // Wait for modal to appear
    await expect(page.locator('#createTeacherModal')).toHaveClass(/show/, { timeout: 5_000 });

    // Verify form fields exist in the modal
    await expect(page.locator('#createFullName')).toBeVisible();
    await expect(page.locator('#createUsername')).toBeVisible();
    await expect(page.locator('#createPassword')).toBeVisible();

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: creates a teacher account', async ({ page }) => {
    requireLocalWrites();

    const token = Date.now().toString(36);
    const fullName = `E2E Teacher ${token}`;
    const username = `e2e_tchr_${token}`;

    await page.goto('/teacherManagement.php');
    await page.getByRole('button', { name: /add teacher/i }).click();
    await expect(page.locator('#createTeacherModal')).toHaveClass(/show/, { timeout: 5_000 });

    await page.locator('#createFullName').fill(fullName);
    await page.locator('#createUsername').fill(username);
    await page.locator('#createPassword').fill('E2ETest123!');

    // The create button calls createTeacher() JS function
    await page.locator('#createTeacherModal .btn-primary').click();

    // Wait for response
    await page.waitForTimeout(3_000);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: opens manage learners for a teacher', async ({ page }) => {
    requireLocalWrites();

    await page.goto('/teacherManagement.php');

    // Find and click a manage/assign button
    const manageBtn = page.locator('button:has-text("Manage"), button:has-text("Assign"), a:has-text("Manage")').first();
    if (await manageBtn.count() === 0) {
      test.skip(true, 'No manage button found — no teachers in list.');
      return;
    }

    await manageBtn.click();
    await page.waitForTimeout(2_000);

    // Verify the page didn't crash — modal, offcanvas, or inline panels may appear
    const body = await page.locator('body').textContent();
    expect(body).toMatch(/assigned|available|learner/i);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });
});
