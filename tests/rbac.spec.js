const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

const ROLES = ['superadmin', 'admin_office', 'school_coordinator', 'teacher'];

function roleMatches(access, role) {
  if (access === 'all') return true;
  if (access === 'admin') return role === 'superadmin' || role === 'admin_office';
  if (access === 'superadmin') return role === 'superadmin';
  if (access === 'coord_not_teacher') return role !== 'teacher';
  return false;
}

const PAGES = [
  { path: '/main.php',               access: 'all' },
  { path: '/learners.php',           access: 'all' },
  { path: '/school_profile.php',     access: 'all' },
  { path: '/itp_form.php',           access: 'all' },
  { path: '/sf9_form.php',           access: 'all' },
  { path: '/blankForm.php',          access: 'all' },
  { path: '/quickguide.php',         access: 'all' },
  { path: '/learnerForm.php',        access: 'all' },
  { path: '/viewLearner.php',        access: 'all' },
  { path: '/iep_editor.php',         access: 'all' },
  { path: '/ilp_form.php',           access: 'all' },
  { path: '/bir_form.php',           access: 'all' },
  { path: '/promotion.php',          access: 'all' },

  { path: '/teacherManagement.php',  access: 'coord_not_teacher', denyMain: true },
  { path: '/transfer.php',           access: 'coord_not_teacher', deny403: true },
  { path: '/transferRequests.php',   access: 'coord_not_teacher', deny403: true },

  { path: '/schools.php',            access: 'admin',             deny403: true },
  { path: '/announcements.php',      access: 'superadmin',       deny403: true },

  { path: '/adminCreateUser.php',    access: 'admin',            denyMain: true },
  { path: '/trash.php',              access: 'admin',            denyMain: true },
  { path: '/transaction_logs.php',   access: 'admin',            denyMain: true },
];

test.describe('Page access by role', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  for (const role of ROLES) {
    test.describe(`role=${role}`, () => {
      test.beforeEach(async ({ page }) => {
        await login(page, getRoleCredentials(role));
      });

      for (const pageDef of PAGES) {
        test(`${roleMatches(pageDef.access, role) ? 'can' : 'cannot'} access ${pageDef.path}`, async ({ page }) => {
          const allowed = roleMatches(pageDef.access, role);

          await page.goto(pageDef.path);

          if (allowed) {
            await expect(page.locator('body')).not.toContainText(/access denied/i);
            expect(page.url()).not.toContain('/403.php');
          } else if (pageDef.deny403) {
            await expect(page.locator('.error-card h3, h3:has-text("Access Denied")')).toContainText(/access denied/i);
          } else if (pageDef.denyMain) {
            await expect(page).toHaveURL(/main\.php/);
          }
        });
      }
    });
  }
});
