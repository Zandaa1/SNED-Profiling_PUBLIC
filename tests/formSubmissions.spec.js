const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { fillCustomAddress } = require('./helpers/forms');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Exhaustive Form Submissions Suite', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('local write: submit IEP, ITP, ILP, BIR, and SF9 forms sequentially', async ({ page }) => {
    test.setTimeout(180_000);
    requireLocalWrites();

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const lrn = `99${Date.now().toString().slice(-10)}`;
    const firstName = `FORMTEST-FIRST-${randomSuffix}`;
    const lastName = `FORMTEST-LAST`;

    // 1. Create a Learner Profile (Fill EVERY possible field)
    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(lrn);
    await page.locator('#first_name').fill(firstName);
    await page.locator('#middle_name').fill('E2E-Middle');
    await page.locator('#last_name').fill(lastName);
    await page.locator('#extension_name').selectOption('JR');
    await page.locator('#birthday').fill('2014-03-12');
    await page.locator('#sex').selectOption('Male');
    await page.locator('#religion').selectOption('Roman Catholic');
    await page.locator('#mother_tongue').selectOption('Filipino');
    await page.locator('#contact_number').fill('09123456789');
    await page.locator('#is_athlete_yes').check({ force: true });
    await page.locator('#athlete_details').fill('Swimming');
    await page.locator('#l_house_no_street').fill('789 Learner St');
    await fillCustomAddress(page, 'l');
    await page.locator('#pwd_id_number').fill('PWD-123456');
    await page.locator('#profile_id').fill('PROF-789');

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');

    await page.locator('#previous_school_name').fill('PWTEST School Falling');
    await page.locator('#years_in_school').fill('4 years');
    await page.locator('#current_level_of_education').fill('Grade 3');
    await page.locator('#interpreter_needed').fill('Sign language');
    await page.locator('#assessment_institution').fill('Hope Center');
    await page.locator('#medical_results').fill('Clinical notes on vision');
    await page.locator('#assess_mode_no').check({ force: true });

    // Check difficulty list
    await page.locator('input[name="disabilities[difficulty][]"][value="Seeing"]').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"][value="Mobility"]').check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').uncheck({ force: true });
    await page.locator('#mo_first_name').fill('MotherFirst');
    await page.locator('#mo_last_name').fill('MotherLast');
    await page.locator('#mo_middle_name').fill('MotherMiddle');
    await page.locator('#mo_sex').selectOption('Female');
    await page.locator('#mo_contact_number').fill('09123456780');
    await page.locator('#mo_occupation').fill('Teacher');
    await page.locator('#mo_civil_status').selectOption('Married');
    await page.evaluate(() => {
      const cb = document.getElementById('mo_different_address');
      if (cb) {
        cb.checked = false;
        cb.dispatchEvent(new Event('change'));
      }
    });

    await page.locator('#no_father').uncheck({ force: true });
    await page.locator('#fa_first_name').fill('FatherFirst');
    await page.locator('#fa_last_name').fill('FatherLast');
    await page.locator('#fa_middle_name').fill('FatherMiddle');
    await page.locator('#fa_sex').selectOption('Male');
    await page.locator('#fa_contact_number').fill('09123456781');
    await page.locator('#fa_occupation').fill('Engineer');
    await page.locator('#fa_civil_status').selectOption('Married');
    await page.evaluate(() => {
      const cb = document.getElementById('fa_different_address');
      if (cb) {
        cb.checked = false;
        cb.dispatchEvent(new Event('change'));
      }
    });

    await page.locator('#g_first_name').fill('GuardianName');
    await page.locator('#g_last_name').fill('GuardianLast');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill('09170009999');
    await page.locator('#house_no_street').fill('123 Street Address');
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    // 2. Submit IEP Form (Fill ALL fields)
    await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);
    // Step 1: Personal Info & Meeting details
    await page.locator('input[name="mother_tongue"]').fill('Filipino');
    await page.locator('input[name="home_address"]').fill('123 Street Address, Valenzuela');
    await page.locator('input[name="learner_phone"]').fill('09123456789');
    await page.locator('input[name="guardian_name"]').fill('GuardianName GuardianLast');
    await page.locator('input[name="guardian_workplace"]').fill('Office');
    await page.locator('input[name="guardian_email"]').fill('demo@example.invalid');
    await page.locator('input[name="guardian_landline"]').fill('82928392');
    await page.locator('input[name="guardian_mobile"]').fill('09170009999');
    await page.locator('input[name="guardian_mother_tongue"]').fill('Filipino');
    await page.locator('input[name="interpreter_needed"]').fill('None');
    await page.locator('#md_yes').check({ force: true });
    await page.locator('input[name="medical_diagnosis_specify"]').fill('Autism Spectrum Disorder');

    // Check all difficulties
    await page.locator('#diff_diff_seeing').check({ force: true });
    await page.locator('#diff_diff_hearing').check({ force: true });
    await page.locator('#diff_diff_communicating').check({ force: true });
    await page.locator('#diff_diff_mobility').check({ force: true });
    await page.locator('#diff_diff_interpersonal').check({ force: true });
    await page.locator('#diff_diff_adaptive').check({ force: true });
    await page.locator('#diff_diff_basic_skills').check({ force: true });
    await page.locator('#diff_diff_remembering').check({ force: true });
    await page.locator('#diff_others_check').check({ force: true });
    await page.locator('input[name="diff_others_text"]').fill('Attention span limits');

    await page.locator('input[name="meeting_date"]').fill('2026-06-15');
    await page.locator('input[name="date_of_last_iep"]').fill('2025-06-15');
    await page.locator('input[name="iep_review_date"]').fill('2027-06-15');
    await page.locator('select[name="purpose_of_meeting"]').selectOption('Initial IEP');
    await page.locator('textarea[name="meeting_comments"]').fill('IEP Meeting Comments');

    // Signatures
    await page.locator('input[name="learner_signature_date"]').fill('2026-06-15');
    await page.locator('input[name="parent_signature_date"]').fill('2026-06-15');
    await page.locator('input[name="rights_notified"]').check({ force: true });

    // Step 2: Present Levels & Special Factors
    await page.evaluate(() => window.goToStep(2));
    await page.locator('textarea[name="present_eval_results"]').fill('IEP Evaluative results');
    await page.locator('textarea[name="academic_strengths"]').fill('E2E Strengths Value');
    await page.locator('textarea[name="academic_needs"]').fill('E2E Needs Value');
    await page.locator('textarea[name="parental_concerns"]').fill('E2E Concerns Value');
    await page.locator('textarea[name="disability_impact"]').fill('E2E Disability Impact Value');

    // Fill a couple of special factors
    await page.locator('select[name="sf[sf_behavior][yesno]"]').selectOption('1');
    await page.locator('select[name="sf[sf_behavior][bip]"]').selectOption('1');
    await page.locator('textarea[name="sf[sf_behavior][notes]"]').fill('Behavior support notes');
    await page.locator('select[name="sf[sf_vision][yesno]"]').selectOption('1');
    await page.locator('textarea[name="sf[sf_vision][notes]"]').fill('Special factors notes for seeing');

    // Step 3: Barriers
    await page.evaluate(() => window.goToStep(3));
    await page.locator('select[name="barriers[0][difficulty]"]').selectOption('diff_seeing');
    await page.locator('textarea[name="barriers[0][barrier_text]"]').fill('E2E Barrier Text');
    await page.locator('textarea[name="barriers[0][facilitator]"]').fill('E2E Facilitator Text');
    await page.locator('textarea[name="barriers[0][accommodations]"]').fill('E2E Accommodations Text');

    // Step 4: Goals
    await page.evaluate(() => window.goToStep(4));
    await page.locator('input[name="goals[0][interest]"]').fill('E2E Goal Interest Area');
    await page.locator('textarea[name="goals[0][goal]"]').fill('E2E SMART Goal');
    await page.locator('textarea[name="goals[0][interventions]"]').fill('E2E Goal Interventions');

    // Step 5: Transition Point & Goals
    await page.evaluate(() => window.goToStep(5));
    await page.locator('input[name="transition_point"][value="transition_school_to_work"]').check({ force: true });
    await page.locator('input[name="transitions[0][interest]"]').fill('E2E Transition Interest');
    await page.locator('input[name="transitions[0][work_opportunities]"]').fill('E2E Transition Goal');
    await page.locator('textarea[name="transitions[0][interventions]"]').fill('E2E Transition Interventions');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save IEP")').click()
    ]);

    // 3. Submit ITP Form (Fill ALL fields)
    await page.goto(`/itp_form.php?learner_id=${encodeURIComponent(lrn)}`);
    // Step 1: General & Personal Info (No step change needed as it loads by default)
    await page.locator('input[name="plan_date"]').fill('2026-06-15');
    await page.locator('input[name="review_date"]').fill('2027-06-15');
    await page.locator('input[name="target_exit_date"]').fill('2028-06-15');
    await page.locator('input[name="parent_guardian_name"]').fill('GuardianName GuardianLast');
    await page.locator('input[name="current_level_of_education"]').fill('Elementary');
    await page.locator('input[name="previous_school_name"]').fill('Previous SPED School');
    await page.locator('input[name="years_in_school"]').fill('5 years');
    await page.locator('input[name="religion"]').fill('Roman Catholic');
    await page.locator('input[name="current_address"]').fill('123 Street Address');
    await page.locator('input[name="contact_number"]').fill('09170009999');
    await page.locator('#assess_no').check({ force: true });
    await page.locator('input[name="assessment_institution"]').fill('VC Hope Center');
    await page.locator('input[name="entry_point_label"][value="Transition from school to employment or entrepreneurship"]').check({ force: true });

    // Step 2: Transition Team & Entry Point
    await page.evaluate(() => window.goToStep(2));
    await page.locator('input[name="team_rows[0][name]"]').fill('E2E Team Member');
    await page.locator('input[name="team_rows[0][contact_details]"]').fill('09123456789');
    await page.locator('input[name="team_rows[0][date_started_with_team]"]').fill('2026-06-15');

    // Step 3: Potentials & Needs & Recommendations
    await page.evaluate(() => window.goToStep(3));
    await page.locator('input[name="strengths"]').fill('E2E Strengths');
    await page.locator('input[name="interests"]').fill('E2E Interests');
    await page.locator('input[name="talents"]').fill('E2E Talents');
    await page.locator('input[name="skills"]').fill('E2E Skills');
    await page.locator('input[name="needs"]').fill('E2E Needs');
    await page.locator('textarea[name="recommendations"]').fill('ITP Recommendations');

    // Step 4: Program Matrix Guide (No inputs, just reference)
    await page.evaluate(() => window.goToStep(4));

    // Step 5: Goal Plan, Program Matrix inputs & Signatures
    await page.evaluate(() => window.goToStep(5));
    await page.locator('input[name="transition_goal"]').fill('Livelihood');
    await page.locator('input[name="learning_packages"]').fill('E2E Package Test');
    await page.locator('textarea[name="goal_rows[0][competency]"]').fill('E2E Competency');
    await page.locator('textarea[name="goal_rows[0][activities]"]').fill('E2E Activities');
    await page.locator('select[name="goal_rows[0][time_frame]"]').selectOption('Whole Term');
    await page.locator('textarea[name="goal_rows[0][persons_responsible]"]').fill('E2E Person Responsible');

    await page.locator('input[name="prepared_by_name"]').fill('Prep Name');
    await page.locator('input[name="prepared_by_title"]').fill('Prep Designation');
    await page.locator('input[name="noted_by_name"]').fill('Note Name');
    await page.locator('input[name="noted_by_title"]').fill('Note Designation');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save ITP Record")').click()
    ]);

    // 4. Submit ILP Form (Fill ALL fields)
    await page.goto(`/ilp_form.php?learner_id=${encodeURIComponent(lrn)}&document_type=ILP`);
    // Step 1
    await page.locator('input[name="adviser_name"]').fill('E2E Teacher');
    await page.locator('input[name="meeting_date"]').fill('2026-06-15');
    await page.locator('input[name="review_date"]').fill('2027-06-15');
    await page.locator('select[name="meeting_purpose"]').selectOption('Initial');
    await page.locator('input[name="interview_date"]').fill('2026-06-14');
    await page.locator('input[name="interview_guardian_name"]').fill('GuardianName');
    await page.locator('input[name="interview_guardian_contact"]').fill('09170009999');
    await page.locator('input[name="interests_hobbies"]').fill('Likes gardening and painting');
    await page.locator('input[name="rights_transfer_notified"]').check({ force: true });
    await page.locator('input[name="rights_not_applicable"]').check({ force: true });

    // Step 2: Present Levels & Assessments
    await page.evaluate(() => window.goToStep(2));
    await page.locator('textarea[name="present_level_results"]').fill('ILP present_level_results');
    await page.locator('textarea[name="parental_concerns"]').fill('ILP parental_concerns');
    await page.locator('textarea[name="disability_impact"]').fill('ILP disability_impact');
    await page.locator('input[name="assessments[0][assessment_type]"]').fill('Initial Assessment');
    await page.locator('input[name="assessments[0][date_administered]"]').fill('2026-06-15');
    await page.locator('input[name="assessments[0][chronological_age]"]').fill('8y');
    await page.locator('input[name="assessments[0][administrator]"]').fill('Adviser Name');
    await page.locator('textarea[name="assessments[0][results]"]').fill('Assessment results');

    // Step 3: Narrative Domains
    await page.evaluate(() => window.goToStep(3));
    await page.locator('textarea[name="daily_living_strengths"]').fill('ILP daily_living_strengths');
    await page.locator('textarea[name="daily_living_needs"]').fill('ILP daily_living_needs');
    await page.locator('textarea[name="language_strengths"]').fill('ILP language strengths');
    await page.locator('textarea[name="language_needs"]').fill('ILP language needs');
    await page.locator('textarea[name="psychomotor_strengths"]').fill('ILP psychomotor strengths');
    await page.locator('textarea[name="psychomotor_needs"]').fill('ILP psychomotor needs');
    await page.locator('textarea[name="cognitive_strengths"]').fill('ILP cognitive strengths');
    await page.locator('textarea[name="cognitive_needs"]').fill('ILP cognitive needs');
    await page.locator('textarea[name="aesthetic_strengths"]').fill('ILP aesthetic strengths');
    await page.locator('textarea[name="aesthetic_needs"]').fill('ILP aesthetic needs');
    await page.locator('textarea[name="behavioral_strengths"]').fill('ILP behavioral strengths');
    await page.locator('textarea[name="behavioral_needs"]').fill('ILP behavioral needs');
    await page.locator('textarea[name="orientation_strengths"]').fill('ILP orientation strengths');
    await page.locator('textarea[name="orientation_needs"]').fill('ILP orientation needs');

    // Step 4: Special Factors
    await page.evaluate(() => window.goToStep(4));
    await page.locator('select[name="sf[sf_behavior][yesno]"]').selectOption('Yes');
    await page.locator('textarea[name="sf[sf_behavior][notes]"]').fill('Behavior notes');

    // Step 5: Barriers
    await page.evaluate(() => window.goToStep(5));
    await page.locator('select[name="barriers[0][difficulty]"]').selectOption('diff_seeing');
    await page.locator('textarea[name="barriers[0][barrier_text]"]').fill('ILP barriers[0][barrier_text]');
    await page.locator('textarea[name="barriers[0][facilitator]"]').fill('ILP barriers[0][facilitator]');
    await page.locator('textarea[name="barriers[0][accommodations]"]').fill('ILP barriers[0][accommodations]');

    // Step 6: Goals
    await page.evaluate(() => window.goToStep(6));
    await page.locator('input[name="goals[0][interest]"]').fill('ILP Goal Interest Area');
    await page.locator('textarea[name="goals[0][goal]"]').fill('E2E ILP SMART Goal');
    await page.locator('textarea[name="goals[0][interventions]"]').fill('ILP goals[0][interventions]');

    // Step 7: Transition
    await page.evaluate(() => window.goToStep(7));
    await page.locator('textarea[name="transition_interests"]').fill('ILP transition_interests');
    await page.locator('textarea[name="transition_skills"]').fill('ILP transition_skills');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Record")').click()
    ]);

    // 5. Submit Behavior Incident Report (BIR) Form (Fill ALL fields)
    await page.goto(`/bir_form.php?learner_id=${encodeURIComponent(lrn)}&q=Q1&sy=2025-2026`);
    await page.locator('select[name="quarter"]').selectOption('Q1');
    await page.locator('input[name="date_of_observation"]').fill('2026-06-15');
    await page.locator('input[name="teacher_name"]').fill('E2E Teacher');
    await page.locator('select[name="educational_placement"]').selectOption('Self-Contained');

    await page.locator('textarea[name="difficulty_disability"]').fill('BIR difficulty_disability');
    await page.locator('textarea[name="baseline_data"]').fill('BIR baseline_data');
    await page.locator('#with_medical_assessment').check({ force: true });
    await page.locator('textarea[name="medical_results"]').fill('BIR medical_results');
    await page.locator('textarea[name="antecedent_home"]').fill('E2E Antecedent Home');
    await page.locator('textarea[name="antecedent_school"]').fill('E2E Antecedent School');
    await page.locator('textarea[name="antecedent_other"]').fill('E2E Antecedent Other');
    await page.locator('textarea[name="observable_home"]').fill('E2E Observable Home');
    await page.locator('textarea[name="observable_school"]').fill('E2E Observable School');
    await page.locator('textarea[name="observable_other"]').fill('E2E Observable Other');
    await page.locator('textarea[name="consequence_home"]').fill('E2E Consequence Home');
    await page.locator('textarea[name="consequence_school"]').fill('E2E Consequence School');
    await page.locator('textarea[name="consequence_other"]').fill('E2E Consequence Other');
    await page.locator('textarea[name="targeted_behavior"]').fill('E2E Targeted Behavior');
    await page.locator('textarea[name="specific_objectives"]').fill('E2E Specific Objectives');
    await page.locator('textarea[name="proactive_strategies"]').fill('E2E Proactive Strategies');
    await page.locator('textarea[name="reactive_strategies"]').fill('E2E Reactive Strategies');
    await page.locator('textarea[name="basic_intervention"]').fill('BIR basic_intervention');
    await page.locator('textarea[name="interventions_done"]').fill('BIR interventions_done');
    await page.locator('input[name="parent_guardian_conforme"]').fill('Guardian conforme signature');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Report")').click()
    ]);

    // 6. Submit School Form 9 (SF9) Details (Fill ALL fields)
    await page.goto(`/sf9_form.php?learner_id=${encodeURIComponent(lrn)}`);
    await page.locator('select[name="assessment_status"]').selectOption('With');

    // Fill attendance values for all 10 indices if possible, or at least index 1
    await page.locator('input[name="attendance[1][school_days]"]').fill('20');
    await page.locator('input[name="attendance[1][days_present]"]').fill('18');
    await page.locator('input[name="attendance[1][days_absent]"]').fill('2');

    // Fill quarter notes
    await page.locator('textarea[name="quarter_notes[1][teacher_remarks]"]').fill('E2E Excellent progress');
    await page.locator('textarea[name="quarter_notes[1][parent_comments]"]').fill('E2E Parent comments value');
    await page.locator('input[name="quarter_notes[1][parent_signature]"]').fill('E2E Signature');

    // Transfer details
    await page.locator('input[name="admitted_to"]').fill('Grade 4 Regular');
    await page.locator('input[name="eligible_for_admission_to"]').fill('Grade 4 Regular Eligible');
    await page.locator('input[name="transfer_teacher_name"]').fill('E2E Transfer Teacher');
    await page.locator('input[name="transfer_principal_name"]').fill('E2E Transfer Principal');
    await page.locator('input[name="cancellation_admitted_in"]').fill('Valenzuela Elementary');
    await page.locator('input[name="cancellation_date"]').fill('2026-06-16');
    await page.locator('input[name="cancellation_principal_name"]').fill('VC Cancellation Principal');

    await Promise.all([
      page.waitForURL(/sf9_form\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Progress Report")').click()
    ]);

    await expect(page.locator('body')).not.toContainText(/fatal error|argumentcounterror|bind_param/i);
    await expect(page.locator('.alert-success').first()).toBeVisible();
  });
});
