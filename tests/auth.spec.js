const { test, expect } = require('@playwright/test');
const { login, dismissPrivacyNotice } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Authentication', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('login page loads with expected form elements', async ({ page }) => {
    await page.goto('/index.php');
    await expect(page.locator('#usernameInput')).toBeVisible();
    await expect(page.locator('#passwordInput')).toBeVisible();
    await expect(page.getByRole('button', { name: /log in/i })).toBeVisible();
    await expect(page.locator('h1')).toContainText(/ValSNED/i);
  });

  test('successful login redirects to main.php', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await page.goto('/index.php');
    await page.locator('#usernameInput').fill(creds.username);
    await page.locator('#passwordInput').fill(creds.password);
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL(/main\.php/);
    await dismissPrivacyNotice(page);
  });

  test('invalid credentials shows error and stays on login', async ({ page }) => {
    await page.goto('/index.php');
    await page.locator('#usernameInput').fill('pwtest_superadmin');
    await page.locator('#passwordInput').fill('WrongPassword!');
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL(/index\.php/);
    await expect(page.locator('.card .alert-danger')).toBeVisible();
  });

  test('already logged-in user is redirected to main.php', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);
    await page.goto('/index.php');
    await expect(page).toHaveURL(/main\.php/);
  });

  test('logout destroys session and redirects to login', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);
    await page.goto('/logout.php');
    await expect(page).toHaveURL(/index\.php/);

    await page.goto('/main.php');
    await expect(page).toHaveURL(/index\.php/);
  });

  test('visiting any protected page without auth redirects to index.php', async ({ page }) => {
    await page.goto('/main.php');
    await expect(page).toHaveURL(/index\.php/);

    await page.goto('/schools.php');
    await expect(page).toHaveURL(/index\.php\b/);

    await page.goto('/learners.php');
    await expect(page).toHaveURL(/index\.php\b/);
  });
});
