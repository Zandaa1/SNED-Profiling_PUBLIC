<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: learners.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("Invalid CSRF token.");
}

$lrn = $_POST['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner ID.");
}

// Load learner for scope check
$stmt = $conn->prepare("
    SELECT e.school_id, e.id as enrollment_id
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();

if (!$current) {
    die("Learner has no active enrollment or does not exist.");
}

if (!user_is_admin($user_role) && user_is_scoped($user_role)) {
    $session_school_id = $_SESSION['school_id'] ?? 0;
    if (!check_teacher_learner_access($conn, $lrn, true)) {
        header("Location: 403.php");
        exit();
    }
}

$school_year = $_POST['school_year'] ?? '';
$school_year = trim($school_year);
if ($school_year === '') {
    die("School year is required.");
}
$enrollment_id = !empty($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : null;

// Section 1: Meeting data
$meeting_date = !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null;
$date_of_last_iep = !empty($_POST['date_of_last_iep']) ? $_POST['date_of_last_iep'] : null;
$iep_review_date = !empty($_POST['iep_review_date']) ? $_POST['iep_review_date'] : null;
$purpose_of_meeting = $_POST['purpose_of_meeting'] ?? '';
$meeting_comments = $_POST['meeting_comments'] ?? '';
$interpreter_needed = $_POST['interpreter_needed'] ?? '';
$medical_diagnosis = ($_POST['medical_diagnosis'] ?? '0') === '1' ? 1 : 0;
$medical_diagnosis_specify = $_POST['medical_diagnosis_specify'] ?? '';

// Areas of difficulty
$difficulties = $_POST['difficulties'] ?? [];
$diff_seeing = in_array('diff_seeing', $difficulties) ? 1 : 0;
$diff_hearing = in_array('diff_hearing', $difficulties) ? 1 : 0;
$diff_communicating = in_array('diff_communicating', $difficulties) ? 1 : 0;
$diff_mobility = in_array('diff_mobility', $difficulties) ? 1 : 0;
$diff_interpersonal = in_array('diff_interpersonal', $difficulties) ? 1 : 0;
$diff_adaptive = in_array('diff_adaptive', $difficulties) ? 1 : 0;
$diff_basic_skills = in_array('diff_basic_skills', $difficulties) ? 1 : 0;
$diff_remembering = in_array('diff_remembering', $difficulties) ? 1 : 0;
$diff_others = in_array('diff_others_check', $difficulties) ? ($_POST['diff_others_text'] ?? '') : '';

// IEP Team
$team_roles = $_POST['team_role'] ?? [];
$team_names = $_POST['team_name'] ?? [];
$team_members = [];
foreach ($team_roles as $i => $role) {
    if (!empty($role) && !empty($team_names[$i])) {
        $team_members[] = ['role' => $role, 'name' => $team_names[$i]];
    }
}
$iep_team_json = json_encode($team_members, JSON_UNESCAPED_UNICODE);

// Signatures
$learner_signature_date = !empty($_POST['learner_signature_date']) ? $_POST['learner_signature_date'] : null;
$parent_signature_date = !empty($_POST['parent_signature_date']) ? $_POST['parent_signature_date'] : null;
$rights_notified = isset($_POST['rights_notified']) ? 1 : 0;

// Extra personal info (stored in special_factors_json)
$extra_info = [
    'mother_tongue' => $_POST['mother_tongue'] ?? '',
    'home_address' => $_POST['home_address'] ?? '',
    'learner_phone' => $_POST['learner_phone'] ?? '',
    'guardian_name' => $_POST['guardian_name'] ?? '',
    'guardian_workplace' => $_POST['guardian_workplace'] ?? '',
    'guardian_landline' => $_POST['guardian_landline'] ?? '',
    'guardian_mobile' => $_POST['guardian_mobile'] ?? '',
    'guardian_email' => $_POST['guardian_email'] ?? '',
    'guardian_mother_tongue' => $_POST['guardian_mother_tongue'] ?? '',
];

// Section 2: Present Levels
$present_eval_results = $_POST['present_eval_results'] ?? '';
$academic_strengths = $_POST['academic_strengths'] ?? '';
$academic_needs = $_POST['academic_needs'] ?? '';
$parental_concerns = $_POST['parental_concerns'] ?? '';
$disability_impact = $_POST['disability_impact'] ?? '';

// Special Factors
$sf_raw = $_POST['sf'] ?? [];
$special_factors_json = json_encode($sf_raw, JSON_UNESCAPED_UNICODE);

// Section 5A: Transition point (single selection)
$selected_transition = $_POST['transition_point'] ?? '';
$transition_home_to_school = ($selected_transition === 'transition_home_to_school') ? 1 : 0;
$transition_school_to_life = ($selected_transition === 'transition_school_to_life') ? 1 : 0;
$transition_sped_to_inclusion = ($selected_transition === 'transition_sped_to_inclusion') ? 1 : 0;
$transition_grade_to_grade = ($selected_transition === 'transition_grade_to_grade') ? 1 : 0;
$transition_school_to_work = ($selected_transition === 'transition_school_to_work') ? 1 : 0;
$transition_class_to_class = ($selected_transition === 'transition_class_to_class') ? 1 : 0;

// Merge extra info into special_factors_json
$sf_data = $sf_raw;
$sf_data = array_merge($sf_data, $extra_info);
$special_factors_json = json_encode($sf_data, JSON_UNESCAPED_UNICODE);

$conn->begin_transaction();
try {
    // Upsert learner_iep_annex6
    $stmt_check = $conn->prepare("SELECT id FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? LIMIT 1");
    $stmt_check->bind_param("ss", $lrn, $school_year);
    $stmt_check->execute();
    $existing = $stmt_check->get_result()->fetch_assoc();

    if ($existing) {
        $iep_id = $existing['id'];
        $stmt_upd = $conn->prepare("
            UPDATE learner_iep_annex6 SET
                school_year = ?, meeting_date = ?, date_of_last_iep = ?, iep_review_date = ?,
                purpose_of_meeting = ?, meeting_comments = ?, interpreter_needed = ?,
                medical_diagnosis = ?, medical_diagnosis_specify = ?,
                diff_seeing = ?, diff_hearing = ?, diff_communicating = ?, diff_mobility = ?,
                diff_interpersonal = ?, diff_adaptive = ?, diff_basic_skills = ?, diff_remembering = ?, diff_others = ?,
                iep_team_json = ?, learner_signature_date = ?, parent_signature_date = ?, rights_notified = ?,
                present_eval_results = ?, academic_strengths = ?, academic_needs = ?,
                parental_concerns = ?, disability_impact = ?,
                special_factors_json = ?,
                transition_home_to_school = ?, transition_school_to_life = ?, transition_sped_to_inclusion = ?,
                transition_grade_to_grade = ?, transition_school_to_work = ?, transition_class_to_class = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $stmt_upd->bind_param(
            "sssssssisiiiiiiiissssisssssssiiiiiii",
            $school_year, $meeting_date, $date_of_last_iep, $iep_review_date,
            $purpose_of_meeting, $meeting_comments, $interpreter_needed,
            $medical_diagnosis, $medical_diagnosis_specify,
            $diff_seeing, $diff_hearing, $diff_communicating, $diff_mobility,
            $diff_interpersonal, $diff_adaptive, $diff_basic_skills, $diff_remembering, $diff_others,
            $iep_team_json, $learner_signature_date, $parent_signature_date, $rights_notified,
            $present_eval_results, $academic_strengths, $academic_needs,
            $parental_concerns, $disability_impact,
            $special_factors_json,
            $transition_home_to_school, $transition_school_to_life, $transition_sped_to_inclusion,
            $transition_grade_to_grade, $transition_school_to_work, $transition_class_to_class,
            $_SESSION['user_id'], $iep_id
        );
        $stmt_upd->execute();
        $action = 'update';
    } else {
        $stmt_ins = $conn->prepare("
            INSERT INTO learner_iep_annex6 (
                learner_id, enrollment_id, school_year,
                meeting_date, date_of_last_iep, iep_review_date, purpose_of_meeting, meeting_comments, interpreter_needed,
                medical_diagnosis, medical_diagnosis_specify,
                diff_seeing, diff_hearing, diff_communicating, diff_mobility,
                diff_interpersonal, diff_adaptive, diff_basic_skills, diff_remembering, diff_others,
                iep_team_json, learner_signature_date, parent_signature_date, rights_notified,
                present_eval_results, academic_strengths, academic_needs,
                parental_concerns, disability_impact,
                special_factors_json,
                transition_home_to_school, transition_school_to_life, transition_sped_to_inclusion,
                transition_grade_to_grade, transition_school_to_work, transition_class_to_class,
                created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_ins->bind_param(
            "sisssssssisiiiiiiiissssisssssssiiiiiii",
            $lrn, $enrollment_id, $school_year,
            $meeting_date, $date_of_last_iep, $iep_review_date, $purpose_of_meeting, $meeting_comments, $interpreter_needed,
            $medical_diagnosis, $medical_diagnosis_specify,
            $diff_seeing, $diff_hearing, $diff_communicating, $diff_mobility,
            $diff_interpersonal, $diff_adaptive, $diff_basic_skills, $diff_remembering, $diff_others,
            $iep_team_json, $learner_signature_date, $parent_signature_date, $rights_notified,
            $present_eval_results, $academic_strengths, $academic_needs,
            $parental_concerns, $disability_impact,
            $special_factors_json,
            $transition_home_to_school, $transition_school_to_life, $transition_sped_to_inclusion,
            $transition_grade_to_grade, $transition_school_to_work, $transition_class_to_class,
            $_SESSION['user_id'], $_SESSION['user_id']
        );
        $stmt_ins->execute();
        $iep_id = $conn->insert_id;
        $action = 'create';
    }

    // Clear existing rows
    $stmt_clear = $conn->prepare("DELETE FROM learner_iep_annex6_rows WHERE iep_id = ?");
    $stmt_clear->bind_param("i", $iep_id);
    $stmt_clear->execute();

    // Insert barriers
    $barriers = $_POST['barriers'] ?? [];
    if (is_array($barriers)) {
        $stmt_b = $conn->prepare("
            INSERT INTO learner_iep_annex6_rows
                (iep_id, section_type, sort_order, difficulty, interest, barrier_qualifier, facilitator, facilitator_qualifier, accommodations)
            VALUES (?, 'barrier', ?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($barriers as $b) {
            $difficulty = $b['difficulty'] ?? '';
            if (empty($difficulty)) continue;
            $barrier_text = $b['barrier_text'] ?? '';
            $barrier_qualifier = $b['barrier_qualifier'] ?? '';
            $facilitator = $b['facilitator'] ?? '';
            $facilitator_qualifier = $b['facilitator_qualifier'] ?? '';
            $accommodations = $b['accommodations'] ?? '';
            $stmt_b->bind_param("iissssss", $iep_id, $order, $difficulty, $barrier_text, $barrier_qualifier, $facilitator, $facilitator_qualifier, $accommodations);
            $stmt_b->execute();
            $order++;
        }
    }

    // Insert goals
    $goals = $_POST['goals'] ?? [];
    if (is_array($goals)) {
        $stmt_g = $conn->prepare("
            INSERT INTO learner_iep_annex6_rows
                (iep_id, section_type, sort_order, interest, goal, interventions, timeline, responsible, remarks, progress)
            VALUES (?, 'goal', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($goals as $g) {
            $interest = $g['interest'] ?? '';
            $goal_text = $g['goal'] ?? '';
            if (empty($interest) && empty($goal_text)) continue;
            $interventions = $g['interventions'] ?? '';
            $timeline = $g['timeline'] ?? '';
            $responsible = $g['responsible'] ?? '';
            $remarks = $g['remarks'] ?? '';
            $progress = $g['progress'] ?? '';
            $stmt_g->bind_param("iisssssss", $iep_id, $order, $interest, $goal_text, $interventions, $timeline, $responsible, $remarks, $progress);
            $stmt_g->execute();
            $order++;
        }
    }

    // Insert transitions
    $transitions = $_POST['transitions'] ?? [];
    if (is_array($transitions)) {
        $stmt_t = $conn->prepare("
            INSERT INTO learner_iep_annex6_rows
                (iep_id, section_type, sort_order, interest, work_opportunities, interventions, responsible, remarks)
            VALUES (?, 'transition', ?, ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($transitions as $t) {
            $interest = $t['interest'] ?? '';
            if (empty($interest)) continue;
            $work_opp = $t['work_opportunities'] ?? '';
            $interventions = $t['interventions'] ?? '';
            $responsible = $t['responsible'] ?? '';
            $remarks = $t['remarks'] ?? '';
            $stmt_t->bind_param("iisssss", $iep_id, $order, $interest, $work_opp, $interventions, $responsible, $remarks);
            $stmt_t->execute();
            $order++;
        }
    }

    $conn->commit();
    log_transaction($conn, $_SESSION['user_id'], $action === 'create' ? 'create_iep' : 'update_iep', 'learners', $lrn, "Saved IEP record for LRN: $lrn");

    header("Location: viewLearner.php?id=" . urlencode($lrn) . "&tab=iep&saved=1");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    die("Database error: " . $e->getMessage());
}
