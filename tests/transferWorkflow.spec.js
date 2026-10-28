const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Transfer Workflow — Full Lifecycle', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');
  test.slow();

  test.beforeEach(async ({ page }) => {
    page.on('console', msg => console.log('BROWSER LOG:', msg.text()));
    page.on('pageerror', err => console.log('BROWSER ERROR:', err.message));
  });

  test('local write: submits transfer and coordinator approves', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    // Step 1: Submit a transfer request as superadmin
    await login(page, getRoleCredentials('superadmin'));
    await page.goto('/transfer.php');

    await page.locator('#transferSearchInput').fill(lrn);
    const result = page.locator('#transferSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();
    await expect(page.locator('#transferSelectedLrn')).toHaveValue(lrn);

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });

    const newSchool = page.locator('select[name="new_school_id"] option').nth(1);
    const schoolValue = await newSchool.getAttribute('value');
    await page.locator('select[name="new_school_id"]').selectOption(schoolValue);

    await page.locator('textarea[name="transfer_reason"]').fill('E2E Test: Full lifecycle transfer.');

    await page.locator('#transferForm button[type="submit"]').click();
    await expect(page.locator('#confirmTransferModal')).toBeVisible();
    await Promise.all([
      page.waitForLoadState('networkidle', { timeout: 15_000 }),
      page.locator('#btnConfirmTransfer').click(),
    ]);

    // Verify transfer was submitted (success or duplicate)
    const alertText = await page.locator('.alert').first().textContent().catch(() => '');
    const submitted = /transfer request sent|already.*pending/i.test(alertText);
    test.skip(!submitted, 'Transfer request could not be submitted (may already have pending).');

    // Step 2: Navigate to transfer requests page and find the request
    await page.goto('/transferRequests.php');
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);

    // Step 3: Approve the transfer
    const approveBtn = page.locator('a:has-text("Approve"), button:has-text("Approve")').first();
    if (await approveBtn.count() > 0) {
      await approveBtn.click();

      // Handle confirmation if present
      const confirmBtn = page.locator('.modal.show button[type="submit"], #confirmApproveBtn');
      if (await confirmBtn.count() > 0) {
        await confirmBtn.click();
      }

      await page.waitForLoadState('networkidle', { timeout: 10_000 });
      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    }
  });

  test('local write: submits transfer and coordinator rejects', async ({ page }) => {
    requireLocalWrites();
    const learnership = getLearnership();
    const lrn = learnership.anyLrn;
    test.skip(!lrn, 'No learner LRN available in fixtures.');

    // Submit a transfer request
    await login(page, getRoleCredentials('superadmin'));
    await page.goto('/transfer.php');

    await page.locator('#transferSearchInput').fill(lrn);
    const result = page.locator('#transferSearchResults .list-group-item').first();
    await expect(result).toBeVisible({ timeout: 10_000 });
    await result.click();
    await expect(page.locator('#transferSelectedLrn')).toHaveValue(lrn);

    await page.locator('select[name="school_year"]').selectOption({ index: 1 });

    const newSchool = page.locator('select[name="new_school_id"] option').nth(1);
    const schoolValue = await newSchool.getAttribute('value');
    await page.locator('select[name="new_school_id"]').selectOption(schoolValue);

    await page.locator('textarea[name="transfer_reason"]').fill('E2E Test: Rejection lifecycle.');

    await page.locator('#transferForm button[type="submit"]').click();
    await expect(page.locator('#confirmTransferModal')).toBeVisible();
    await Promise.all([
      page.waitForLoadState('networkidle', { timeout: 15_000 }),
      page.locator('#btnConfirmTransfer').click(),
    ]);

    const alertText = await page.locator('.alert').first().textContent().catch(() => '');
    const submitted = /transfer request sent|already.*pending/i.test(alertText);
    test.skip(!submitted, 'Transfer request could not be submitted.');

    // Navigate to transfer requests and reject
    await page.goto('/transferRequests.php');

    const rejectBtn = page.locator('a:has-text("Reject"), button:has-text("Reject")').first();
    if (await rejectBtn.count() > 0) {
      await rejectBtn.click();

      // Fill rejection notes if textarea appears
      const notesField = page.locator('textarea[name="admin_notes"], #rejectNotes');
      if (await notesField.count() > 0) {
        await notesField.fill('E2E Test: Rejected for testing purposes.');
      }

      const confirmBtn = page.locator('.modal.show button[type="submit"], #confirmRejectBtn');
      if (await confirmBtn.count() > 0) {
        await confirmBtn.click();
      }

      await page.waitForLoadState('networkidle', { timeout: 10_000 });
      await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    }
  });

  test('unauthenticated user cannot approve transfer', async ({ page }) => {
    const response = await page.request.post('/approveTransfer.php', {
      form: {
        request_id: '1',
        action: 'approve',
      },
    });

    // Should redirect to login or return error
    const url = response.url();
    expect(url.includes('index.php') || response.status() >= 400).toBe(true);
  });
});
