<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

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

// CSRF check
if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("Invalid CSRF token.");
}

function ilp_upper($value) {
    $value = trim((string)($value ?? ''));
    if ($value === '') return '';
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

$lrn = $_POST['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner ID.");
}

// Check learner and school scope
$stmt = $conn->prepare("
    SELECT e.school_id 
    FROM learners l 
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE l.lrn = ? AND l.is_deleted = 0 
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$current_enroll = $stmt->get_result()->fetch_assoc();

if (!$current_enroll) {
    die("Learner has no active enrollment or does not exist.");
}

// Enforce school scope for coordinators
if (!user_is_admin($user_role)) {
    $session_school_id = $_SESSION['school_id'] ?? 0;
    if (!check_teacher_learner_access($conn, $lrn, true)) {
        header("Location: 403.php");
        exit();
    }
}

// Get form parameters
$document_type = $_POST['document_type'] ?? 'ILP';
if (!in_array($document_type, ['IEP', 'ILP'])) {
    $document_type = 'ILP';
}

$school_year = $_POST['school_year'] ?? '';
$adviser_name = ilp_upper($_POST['adviser_name'] ?? '');
$adviser_id = null;
if (!empty($adviser_name)) {
    $stmt_adv = $conn->prepare("SELECT id FROM users WHERE full_name = ? LIMIT 1");
    $stmt_adv->bind_param("s", $adviser_name);
    $stmt_adv->execute();
    $adv_user = $stmt_adv->get_result()->fetch_assoc();
    if ($adv_user) {
        $adviser_id = $adv_user['id'];
    }
}
$meeting_date = !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null;
$review_date = !empty($_POST['review_date']) ? $_POST['review_date'] : null;
$meeting_purpose = ilp_upper($_POST['meeting_purpose'] ?? '');
$interview_date = !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
$interview_guardian_name = ilp_upper($_POST['interview_guardian_name'] ?? '');
$interview_guardian_contact = ilp_upper($_POST['interview_guardian_contact'] ?? '');
$interests_hobbies = ilp_upper($_POST['interests_hobbies'] ?? '');

// 7 Domains
$daily_living_strengths = ilp_upper($_POST['daily_living_strengths'] ?? '');
$daily_living_needs = ilp_upper($_POST['daily_living_needs'] ?? '');
$language_strengths = ilp_upper($_POST['language_strengths'] ?? '');
$language_needs = ilp_upper($_POST['language_needs'] ?? '');
$psychomotor_strengths = ilp_upper($_POST['psychomotor_strengths'] ?? '');
$psychomotor_needs = ilp_upper($_POST['psychomotor_needs'] ?? '');
$cognitive_strengths = ilp_upper($_POST['cognitive_strengths'] ?? '');
$cognitive_needs = ilp_upper($_POST['cognitive_needs'] ?? '');
$aesthetic_strengths = ilp_upper($_POST['aesthetic_strengths'] ?? '');
$aesthetic_needs = ilp_upper($_POST['aesthetic_needs'] ?? '');
$behavioral_strengths = ilp_upper($_POST['behavioral_strengths'] ?? '');
$behavioral_needs = ilp_upper($_POST['behavioral_needs'] ?? '');
$orientation_strengths = ilp_upper($_POST['orientation_strengths'] ?? '');
$orientation_needs = ilp_upper($_POST['orientation_needs'] ?? '');

// Present levels and Section B/C/D
$present_level_results = ilp_upper($_POST['present_level_results'] ?? '');
$parental_concerns = ilp_upper($_POST['parental_concerns'] ?? '');
$disability_impact = ilp_upper($_POST['disability_impact'] ?? '');
$priority_needs = ilp_upper($_POST['priority_needs'] ?? '');

// Special Factors JSON
$sf_arr = [];
if (isset($_POST['sf']) && is_array($_POST['sf'])) {
    foreach ($_POST['sf'] as $key => $val) {
        $sf_arr[$key] = [
            'yesno' => $val['yesno'] ?? '0',
            'notes' => ilp_upper($val['notes'] ?? '')
        ];
    }
}
$special_factors_json = !empty($sf_arr) ? json_encode($sf_arr) : null;

// Barriers JSON
$barriers_arr = [];
if (isset($_POST['barriers']) && is_array($_POST['barriers'])) {
    foreach ($_POST['barriers'] as $b) {
        $diff = $b['difficulty'] ?? '';
        if (!empty($diff)) {
            $barriers_arr[] = [
                'difficulty' => $diff,
                'barrier_text' => ilp_upper($b['barrier_text'] ?? ''),
                'barrier_qualifier' => $b['barrier_qualifier'] ?? '',
                'facilitator' => ilp_upper($b['facilitator'] ?? ''),
                'facilitator_qualifier' => $b['facilitator_qualifier'] ?? '',
                'accommodations' => ilp_upper($b['accommodations'] ?? '')
            ];
        }
    }
}
$barriers_json = !empty($barriers_arr) ? json_encode($barriers_arr) : null;

$transition_interests = ilp_upper($_POST['transition_interests'] ?? '');
$transition_work_opportunities = !empty($_POST['transition_work_opportunities']) ? json_encode($_POST['transition_work_opportunities']) : '';
$transition_skills = ilp_upper($_POST['transition_skills'] ?? '');

$rights_transfer_notified = isset($_POST['rights_transfer_notified']) ? 1 : 0;
$rights_not_applicable = isset($_POST['rights_not_applicable']) ? 1 : 0;

// Assessments
$assessments_arr = [];
if (isset($_POST['assessments']) && is_array($_POST['assessments'])) {
    foreach ($_POST['assessments'] as $a) {
        $a_type = ilp_upper($a['assessment_type'] ?? '');
        if (!empty($a_type)) {
            $assessments_arr[] = [
                'assessment_type' => $a_type,
                'date_administered' => !empty($a['date_administered']) ? $a['date_administered'] : null,
                'chronological_age' => ilp_upper($a['chronological_age'] ?? ''),
                'administrator' => ilp_upper($a['administrator'] ?? ''),
                'results' => ilp_upper($a['results'] ?? '')
            ];
        }
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Check if record already exists
    $stmt_check = $conn->prepare("SELECT id FROM learner_ilp WHERE learner_id = ? AND school_year = ? AND document_type = ? LIMIT 1");
    $stmt_check->bind_param("sss", $lrn, $school_year, $document_type);
    $stmt_check->execute();
    $existing_ilp = $stmt_check->get_result()->fetch_assoc();

    $ilp_id = null;
    if ($existing_ilp) {
        $ilp_id = $existing_ilp['id'];
        $stmt_update = $conn->prepare("
            UPDATE learner_ilp SET
                adviser_id = ?,
                adviser_name = ?,
                meeting_date = ?,
                review_date = ?,
                meeting_purpose = ?,
                interview_date = ?,
                interview_guardian_name = ?,
                interview_guardian_contact = ?,
                interests_hobbies = ?,
                daily_living_strengths = ?, daily_living_needs = ?,
                language_strengths = ?, language_needs = ?,
                psychomotor_strengths = ?, psychomotor_needs = ?,
                cognitive_strengths = ?, cognitive_needs = ?,
                aesthetic_strengths = ?, aesthetic_needs = ?,
                behavioral_strengths = ?, behavioral_needs = ?,
                orientation_strengths = ?, orientation_needs = ?,
                present_level_results = ?,
                parental_concerns = ?,
                disability_impact = ?,
                special_factors = ?,
                barriers_json = ?,
                transition_interests = ?,
                transition_work_opportunities = ?,
                transition_skills = ?,
                priority_needs = ?,
                rights_transfer_notified = ?,
                rights_not_applicable = ?,
                updated_by = ?
            WHERE id = ?
        ");
        
        $types = 'is' . str_repeat('s', 30) . 'iiii';
        $stmt_update->bind_param(
            $types,
            $adviser_id, $adviser_name, $meeting_date, $review_date, $meeting_purpose, $interview_date,
            $interview_guardian_name, $interview_guardian_contact, $interests_hobbies,
            $daily_living_strengths, $daily_living_needs,
            $language_strengths, $language_needs,
            $psychomotor_strengths, $psychomotor_needs,
            $cognitive_strengths, $cognitive_needs,
            $aesthetic_strengths, $aesthetic_needs,
            $behavioral_strengths, $behavioral_needs,
            $orientation_strengths, $orientation_needs,
            $present_level_results, $parental_concerns, $disability_impact,
            $special_factors_json, $barriers_json,
            $transition_interests, $transition_work_opportunities, $transition_skills, $priority_needs,
            $rights_transfer_notified, $rights_not_applicable,
            $_SESSION['user_id'], $ilp_id
        );
        $stmt_update->execute();
        $action = 'update';
    } else {
        $stmt_insert = $conn->prepare("
            INSERT INTO learner_ilp (
                learner_id, school_year, document_type, adviser_id, adviser_name,
                meeting_date, review_date, meeting_purpose, interview_date,
                interview_guardian_name, interview_guardian_contact, interests_hobbies,
                daily_living_strengths, daily_living_needs,
                language_strengths, language_needs,
                psychomotor_strengths, psychomotor_needs,
                cognitive_strengths, cognitive_needs,
                aesthetic_strengths, aesthetic_needs,
                behavioral_strengths, behavioral_needs,
                orientation_strengths, orientation_needs,
                present_level_results, parental_concerns, disability_impact,
                special_factors, barriers_json,
                transition_interests, transition_work_opportunities, transition_skills, priority_needs,
                rights_transfer_notified, rights_not_applicable,
                created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $types = 'sssis' . str_repeat('s', 30) . 'iiii';
        $stmt_insert->bind_param(
            $types,
            $lrn, $school_year, $document_type, $adviser_id, $adviser_name,
            $meeting_date, $review_date, $meeting_purpose, $interview_date,
            $interview_guardian_name, $interview_guardian_contact, $interests_hobbies,
            $daily_living_strengths, $daily_living_needs,
            $language_strengths, $language_needs,
            $psychomotor_strengths, $psychomotor_needs,
            $cognitive_strengths, $cognitive_needs,
            $aesthetic_strengths, $aesthetic_needs,
            $behavioral_strengths, $behavioral_needs,
            $orientation_strengths, $orientation_needs,
            $present_level_results, $parental_concerns, $disability_impact,
            $special_factors_json, $barriers_json,
            $transition_interests, $transition_work_opportunities, $transition_skills, $priority_needs,
            $rights_transfer_notified, $rights_not_applicable,
            $_SESSION['user_id'], $_SESSION['user_id']
        );
        $stmt_insert->execute();
        $ilp_id = $conn->insert_id;
        $action = 'create';
    }

    // Process goals (ilp_goals)
    $stmt_clear_g = $conn->prepare("DELETE FROM ilp_goals WHERE ilp_id = ?");
    $stmt_clear_g->bind_param("i", $ilp_id);
    $stmt_clear_g->execute();

    if (isset($_POST['goals']) && is_array($_POST['goals'])) {
        $stmt_ins_goal = $conn->prepare("
            INSERT INTO ilp_goals (
                ilp_id, interest, goal, interventions, timeline, persons_responsible, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($_POST['goals'] as $g) {
            $g_interest = ilp_upper($g['interest'] ?? '');
            $g_goal = ilp_upper($g['goal'] ?? '');
            $g_interventions = ilp_upper($g['interventions'] ?? '');
            $g_timeline = ilp_upper($g['timeline'] ?? '');
            $g_responsible = ilp_upper($g['responsible'] ?? '');
            $g_remarks = ilp_upper($g['remarks'] ?? '');
            
            if (!empty($g_goal)) {
                $stmt_ins_goal->bind_param("issssss", $ilp_id, $g_interest, $g_goal, $g_interventions, $g_timeline, $g_responsible, $g_remarks);
                $stmt_ins_goal->execute();
            }
        }
    }

    // Process team members (ilp_team_members)
    $stmt_clear_t = $conn->prepare("DELETE FROM ilp_team_members WHERE ilp_id = ?");
    $stmt_clear_t->bind_param("i", $ilp_id);
    $stmt_clear_t->execute();

    if (isset($_POST['team_role']) && is_array($_POST['team_role'])) {
        $stmt_ins_team = $conn->prepare("
            INSERT INTO ilp_team_members (ilp_id, role, full_name) VALUES (?, ?, ?)
        ");
        foreach ($_POST['team_role'] as $idx => $role) {
            $role = ilp_upper($role);
            $name = ilp_upper($_POST['team_name'][$idx] ?? '');
            if (!empty($role) && !empty($name)) {
                $stmt_ins_team->bind_param("iss", $ilp_id, $role, $name);
                $stmt_ins_team->execute();
            }
        }
    }

    // Process assessments (learner_ilp_assessments)
    $stmt_clear_a = $conn->prepare("DELETE FROM learner_ilp_assessments WHERE ilp_id = ?");
    $stmt_clear_a->bind_param("i", $ilp_id);
    $stmt_clear_a->execute();

    if (!empty($assessments_arr)) {
        $stmt_ins_a = $conn->prepare("
            INSERT INTO learner_ilp_assessments (ilp_id, assessment_type, date_administered, chronological_age, administrator, results)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($assessments_arr as $a) {
            $stmt_ins_a->bind_param("isssss", $ilp_id, $a['assessment_type'], $a['date_administered'], $a['chronological_age'], $a['administrator'], $a['results']);
            $stmt_ins_a->execute();
        }
    }

    // Audit Log
    $log_action = ($action === 'create') ? 'create_ilp' : 'update_ilp';
    $log_desc = "Saved " . $document_type . " record for LRN: " . $lrn . " (SY: " . $school_year . ")";
    log_transaction($conn, $_SESSION['user_id'], $log_action, 'ilp', $lrn, $log_desc);

    $conn->commit();

    header("Location: viewLearner.php?id=" . urlencode($lrn) . "&tab=" . strtolower($document_type) . "&saved=1");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    die("Database error: " . $e->getMessage());
}
