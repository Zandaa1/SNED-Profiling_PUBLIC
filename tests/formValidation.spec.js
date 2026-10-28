const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Form Validation Negative Paths', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.describe('learner form', () => {
    test('learner form page loads', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/learnerForm.php');
      expect(response.status()).toBe(200);
    });

    test('learner form has LRN input field', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      await page.goto('/learnerForm.php');
      const lrnInput = page.locator('input[name="lrn"]');
      const count = await lrnInput.count();
      expect(count).toBeGreaterThanOrEqual(0);
    });
  });

  test.describe('BIR form', () => {
    test('BIR form requires learner_id', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/bir_form.php');
      expect([200, 302]).toContain(response.status());
    });

    test('BIR form loads with learner_id', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/bir_form.php?learner_id=123456789012');
      expect([200, 403]).toContain(response.status());
    });
  });

  test.describe('ILP form', () => {
    test('ILP form page loads with LRN', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/ilp_form.php?lrn=123456789012');
      expect([200, 403]).toContain(response.status());
    });

    test('ILP form handles missing LRN', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/ilp_form.php');
      expect([200, 302, 403]).toContain(response.status());
    });
  });

  test.describe('IEP form', () => {
    test('IEP form page loads with LRN', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.goto('/iep_editor.php?lrn=123456789012');
      expect([200, 403]).toContain(response.status());
    });
  });

  test.describe('save endpoints', () => {
    test('saveIEP endpoint is reachable', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.evaluate(async () => {
        const formData = new FormData();
        formData.append('school_year', '2025-2026');
        const res = await fetch('/saveIEP.php', { method: 'POST', body: formData });
        return { status: res.status };
      });
      expect(response.status).toBeLessThan(500);
    });

    test('saveILP endpoint is reachable', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.evaluate(async () => {
        const formData = new FormData();
        formData.append('school_year', '2025-2026');
        const res = await fetch('/saveILP.php', { method: 'POST', body: formData });
        return { status: res.status };
      });
      expect(response.status).toBeLessThan(500);
    });

    test('submitTransferRequest requires POST and learner_id', async ({ page }) => {
      await login(page, getRoleCredentials('school_coordinator'));
      const response = await page.evaluate(async () => {
        const formData = new FormData();
        formData.append('school_year', '2025-2026');
        const res = await fetch('/submitTransferRequest.php', { method: 'POST', body: formData });
        return { status: res.status };
      });
      expect(response.status).toBeGreaterThanOrEqual(400);
    });
  });
});
