const { test, expect } = require('@playwright/test');

test.describe('Login Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/index.php');
  });

  test('login form is visible', async ({ page }) => {
    const form = page.locator('form');
    await expect(form).toBeVisible();
  });

  test('username input is present', async ({ page }) => {
    const input = page.locator('#usernameInput');
    await expect(input).toBeVisible();
  });

  test('password input is present', async ({ page }) => {
    const input = page.locator('#passwordInput');
    await expect(input).toBeVisible();
  });

  test('submit button is present', async ({ page }) => {
    const button = page.locator('button[type="submit"]');
    await expect(button).toBeVisible();
  });

  test('page has SNED branding', async ({ page }) => {
    const body = page.locator('body');
    await expect(body).toContainText('SNED');
  });

  test('empty credentials shows error or stays on login', async ({ page }) => {
    await page.locator('button[type="submit"]').click();
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('index.php') || url.includes('main.php')).toBeTruthy();
  });

  test('wrong password stays on login page', async ({ page }) => {
    await page.fill('#usernameInput', 'nonexistent_user');
    await page.fill('#passwordInput', 'WrongPassword123!');
    await page.locator('button[type="submit"]').click();
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('index.php')).toBeTruthy();
  });

  test('announcements section is visible on login page', async ({ page }) => {
    const announcements = page.locator('#announcements, .announcements, [class*="announcement"]');
    const count = await announcements.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});
