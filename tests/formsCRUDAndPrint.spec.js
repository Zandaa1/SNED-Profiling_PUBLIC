const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/auth');
const { getRoleCredentials, loadFixtures } = require('./helpers/fixtures');
const { requireLocalWrites } = require('./helpers/guards');
const { fillCustomAddress } = require('./helpers/forms');

const hasCredentials = () => {
  const f = loadFixtures();
  return !!(f?.users || process.env.E2E_USERNAME);
};

test.describe('Forms CRUD and Print Verification Suite', () => {
  test.skip(!hasCredentials(), 'Run global setup first or set E2E_USERNAME/E2E_PASSWORD.');

  test.beforeEach(async ({ page }) => {
    await login(page, getRoleCredentials('superadmin'));
  });

  test('Create, Edit and Verify all forms in Print Layouts', async ({ page }) => {
    test.setTimeout(180_000);
    requireLocalWrites();

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const lrn = `99${Date.now().toString().slice(-10)}`;
    const firstName = `CRUDTEST-FIRST-${randomSuffix}-ORIG`;
    const lastName = `CRUDTEST-LAST`;
    const guardianContact = '09112223333';
    let schoolYear;

    // ==========================================
    // 1. Create a Learner Profile
    // ==========================================
    await page.goto('/learnerForm.php');
    await page.locator('#lrn').fill(lrn);
    await page.locator('#first_name').fill(firstName);
    await page.locator('#middle_name').fill('E2E-Middle');
    await page.locator('#last_name').fill(lastName);
    await page.locator('#birthday').fill('2014-03-12');
    await page.locator('#sex').selectOption('Male');
    await page.locator('#contact_number').fill(guardianContact);

    await page.evaluate(() => window.switchTab('placement-tab'));
    await page.locator('#school_id').selectOption({ index: 1 });
    await page.locator('#school_year').selectOption({ index: 1 });
    schoolYear = await page.locator('#school_year').inputValue();
    await page.locator('#placement_type').selectOption('Non-Graded');
    await page.locator('#placement_type').dispatchEvent('change');
    await page.locator('#self_contained_level').selectOption('Primary Level 1');
    await page.locator('#assess_mode_no').check({ force: true });
    await page.locator('input[name="disabilities[difficulty][]"][value="Seeing"]').check({ force: true });

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#no_mother').check({ force: true });
    await page.locator('#no_father').check({ force: true });
    await page.locator('#g_first_name').fill('GuardianFirst');
    await page.locator('#g_last_name').fill('GuardianLast');
    await page.locator('#g_relationship').selectOption('Parent');
    await page.locator('#g_contact_number').fill(guardianContact);
    await page.locator('#house_no_street').fill('789 Learner St');
    await fillCustomAddress(page, 'g');

    await page.locator('#primary_guardian_guardian').click();

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    // Verify print view for Learner Profile
    await page.goto(`/printForm.php?id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(firstName);
    await expect(page.locator('body')).toContainText(lastName);
    await expect(page.locator('body')).toContainText(guardianContact);

    await page.goto(`/printAnnex1.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(firstName);
    await expect(page.locator('body')).toContainText(lastName);

    // ==========================================
    // 2. Edit Learner Profile
    // ==========================================
    await page.goto(`/learnerForm.php?id=${encodeURIComponent(lrn)}`);
    const firstNameEdited = `CRUDTEST-FIRST-${randomSuffix}-EDIT`;
    const contactEdited = '09998887777';
    await page.locator('#first_name').fill(firstNameEdited);
    await page.locator('#contact_number').fill(contactEdited);

    await page.evaluate(() => window.switchTab('family-tab'));
    await page.locator('#g_contact_number').fill(contactEdited);

    await Promise.all([
      page.waitForURL(/learners\.php|viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveFormBtn').click()
    ]);

    // Verify updated details on print layouts
    await page.goto(`/printForm.php?id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(firstNameEdited);
    await expect(page.locator('body')).toContainText(contactEdited);
    await expect(page.locator('body')).not.toContainText(firstName);

    // ==========================================
    // 3. Create IEP Form
    // ==========================================
    await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);
    const iepDiagnosis = `IEP-Diag-${randomSuffix}`;
    const iepStrengths = `IEP-Strength-${randomSuffix}-Orig`;
    await page.locator('input[name="mother_tongue"]').fill('Filipino');
    await page.locator('input[name="home_address"]').fill('123 Valenzuela St');
    await page.locator('input[name="learner_phone"]').fill('09123456789');
    await page.locator('input[name="guardian_name"]').fill('GuardianFirst GuardianLast');
    await page.locator('#md_yes').check({ force: true });
    await page.locator('input[name="medical_diagnosis_specify"]').fill(iepDiagnosis);
    await page.locator('input[name="meeting_date"]').fill('2026-06-15');
    await page.locator('select[name="purpose_of_meeting"]').selectOption('Initial IEP');

    await page.evaluate(() => window.goToStep(2));
    await page.locator('textarea[name="present_eval_results"]').fill('IEP Evaluative results');
    await page.locator('textarea[name="academic_strengths"]').fill(iepStrengths);

    await page.evaluate(() => window.goToStep(5));
    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveBtn').click()
    ]);

    // Verify print view for IEP
    await page.goto(`/printIEP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(iepDiagnosis);
    await expect(page.locator('body')).toContainText(iepStrengths);

    // ==========================================
    // 4. Edit IEP Form
    // ==========================================
    await page.goto(`/iep_editor.php?learner_id=${encodeURIComponent(lrn)}`);
    const iepStrengthsEdited = `IEP-Strength-${randomSuffix}-Edit`;
    await page.evaluate(() => window.goToStep(2));
    await page.locator('textarea[name="academic_strengths"]').fill(iepStrengthsEdited);

    await page.evaluate(() => window.goToStep(5));
    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('#saveBtn').click()
    ]);

    // Verify print updates for IEP
    await page.goto(`/printIEP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(iepStrengthsEdited);
    await expect(page.locator('body')).not.toContainText(iepStrengths);

    // ==========================================
    // 5. Create ITP Form
    // ==========================================
    await page.goto(`/itp_form.php?learner_id=${encodeURIComponent(lrn)}`);
    const itpRecommendations = `ITP-Rec-${randomSuffix}`;
    await page.locator('input[name="plan_date"]').fill('2026-06-15');
    await page.locator('input[name="review_date"]').fill('2027-06-15');
    await page.locator('input[name="target_exit_date"]').fill('2028-06-15');
    await page.locator('input[name="parent_guardian_name"]').fill('GuardianFirst GuardianLast');
    await page.locator('input[name="contact_number"]').fill('09170009999');
    await page.locator('input[name="entry_point_label"][value="Transition from school to employment or entrepreneurship"]').check({ force: true });

    await page.evaluate(() => window.goToStep(2));

    await page.evaluate(() => window.goToStep(3));
    await page.locator('input[name="strengths"]').fill('ITP Strengths');
    await page.locator('input[name="interests"]').fill('ITP Interests');
    await page.locator('textarea[name="recommendations"]').fill(itpRecommendations);

    await page.evaluate(() => window.goToStep(5));
    await page.locator('input[name="transition_goal"]').fill('Livelihood');
    await page.locator('input[name="learning_packages"]').fill('Package A');
    await page.locator('textarea[name="goal_rows[0][competency]"]').fill('E2E Competency');
    await page.locator('textarea[name="goal_rows[0][activities]"]').fill('E2E Activities');
    await page.locator('select[name="goal_rows[0][time_frame]"]').selectOption('Whole Term');
    await page.locator('textarea[name="goal_rows[0][persons_responsible]"]').fill('E2E Person Responsible');
    await page.locator('input[name="prepared_by_name"]').fill('Prep Name');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save ITP Record")').click()
    ]);

    // Verify print view for ITP
    await page.goto(`/printITP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(itpRecommendations);

    // ==========================================
    // 6. Edit ITP Form
    // ==========================================
    await page.goto(`/itp_form.php?learner_id=${encodeURIComponent(lrn)}`);
    const itpRecommendationsEdited = `${itpRecommendations}-Edited`;
    await page.evaluate(() => window.goToStep(3));
    await page.locator('textarea[name="recommendations"]').fill(itpRecommendationsEdited);

    await page.evaluate(() => window.goToStep(5));
    await page.locator('textarea[name="goal_rows[0][competency]"]').fill('E2E Competency');
    await page.locator('textarea[name="goal_rows[0][activities]"]').fill('E2E Activities');
    await page.locator('select[name="goal_rows[0][time_frame]"]').selectOption('Whole Term');
    await page.locator('textarea[name="goal_rows[0][persons_responsible]"]').fill('E2E Person Responsible');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save ITP Record")').click()
    ]);

    // Verify print updates for ITP
    await page.goto(`/printITP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(itpRecommendationsEdited);

    // ==========================================
    // 7. Create ILP Form
    // ==========================================
    await page.goto(`/ilp_form.php?learner_id=${encodeURIComponent(lrn)}&document_type=ILP`);
    const ilpCognitive = `ILP-Cog-${randomSuffix}`;
    await page.locator('input[name="adviser_name"]').fill('E2E Teacher');
    await page.locator('input[name="meeting_date"]').fill('2026-06-15');
    await page.locator('input[name="review_date"]').fill('2027-06-15');
    await page.locator('select[name="meeting_purpose"]').selectOption('Initial');

    await page.evaluate(() => window.goToStep(3));
    await page.locator('textarea[name="cognitive_strengths"]').fill(ilpCognitive);

    await page.evaluate(() => window.goToStep(6));
    await page.locator('textarea[name="goals[0][goal]"]').fill('E2E ILP SMART Goal');

    await page.evaluate(() => window.goToStep(7));
    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Record")').click()
    ]);

    // Verify print view for ILP
    await page.goto(`/printILP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(ilpCognitive.toUpperCase());

    // ==========================================
    // 8. Edit ILP Form
    // ==========================================
    await page.goto(`/ilp_form.php?learner_id=${encodeURIComponent(lrn)}&document_type=ILP`);
    const ilpCognitiveEdited = `${ilpCognitive}-Edited`;
    await page.evaluate(() => window.goToStep(3));
    await page.locator('textarea[name="cognitive_strengths"]').fill(ilpCognitiveEdited);

    await page.evaluate(() => window.goToStep(7));
    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Record")').click()
    ]);

    // Verify print updates for ILP
    await page.goto(`/printILP.php?learner_id=${encodeURIComponent(lrn)}`);
    await expect(page.locator('body')).toContainText(ilpCognitiveEdited.toUpperCase());

    // ==========================================
    // 9. Create BIR Form
    // ==========================================
    const targetSy = schoolYear;
    await page.goto(`/bir_form.php?learner_id=${encodeURIComponent(lrn)}&q=Q1&sy=${targetSy}`);
    const birObjectives = `BIR-Obj-${randomSuffix}`;
    await page.locator('select[name="quarter"]').selectOption('Q1');
    await page.locator('input[name="date_of_observation"]').fill('2026-06-15');
    await page.locator('input[name="teacher_name"]').fill('E2E Teacher');
    await page.locator('select[name="educational_placement"]').selectOption('Self-Contained');
    await page.locator('textarea[name="difficulty_disability"]').fill('Behavior concerns');
    await page.locator('textarea[name="antecedent_home"]').fill('E2E Antecedent Home');
    await page.locator('textarea[name="antecedent_school"]').fill('E2E Antecedent School');
    await page.locator('textarea[name="antecedent_other"]').fill('E2E Antecedent Other');
    await page.locator('textarea[name="observable_home"]').fill('E2E Observable Home');
    await page.locator('textarea[name="observable_school"]').fill('E2E Observable School');
    await page.locator('textarea[name="observable_other"]').fill('E2E Observable Other');
    await page.locator('textarea[name="consequence_home"]').fill('E2E Consequence Home');
    await page.locator('textarea[name="consequence_school"]').fill('E2E Consequence School');
    await page.locator('textarea[name="consequence_other"]').fill('E2E Consequence Other');
    await page.locator('textarea[name="targeted_behavior"]').fill('E2E Problem Behavior');
    await page.locator('textarea[name="specific_objectives"]').fill(birObjectives);
    await page.locator('textarea[name="proactive_strategies"]').fill('E2E Proactive Strategies');
    await page.locator('textarea[name="reactive_strategies"]').fill('E2E Reactive Strategies');

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Report")').click()
    ]);

    // Verify print view for BIR
    await page.goto(`/printBIR.php?learner_id=${encodeURIComponent(lrn)}&q=Q1&sy=${targetSy}`);
    await expect(page.locator('body')).toContainText(birObjectives);

    // ==========================================
    // 10. Edit BIR Form
    // ==========================================
    await page.goto(`/bir_form.php?learner_id=${encodeURIComponent(lrn)}&q=Q1&sy=${targetSy}`);
    const birObjectivesEdited = `${birObjectives}-Edited`;
    await page.locator('textarea[name="specific_objectives"]').fill(birObjectivesEdited);

    await Promise.all([
      page.waitForURL(/viewLearner\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Report")').click()
    ]);

    // Verify print updates for BIR
    await page.goto(`/printBIR.php?learner_id=${encodeURIComponent(lrn)}&q=Q1&sy=${targetSy}`);
    await expect(page.locator('body')).toContainText(birObjectivesEdited);

    // ==========================================
    // 11. Create & Edit SF9 Details
    // ==========================================
    await page.goto(`/sf9_form.php?learner_id=${encodeURIComponent(lrn)}`);
    const sf9Remarks = `SF9-Remarks-${randomSuffix}`;
    await page.locator('select[name="assessment_status"]').selectOption('With');
    await page.locator('textarea[name="quarter_notes[1][teacher_remarks]"]').fill(sf9Remarks);

    await Promise.all([
      page.waitForURL(/sf9_form\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Progress Report")').click()
    ]);

    // Verify print view for SF9
    await page.goto(`/printAnnex2.php?learner_id=${encodeURIComponent(lrn)}&school_year=${encodeURIComponent(schoolYear)}`);
    await expect(page.locator('body')).toContainText(sf9Remarks);

    // Edit SF9 Details
    await page.goto(`/sf9_form.php?learner_id=${encodeURIComponent(lrn)}`);
    const sf9RemarksEdited = `${sf9Remarks}-Edited`;
    await page.locator('textarea[name="quarter_notes[1][teacher_remarks]"]').fill(sf9RemarksEdited);

    await Promise.all([
      page.waitForURL(/sf9_form\.php/, { timeout: 30_000 }),
      page.locator('button[type="submit"]:has-text("Save Progress Report")').click()
    ]);

    // Verify print updates for SF9
    await page.goto(`/printAnnex2.php?learner_id=${encodeURIComponent(lrn)}&school_year=${encodeURIComponent(schoolYear)}`);
    await expect(page.locator('body')).toContainText(sf9RemarksEdited);
  });
});
