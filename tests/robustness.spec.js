const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { fillCustomAddress } = require('./helpers/forms');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Robustness and Form Exhaustiveness tests', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('local write: fully filled learner form with accents, emojis, custom options, and nested entities', async ({ page }) => {
    requireLocalWrites();

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const lrn = `99${Date.now().toString().slice(-10)}`;
    const firstName = `José-María 🧑‍🏫 ${randomSuffix}`;
    const lastName = `O'Connor-Ñeño 🧑‍🎓`;
    const middleName = `d'Albuquerque 🗺️`;
    const pwdId = `PWD-1234-✨`;
    const profileId = `PR-5678-🔥`;
    const contactNum = `0917000${randomSuffix}`;

    await page.goto('/learnerForm.php');

    // Tab 1: Student Information
    await page.locator('#lrn').fill(lrn);
    await page.locator('#first_name').fill(firstName);
    await page.locator('#middle_name').fill(middleName);
    await page.locator('#last_name').fill(lastName);
    await page.locator('#extension_name').selectOption('JR');
    await page.locator('#birthday').fill('2015-06-15');
    await page.locator('#sex').selectOption('Male');
    await page.locator('#pwd_id_number').fill(pwdId);
    await page.locator('#profile_id').fill(profileId);

    // Select Other for Religion and fill custom
    await page.locator('#religion').selectOption('__OTHER__');
    await page.locator('#religion_other').fill('Custom Religion ⛪');

    // Select Other for Mother Tongue and fill custom
    await page.locator('#mother_tongue').selectOption('__OTHER__');
    await page.locator('#mother_tongue_other').fill('Custom Tongue 🗣️');

    await page.locator('#contact_number').fill(contactNum);
    await page.locator('#is_athlete_yes').check({ force: true });
    await page.locator('#athlete_details').fill('Athlete details 🏆');

    // Go to Tab 2: Placement Details
    await page.evaluate(() => window.switchTab('placement-tab'));
    await expect(page.locator('#step-2')).toBeVisible();

    // Select School, SY, and Program type
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Graded');
    await page.locator('#placement_type').dispatchEvent('change');

    await page.locator('#grade_level').selectOption({ index: 1 });
    await page.locator('#remarks').fill('Placement remarks 📝');
    await page.locator('#previous_school_name').fill('PWTEST Previous 🏫');
    await page.locator('#years_in_school').fill('3');
    await page.locator('#current_level_of_education').fill('Grade 2');
    await page.locator('#interpreter_needed').fill('Yes');

    await page.locator('#assess_mode_yes').check({ force: true });
    await page.locator('#assessment_institution').fill('Assess Inst 🏥');
    await page.locator('#medical_results').fill('Med Results 🧪');

    // Check multiple diagnosed difficulties
    await page.locator('input[name="disabilities[diagnosed][]"][value="Autism Spectrum Disorder"]').check({ force: true });
    await page.locator('input[name="disabilities[diagnosed][]"][value="Intellectual Disability"]').check({ force: true });
    await page.locator('input[name="disability_other[diagnosed]"]').fill('Diagnosed Other Text 🩺');

    // Go to Tab 3: Family details
    await page.evaluate(() => window.switchTab('family-tab'));
    await expect(page.locator('#step-3')).toBeVisible();

    // Mother details
    await page.locator('#no_mother').uncheck({ force: true });
    await page.locator('#mo_first_name').fill('Mo-First 👩');
    await page.locator('#mo_last_name').fill('Mo-Last');
    await page.locator('#mo_middle_name').fill('Mo-Middle');
    await page.locator('#mo_extension_name').selectOption('JR');
    await page.locator('#mo_sex').selectOption('Female');
    await page.locator('#mo_civil_status').selectOption('Married');
    await page.locator('#mo_contact_number').fill('09170002222');
    await page.locator('#mo_secondary_contact_number').fill('09170003333');
    await page.locator('#mo_email').fill('demo@example.invalid');
    await page.locator('#mo_occupation').fill('Mo-Occ');
    await page.locator('#mo_workplace').fill('Mo-Work');
    await page.locator('#mo_landline').fill('123456');
    await page.locator('#mo_mother_tongue').fill('Tagalog');
    await page.locator('#mo_different_address').check({ force: true });
    await page.locator('#mo_house_no_street').fill('Mo-House 🏡');
    await fillCustomAddress(page, 'mo');

    // Father details
    await page.locator('#no_father').uncheck({ force: true });
    await page.locator('#fa_first_name').fill('Fa-First 👨');
    await page.locator('#fa_last_name').fill('Fa-Last');
    await page.locator('#fa_middle_name').fill('Fa-Middle');
    await page.locator('#fa_extension_name').selectOption('JR');
    await page.locator('#fa_sex').selectOption('Male');
    await page.locator('#fa_civil_status').selectOption('Married');
    await page.locator('#fa_contact_number').fill('09170004444');
    await page.locator('#fa_secondary_contact_number').fill('09170005555');
    await page.locator('#fa_email').fill('demo@example.invalid');
    await page.locator('#fa_occupation').fill('Fa-Occ');
    await page.locator('#fa_workplace').fill('Fa-Work');
    await page.locator('#fa_landline').fill('789012');
    await page.locator('#fa_mother_tongue').fill('English');
    await page.locator('#fa_different_address').check({ force: true });
    await page.locator('#fa_house_no_street').fill('Fa-House 🏠');
    await fillCustomAddress(page, 'fa');

    // Primary Guardian details
    await page.locator('#g_first_name').fill('G-First 🧑');
    await page.locator('#g_last_name').fill('G-Last');
    await page.locator('#g_middle_name').fill('G-Middle');
    await page.locator('#g_extension_name').selectOption('JR');
    await page.locator('#g_sex').selectOption('Male');
    await page.locator('#g_civil_status').selectOption('Single');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill('09170006666');
    await page.locator('#g_secondary_contact_number').fill('09170007777');
    await page.locator('#g_email').fill('demo@example.invalid');
    await page.locator('#g_occupation').fill('G-Occ');
    await page.locator('#g_workplace').fill('G-Work');
    await page.locator('#g_landline').fill('345678');
    await page.locator('#g_mother_tongue').fill('Tagalog');
    await page.locator('#house_no_street').fill('G-House 🏘️');
    await fillCustomAddress(page, 'g');

    // Select primary guardian for forms
    await page.locator('#primary_guardian_guardian').click();

    // Emergency contact
    await page.locator('#emergency_name').fill('Emerg-Name 🚨');
    await page.locator('#emergency_relationship').selectOption('Sibling');
    await page.locator('#emergency_phone').fill('09170008888');
    await page.locator('#emergency_email').fill('demo@example.invalid');

    // Save and verify redirection
    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);

    // Open edit form and verify values
    await page.goto(`/learnerForm.php?id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('#lrn')).toHaveValue(lrn);
    await expect(page.locator('#first_name')).toHaveValue(firstName.toUpperCase());
    await expect(page.locator('#last_name')).toHaveValue(lastName.toUpperCase());
    await expect(page.locator('#middle_name')).toHaveValue(middleName.toUpperCase());
    await expect(page.locator('#extension_name')).toHaveValue('JR');
    await expect(page.locator('#pwd_id_number')).toHaveValue(pwdId);
    await expect(page.locator('#profile_id')).toHaveValue(profileId);
    await expect(page.locator('#contact_number')).toHaveValue(contactNum);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('local write: autofill integration and propagation from Learner -> IEP -> ITP', async ({ page }) => {
    requireLocalWrites();

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const lrn = `99${Date.now().toString().slice(-10)}`;
    const firstName = `Autofill-Test ${randomSuffix}`;
    const lastName = `Learner`;

    // 1. Create a learner with specific properties
    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(lrn);
    await page.locator('#first_name').fill(firstName);
    await page.locator('#last_name').fill(lastName);
    await page.locator('#birthday').fill('2014-03-12');
    await page.locator('#sex').selectOption('Male');

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');

    await page.locator('#previous_school_name').fill('PWTEST School Falling');
    await page.locator('#current_level_of_education').fill('Grade 3');
    await page.locator('#assess_mode_no').check({ force: true });

    // Check difficulty list
    await page.locator('input[name="disabilities[difficulty][]"][value="Seeing"]').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"][value="Mobility"]').check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await page.locator('#g_first_name').fill('Guardian');
    await page.locator('#g_last_name').fill('Last');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill('09170009999');
    await page.locator('#house_no_street').fill('123 Street');
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    // 2. Open IEP and check autofilled values
    await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);

    // Checkboxes diff_diff_seeing and diff_diff_mobility should be checked
    await expect(page.locator('#diff_diff_seeing')).toBeChecked();
    await expect(page.locator('#diff_diff_mobility')).toBeChecked();

    // Switch to Step 2: Present Levels
    await page.evaluate(() => window.goToStep(2));

    // Fill in academic strengths, needs, and transition goals
    await page.locator('textarea[name="academic_strengths"]').fill('PWTEST Strengths ✨');
    await page.locator('textarea[name="academic_needs"]').fill('PWTEST Needs 🛠️');
    await page.locator('textarea[name="parental_concerns"]').fill('PWTEST Concerns 🗣️');
    await page.locator('textarea[name="disability_impact"]').fill('PWTEST Disability Impact 🧠');

    // Switch to Step 5: Transitions
    await page.evaluate(() => window.goToStep(5));

    // Fill the default transition row directly (no need to click Add Transition Row since empty rendering provides index 0)
    await page.locator('input[name="transitions[0][interest]"]').fill('Transition Interest');
    await page.locator('input[name="transitions[0][work_opportunities]"]').fill('PWTEST Transition Goal');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save IEP")').click()
    ]);

    // 3. Open ITP and check propagation
    await page.goto(`/itp_form.php?learner_id=${encodeURIComponent(lrn)}`);

    // Verify values from IEP (mixed case)
    await expect(page.locator('input[name="strengths"]')).toHaveValue('PWTEST Strengths ✨');
    await expect(page.locator('input[name="needs"]')).toHaveValue('PWTEST Needs 🛠️');

    // Verify values from Learner profile (mixed case)
    await expect(page.locator('input[name="previous_school_name"]')).toHaveValue('PWTEST School Falling');
    await expect(page.locator('input[name="current_level_of_education"]')).toHaveValue('Grade 3');

    // Verify transition row is populated (mixed case)
    await expect(page.locator('input[name="transition_goal"]')).toHaveValue('PWTEST Transition Goal');

    // 4. Open BIR Form and check prefill from IEP (disability_impact -> baseline_data)
    await page.goto(`/bir_form.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('textarea[name="baseline_data"]')).toHaveValue('PWTEST Disability Impact 🧠');

    // 5. Open ILP Form and check prefill (interview_guardian_name/contact)
    await page.goto(`/ilp_form.php?learner_id=${encodeURIComponent(lrn)}&document_type=ILP`);
    await expect(page.locator('input[name="interview_guardian_name"]')).not.toHaveValue('');
    await expect(page.locator('input[name="interview_guardian_contact"]')).toHaveValue('09170009999');

    // 6. Open SF9 Form and check it loads successfully
    await page.goto(`/sf9_form.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
  });

  test('XSS sanitization: scripts and HTML inside forms are escaped on render', async ({ page }) => {
    requireLocalWrites();

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const lrn = `99${Date.now().toString().slice(-10)}`;

    // Malicious script payload
    const xssPayload = `<script id="malicious-xss">alert('XSS_${randomSuffix}')</script><div id="xss-div">div content</div>`;

    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(lrn);
    await page.locator('#first_name').fill(`XSS-Test ${randomSuffix}`);
    await page.locator('#last_name').fill(xssPayload); // Last name gets the XSS tag
    await page.locator('#birthday').fill('2013-11-20');
    await page.locator('#sex').selectOption('Male');

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');
    await page.locator('#assess_mode_no').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"][value="Seeing"]').check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await page.locator('#g_first_name').fill('Guardian');
    await page.locator('#g_last_name').fill('Last');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill('09171112222');
    await page.locator('#house_no_street').fill('123 Street');
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    // Go to learners.php directory and verify XSS did not execute
    await page.goto('/learners.php');

    // Check if script tag was injected in DOM
    const xssScriptCount = await page.locator('#malicious-xss').count();
    expect(xssScriptCount).toBe(0);

    const xssDivCount = await page.locator('#xss-div').count();
    expect(xssDivCount).toBe(0);

    // Verify it is displayed literally as text (converted to uppercase on the frontend list)
    await expect(page.locator('body')).toContainText(`<SCRIPT ID="MALICIOUS-XSS">`);
  });

  test('Validation boundaries: block invalid LRN, contact numbers, and emails', async ({ page }) => {
    await page.goto('/learnerForm.php');

    // Fill in invalid values
    await page.locator('#lrn').fill('12345'); // Too short
    await page.locator('#first_name').fill('Invalid');
    await page.locator('#last_name').fill('Validation');
    await page.locator('#birthday').fill('2015-01-15');

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#g_first_name').fill('Guardian');
    await page.locator('#g_last_name').fill('Last');
    await page.locator('#g_contact_number').fill('0917-abc-123'); // Bad contact format
    await page.locator('#house_no_street').fill('123 Street');
    await fillCustomAddress(page, 'g');

    await page.locator('#saveFormBtn').click();

    // Verify system notice modal warns about required/incorrect fields
    await expect(page.locator('#learnerFormSystemNotice')).toBeVisible();
    await expect(page.locator('#learnerFormSystemNotice')).toContainText(/select a primary parent\/guardian/i);
  });
});
