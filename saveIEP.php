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

// CSRF validation
if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("Invalid CSRF token.");
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

$school_year = $_POST['school_year'] ?? '';
$meeting_date = !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null;
$last_iep_date = !empty($_POST['last_iep_date']) ? $_POST['last_iep_date'] : null;
$iep_type = $_POST['iep_type'] ?? '';

// Difficulties checklist (9 booleans)
$diff_seeing = isset($_POST['diff_seeing']) ? 1 : 0;
$diff_hearing = isset($_POST['diff_hearing']) ? 1 : 0;
$diff_communicating = isset($_POST['diff_communicating']) ? 1 : 0;
$diff_mobility = isset($_POST['diff_mobility']) ? 1 : 0;
$diff_interpersonal = isset($_POST['diff_interpersonal']) ? 1 : 0;
$diff_adaptive = isset($_POST['diff_adaptive']) ? 1 : 0;
$diff_basic_skills = isset($_POST['diff_basic_skills']) ? 1 : 0;
$diff_remembering = isset($_POST['diff_remembering']) ? 1 : 0;
$diff_others = $_POST['diff_others'] ?? '';

// Section A: Team members
$team_psychologist = $_POST['team_psychologist'] ?? '';
$team_guidance = $_POST['team_guidance'] ?? '';
$team_nurse = $_POST['team_nurse'] ?? '';
$team_therapist = $_POST['team_therapist'] ?? '';
$team_speech_pathologist = $_POST['team_speech_pathologist'] ?? '';
$team_other = $_POST['team_other'] ?? '';

// Present levels
$present_eval_results = $_POST['present_eval_results'] ?? '';
$academic_strengths = $_POST['academic_strengths'] ?? '';
$academic_needs = $_POST['academic_needs'] ?? '';
$parental_concerns = $_POST['parental_concerns'] ?? '';
$disability_impact = $_POST['disability_impact'] ?? '';

// Special considerations (10 flags)
$sc_behavior = isset($_POST['sc_behavior']) ? 1 : 0;
$sc_behavior_plan = isset($_POST['sc_behavior_plan']) ? 1 : 0;
$sc_mobility = isset($_POST['sc_mobility']) ? 1 : 0;
$sc_vision = isset($_POST['sc_vision']) ? 1 : 0;
$sc_communication = isset($_POST['sc_communication']) ? 1 : 0;
$sc_attention = isset($_POST['sc_attention']) ? 1 : 0;
$sc_memory = isset($_POST['sc_memory']) ? 1 : 0;
$sc_hearing = isset($_POST['sc_hearing']) ? 1 : 0;
$sc_assistive_tech = isset($_POST['sc_assistive_tech']) ? 1 : 0;
$sc_alt_format = isset($_POST['sc_alt_format']) ? 1 : 0;
$sc_notes = $_POST['sc_notes'] ?? '';

// Section B: Barriers and Accommodations notes
$barriers_notes = $_POST['barriers_notes'] ?? '';
$accommodations = $_POST['accommodations'] ?? '';

// Start database transaction
$conn->begin_transaction();

