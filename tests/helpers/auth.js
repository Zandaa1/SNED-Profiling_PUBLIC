const { test } = require('@playwright/test');

async function login(page, credentials = {}) {
  const username = credentials.username || process.env.E2E_USERNAME;
  const password = credentials.password || process.env.E2E_PASSWORD;

  test.skip(!username || !password, 'Set E2E_USERNAME and E2E_PASSWORD to run e2e tests.');

  // Pre-set privacy acknowledgment before any page load to prevent modal from showing
  await page.addInitScript(() => {
    try { localStorage.setItem('privacyAcknowledged', '1'); } catch {}
  });

  await page.goto('/index.php');

  if (!page.url().includes('/index.php')) {
    return;
  }

  await page.locator('#usernameInput').fill(username);
  await page.locator('#passwordInput').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/index.php'), { timeout: 15_000 }),
    page.getByRole('button', { name: /log in/i }).click(),
  ]);

  test.skip(page.url().includes('/2fa_verify.php'), 'The configured test account requires 2FA. Use a non-2FA test account.');
}

async function dismissPrivacyNotice(page) {
  try {
    // Ensure localStorage flag is set
    await page.evaluate(() => {
      try { localStorage.setItem('privacyAcknowledged', '1'); } catch {}
    });

    // If modal is already visible, dismiss it
    const btn = page.locator('#privacyAcknowledgeBtn');
    if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await page.locator('#privacyAcknowledgeCheck').check({ force: true, timeout: 2000 });
      await btn.click({ force: true, timeout: 2000 });
    }
  } catch {
    // Privacy notice may not be shown or already dismissed
  }
}

module.exports = { login, dismissPrivacyNotice };
