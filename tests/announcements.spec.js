const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('announcements.php — Announcements CRUD', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('page loads with announcement management UI', async ({ page }) => {
    await page.goto('/announcements.php');
    await expect(page.locator('h1')).toContainText(/announcements/i);
    await expect(page.getByRole('button', { name: /new announcement/i }).first()).toBeVisible();
  });

  test('create modal opens and has required fields', async ({ page }) => {
    await page.goto('/announcements.php');
    await page.getByRole('button', { name: /new announcement/i }).first().click();
    await expect(page.locator('#addAnnouncementModal')).toBeVisible();
    await expect(page.locator('#addTitle')).toBeVisible();
    await expect(page.locator('#addContent')).toBeVisible();
    await expect(page.locator('#addAnnouncementModal button[type="submit"]')).toContainText(/publish announcement/i);
  });

  test('local write: creates, edits, then deletes an announcement', async ({ page }) => {
    requireLocalWrites();

    const token = Date.now().toString(36);
    const title = `E2E Test Announcement ${token}`;
    const content = `This is a test announcement created by Playwright at ${new Date().toISOString()}.`;
    const editedTitle = `E2E Edited ${token}`;
    const editedContent = `Edited content for ${token}.`;

    await page.goto('/announcements.php');

    await page.getByRole('button', { name: /new announcement/i }).first().click();
    await expect(page.locator('#addAnnouncementModal')).toBeVisible();

    await page.locator('#addTitle').fill(title);
    await page.locator('#addContent').fill(content);
    await page.locator('#addAnnouncementModal button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/announcement published/i);
    await expect(page.locator('.announcement-active-panel')).toContainText(title);

    const editButton = page.locator('.announcement-active-panel button:has-text("Edit Active")');
    await expect(editButton).toBeVisible();
    await editButton.click();
    await expect(page.locator('#editAnnouncementModal')).toBeVisible();

    await page.locator('#editTitle').fill(editedTitle);
    await page.locator('#editContent').fill(editedContent);
    await page.locator('#editActive').uncheck({ force: true });
    await page.locator('#editAnnouncementModal button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/announcement updated/i);
    await expect(page.locator('.announcement-board')).toContainText(editedTitle);

    const deleteButton = page.locator(`.announcement-card:has(.announcement-card__title:text-is("${editedTitle}")) .btn-row-archive`);
    await expect(deleteButton).toBeVisible();
    await deleteButton.click();
    await expect(page.locator('#confirmDeleteModal')).toBeVisible();

    await page.locator('#confirmDeleteModal button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/announcement removed/i);
    await expect(page.locator('body')).not.toContainText(editedTitle);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: creates announcement with warning type', async ({ page }) => {
    requireLocalWrites();

    const token = Date.now().toString(36);
    const title = `E2E Warning Announcement ${token}`;

    await page.goto('/announcements.php');
    await page.getByRole('button', { name: /new announcement/i }).first().click();
    await expect(page.locator('#addAnnouncementModal')).toBeVisible();

    await page.locator('#addTitle').fill(title);
    await page.locator('#addContent').fill('This is a warning type test.');
    await page.locator('#addAnnouncementModal input[name="type"][value="warning"]').check({ force: true });
    await page.locator('#addAnnouncementModal button[type="submit"]').click();

    await expect(page.locator('.alert-success')).toContainText(/announcement published/i);
    await expect(page.locator('.announcement-active-panel')).toContainText(title);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: empty title shows validation error', async ({ page }) => {
    requireLocalWrites();

    await page.goto('/announcements.php');
    await page.getByRole('button', { name: /new announcement/i }).first().click();
    await expect(page.locator('#addAnnouncementModal')).toBeVisible();

    await page.locator('#addContent').fill('Content without title.');

    await page.locator('#addTitle').evaluate(el => el.removeAttribute('required'));
    await page.locator('#addAnnouncementModal button[type="submit"]').click();

    await expect(page.locator('body')).toContainText(/title and content are required/i);
  });
});