try {
    // Check if IEP already exists
    $stmt_check = $conn->prepare("SELECT id FROM learner_iep WHERE learner_id = ? LIMIT 1");
    $stmt_check->bind_param("s", $lrn);
    $stmt_check->execute();
    $existing_iep = $stmt_check->get_result()->fetch_assoc();

    $iep_id = null;
    if ($existing_iep) {
        $iep_id = $existing_iep['id'];
        $stmt_update = $conn->prepare("
            UPDATE learner_iep SET
                meeting_date = ?,
                last_iep_date = ?,
                iep_type = ?,
                diff_seeing = ?,
                diff_hearing = ?,
                diff_communicating = ?,
                diff_mobility = ?,
                diff_interpersonal = ?,
                diff_adaptive = ?,
                diff_basic_skills = ?,
                diff_remembering = ?,
                diff_others = ?,
                team_psychologist = ?,
                team_guidance = ?,
                team_nurse = ?,
                team_therapist = ?,
                team_speech_pathologist = ?,
                team_other = ?,
                present_eval_results = ?,
                academic_strengths = ?,
                academic_needs = ?,
                parental_concerns = ?,
                disability_impact = ?,
                sc_behavior = ?,
                sc_behavior_plan = ?,
                sc_mobility = ?,
                sc_vision = ?,
                sc_communication = ?,
                sc_attention = ?,
                sc_memory = ?,
                sc_hearing = ?,
                sc_assistive_tech = ?,
                sc_alt_format = ?,
                sc_notes = ?,
                barriers_notes = ?,
                accommodations = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $stmt_update->bind_param(
            "sssiiiiiiiisssssssssssiiiiiiiiiiisssii",
            $meeting_date, $last_iep_date, $iep_type,
            $diff_seeing, $diff_hearing, $diff_communicating, $diff_mobility, $diff_interpersonal, $diff_adaptive, $diff_basic_skills, $diff_remembering, $diff_others,
            $team_psychologist, $team_guidance, $team_nurse, $team_therapist, $team_speech_pathologist, $team_other,
            $present_eval_results, $academic_strengths, $academic_needs, $parental_concerns, $disability_impact,
            $sc_behavior, $sc_behavior_plan, $sc_mobility, $sc_vision, $sc_communication, $sc_attention, $sc_memory, $sc_hearing, $sc_assistive_tech, $sc_alt_format,
            $sc_notes, $barriers_notes, $accommodations, $_SESSION['user_id'], $iep_id
        );
        $stmt_update->execute();
        $action = 'update';
    } else {
        $stmt_insert = $conn->prepare("
            INSERT INTO learner_iep (
                learner_id, school_year, meeting_date, last_iep_date, iep_type,
                diff_seeing, diff_hearing, diff_communicating, diff_mobility, diff_interpersonal, diff_adaptive, diff_basic_skills, diff_remembering, diff_others,
                team_psychologist, team_guidance, team_nurse, team_therapist, team_speech_pathologist, team_other,
                present_eval_results, academic_strengths, academic_needs, parental_concerns, disability_impact,
                sc_behavior, sc_behavior_plan, sc_mobility, sc_vision, sc_communication, sc_attention, sc_memory, sc_hearing, sc_assistive_tech, sc_alt_format,
                sc_notes, barriers_notes, accommodations, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_insert->bind_param(
            "sssssiiiiiiiisssssssssssiiiiiiiiiiisssii",
            $lrn, $school_year, $meeting_date, $last_iep_date, $iep_type,
            $diff_seeing, $diff_hearing, $diff_communicating, $diff_mobility, $diff_interpersonal, $diff_adaptive, $diff_basic_skills, $diff_remembering, $diff_others,
            $team_psychologist, $team_guidance, $team_nurse, $team_therapist, $team_speech_pathologist, $team_other,
            $present_eval_results, $academic_strengths, $academic_needs, $parental_concerns, $disability_impact,
            $sc_behavior, $sc_behavior_plan, $sc_mobility, $sc_vision, $sc_communication, $sc_attention, $sc_memory, $sc_hearing, $sc_assistive_tech, $sc_alt_format,
            $sc_notes, $barriers_notes, $accommodations, $_SESSION['user_id'], $_SESSION['user_id']
        );
        $stmt_insert->execute();
        $iep_id = $conn->insert_id;
        $action = 'create';
    }

    // Clear old goals/barriers/transitions for this IEP
    $stmt_clear = $conn->prepare("DELETE FROM learner_iep_goals WHERE iep_id = ?");
    $stmt_clear->bind_param("i", $iep_id);
    $stmt_clear->execute();

    // Insert Barriers (type = 'barrier')
    if (isset($_POST['barriers']) && is_array($_POST['barriers'])) {
        $stmt_ins_b = $conn->prepare("
            INSERT INTO learner_iep_goals (
                iep_id, type, interest, goal, interventions, remarks, sort_order
            ) VALUES (?, 'barrier', ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($_POST['barriers'] as $b) {
            $barrier = $b['interest'] ?? '';
            $facilitator = $b['goal'] ?? '';
            $accommodation = $b['interventions'] ?? '';
            $notes = $b['remarks'] ?? '';
            
            if (!empty($barrier)) {
                $stmt_ins_b->bind_param("issssi", $iep_id, $barrier, $facilitator, $accommodation, $notes, $order);
                $stmt_ins_b->execute();
                $order++;
            }
        }
    }

    // Insert Goals (type = 'goal')
    if (isset($_POST['goals']) && is_array($_POST['goals'])) {
        $stmt_ins_g = $conn->prepare("
            INSERT INTO learner_iep_goals (
                iep_id, type, interest, goal, interventions, timeline, responsible, remarks, progress, sort_order
            ) VALUES (?, 'goal', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($_POST['goals'] as $g) {
            $interest = $g['interest'] ?? '';
            $goal_text = $g['goal'] ?? '';
            $interventions = $g['interventions'] ?? '';
            $timeline = $g['timeline'] ?? '';
            $responsible = $g['responsible'] ?? '';
            $remarks = $g['remarks'] ?? '';
            $progress = $g['progress'] ?? '';
            
            if (!empty($interest)) {
                $stmt_ins_g->bind_param("isssssssi", $iep_id, $interest, $goal_text, $interventions, $timeline, $responsible, $remarks, $progress, $order);
                $stmt_ins_g->execute();
                $order++;
            }
        }
    }

    // Insert Transitions (type = 'transition')
    if (isset($_POST['transitions']) && is_array($_POST['transitions'])) {
        $stmt_ins_t = $conn->prepare("
            INSERT INTO learner_iep_goals (
                iep_id, type, interest, work_opportunities, interventions, timeline, responsible, remarks, sort_order
            ) VALUES (?, 'transition', ?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 0;
        foreach ($_POST['transitions'] as $t) {
            $interest = $t['interest'] ?? '';
            $work_opp = $t['work_opportunities'] ?? '';
            $interventions = $t['interventions'] ?? '';
            $timeline = $t['timeline'] ?? '';
            $responsible = $t['responsible'] ?? '';
            $remarks = $t['remarks'] ?? '';
            
            if (!empty($interest)) {
                $stmt_ins_t->bind_param("issssssi", $iep_id, $interest, $work_opp, $interventions, $timeline, $responsible, $remarks, $order);
                $stmt_ins_t->execute();
                $order++;
            }
        }
    }

    $conn->commit();
    log_transaction($conn, $_SESSION['user_id'], $action === 'create' ? 'create_iep' : 'update_iep', 'learners', $lrn, "Saved IEP profile for LRN: $lrn");
    header("Location: viewLearner.php?id=" . urlencode($lrn) . "&tab=iep&success=1");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    die("Database error: " . $e->getMessage());
}
