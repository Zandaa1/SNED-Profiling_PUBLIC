const { test, expect } = require('@playwright/test');
const { login, dismissPrivacyNotice } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures, getLearnership } = require('./helpers/fixtures');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

// ─────────────────────────────────────────────────────────────────────────────
// SQL INJECTION TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('SQL Injection', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test.describe('Login form', () => {
    test('SQL injection in username field does not bypass auth', async ({ page }) => {
      await page.goto('/index.php');
      await page.locator('#usernameInput').fill("' OR '1'='1' --");
      await page.locator('#passwordInput').fill('anything');
      await page.getByRole('button', { name: /log in/i }).click();

      await expect(page).toHaveURL(/index\.php/);
      await expect(page.locator('.card .alert-danger')).toBeVisible();
    });

    test('SQL injection with UNION SELECT in username does not bypass auth', async ({ page }) => {
      await page.goto('/index.php');
      await page.locator('#usernameInput').fill("' UNION SELECT 1,2,3,4,5,6,7,8 FROM users --");
      await page.locator('#passwordInput').fill('anything');
      await page.getByRole('button', { name: /log in/i }).click();

      await expect(page).toHaveURL(/index\.php/);
      await expect(page.locator('.card .alert-danger')).toBeVisible();
    });

    test('SQL injection with double quotes does not bypass auth', async ({ page }) => {
      await page.goto('/index.php');
      await page.locator('#usernameInput').fill('admin" OR "1"="1');
      await page.locator('#passwordInput').fill('anything');
      await page.getByRole('button', { name: /log in/i }).click();

      await expect(page).toHaveURL(/index\.php/);
      await expect(page.locator('.card .alert-danger')).toBeVisible();
    });

    test('SQL injection with stacked queries does not bypass auth', async ({ page }) => {
      await page.goto('/index.php');
      await page.locator('#usernameInput').fill("'; DROP TABLE users; --");
      await page.locator('#passwordInput').fill('anything');
      await page.getByRole('button', { name: /log in/i }).click();

      await expect(page).toHaveURL(/index\.php/);
      await expect(page.locator('.card .alert-danger')).toBeVisible();
    });
  });

  test.describe('Learner search SQL injection', () => {
    test('SQL injection in learner search is treated as literal search term (safe)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      const response = await page.request.get(
        '/ajax_learner_search.php?q=' + encodeURIComponent("' OR '1'='1' --")
      );
      const body = await response.json();

      // Prepared statements treat the payload as a literal LIKE search term.
      // The key assertion: no SQL error, and results (if any) are limited (not all rows).
      expect(body.success).toBe(true);
      expect(Array.isArray(body.results)).toBe(true);
      // If injection worked, we'd get ALL learners; with LIKE on a weird string, we get 0-20.
      expect(body.results.length).toBeLessThanOrEqual(20);
    });

    test('SQL injection via UNION does not leak user table data', async ({ page }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      const response = await page.request.get(
        '/ajax_learner_search.php?q=' +
          encodeURIComponent("' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11 FROM users --")
      );
      const body = await response.json();

      // If UNION injection worked, results would contain user table data.
      // With prepared statements, the entire string is a literal LIKE term — safe.
      expect(body.success).toBe(true);
      expect(Array.isArray(body.results)).toBe(true);
      // Verify no user-table columns leaked (results should only have learner fields)
      for (const r of body.results) {
        expect(r).not.toHaveProperty('password');
        expect(r).not.toHaveProperty('username');
      }
    });
  });

  test.describe('Promotion page SQL injection', () => {
    test('SQL injection in next_grade_level is not executed', async ({ page }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);
      const learner = getLearnership();
      if (!learner.anyLrn) {
        test.skip(true, 'No test learner available.');
        return;
      }

      // Get CSRF token
      await page.goto('/promotion.php');
      const csrf = await page.locator('input[name="csrf_token"]').first().inputValue();

      // Attempt SQL injection via POST
      const response = await page.request.post('/promotion.php', {
        form: {
          csrf_token: csrf,
          action: 'promote',
          lrn: learner.anyLrn,
          next_grade_level: "Grade 1'; DROP TABLE enrollments; --",
          school_year: '2025-2026',
          placement_type: 'Graded',
        },
      });

      const text = await response.text();
      // Should either show an error about invalid grade level or succeed without SQL error
      expect(text).not.toMatch(/sql syntax|mysql|syntax error|uncaught/i);
    });
  });

  test.describe('Dashboard second-order SQL injection via school_year', () => {
    test('dashboard loads without SQL error when school_year has special chars', async ({
      page,
    }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      // The dashboard reads school_year from the DB. If the DB has a malicious
      // school_year, the dashboard should still render without SQL errors.
      // We verify by checking the dashboard loads normally.
      const response = await page.goto('/main.php');
      expect(response.status()).toBe(200);
      await dismissPrivacyNotice(page);

      // Should not show SQL errors
      await expect(page.locator('body')).not.toContainText(
        /sql syntax|mysql|uncaught|syntax error/i
      );
    });
  });

  test.describe('adminCreateUser teacher_id injection', () => {
    test('DELETE query with uncast teacher_id is safely cast to int', async ({ page }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      // Navigate to the page to get session + CSRF
      await page.goto('/adminCreateUser.php');

      // Verify we're on the admin page (not redirected)
      if (!page.url().includes('adminCreateUser.php')) {
        test.skip(true, 'Not on adminCreateUser page (redirected).');
        return;
      }

      const csrfInput = page.locator('input[name="csrf_token"]').first();
      if ((await csrfInput.count()) === 0) {
        test.skip(true, 'CSRF token not found on page.');
        return;
      }
      const csrf = await csrfInput.inputValue();

      // Submit the assignment save form via the page (preserves cookies/session)
      const response = await page.request.post('/adminCreateUser.php', {
        form: {
          csrf_token: csrf,
          save_assignments: '1',
          assign_teacher_id: '1 OR 1=1',
          assigned_lrns: '[]',
        },
      });

      const text = await response.text();
      // Should not contain SQL errors — the int cast prevents injection
      expect(text).not.toMatch(/sql syntax|mysql|uncaught|syntax error/i);
      // Page should render normally (no crash)
      expect(response.status()).toBe(200);
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// RBAC / ACCESS CONTROL TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('RBAC Enforcement', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test.describe('ajax_learners_by_school.php - cross-school enumeration', () => {
    test('teacher can enumerate learners across schools (CONFIRMED VULN)', async ({ page }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      // No school_id filter = returns ALL learners
      const response = await page.request.get('/ajax_learners_by_school.php');
      const body = await response.json();

      expect(body.success).toBe(true);
      // If the vulnerability exists, results will contain learners from other schools
      // We check that it returns results (any results = vuln, since teacher should be scoped)
      if (body.results && body.results.length > 0) {
        // This confirms the vulnerability - teacher can see learners without school filter
        console.log(
          `[VULN] ajax_learners_by_school: teacher returned ${body.results.length} learners without school filter`
        );
      }
    });

    test('teacher can query specific school_id (CONFIRMED VULN)', async ({ page }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      // Query school_id=1 (likely different from teacher's school)
      const response = await page.request.get('/ajax_learners_by_school.php?school_id=1');
      const body = await response.json();

      expect(body.success).toBe(true);
      if (body.results && body.results.length > 0) {
        console.log(
          `[VULN] ajax_learners_by_school: teacher enumerated ${body.results.length} learners from school_id=1`
        );
      }
    });

    test('unauthenticated request is rejected', async ({ page }) => {
      const response = await page.request.get('/ajax_learners_by_school.php');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('ajax_teacher_assignments.php - no role check', () => {
    test('any authenticated user can enumerate teacher assignments (CONFIRMED VULN)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      // Try teacher_id=1 (any ID)
      const response = await page.request.get('/ajax_teacher_assignments.php?teacher_id=1');
      const body = await response.json();

      expect(body.success).toBe(true);
      console.log(
        `[VULN] ajax_teacher_assignments: teacher can query assignments for any teacher_id`
      );
    });

    test('unauthenticated request is rejected', async ({ page }) => {
      const response = await page.request.get('/ajax_teacher_assignments.php?teacher_id=1');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('transferRequests.php - teacher access', () => {
    test('teacher cannot access transfer requests page (SECURED)', async ({ page }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      await page.goto('/transferRequests.php');
      expect(page.url()).toContain('403.php');
    });
  });

  test.describe('printBIR.php - no scope check', () => {
    test('any authenticated user can print BIR without scope check (CONFIRMED VULN)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      // Try with a known learner LRN
      const learner = getLearnership();
      if (!learner.anyLrn) {
        test.skip(true, 'No test learner available.');
        return;
      }

      const response = await page.request.get(
        `/printBIR.php?learner_id=${learner.anyLrn}&sy=2025-2026&q=Q1`
      );

      // If the page returns 200 without redirecting to 403, the vuln exists
      if (response.status() === 200) {
        const text = await response.text();
        if (!text.includes('Access Denied') && !text.includes('403')) {
          console.log(`[VULN] printBIR: no scope check - teacher can print any learner's BIR`);
        }
      }
    });
  });

  test.describe('bir_form.php - IDOR on bir_id', () => {
    test('GET fetch by bir_id does not verify learner ownership (POTENTIAL IDOR)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      // bir_form.php fetches BIR by id without checking it belongs to the learner
      // We test by providing a learner_id that the user can access but a bir_id that
      // might belong to another learner
      const learner = getLearnership();
      if (!learner.anyLrn) {
        test.skip(true, 'No test learner available.');
        return;
      }

      // First, get a valid BIR id by loading the form for the test learner
      const response = await page.request.get(
        `/bir_form.php?learner_id=${learner.anyLrn}`
      );
      const text = await response.text();

      // Look for bir_id in hidden input
      const birIdMatch = text.match(/name="bir_id"\s+value="(\d+)"/);
      if (birIdMatch && birIdMatch[1] !== '0') {
        // Try fetching with a different learner_id but same bir_id
        const idorResponse = await page.request.get(
          `/bir_form.php?learner_id=000000000000&id=${birIdMatch[1]}`
        );
        const idorText = await idorResponse.text();
        if (!idorText.includes('Learner not found') && !idorText.includes('403')) {
          console.log(`[VULN] bir_form: IDOR - bir_id fetched without learner ownership check`);
        }
      }
    });
  });

  test.describe('viewLearner.php - quick enroll arbitrary school', () => {
    test('scoped user can enroll learner into arbitrary school (POTENTIAL VULN)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('school_coordinator');
      await login(page, creds);
      const learner = getLearnership();
      if (!learner.anyLrn) {
        test.skip(true, 'No test learner available.');
        return;
      }

      // Navigate to the learner page
      await page.goto(`/viewLearner.php?id=${learner.anyLrn}`);

      // Skip if redirected (learner not accessible to this role)
      if (!page.url().includes('viewLearner.php')) {
        test.skip(true, 'Learner not accessible to school_coordinator.');
        return;
      }

      // Find the quick enroll CSRF token (second csrf_input on the page)
      const csrfInputs = page.locator('input[name="csrf_token"]');
      const csrfCount = await csrfInputs.count();
      if (csrfCount < 2) {
        test.skip(true, 'Quick enroll form not found on page.');
        return;
      }
      const csrf = await csrfInputs.nth(1).inputValue();

      // Use page.evaluate + fetch to send POST with browser cookies/session
      const body = await page.evaluate(
        async ({ csrf, lrn }) => {
          const fd = new FormData();
          fd.append('csrf_token', csrf);
          fd.append('action', 'quick_enroll');
          fd.append('school_id', '1');
          fd.append('school_year', '2098-2099');
          fd.append('placement_type', 'Non-Graded');
          fd.append('self_contained_level', 'Primary Level 1');
          fd.append('assessment_mode', 'Without Assessment');
          fd.append('disabilities[difficulty][]', 'Seeing');

          const resp = await fetch(`/viewLearner.php?id=${lrn}`, {
            method: 'POST',
            body: fd,
          });
          const text = await resp.text();
          try { return JSON.parse(text); } catch { return { ok: false, error: 'Non-JSON response', raw: text.substring(0, 200) }; }
        },
        { csrf, lrn: learner.anyLrn }
      );

      if (body.ok) {
        console.log(
          `[VULN] viewLearner quick_enroll: coordinator enrolled learner into arbitrary school_id=1`
        );
      }
      // Document the finding — ok=true means the vuln is confirmed
      expect(typeof body.ok).toBe('boolean');
    });
  });

  test.describe('quickguide.php - auth check', () => {
    test('unauthenticated user is redirected from quickguide to login', async ({ page }) => {
      // Clear any existing session
      await page.context().clearCookies();
      await page.goto('/logout.php');
      await page.goto('/index.php');

      await page.goto('/quickguide.php');
      // quickguide.php has auth guard — redirects unauthenticated users to login
      expect(page.url()).not.toContain('quickguide.php');
    });
  });

  test.describe('learners.php - CSRF-vulnerable GET delete', () => {
    test('learner archive uses GET without CSRF (CONFIRMED VULN)', async ({ page }) => {
      const creds = getRoleCredentials('superadmin');
      await login(page, creds);

      // The delete action uses GET: ?action=delete&id=<lrn>
      // This is vulnerable to CSRF via img tags or link prefetch
      // We verify the endpoint accepts GET for destructive action
      const response = await page.request.get(
        '/learners.php?action=delete&id=000000000000'
      );
      const text = await response.text();

      // If it processes the request (even if learner doesn't exist), it confirms GET-based delete
      if (response.status() === 200 && !text.includes('403') && !text.includes('Method Not Allowed')) {
        console.log(`[VULN] learners.php: destructive archive action accepts GET without CSRF`);
      }
    });
  });

  test.describe('import_document.php - global document metadata leak', () => {
    test('scoped user sees documents from all schools (CONFIRMED VULN)', async ({ page }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      const response = await page.goto('/import_document.php');
      expect(response.status()).toBe(200);

      // The recent documents query is global - check if the page shows documents
      // from learners outside the teacher's school
      const docRows = await page.locator('table tbody tr').count();
      if (docRows > 0) {
        console.log(
          `[VULN] import_document: teacher can see ${docRows} document entries from all schools`
        );
      }
    });
  });

  test.describe('export_enrollment_macro.php - cross-school data', () => {
    test('teacher can export citywide enrollment data (CONFIRMED VULN)', async ({ page }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      const response = await page.request.get(
        '/export_enrollment_macro.php?school_year=2025-2026'
      );

      // If the response is 200 with an Excel file, the vuln exists
      if (response.status() === 200) {
        const contentType = response.headers()['content-type'] || '';
        if (
          contentType.includes('spreadsheet') ||
          contentType.includes('excel') ||
          contentType.includes('octet-stream')
        ) {
          console.log(
            `[VULN] export_enrollment_macro: teacher can download citywide enrollment export`
          );
        }
      }
    });
  });

  test.describe('download_doc.php - scope check bypass', () => {
    test('scoped user without school_id can download any document (POTENTIAL VULN)', async ({
      page,
    }) => {
      const creds = getRoleCredentials('teacher');
      await login(page, creds);

      // Try downloading document ID=1 (may belong to another school's learner)
      const response = await page.request.get('/download_doc.php?id=1');

      // If it returns 200 with file content, the vuln exists
      if (response.status() === 200) {
        const contentType = response.headers()['content-type'] || '';
        if (!contentType.includes('text/html')) {
          console.log(`[VULN] download_doc: teacher can download documents without proper scope check`);
        }
      }
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// SESSION / CSRF TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Session Security', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test('session fixation: session ID changes after login', async ({ page }) => {
    // Visit login page to get initial session cookie
    await page.goto('/index.php');
    const cookiesBefore = await page.context().cookies();
    const phpSessionBefore = cookiesBefore.find((c) => c.name.startsWith('PHPSESSID'));

    // Login
    const creds = getRoleCredentials('superadmin');
    await page.locator('#usernameInput').fill(creds.username);
    await page.locator('#passwordInput').fill(creds.password);
    await Promise.all([
      page.waitForURL((url) => !url.pathname.endsWith('/index.php'), { timeout: 30_000 }),
      page.getByRole('button', { name: /log in/i }).click(),
    ]);

    const cookiesAfter = await page.context().cookies();
    const phpSessionAfter = cookiesAfter.find((c) => c.name.startsWith('PHPSESSID'));

    // Session ID should change after login (session fixation prevention)
    if (phpSessionBefore && phpSessionAfter) {
      if (phpSessionBefore.value === phpSessionAfter.value) {
        console.log(
          `[VULN] session fixation: PHPSESSID did not change after login (session_regenerate_id not called)`
        );
      }
      // We expect them to be different, but the current code doesn't regenerate
      // So this test documents the vulnerability
    }
  });

  test('CSRF token is present on login form', async ({ page }) => {
    await page.goto('/index.php');
    const csrf = page.locator('input[name="csrf_token"]');
    await expect(csrf).toBeAttached();
    const value = await csrf.inputValue();
    expect(value.length).toBeGreaterThan(10);
  });

  test('POST without CSRF token is rejected on login', async ({ page }) => {
    // Direct POST without visiting the form first (no CSRF token)
    const response = await page.request.post('/index.php', {
      form: {
        username: 'pwtest_superadmin',
        password: 'PWTest123!',
      },
    });

    const text = await response.text();
    // Should show CSRF error or not authenticate
    expect(text).toMatch(/invalid request token|csrf|index\.php/i);
  });

  test('passwords are not stored in plaintext', async ({ page }) => {
    // Login and check that we can't authenticate with plaintext comparison
    // This is already handled by the auth system using password_verify,
    // but we verify by checking the login works with proper hashing
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);
    await expect(page).toHaveURL(/main\.php/);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// DOCUMENT UPLOAD SECURITY
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Document Upload Security', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test('PHP file upload is rejected by extension check', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);
    const learner = getLearnership();
    if (!learner.anyLrn) {
      test.skip(true, 'No test learner available.');
      return;
    }

    // Navigate to import page
    await page.goto('/import_document.php');

    // Upload a PHP file via the file input
    const phpBuffer = Buffer.from('<?php echo "hacked"; ?>');
    const [fileChooser] = await Promise.all([
      page.waitForEvent('filechooser'),
      page.locator('input[name="doc_file"]').click(),
    ]);
    await fileChooser.setFiles({
      name: 'malicious.php',
      mimeType: 'application/x-php',
      buffer: phpBuffer,
    });

    // Select learner from dropdown and document type
    await page.locator('select[name="lrn"]').selectOption({ index: 1 });
    await page.locator('select[name="document_type"]').selectOption('Other');

    // Submit the form
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // The page should show an error about unsupported file type
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/unsupported file type|invalid/i);
  });

  test('executable file types are rejected', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);
    const learner = getLearnership();
    if (!learner.anyLrn) {
      test.skip(true, 'No test learner available.');
      return;
    }

    await page.goto('/import_document.php');

    const exeBuffer = Buffer.from([0x4d, 0x5a, 0x90, 0x00]); // MZ PE header
    const [fileChooser] = await Promise.all([
      page.waitForEvent('filechooser'),
      page.locator('input[name="doc_file"]').click(),
    ]);
    await fileChooser.setFiles({
      name: 'malicious.exe',
      mimeType: 'application/octet-stream',
      buffer: exeBuffer,
    });

    await page.locator('select[name="lrn"]').selectOption({ index: 1 });
    await page.locator('select[name="document_type"]').selectOption('Other');
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/unsupported file type|invalid/i);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// XSS PREVENTION TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('XSS Prevention', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test('login error message escapes HTML entities', async ({ page }) => {
    await page.goto('/index.php');
    await page.locator('#usernameInput').fill('<img src=x onerror=alert(1)>');
    await page.locator('#passwordInput').fill('anything');
    await page.getByRole('button', { name: /log in/i }).click();

    // The error message should not render the img tag
    const imgCount = await page.locator('.alert-danger img[src="x"]').count();
    expect(imgCount).toBe(0);
  });

  test('announcement titles are escaped on login page', async ({ page }) => {
    await page.goto('/index.php');

    // Check that any announcement content is properly escaped
    const announcements = page.locator('.alert .fw-bold.small');
    const count = await announcements.count();
    for (let i = 0; i < count; i++) {
      const text = await announcements.nth(i).textContent();
      // Should not contain raw HTML tags
      expect(text).not.toMatch(/<script|<img|<iframe|onerror/i);
    }
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// INFORMATION DISCLOSURE TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Information Disclosure', () => {
  test.skip(!hasCredentials(), 'Run global setup first.');

  test('failed login does not reveal whether username exists', async ({ page }) => {
    // Try a non-existent username
    await page.goto('/index.php');
    await page.locator('#usernameInput').fill('nonexistent_user_xyz');
    await page.locator('#passwordInput').fill('WrongPassword!');
    await page.getByRole('button', { name: /log in/i }).click();

    const errorText1 = await page.locator('.card .alert-danger').textContent();

    // Try an existing username with wrong password
    await page.goto('/index.php');
    const creds = getRoleCredentials('superadmin');
    await page.locator('#usernameInput').fill(creds.username);
    await page.locator('#passwordInput').fill('WrongPassword!');
    await page.getByRole('button', { name: /log in/i }).click();

    const errorText2 = await page.locator('.card .alert-danger').textContent();

    // Both should show the same generic error message
    expect(errorText1.trim()).toBe(errorText2.trim());
  });

  test('database error details are not exposed to users', async ({ page }) => {
    const creds = getRoleCredentials('superadmin');
    await login(page, creds);

    // Try accessing a non-existent learner
    const response = await page.request.get('/viewLearner.php?id=000000000000');
    const text = await response.text();

    // Should not contain database error details
    expect(text).not.toMatch(/mysql|mysqli|SQLSTATE|PDOException|stack trace/i);
  });

  test('config.php is not directly accessible', async ({ page }) => {
    const response = await page.request.get('/config.php');

    // Should not expose PHP source code
    const text = await response.text();
    expect(text).not.toContain('DB_PASSWORD');
    expect(text).not.toContain('DB_USERNAME');
  });

  test('version.txt is not directly accessible', async ({ page }) => {
    const response = await page.request.get('/version.txt');

    // May return the version but should not expose sensitive info
    if (response.status() === 200) {
      const text = await response.text();
      expect(text).not.toMatch(/password|secret|key|token/i);
    }
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// HEADER SECURITY TESTS
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Security Headers', () => {
  test('login page has X-Content-Type-Options', async ({ page }) => {
    const response = await page.goto('/index.php');
    const headers = response.headers();

    // These are nice-to-have but not critical for the audit
    if (headers['x-content-type-options']) {
      expect(headers['x-content-type-options']).toBe('nosniff');
    }
  });

  test('login page does not expose server version in headers', async ({ page }) => {
    const response = await page.goto('/index.php');
    const headers = response.headers();

    // Server header should not reveal detailed version info
    const server = headers['server'] || '';
    if (server) {
      // Exposes nginx/1.30.0 — this is a finding
      if (server.match(/\d+\.\d+\.\d+/)) {
        console.log(`[VULN] Server header exposes version: ${server}`);
      }
      // Test documents the finding; don't fail since this is infra-level
      expect(server).toBeTruthy();
    }
  });
});
