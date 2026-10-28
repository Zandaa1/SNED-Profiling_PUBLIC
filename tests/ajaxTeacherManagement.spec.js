const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

async function getCsrfFromPage(page) {
  await page.goto('/teacherManagement.php');
  return page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : null;
  });
}

test.describe('ajax_teacher_management.php — API Tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('list_teachers returns teacher array with stats', async ({ page }) => {
    const response = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.success).toBe(true);
    expect(Array.isArray(body.teachers)).toBe(true);
    expect(typeof body.total_learners).toBe('number');
    expect(typeof body.assigned_learners).toBe('number');
    expect(typeof body.unassigned_learners).toBe('number');
  });

  test('create_teacher via API creates a new teacher', async ({ page }) => {
    const token = Date.now().toString(36);
    const csrf = await getCsrfFromPage(page);
    test.skip(!csrf, 'No CSRF token found.');

    const response = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'create_teacher',
        csrf_token: csrf,
        full_name: `API Teacher ${token}`,
        username: `api_tchr_${token}`,
        password: 'APITest123!',
      },
    });

    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.success).toBe(true);
  });

  test('create_teacher rejects duplicate username', async ({ page }) => {
    const token = Date.now().toString(36);
    const username = `dup_tchr_${token}`;

    const csrf = await getCsrfFromPage(page);
    test.skip(!csrf, 'No CSRF token found.');

    await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'create_teacher',
        csrf_token: csrf,
        full_name: `Dup Teacher ${token}`,
        username,
        password: 'DupTest123!',
      },
    });

    const csrf2 = await getCsrfFromPage(page);
    const response = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'create_teacher',
        csrf_token: csrf2,
        full_name: `Dup Teacher 2 ${token}`,
        username,
        password: 'DupTest123!',
      },
    });

    const body = await response.json();
    expect(body.success).toBe(false);
    expect(body.message).toMatch(/already exists/i);
  });

  test('delete_teacher soft-deletes a created teacher', async ({ page }) => {
    const token = Date.now().toString(36);
    const csrf = await getCsrfFromPage(page);
    test.skip(!csrf, 'No CSRF token found.');

    const createResp = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'create_teacher',
        csrf_token: csrf,
        full_name: `Del Teacher ${token}`,
        username: `del_tchr_${token}`,
        password: 'DelTest123!',
      },
    });
    const createBody = await createResp.json();
    test.skip(!createBody.success, 'Could not create teacher to delete.');

    const listResp = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    const listBody = await listResp.json();
    const teacher = listBody.teachers.find((t) => t.username === `del_tchr_${token}`);
    test.skip(!teacher, 'Could not find created teacher in list.');

    const csrf2 = await getCsrfFromPage(page);
    const deleteResp = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'delete_teacher',
        csrf_token: csrf2,
        teacher_id: teacher.id,
      },
    });

    expect(deleteResp.status()).toBe(200);
    const deleteBody = await deleteResp.json();
    expect(deleteBody.success).toBe(true);
  });

  test('toggle_status changes teacher active status', async ({ page }) => {
    const token = Date.now().toString(36);
    const csrf = await getCsrfFromPage(page);
    test.skip(!csrf, 'No CSRF token found.');

    const createResp = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'create_teacher',
        csrf_token: csrf,
        full_name: `Toggle Teacher ${token}`,
        username: `tog_tchr_${token}`,
        password: 'TogTest123!',
      },
    });
    const createBody = await createResp.json();
    test.skip(!createBody.success, 'Could not create teacher to toggle.');

    const listResp = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    const listBody = await listResp.json();
    const teacher = listBody.teachers.find((t) => t.username === `tog_tchr_${token}`);
    test.skip(!teacher, 'Could not find created teacher.');

    const csrf2 = await getCsrfFromPage(page);
    const toggleResp = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'toggle_status',
        csrf_token: csrf2,
        teacher_id: teacher.id,
      },
    });

    expect(toggleResp.status()).toBe(200);
    const toggleBody = await toggleResp.json();
    expect(toggleBody.success).toBe(true);
  });

  test('get_learners returns assigned learners for a teacher', async ({ page }) => {
    const listResp = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    const listBody = await listResp.json();
    test.skip(!listBody.teachers.length, 'No teachers in list.');

    const teacherId = listBody.teachers[0].id;
    const response = await page.request.get(
      `/ajax_teacher_management.php?action=get_learners&teacher_id=${teacherId}`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(Array.isArray(body.assigned)).toBe(true);
    expect(Array.isArray(body.unassigned)).toBe(true);
  });

  test('save_assignments replaces learner list for a teacher', async ({ page }) => {
    const listResp = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    const listBody = await listResp.json();
    test.skip(!listBody.teachers.length, 'No teachers in list.');

    const teacherId = listBody.teachers[0].id;
    const csrf = await getCsrfFromPage(page);
    test.skip(!csrf, 'No CSRF token found.');

    const response = await page.request.post('/ajax_teacher_management.php', {
      form: {
        action: 'save_assignments',
        csrf_token: csrf,
        teacher_id: teacherId,
        assigned_lrns: '[]',
      },
    });

    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.success).toBe(true);
  });

  test('rejects unauthenticated request with 401', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.request.get('/ajax_teacher_management.php?action=list_teachers');
    expect(response.status()).toBe(401);
  });
});
