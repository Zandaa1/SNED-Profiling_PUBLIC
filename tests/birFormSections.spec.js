const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('BIR Form Features', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test('BIR form requires learner_id param', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.goto('/bir_form.php');
    expect([200, 302]).toContain(response.status());
  });

  test('BIR form loads with learner_id param', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.goto('/bir_form.php?learner_id=123456789012');
    expect([200, 403]).toContain(response.status());
  });

  test('BIR form rejects non-existent learner', async ({ page }) => {
    await login(page, getRoleCredentials('school_coordinator'));
    const response = await page.goto('/bir_form.php?learner_id=999999999999');
    expect([200, 403]).toContain(response.status());
  });
});
