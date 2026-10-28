<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';
require_once 'includes/itp_helpers.php';

$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;

// Route: viewLearner.php?id={learner_id}
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid LRN request.");
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

// School role redirect to existing profile page
if (!user_is_coordinator($user_role)) {
    header("Location: learnerForm.php?id=" . urlencode($lrn));
    exit();
}

// Check learner existence & get active enrollment
$stmt = $conn->prepare("
    SELECT l.*, 
           e.id as enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level, e.grade_level, e.primary_diagnosis, e.status as enroll_status,
           s.school_name,
           g.first_name as g_first, g.last_name as g_last, g.contact_number as g_contact, g.relationship, g.secondary_contact_number,
           g.mother_first_name, g.mother_last_name, g.mother_contact_number, g.mother_same_as_guardian, g.no_mother,
           g.father_first_name, g.father_last_name, g.father_contact_number, g.father_same_as_guardian, g.no_father,
            lg.full_name as primary_contact_name, lg.contact_number as primary_contact_number,
            lg.email as primary_contact_email, lg.occupation as primary_contact_occupation
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    LEFT JOIN learner_guardians lg ON lg.id = (
        SELECT id
        FROM learner_guardians
        WHERE lrn = l.lrn AND is_primary_contact = 1
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    )
    LEFT JOIN enrollments e ON e.id = (
        SELECT id
        FROM enrollments
        WHERE lrn = l.lrn AND is_deleted = 0 AND is_historical = 0
        ORDER BY school_year DESC, id DESC
        LIMIT 1
    )
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Student record not found.");
}

$active_disabilities = sned_get_enrollment_disabilities($conn, isset($learner['enrollment_id']) ? (int)$learner['enrollment_id'] : null);
if (empty($active_disabilities) && !empty($learner['primary_diagnosis'])) {
    $active_disabilities = sned_disability_rows_from_legacy_diagnosis($learner['primary_diagnosis']);
}
$active_disability_summary = sned_disability_summary($active_disabilities, $learner['primary_diagnosis'] ?: 'Unassigned');

// Calculate age to check for environment rules and referrals
$birthDate = new DateTime($learner['birthday']);
$today = new DateTime();
$age = $today->diff($birthDate)->y;

$is_non_graded = ($learner['placement_type'] ?? '') === 'Non-Graded';

// Fetch enrollment history for timeline
$enrollment_history = [];
$stmt_eh = $conn->prepare("
    SELECT e.*, s.school_name 
    FROM enrollments e 
    LEFT JOIN schools s ON e.school_id = s.id 
    WHERE e.lrn = ? AND e.is_deleted = 0 
    ORDER BY e.school_year DESC, e.id DESC
");
$stmt_eh->bind_param("s", $lrn);
$stmt_eh->execute();
$res_eh = $stmt_eh->get_result();
if ($res_eh) {
    while ($row = $res_eh->fetch_assoc()) $enrollment_history[] = $row;
}


// Fetch documents for the learner
$learner_docs = [];
$stmt_ld = $conn->prepare("
    SELECT * 
    FROM documents 
    WHERE lrn = ? 
    ORDER BY created_at DESC
");
$stmt_ld->bind_param("s", $lrn);
$stmt_ld->execute();
$res_ld = $stmt_ld->get_result();
if ($res_ld) {
    while ($row = $res_ld->fetch_assoc()) $learner_docs[] = $row;
}


// Gate at top of file: confirm learner is at user's school, or teacher is assigned
if (!check_teacher_learner_access($conn, $lrn)) {
    header("Location: 403.php");
    exit();
}

$can_edit_learner = check_teacher_learner_access($conn, $lrn, true);

// Quick Enrollment AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_enroll') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed.']);
        exit;
    }
    if (!user_is_coordinator($user_role) || !$can_edit_learner) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized: you do not have edit permission for this student.']);
        exit;
    }

    $q_school_id              = (int)($_POST['school_id'] ?? 0);
    $q_school_year            = trim($_POST['school_year'] ?? '');
    $q_grade_level            = trim($_POST['grade_level'] ?? '');
    $q_placement              = $_POST['placement_type'] ?? '';
    $q_scl                    = trim($_POST['self_contained_level'] ?? '') ?: null;
    $q_assessment_mode        = trim($_POST['assessment_mode'] ?? '') ?: null;
    $q_assessment_institution = trim($_POST['assessment_institution'] ?? '') ?: null;
    $q_medical_results        = trim($_POST['medical_results'] ?? '') ?: null;
    $q_previous_school_name   = trim($_POST['previous_school_name'] ?? '') ?: null;
    $q_years_in_school        = trim($_POST['years_in_school'] ?? '') ?: null;
    $q_current_level          = trim($_POST['current_level_of_education'] ?? '') ?: null;
    $q_interpreter            = trim($_POST['interpreter_needed'] ?? '') ?: null;
    if ($q_placement === 'Non-Graded') {
        $q_grade_level = 'Non-Graded';
    }
    $q_disability_rows = sned_collect_disability_rows_from_post($_POST);
    // Filter rows based on assessment mode (mirrors learnerForm logic)
    if ($q_assessment_mode === 'With Assessment') {
        $q_disability_rows = array_filter($q_disability_rows, fn($r) => $r['category'] === 'diagnosed');
    } else {
        $q_disability_rows = array_filter($q_disability_rows, fn($r) => $r['category'] === 'difficulty');
    }
    if (empty($q_disability_rows) && !empty($_POST['primary_diagnosis'] ?? '')) {
        $q_disability_rows = sned_disability_rows_from_legacy_diagnosis($_POST['primary_diagnosis']);
    }
    $q_diagnosis = sned_disability_summary($q_disability_rows, clean_diagnosis($_POST['primary_diagnosis'] ?? 'Unassigned') ?: 'Unassigned');
    $q_remarks   = trim($_POST['remarks'] ?? '') ?: null;

    if ($q_school_id <= 0 || empty($q_school_year) || empty($q_grade_level) || empty($q_placement) || empty($q_disability_rows)) {
        echo json_encode(['ok' => false, 'error' => 'All required fields must be filled.']);
        exit;
    }
    if (empty($q_assessment_mode)) {
        echo json_encode(['ok' => false, 'error' => 'Assessment: Please indicate if the learner has a medical/clinical assessment.']);
        exit;
    }
    if (!in_array($q_assessment_mode, ['With Assessment', 'Without Assessment'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid assessment mode.']);
        exit;
    }
    if (!in_array($q_placement, ['Graded', 'Non-Graded'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid placement type.']);
        exit;
    }
    if ($q_placement === 'Non-Graded' && !in_array($q_scl, ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Program: Required for Non-Graded placements.']);
        exit;
    }
    if ($q_placement === 'Graded' && !sned_school_allows_grade_level($conn, $q_school_id, $q_grade_level)) {
        echo json_encode(['ok' => false, 'error' => 'Grade Level: Not offered by the selected school.']);
        exit;
    }

    // Check duplicate
    $stmt_dup = $conn->prepare("SELECT id FROM enrollments WHERE lrn = ? AND school_year = ? AND is_deleted = 0");
    $stmt_dup->bind_param("ss", $lrn, $q_school_year);
    $stmt_dup->execute();
    if ($stmt_dup->get_result()->num_rows > 0) {
        echo json_encode(['ok' => false, 'error' => 'An active enrollment already exists for SY ' . htmlspecialchars($q_school_year) . '.']);
        exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $stmt_ins = $conn->prepare("INSERT INTO enrollments (lrn, school_id, school_year, placement_type, self_contained_level, grade_level, primary_diagnosis, remarks, updated_by, assessment_mode, assessment_institution, medical_results, previous_school_name, years_in_school, current_level_of_education, interpreter_needed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_ins->bind_param("sissssssissssssss", $lrn, $q_school_id, $q_school_year, $q_placement, $q_scl, $q_grade_level, $q_diagnosis, $q_remarks, $uid, $q_assessment_mode, $q_assessment_institution, $q_medical_results, $q_previous_school_name, $q_years_in_school, $q_current_level, $q_interpreter);

    if ($stmt_ins->execute()) {
        sned_save_enrollment_disabilities($conn, (int)$stmt_ins->insert_id, $q_disability_rows);
        $log_desc = "Quick-enrolled LRN $lrn into SY $q_school_year";
        if ($age >= 25) {
            $log_desc .= " [Referred to ALS/DSWD/TESDA/DOLE]";
        }
        log_transaction($conn, $uid, 'create', 'enrollments', $lrn, $log_desc);
        echo json_encode(['ok' => true, 'msg' => 'Student enrolled successfully for SY ' . htmlspecialchars($q_school_year) . '.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $stmt_ins->error]);
    }
    exit;
}

// Determine if user can edit
// Can edit if user is admin, or coordinator of student's current school
$can_edit = user_is_coordinator($user_role) && $can_edit_learner;

$quick_enroll_schools = [];
$quick_enroll_grade_levels = [];
$res_qsch = $conn->query("SELECT id, school_name, grade_levels_offered FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
if ($res_qsch) {
    while ($qsch = $res_qsch->fetch_assoc()) {
        $quick_enroll_schools[] = $qsch;
        $quick_enroll_grade_levels[(string)$qsch['id']] = sned_grade_levels_from_school_offerings($qsch['grade_levels_offered'] ?? '');
    }
}

// Auto-seed Annex 2 report-card indicators for the active enrollment.
if (!empty($learner['enrollment_id']) && !empty($learner['school_year'])) {
    ensure_sf9_indicator_ratings($conn, $lrn, (int)$learner['enrollment_id'], $learner['school_year']);
}

$encoding_sy = $learner['school_year'] ?: $current_sy;
$profile_reviewed = empty($learner['needs_profile_update']);
$has_current_enrollment = !empty($learner['enrollment_id']) && ($learner['school_year'] === $current_sy);
$guardian_name = trim((string)($learner['primary_contact_name'] ?? ''));
if ($guardian_name === '') {
    $guardian_name = sned_guardian_name($learner);
}
$guardian_contact = trim((string)($learner['primary_contact_number'] ?? $learner['g_contact'] ?? ''));
$guardian_complete = $guardian_name !== '' && $guardian_contact !== '';

$stmt_ilp_current = $conn->prepare("SELECT id FROM learner_ilp WHERE learner_id = ? AND school_year = ? AND document_type = 'ILP' LIMIT 1");
$stmt_ilp_current->bind_param("ss", $lrn, $encoding_sy);
$stmt_ilp_current->execute();
$has_current_ilp = (bool)$stmt_ilp_current->get_result()->fetch_assoc();

$stmt_iep_current = $conn->prepare("SELECT id FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? LIMIT 1");
$stmt_iep_current->bind_param("ss", $lrn, $encoding_sy);
$stmt_iep_current->execute();
$has_current_iep = (bool)$stmt_iep_current->get_result()->fetch_assoc();

$stmt_itp_current = $conn->prepare("SELECT id FROM learner_itp WHERE learner_id = ? AND school_year = ? LIMIT 1");
$stmt_itp_current->bind_param("ss", $lrn, $encoding_sy);
$stmt_itp_current->execute();
$has_current_itp = (bool)$stmt_itp_current->get_result()->fetch_assoc();

$stmt_sf9_current = $conn->prepare("SELECT id FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
$stmt_sf9_current->bind_param("ss", $lrn, $encoding_sy);
$stmt_sf9_current->execute();
$has_current_sf9 = (bool)$stmt_sf9_current->get_result()->fetch_assoc();

$stmt_bir_current = $conn->prepare("SELECT id FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? LIMIT 1");
$stmt_bir_current->bind_param("ss", $lrn, $encoding_sy);
$stmt_bir_current->execute();
$has_current_bir = (bool)$stmt_bir_current->get_result()->fetch_assoc();

$profile_url = 'learnerForm.php?id=' . urlencode($lrn);
$ilp_url = 'ilp_form.php?learner_id=' . urlencode($lrn) . '&document_type=ILP&sy=' . urlencode($encoding_sy);
$iep_url = 'iep_editor.php?learner_id=' . urlencode($lrn) . '&sy=' . urlencode($encoding_sy);
$itp_url = 'itp_form.php?learner_id=' . urlencode($lrn) . '&school_year=' . urlencode($encoding_sy);
$sf9_url = 'sf9_form.php?learner_id=' . urlencode($lrn) . '&school_year=' . urlencode($encoding_sy);
$bir_url = 'bir_form.php?learner_id=' . urlencode($lrn) . '&sy=' . urlencode($encoding_sy);

// Assessment summary data
$stmt_ass_count = $conn->prepare("SELECT COUNT(*) as total_rated, MAX(updated_at) as last_updated FROM learner_assessment_checklist WHERE learner_id = ? AND (pre_rating IS NOT NULL OR post_rating IS NOT NULL)");
$stmt_ass_count->bind_param("s", $lrn);
$stmt_ass_count->execute();
$ass_summary = $stmt_ass_count->get_result()->fetch_assoc();
$ass_total_rated = (int)($ass_summary['total_rated'] ?? 0);
$ass_last_updated = $ass_summary['last_updated'] ?? null;
$stmt_ass_count->close();

$stmt_total_items = $conn->prepare("SELECT COUNT(*) as total_items FROM assessment_checklist_items");
$stmt_total_items->execute();
$ass_total_items = (int)($stmt_total_items->get_result()->fetch_assoc()['total_items'] ?? 200);
$stmt_total_items->close();

$ass_percent = $ass_total_items > 0 ? (int)round(($ass_total_rated / $ass_total_items) * 100) : 0;

$encoding_checklist = [
    [
        'label' => 'Profile reviewed',
        'help' => $profile_reviewed ? 'Learner profile was reviewed and saved.' : 'Review learner, address, and guardian details for this school year.',
        'done' => $profile_reviewed,
        'required' => true,
        'action_label' => $profile_reviewed ? 'Open profile' : 'Review profile',
        'action_href' => $profile_url,
    ],
    [
        'label' => 'Guardian contact complete',
        'help' => $guardian_complete ? 'A guardian/contact person and phone number are on file.' : 'Add the main guardian/contact person and phone number.',
        'done' => $guardian_complete,
        'required' => true,
        'action_label' => $guardian_complete ? 'Open profile' : 'Add contact',
        'action_href' => $profile_url,
    ],
    [
        'label' => 'Enrollment updated',
        'help' => $has_current_enrollment ? 'Learner has an active enrollment for the current school year.' : 'Enroll or promote the learner for the current school year.',
        'done' => $has_current_enrollment,
        'required' => true,
        'action_label' => $has_current_enrollment ? 'View details' : 'Quick enroll',
        'action_href' => $has_current_enrollment ? null : '#quickEnrollModal',
        'action_modal' => !$has_current_enrollment,
    ],
    [
        'label' => 'ILP for this SY',
        'help' => $has_current_ilp ? 'Individual Learner Profile exists for this school year.' : 'Create or copy-forward the ILP for this school year.',
        'done' => $has_current_ilp,
        'required' => true,
        'action_label' => $has_current_ilp ? 'Open ILP' : 'Create ILP',
        'action_href' => $ilp_url,
    ],
    [
        'label' => 'IEP for this SY',
        'help' => $has_current_iep ? 'IEP record exists for this school year.' : 'Create or copy-forward the IEP for this school year.',
        'done' => $has_current_iep,
        'required' => true,
        'action_label' => $has_current_iep ? 'Open IEP' : 'Create IEP',
        'action_href' => $iep_url,
    ],
    [
        'label' => 'ITP for this SY',
        'help' => $has_current_itp ? 'Transition plan exists for this school year.' : 'Create or review the transition plan for this school year.',
        'done' => $has_current_itp,
        'required' => true,
        'action_label' => $has_current_itp ? 'Open ITP' : 'Create ITP',
        'action_href' => $itp_url,
    ],
];
if ($is_non_graded) {
    $encoding_checklist[] = [
        'label' => 'Progress Report started',
        'help' => $has_current_sf9 ? 'Progress Report/SF9 is started for this school year.' : 'Start the Non-Graded Progress Report for this school year.',
        'done' => $has_current_sf9,
        'required' => true,
        'action_label' => $has_current_sf9 ? 'Open report' : 'Start report',
        'action_href' => $sf9_url,
    ];
}
$encoding_checklist[] = [
    'label' => 'BIR if applicable',
    'help' => $has_current_bir ? 'A Behavior Intervention Report exists for this school year.' : 'Create only when behavior support needs to be documented.',
    'done' => $has_current_bir,
    'required' => false,
    'action_label' => $has_current_bir ? 'Open BIR' : 'Create BIR',
    'action_href' => $bir_url,
];

$encoding_checklist[] = [
    'label' => 'Assessment Checklist',
    'help' => $ass_total_rated > 0 ? "Checklist started: {$ass_total_rated} of {$ass_total_items} items rated." : 'Track development/academic skills across 7 learning areas.',
    'done' => ($ass_total_rated > 0),
    'required' => false,
    'action_label' => $ass_total_rated > 0 ? 'Open Assessment' : 'Start Assessment',
    'action_href' => 'assessment_form.php?id=' . urlencode($lrn),
];

$encoding_required_total = 0;
$encoding_required_done = 0;
foreach ($encoding_checklist as $item) {
    if (!empty($item['required'])) {
        $encoding_required_total++;
        if (!empty($item['done'])) {
            $encoding_required_done++;
        }
    }
}
$encoding_percent = $encoding_required_total > 0 ? (int)round(($encoding_required_done / $encoding_required_total) * 100) : 100;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title><?= htmlspecialchars($learner['first_name'] . ' ' . $learner['last_name']) ?> — Profile | SNED Profiling</title>
    <style>
        body { background: var(--color-paper-3); font-family: var(--font-body); color: var(--color-ink); }
        .main-card { border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
        .student-photo { width: 140px; height: 140px; object-fit: cover; border-radius: var(--radius-md); border: 3px solid var(--color-rule); }
        .nav-tabs {
            border-bottom: none;
            gap: var(--space-2xs);
            background: var(--color-surface-container-low);
            padding: var(--space-2xs);
            border-radius: var(--radius-lg);
            display: flex;
            flex-wrap: wrap !important;
            overflow: visible !important;
        }
        .nav-tabs .nav-item {
            margin: 0;
            flex: 0 0 auto !important;
        }
        .nav-tabs .nav-link {
            border: none;
            font-weight: 600;
            color: var(--color-muted-dark);
            padding: var(--space-xs) var(--space-md);
            border-radius: var(--radius-md);
            transition: all var(--dur-short) var(--ease-out);
            background: transparent;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2xs);
        }
        .nav-tabs .nav-link:hover {
            color: var(--color-primary);
            background: rgba(64, 145, 108, 0.08);
        }
        .nav-tabs .nav-link.active {
            color: var(--color-primary-ink);
            background: var(--color-primary);
            box-shadow: var(--shadow-sm);
        }
        .status-badge { font-weight: 700; text-transform: uppercase; font-size: var(--text-xs); padding: var(--space-2xs) var(--space-sm); border-radius: var(--radius-pill); letter-spacing: 0.02em; }
        .tab-pane { padding-top: var(--space-lg); }
        .info-grid dt { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.03em; color: var(--color-muted); font-weight: 600; margin-bottom: var(--space-3xs); }
        .info-grid dd { font-weight: 600; margin-bottom: var(--space-sm); }
        .lis-disability-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .family-contact-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .family-contact-row {
            display: grid;
            grid-template-columns: minmax(64px, 78px) minmax(0, 1fr);
            gap: 0.35rem 0.9rem;
            align-items: start;
        }
        .family-contact-role {
            font-size: 0.7rem;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--color-muted);
            font-weight: 700;
            padding-top: 0.1rem;
        }
        .family-contact-details {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.18rem;
        }
        .family-contact-name,
        .family-contact-phone {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .family-contact-name {
            font-size: 0.95rem;
            line-height: 1.35;
            color: var(--color-ink);
            font-weight: 600;
        }
        .family-contact-phone {
            font-size: 0.82rem;
            line-height: 1.35;
            color: var(--color-muted-dark);
        }
        .encoding-checklist-header {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
            justify-content: space-between;
        }
        .encoding-checklist-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.38rem;
        }
        .encoding-checklist-item {
            border: 1px solid var(--color-rule);
            border-radius: var(--radius-sm);
            padding: 0.42rem 0.5rem;
            background: var(--color-surface);
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 0.42rem;
            align-items: center;
        }
        .encoding-check-icon {
            width: 1.45rem;
            height: 1.45rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            flex: 0 0 auto;
        }
        .encoding-check-icon.done { background: var(--sned-success-light); color: var(--sned-success); }
        .encoding-check-icon.todo { background: var(--sned-warning-light); color: var(--sned-warning); }
        .encoding-check-icon.optional { background: var(--sned-info-light); color: var(--sned-info); }
        .encoding-check-title {
            font-weight: 700;
            color: var(--color-ink);
            line-height: 1.25;
            font-size: 0.78rem;
        }
        .encoding-check-help {
            color: var(--color-muted-dark);
            font-size: 0.72rem;
            line-height: 1.25;
            margin-top: 0.12rem;
        }
        .encoding-check-status {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            align-items: center;
            margin-top: 0.12rem;
        }
        .encoding-checklist-item .badge {
            font-size: 0.62rem;
            padding: 0.18rem 0.42rem;
        }
        .encoding-checklist-item .btn {
            font-size: 0.72rem;
            padding: 0.18rem 0.55rem;
        }
        @media (max-width: 576px) {
            .encoding-checklist-item { grid-template-columns: auto minmax(0, 1fr); }
            .encoding-checklist-item .btn { grid-column: 2; justify-self: start; }
        }
        @media (max-width: 576px) {
            .family-contact-row {
                grid-template-columns: 1fr;
                gap: 0.2rem;
            }
        }
        @media (max-width: 768px) { .lis-disability-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column bg-white overflow-auto">
            <header class="px-4 py-3 border-bottom d-flex align-items-center justify-content-between sticky-top bg-white" style="z-index: var(--z-sticky);">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2 rounded-pill px-3" id="sidebarToggle" type="button">
                    <i class="bi bi-list"></i> Menu
                </button>
                <div class="fw-semibold text-primary"><i class="bi bi-person-badge me-1"></i> Student Case Record</div>
            </header>

            <div class="p-4">
                
                <!-- STUDENT IDENTITY CARD -->
                <div class="card main-card p-4 mb-3 bg-white border-0">
                    <div class="row align-items-center g-4">
                        <div class="col-auto">
                            <?php
                            $photo_src = '';
                            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                                $test = "uploads/{$lrn}.{$ext}";
                                if (file_exists(__DIR__ . '/' . $test)) {
                                    $photo_src = $test;
                                    break;
                                }
                            }
                            ?>
                            <img src="<?= $photo_src ? htmlspecialchars($photo_src) : 'img/student_placeholder.webp' ?>" alt="Student Portrait" class="student-photo">
                        </div>
                        
                        <div class="col">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <h2 class="fw-bold mb-0" style="color:var(--color-ink);font-size:1.75rem;"><?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name'] . ' ' . $learner['extension_name']) ?></h2>
                                <?php
                                $status_badge_class = 'bg-success text-white';
                                $status_text = 'Active';
                                if ($learner['enroll_status'] === 'transferred') {
                                    $status_badge_class = 'bg-info text-white';
                                    $status_text = 'Transferred';
                                } elseif ($learner['enroll_status'] === 'dropped') {
                                    $status_badge_class = 'bg-danger text-white';
                                    $status_text = 'Dropped';
                                }
                                ?>
                                <span class="status-badge <?= $status_badge_class ?>"><?= $status_text ?></span>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <span class="d-inline-flex align-items-center gap-1 fw-semibold text-muted">LRN</span>
                                <span class="d-inline-flex align-items-center gap-1 px-3 py-1 rounded-pill" style="background:var(--color-primary-light);color:var(--color-primary);font-size:1.15rem;font-weight:700;letter-spacing:0.05em;font-family:ui-monospace,SFMono-Regular,SF Mono,Menlo,Consolas,monospace;"><?= htmlspecialchars($learner['lrn']) ?></span>
                            </div>
                            
                            <div class="row row-cols-1 row-cols-md-3 g-1 small text-muted">
                                <div><i class="bi bi-calendar-event text-primary"></i> <?= date('F d, Y', strtotime($learner['birthday'])) ?> (<?= $age ?> yrs, <?= htmlspecialchars($learner['sex']) ?>)</div>
                                <div><i class="bi bi-building text-primary"></i> <?= htmlspecialchars($learner['school_name'] ?: 'Not Enrolled') ?></div>
                                <div><i class="bi bi-award text-primary"></i> <?= htmlspecialchars($learner['grade_level'] ?: 'N/A') ?> · <?= htmlspecialchars($learner['placement_type'] ?: 'N/A') ?></div>
                                <div><i class="bi bi-shield-plus text-primary"></i> <?= htmlspecialchars($active_disability_summary) ?></div>
                                <div><i class="bi bi-calendar3 text-primary"></i> SY <?= htmlspecialchars($learner['school_year'] ?: 'N/A') ?></div>
                            </div>
                        </div>

                        <div class="col-auto">
                            <div class="d-flex flex-column gap-2 h-100 justify-content-center">
                                <?php if ($can_edit): ?>
                                    <a href="learnerForm.php?id=<?= urlencode($lrn) ?>" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold"><i class="bi bi-pencil-square me-1"></i> Edit Record</a>
                                <?php endif; ?>
                                <a href="printForm.php?id=<?= urlencode($lrn) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-eye me-1"></i> View Case Study</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($learner['needs_profile_update']): ?>
                <div class="alert alert-warning border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                    <div>
                        <i class="bi bi-pencil-square text-warning me-2" style="font-size: 1.5rem;"></i>
                        <strong>Profile Needs Update:</strong> Please review this learner's profile for the current school year.
                    </div>
                    <a href="learnerForm.php?id=<?= urlencode($lrn) ?>" class="btn btn-warning rounded-pill px-3 py-1 fw-bold text-nowrap">
                        <i class="bi bi-pencil-square me-1"></i> Update Profile
                    </a>
                </div>
                <?php endif; ?>

                <?php if (!empty($learner['lrn_is_temporary'])): ?>
                <div class="alert alert-warning border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                    <div>
                        <i class="bi bi-lock-fill text-warning me-2" style="font-size: 1.5rem;"></i>
                        <strong>Temporary LRN Profile:</strong> This learner has a temporary LRN (<?= htmlspecialchars($lrn) ?>). All other document forms (IEP, ILP, ITP, SF9, BIR) are locked. Please add a valid 12-digit LRN to unlock them.
                    </div>
                    <a href="learnerForm.php?id=<?= urlencode($lrn) ?>" class="btn btn-warning rounded-pill px-3 py-1 fw-bold text-nowrap">
                        <i class="bi bi-pencil-square me-1"></i> Add LRN
                    </a>
                </div>
                <?php endif; ?>

                <!-- TABS NAVIGATION -->
                <ul class="nav nav-tabs mb-4" id="learnerTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview-pane" type="button" role="tab"><i class="bi bi-grid-fill"></i> Overview</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="assessment-tab" data-bs-toggle="tab" data-bs-target="#assessment-pane" type="button" role="tab"><i class="bi bi-check2-square"></i> Assessment Checklist</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="ilp-tab" data-bs-toggle="tab" data-bs-target="#ilp-pane" type="button" role="tab" <?php if (!empty($learner['lrn_is_temporary'])): ?>disabled title="Locked: Valid LRN required"<?php endif; ?>><?php if (!empty($learner['lrn_is_temporary'])): ?><i class="bi bi-lock-fill text-muted me-1"></i><?php else: ?><i class="bi bi-person-bounding-box"></i><?php endif; ?> Individual Learner Profile</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="iep-tab" data-bs-toggle="tab" data-bs-target="#iep-pane" type="button" role="tab" <?php if (!empty($learner['lrn_is_temporary'])): ?>disabled title="Locked: Valid LRN required"<?php endif; ?>><?php if (!empty($learner['lrn_is_temporary'])): ?><i class="bi bi-lock-fill text-muted me-1"></i><?php else: ?><i class="bi bi-clipboard-pulse"></i><?php endif; ?> Individualized Education Program</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="itp-tab" data-bs-toggle="tab" data-bs-target="#itp-pane" type="button" role="tab" <?php if (!empty($learner['lrn_is_temporary'])): ?>disabled title="Locked: Valid LRN required"<?php endif; ?>><?php if (!empty($learner['lrn_is_temporary'])): ?><i class="bi bi-lock-fill text-muted me-1"></i><?php else: ?><i class="bi bi-signpost-split"></i><?php endif; ?> Individual Transition Plan</button>
                    </li>
                    <?php if ($is_non_graded): ?>
                        <li class="nav-item">
                            <button class="nav-link" id="sf9-tab" data-bs-toggle="tab" data-bs-target="#sf9-pane" type="button" role="tab" <?php if (!empty($learner['lrn_is_temporary'])): ?>disabled title="Locked: Valid LRN required"<?php endif; ?>><?php if (!empty($learner['lrn_is_temporary'])): ?><i class="bi bi-lock-fill text-muted me-1"></i><?php else: ?><i class="bi bi-card-checklist"></i><?php endif; ?> Non Graded Progress Report</button>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <button class="nav-link" id="bir-tab" data-bs-toggle="tab" data-bs-target="#bir-pane" type="button" role="tab" <?php if (!empty($learner['lrn_is_temporary'])): ?>disabled title="Locked: Valid LRN required"<?php endif; ?>><?php if (!empty($learner['lrn_is_temporary'])): ?><i class="bi bi-lock-fill text-muted me-1"></i><?php else: ?><i class="bi bi-clipboard-pulse"></i><?php endif; ?> Behavior Intervention Report</button>
                </ul>

                <!-- TABS PANES -->
                <div class="tab-content" id="learnerTabsContent">
                    
                    <!-- OVERVIEW TAB -->
                    <div class="tab-pane fade show active" id="overview-pane" role="tabpanel">
                        <?php
                        // Check pending transfer
                        $stmt_tr = $conn->prepare("
                            SELECT tr.*, s.school_name as to_school_name 
                            FROM transfer_requests tr 
                            JOIN schools s ON tr.to_school_id = s.id 
                            WHERE tr.learner_id = ? AND tr.status = 'pending' LIMIT 1
                        ");
                        $stmt_tr->bind_param("s", $lrn);
                        $stmt_tr->execute();
                        $pending_tr = $stmt_tr->get_result()->fetch_assoc();

                        // Latest assessment from ILP assessments (new table)
                        $stmt_la = $conn->prepare("
                            SELECT la.date_administered AS assessment_date, la.assessment_type 
                            FROM learner_ilp_assessments la
                            JOIN learner_ilp li ON la.ilp_id = li.id
                            WHERE li.learner_id = ?
                            ORDER BY la.date_administered DESC, la.id DESC
                            LIMIT 1
                        ");
                        $stmt_la->bind_param("s", $lrn);
                        $stmt_la->execute();
                        $latest_ass = $stmt_la->get_result()->fetch_assoc();

                        if (!$latest_ass) {
                            // Fallback to legacy assessments table if nothing in ILP
                            $stmt_la_legacy = $conn->prepare("SELECT assessment_date, assessment_type FROM assessments WHERE lrn = ? ORDER BY assessment_date DESC LIMIT 1");
                            $stmt_la_legacy->bind_param("s", $lrn);
                            $stmt_la_legacy->execute();
                            $latest_ass = $stmt_la_legacy->get_result()->fetch_assoc();
                        }

                        // ILP status
                        $stmt_li = $conn->prepare("SELECT * FROM learner_ilp WHERE learner_id = ? AND document_type = 'ILP' ORDER BY school_year DESC LIMIT 1");
                        $stmt_li->bind_param("s", $lrn);
                        $stmt_li->execute();
                        $ilp_rec = $stmt_li->get_result()->fetch_assoc();
                        
                        $ilp_status_text = 'Not Started';
                        $ilp_badge_class = 'bg-secondary';
                        if ($ilp_rec) {
                            $domain_fields = [
                                'daily_living_strengths', 'daily_living_needs',
                                'language_strengths', 'language_needs',
                                'psychomotor_strengths', 'psychomotor_needs',
                                'cognitive_strengths', 'cognitive_needs',
                                'aesthetic_strengths', 'aesthetic_needs',
                                'behavioral_strengths', 'behavioral_needs',
                                'orientation_strengths', 'orientation_needs'
                            ];
                            $filled = 0;
                            foreach ($domain_fields as $df) {
                                if (!empty(trim($ilp_rec[$df] ?? ''))) $filled++;
                            }
                            if ($filled === count($domain_fields)) {
                                $ilp_status_text = 'Completed';
                                $ilp_badge_class = 'bg-success';
                            } elseif ($filled > 0) {
                                $ilp_status_text = 'Partial';
                                $ilp_badge_class = 'bg-warning text-dark';
                            }
                        }

                        // IEP status
                        $stmt_lip = $conn->prepare("SELECT meeting_date FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY school_year DESC, id DESC LIMIT 1");
                        $stmt_lip->bind_param("s", $lrn);
                        $stmt_lip->execute();
                        $iep_rec = $stmt_lip->get_result()->fetch_assoc();

                        $stmt_itp_status = $conn->prepare("
                            SELECT school_year, plan_date
                            FROM learner_itp
                            WHERE learner_id = ?
                            ORDER BY school_year DESC, id DESC LIMIT 1
                        ");
                        $stmt_itp_status->bind_param("s", $lrn);
                        $stmt_itp_status->execute();
                        $itp_rec = $stmt_itp_status->get_result()->fetch_assoc();
                        ?>

                        <?php if ($pending_tr): ?>
                            <div class="alert alert-warning border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="bi bi-exclamation-triangle-fill text-warning me-2" style="font-size: 1.5rem;"></i>
                                    <strong>Pending Transfer Request:</strong> This student has a pending transfer request to <strong><?= htmlspecialchars($pending_tr['to_school_name']) ?></strong> for SY <?= htmlspecialchars($pending_tr['effective_sy']) ?>.
                                </div>
                                <span class="badge bg-warning text-dark text-uppercase px-3 py-1 rounded-pill">Pending</span>
                            </div>
                        <?php endif; ?>

                        <div class="row g-4">
                            <!-- Enrollment Summary Card -->
                            <div class="col-md-6 col-lg-4">
                                <div class="card main-card p-4 border-0 bg-white shadow-sm rounded-4 h-100">
                                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-mortarboard-fill"></i></span> Current SY Placement</h6>
                                    <?php if ($learner['school_name'] && $learner['school_year'] === $current_sy): ?>
                                        <dl class="info-grid mb-0">
                                            <dt>School</dt><dd><?= htmlspecialchars($learner['school_name']) ?></dd>
                                            <dt>Grade Level</dt><dd><?= htmlspecialchars($learner['grade_level']) ?></dd>
                                            <dt>Placement Type</dt><dd><?= htmlspecialchars($learner['placement_type']) ?></dd>
                                            <?php if ($learner['placement_type'] === 'Non-Graded'): ?>
                                                <dt>Program</dt><dd><?= htmlspecialchars($learner['self_contained_level'] ?: 'Unassigned') ?></dd>
                                            <?php endif; ?>
                                            <dt>Disabilities</dt><dd><?= htmlspecialchars($active_disability_summary) ?></dd>
                                            <dt>School Year</dt><dd>SY <?= htmlspecialchars($learner['school_year']) ?></dd>
                                        </dl>
                                    <?php else: ?>
                                        <p class="text-muted small mb-2">Not enrolled in the current school year.</p>
                                        <?php if ($can_edit): ?>
                                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#quickEnrollModal"><i class="bi bi-plus-lg me-1"></i> Quick Enroll</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Guardian & Contact Card -->
                            <div class="col-md-6 col-lg-4">
                                <div class="card main-card p-4 border-0 bg-white shadow-sm rounded-4 h-100">
                                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-people-fill"></i></span> Parent / Guardian Profile</h6>
                                    <?php
                                    $g_name = $learner['primary_contact_name'] ?? ($learner['g_last'] ? ($learner['g_last'] . ', ' . $learner['g_first']) : '');
                                    $g_num = $learner['primary_contact_number'] ?? $learner['g_contact'];
                                    ?>
                                    <?php if ($g_name): ?>
                                        <dl class="info-grid mb-3">
                                            <dt>Guardian</dt><dd><?= htmlspecialchars($g_name) ?></dd>
                                            <?php if (!empty($learner['relationship'])): ?>
                                                <dt>Relationship</dt><dd><?= htmlspecialchars($learner['relationship']) ?></dd>
                                            <?php endif; ?>
                                            <dt>Phone</dt><dd><?= htmlspecialchars($g_num ?: 'No contact details') ?></dd>
                                            <?php if (!empty($learner['primary_contact_email'])): ?>
                                                <dt>Email</dt><dd><?= htmlspecialchars($learner['primary_contact_email']) ?></dd>
                                            <?php endif; ?>
                                            <?php if (!empty($learner['primary_contact_occupation'])): ?>
                                                <dt>Occupation</dt><dd><?= htmlspecialchars($learner['primary_contact_occupation']) ?></dd>
                                            <?php endif; ?>
                                            <?php if (!empty($learner['secondary_contact_number'])): ?>
                                                <dt>Secondary Phone</dt><dd><?= htmlspecialchars($learner['secondary_contact_number']) ?></dd>
                                            <?php endif; ?>
                                        </dl>
                                        
                                        <div class="border-top pt-3">
                                            <div class="family-contact-list">
                                            <?php if (!empty($learner['mother_first_name']) && empty($learner['no_mother'])): ?>
                                                <div class="family-contact-row">
                                                    <span class="family-contact-role">Mother</span>
                                                    <div class="family-contact-details">
                                                        <span class="family-contact-name"><?= htmlspecialchars(($learner['mother_last_name'] ?? '') . ', ' . ($learner['mother_first_name'] ?? '')) ?></span>
                                                        <?php if (!empty($learner['mother_contact_number'])): ?>
                                                            <span class="family-contact-phone"><?= htmlspecialchars($learner['mother_contact_number']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($learner['father_first_name']) && empty($learner['no_father'])): ?>
                                                <div class="family-contact-row">
                                                    <span class="family-contact-role">Father</span>
                                                    <div class="family-contact-details">
                                                        <span class="family-contact-name"><?= htmlspecialchars(($learner['father_last_name'] ?? '') . ', ' . ($learner['father_first_name'] ?? '')) ?></span>
                                                        <?php if (!empty($learner['father_contact_number'])): ?>
                                                            <span class="family-contact-phone"><?= htmlspecialchars($learner['father_contact_number']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="border-top pt-3 mt-2">
                                            <span class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;">Secondary Contacts</span>
                                            <?php
                                            $stmt_sec_g = $conn->prepare("SELECT full_name, contact_number, email, relation_type, occupation FROM learner_guardians WHERE lrn = ? AND is_primary_contact = 0");
                                            $stmt_sec_g->bind_param("s", $lrn);
                                            $stmt_sec_g->execute();
                                            $sec_guardians = $stmt_sec_g->get_result()->fetch_all(MYSQLI_ASSOC);
                                            if (empty($sec_guardians)) {
                                                echo '<span class="text-muted small d-block mt-1">None on file</span>';
                                            } else {
                                                echo '<ul class="mt-2 ps-3 mb-0 small text-muted">';
                                                foreach ($sec_guardians as $sg) {
                                                    $contact_bits = array_filter([$sg['contact_number'] ?? '', $sg['email'] ?? '']);
                                                    $label = trim(($sg['full_name'] ?? '') . ' (' . ($sg['relation_type'] ?? 'Other') . ')');
                                                    if (!empty($sg['occupation'])) {
                                                        $label .= ' - ' . $sg['occupation'];
                                                    }
                                                    if (!empty($contact_bits)) {
                                                        $label .= ' - ' . implode(' / ', $contact_bits);
                                                    }
                                                    echo '<li>' . htmlspecialchars($label) . '</li>';
                                                }
                                                echo '</ul>';
                                            }
                                            ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0">No guardian information associated with this record.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Profile Status & Reports -->
                            <div class="col-md-12 col-lg-4 d-flex flex-column gap-3">
                                <div class="card main-card p-3 bg-white border-0 shadow-sm rounded-4" id="encodingChecklist">
                                    <div class="encoding-checklist-header mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1 d-flex align-items-center gap-2">
                                                <span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:24px;height:24px;background:var(--color-primary-light);color:var(--color-primary);font-size:.8rem;"><i class="bi bi-list-check"></i></span>
                                                Encoding Checklist
                                            </h6>
                                            <p class="text-muted mb-0" style="font-size:0.72rem;">SY <?= htmlspecialchars($encoding_sy) ?> review guide</p>
                                        </div>
                                        <div class="text-end" style="min-width: 120px;">
                                            <div class="fw-bold text-primary mb-1" style="font-size:0.76rem;"><?= $encoding_required_done ?>/<?= $encoding_required_total ?> required</div>
                                            <div class="progress rounded-pill" style="height: 0.35rem;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $encoding_percent ?>%;" aria-valuenow="<?= $encoding_percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="encoding-checklist-grid">
                                        <?php foreach ($encoding_checklist as $item): ?>
                                            <?php
                                            $is_optional = empty($item['required']) && empty($item['done']);
                                            $status_key = !empty($item['done']) ? 'done' : ($is_optional ? 'optional' : 'todo');
                                            $status_icon = $status_key === 'done' ? 'bi-check-lg' : ($status_key === 'optional' ? 'bi-info-lg' : 'bi-exclamation-lg');
                                            $status_text = $status_key === 'done' ? 'Done' : ($status_key === 'optional' ? 'Optional' : 'Needs action');
                                            ?>
                                            <div class="encoding-checklist-item" title="<?= htmlspecialchars($item['help']) ?>">
                                                <span class="encoding-check-icon <?= $status_key ?>" aria-hidden="true"><i class="bi <?= $status_icon ?>"></i></span>
                                                <div>
                                                    <div class="encoding-check-title"><?= htmlspecialchars($item['label']) ?></div>
                                                    <div class="encoding-check-status">
                                                        <span class="badge <?= $status_key === 'done' ? 'text-bg-success' : ($status_key === 'optional' ? 'text-bg-info' : 'text-bg-warning') ?> rounded-pill"><?= htmlspecialchars($status_text) ?></span>
                                                        <?php if ($can_edit && !empty($item['action_label'])): ?>
                                                            <?php if (!empty($item['action_modal'])): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#quickEnrollModal"><?= htmlspecialchars($item['action_label']) ?></button>
                                                            <?php elseif (!empty($item['action_href'])): ?>
                                                                <a class="btn btn-sm btn-outline-primary rounded-pill" href="<?= htmlspecialchars($item['action_href']) ?>"><?= htmlspecialchars($item['action_label']) ?></a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="card main-card p-4 bg-white border-0 shadow-sm rounded-4">
                                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-file-earmark-arrow-down"></i></span> Downloadable Files</h6>

                                    <?php if ($is_non_graded): ?>
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <span class="small fw-semibold text-dark">Non Graded Progress Report</span>
                                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="document.getElementById('sf9-tab').click(); document.getElementById('sf9-tab').scrollIntoView({behavior: 'smooth'});"><i class="bi bi-card-checklist"></i> Open</button>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="small fw-semibold text-dark">Annex 4 Adaptive Skills</span>
                                        <a href="printAnnex4.php" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ILP TAB -->
                    <div class="tab-pane fade" id="ilp-pane" role="tabpanel">
                        <?php
                        // Fetch ilp row
                        $stmt_ilp = $conn->prepare("SELECT * FROM learner_ilp WHERE learner_id = ? AND document_type = 'ILP' ORDER BY school_year DESC LIMIT 1");
                        $stmt_ilp->bind_param("s", $lrn);
                        $stmt_ilp->execute();
                        $ilp = $stmt_ilp->get_result()->fetch_assoc();
                        ?>

                        <!-- Read-only Summary Block -->
                        <div id="ilpSummaryBlock" class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-person-bounding-box"></i></span> Individual Learner Profile</h5>
                                <div class="d-flex gap-2">
                                    <?php if ($ilp): ?>
                                        <a href="printAnnex1.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($ilp['school_year']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                        <?php if ($can_edit): ?>
                                            <a href="ilp_form.php?learner_id=<?= urlencode($lrn) ?>&document_type=ILP&sy=<?= urlencode($ilp['school_year']) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($can_edit): ?>
                                            <a href="ilp_form.php?learner_id=<?= urlencode($lrn) ?>&document_type=ILP" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-plus-lg"></i> Create ILP</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$ilp): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-person-bounding-box" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No ILP on file for this student.</p>
                                    <?php if ($can_edit): ?>
                                        <a href="ilp_form.php?learner_id=<?= urlencode($lrn) ?>&document_type=ILP" class="btn btn-primary rounded-pill px-4 mt-2"><i class="bi bi-plus-lg"></i> Create ILP</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Inline Iframe Preview -->
                                <div class="bg-secondary bg-opacity-10 p-2 rounded-3">
                                    <iframe class="w-100 border-0 rounded-3 shadow-sm" style="height: 80vh; background: #fff;" src="printAnnex1.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($ilp['school_year']) ?>"></iframe>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- IEP TAB -->
                    <div class="tab-pane fade" id="iep-pane" role="tabpanel">
                        <?php
                        // Fetch IEP row from the dedicated Annex 6 editor tables
                        $stmt_iep = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY school_year DESC, id DESC LIMIT 1");
                        $stmt_iep->bind_param("s", $lrn);
                        $stmt_iep->execute();
                        $iep = $stmt_iep->get_result()->fetch_assoc();

                        $iep_goals = [];
                        $iep_team = [];
                        $iep_transitions = [];
                        $active_diffs = [];
                        $barriers = [];
                        if ($iep) {
                            $diff_columns = [
                                'diff_seeing', 'diff_hearing', 'diff_communicating', 'diff_mobility',
                                'diff_interpersonal', 'diff_adaptive', 'diff_basic_skills', 'diff_remembering'
                            ];
                            foreach ($diff_columns as $dk) {
                                if (!empty($iep[$dk])) {
                                    $active_diffs[] = $dk;
                                }
                            }

                            // Fetch barriers, goals, and transitions from Annex 6 rows
                            $stmt_rows = $conn->prepare("SELECT * FROM learner_iep_annex6_rows WHERE iep_id = ? ORDER BY sort_order ASC, id ASC");
                            $stmt_rows->bind_param("i", $iep['id']);
                            $stmt_rows->execute();
                            $iep_rows = $stmt_rows->get_result()->fetch_all(MYSQLI_ASSOC);
                            foreach ($iep_rows as $row) {
                                if ($row['section_type'] === 'barrier') {
                                    $barriers[] = $row;
                                    if (!empty($row['difficulty'])) {
                                        $active_diffs[] = $row['difficulty'];
                                    }
                                } elseif ($row['section_type'] === 'goal') {
                                    $iep_goals[] = $row;
                                } elseif ($row['section_type'] === 'transition') {
                                    $iep_transitions[] = $row;
                                }
                            }
                            $active_diffs = array_values(array_unique($active_diffs));

                            if (!empty($iep['iep_team_json'])) {
                                $iep_team = json_decode($iep['iep_team_json'], true) ?? [];
                            }
                        }
                        ?>

                        <!-- Read-only IEP Summary Block -->
                        <div id="iepSummaryBlock" class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-clipboard-pulse"></i></span> Individualized Education Program</h5>
                                <div class="d-flex gap-2">
                                    <?php if ($iep): ?>
                                        <a href="printIEP.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($iep['school_year']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                        <?php if ($can_edit): ?>
                                            <a href="iep_editor.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($iep['school_year']) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($can_edit): ?>
                                            <a href="iep_editor.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($learner['school_year'] ?? '') ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-plus-lg"></i> Create IEP</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$iep): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-clipboard-pulse" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No IEP on file for this student.</p>
                                    <?php if ($can_edit): ?>
                                    <div class="d-flex justify-content-center gap-2">
                                        <a href="iep_editor.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($learner['school_year'] ?? '') ?>" class="btn btn-primary rounded-pill px-4 mt-2"><i class="bi bi-plus-lg"></i> Create IEP</a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Inline Iframe Preview -->
                                <div class="bg-secondary bg-opacity-10 p-2 rounded-3">
                                    <iframe class="w-100 border-0 rounded-3 shadow-sm" style="height: 80vh; background: #fff;" src="printIEP.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($iep['school_year']) ?>"></iframe>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ITP TAB -->
                    <div class="tab-pane fade" id="itp-pane" role="tabpanel">
                        <?php
                        $stmt_itp = $conn->prepare("
                            SELECT *
                            FROM learner_itp
                            WHERE learner_id = ?
                            ORDER BY school_year DESC, id DESC LIMIT 1
                        ");
                        $stmt_itp->bind_param("s", $lrn);
                        $stmt_itp->execute();
                        $itp = $stmt_itp->get_result()->fetch_assoc();

                        $itp_rows = [];
                        $itp_team = [];
                        $itp_team_rows = [];
                        $itp_labels = itp_entry_point_options();
                        $has_itp_team_table = itp_table_exists($conn, 'learner_itp_team_members');
                        $has_itp_goal_table = itp_table_exists($conn, 'learner_itp_goal_rows');

                        if ($itp) {
                            if ($has_itp_team_table) {
                                $stmt_itp_team = $conn->prepare("SELECT * FROM learner_itp_team_members WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
                                if ($stmt_itp_team) {
                                    $stmt_itp_team->bind_param("i", $itp['id']);
                                    $stmt_itp_team->execute();
                                    $itp_team_rows = $stmt_itp_team->get_result()->fetch_all(MYSQLI_ASSOC);
                                }
                            }

                            if ($has_itp_goal_table) {
                                $stmt_itp_goal = $conn->prepare("SELECT * FROM learner_itp_goal_rows WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
                                if ($stmt_itp_goal) {
                                    $stmt_itp_goal->bind_param("i", $itp['id']);
                                    $stmt_itp_goal->execute();
                                    $itp_rows = $stmt_itp_goal->get_result()->fetch_all(MYSQLI_ASSOC);
                                }
                            }

                            if (empty($itp_rows)) {
                                $stmt_itp_legacy = $conn->prepare("SELECT * FROM learner_itp_plans WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
                                $stmt_itp_legacy->bind_param("i", $itp['id']);
                                $stmt_itp_legacy->execute();
                                $legacy_rows = $stmt_itp_legacy->get_result()->fetch_all(MYSQLI_ASSOC);
                                foreach ($legacy_rows as $legacy_row) {
                                    $itp_rows[] = [
                                        'competency' => $legacy_row['interest'] ?? '',
                                        'activities' => trim(implode("\n", array_filter([
                                            trim((string)($legacy_row['work_opportunities'] ?? '')),
                                            trim((string)($legacy_row['interventions'] ?? '')),
                                        ]))),
                                        'time_frame' => $legacy_row['timeline'] ?? '',
                                        'persons_responsible' => $legacy_row['responsible'] ?? '',
                                        'remarks' => trim(implode("\n", array_filter([
                                            trim((string)($legacy_row['remarks'] ?? '')),
                                            !empty($legacy_row['status']) ? 'Status: ' . trim((string)$legacy_row['status']) : '',
                                        ]))),
                                    ];
                                }
                            }

                            $itp_team_map = [];
                            foreach (itp_fixed_team_roles() as $idx => $fixed_role) {
                                $itp_team_map[$fixed_role] = [
                                    'role' => $fixed_role,
                                    'name' => '',
                                    'contact_details' => '',
                                    'date_started_with_team' => '',
                                    'sort_order' => $idx,
                                ];
                            }
                            foreach ($itp_team_rows as $team_row) {
                                $mapped_role = itp_map_legacy_role((string)($team_row['role'] ?? ''));
                                if (!isset($itp_team_map[$mapped_role])) {
                                    continue;
                                }
                                if (!empty($team_row['name'])) {
                                    $current_name = trim((string)$itp_team_map[$mapped_role]['name']);
                                    $itp_team_map[$mapped_role]['name'] = $current_name === '' ? $team_row['name'] : ($current_name . '; ' . $team_row['name']);
                                }
                                if (!empty($team_row['contact_details']) && empty($itp_team_map[$mapped_role]['contact_details'])) {
                                    $itp_team_map[$mapped_role]['contact_details'] = $team_row['contact_details'];
                                }
                                if (!empty($team_row['date_started_with_team']) && empty($itp_team_map[$mapped_role]['date_started_with_team'])) {
                                    $itp_team_map[$mapped_role]['date_started_with_team'] = $team_row['date_started_with_team'];
                                }
                            }
                            if (!empty($itp['team_json']) && json_decode($itp['team_json'], true)) {
                                foreach (json_decode($itp['team_json'], true) as $team_member) {
                                    $mapped_role = itp_map_legacy_role((string)($team_member['role'] ?? ''));
                                    if (isset($itp_team_map[$mapped_role]) && empty($itp_team_map[$mapped_role]['name']) && !empty($team_member['name'])) {
                                        $itp_team_map[$mapped_role]['name'] = $team_member['name'];
                                    }
                                }
                            }
                            $itp_team = array_values($itp_team_map);

                            $itp['parent_guardian_name'] = itp_record_value($itp, ['parent_guardian_name'], $learner['primary_contact_name'] ?? '');
                            $itp['current_level_of_education'] = itp_record_value($itp, ['current_level_of_education']);
                            $itp['previous_school_name'] = itp_record_value($itp, ['previous_school_name']);
                            $itp['years_in_school'] = itp_record_value($itp, ['years_in_school']);
                            $itp['religion'] = itp_record_value($itp, ['religion']);
                            $itp['contact_number'] = itp_record_value($itp, ['contact_number'], $learner['primary_contact_number'] ?? '');
                            $itp['current_address'] = itp_record_value($itp, ['current_address']);
                            $itp['assessment_mode'] = itp_record_value($itp, ['assessment_mode']);
                            $itp['assessment_institution'] = itp_record_value($itp, ['assessment_institution']);
                            $itp['learning_packages'] = itp_record_value($itp, ['learning_packages']);
                            $itp['prepared_by_name'] = itp_record_value($itp, ['prepared_by_name']);
                            $itp['prepared_by_title'] = itp_record_value($itp, ['prepared_by_title']);
                            $itp['noted_by_name'] = itp_record_value($itp, ['noted_by_name']);
                            $itp['noted_by_title'] = itp_record_value($itp, ['noted_by_title']);
                            $itp['strengths'] = itp_record_value($itp, ['strengths', 'learner_strengths']);
                            $itp['interests'] = itp_record_value($itp, ['interests', 'learner_preferences']);
                            $itp['talents'] = itp_record_value($itp, ['talents']);
                            $itp['skills'] = itp_record_value($itp, ['skills']);
                            $itp['needs'] = itp_record_value($itp, ['needs', 'transition_needs']);
                            $itp['transition_goal'] = itp_record_value($itp, ['transition_goal', 'post_school_goal']);
                            $itp['recommendations'] = itp_record_value(
                                $itp,
                                ['recommendations'],
                                trim(implode("\n", array_filter([
                                    $itp['family_supports'] ?? '',
                                    $itp['community_linkages'] ?? '',
                                    $itp['follow_up_notes'] ?? '',
                                ])))
                            );
                            if (empty($itp['entry_point_label'])) {
                                foreach ($itp_labels as $field => $label) {
                                    if (!empty($itp[$field])) {
                                        $itp['entry_point_label'] = $label;
                                        break;
                                    }
                                }
                            }
                        }
                        ?>

                        <div id="itpSummaryBlock" class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-signpost-split"></i></span> Individual Transition Plan</h5>
                                <div class="d-flex gap-2">
                                    <?php if ($itp): ?>
                                        <a href="printITP.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($itp['school_year']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                        <?php if ($can_edit): ?>
                                            <a href="itp_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($itp['school_year']) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php endif; ?>
                                    <?php elseif ($can_edit): ?>
                                        <a href="itp_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($learner['school_year'] ?? '') ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-plus-lg"></i> Create ITP</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$itp): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-signpost-split" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No Individual Transition Plan is on file for this learner.</p>
                                    <?php if ($can_edit): ?>
                                        <a href="itp_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($learner['school_year'] ?? '') ?>" class="btn btn-primary rounded-pill px-4 mt-2"><i class="bi bi-plus-lg"></i> Create ITP</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Inline Iframe Preview -->
                                <div class="bg-secondary bg-opacity-10 p-2 rounded-3">
                                    <iframe class="w-100 border-0 rounded-3 shadow-sm" style="height: 80vh; background: #fff;" src="printITP.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($itp['school_year']) ?>"></iframe>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($is_non_graded): ?>
                    <!-- REPORT CARD TAB -->
                    <div class="tab-pane fade" id="sf9-pane" role="tabpanel">
                        <div class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-card-checklist"></i></span> Non Graded Progress Report</h5>
                                <div class="d-flex gap-2">
                                    <?php if (($learner['placement_type'] ?? '') === 'Non-Graded'): ?>
                                        <a href="printAnnex2.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($learner['school_year'] ?? '') ?>&quarter=all" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                        <?php if ($can_edit): ?>
                                            <a href="sf9_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($learner['school_year'] ?? '') ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border rounded-pill px-3 py-2">Available for Non-Graded placement only</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (($learner['placement_type'] ?? '') === 'Non-Graded'): ?>
                                <!-- Inline Iframe Preview -->
                                <div class="bg-secondary bg-opacity-10 p-2 rounded-3">
                                    <iframe class="w-100 border-0 rounded-3 shadow-sm" style="height: 80vh; background: #fff;" src="printAnnex2.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($learner['school_year'] ?? '') ?>&quarter=all"></iframe>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-card-checklist" style="font-size: 3rem;"></i>
                                    <p class="mt-2">Progress Report is available for Non-Graded placement only.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- BIR TAB -->
                    <div class="tab-pane fade" id="bir-pane" role="tabpanel">
                        <?php
                        $stmt_bir_tab = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE lrn = ? ORDER BY school_year DESC, FIELD(quarter,'Q1','Q2','Q3','Q4') DESC LIMIT 1");
                        $stmt_bir_tab->bind_param("s", $lrn);
                        $stmt_bir_tab->execute();
                        $bir_tab = $stmt_bir_tab->get_result()->fetch_assoc();
                        $bir_sy = $bir_tab['school_year'] ?? ($learner['school_year'] ?? $current_sy);
                        $bir_q = $bir_tab['quarter'] ?? '';
                        $bir_q_label = sned_bir_quarter_label($bir_q);
                        ?>

                        <div class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-clipboard-pulse"></i></span> Behavior Intervention Report (BIR)</h5>
                                    <?php if ($bir_tab): ?>
                                        <div class="small text-muted mt-1">SY <?= htmlspecialchars($bir_sy) ?> · <?= htmlspecialchars($bir_q_label) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if ($bir_tab): ?>
                                        <a href="printBIR.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($bir_sy) ?>&q=<?= urlencode($bir_q) ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print</a>
                                        <?php if ($can_edit): ?>
                                            <a href="bir_form.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($bir_sy) ?>&q=<?= urlencode($bir_q) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($can_edit): ?>
                                            <a href="bir_form.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($learner['school_year'] ?? $current_sy) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-plus-lg"></i> Create BIR</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$bir_tab): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-clipboard-pulse" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No Behavior Intervention Report on file for this student.</p>
                                    <?php if ($can_edit): ?>
                                        <a href="bir_form.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($learner['school_year'] ?? $current_sy) ?>" class="btn btn-primary rounded-pill px-4 mt-2"><i class="bi bi-plus-lg"></i> Create BIR</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Inline Iframe Preview -->
                                <div class="bg-secondary bg-opacity-10 p-2 rounded-3">
                                    <iframe class="w-100 border-0 rounded-3 shadow-sm" style="height: 80vh; background: #fff;" src="printBIR.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($bir_sy) ?>&q=<?= urlencode($bir_q) ?>"></iframe>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ASSESSMENT TAB -->
                    <div class="tab-pane fade" id="assessment-pane" role="tabpanel">
                        <?php
                        $stmt_ass_areas = $conn->prepare("
                            SELECT i.learning_area, COUNT(c.id) as rated_count, COUNT(i.id) as total_count
                            FROM assessment_checklist_items i
                            LEFT JOIN learner_assessment_checklist c ON i.id = c.item_id AND c.learner_id = ? AND (c.pre_rating IS NOT NULL OR c.post_rating IS NOT NULL)
                            GROUP BY i.learning_area
                            ORDER BY MIN(i.sort_order)
                        ");
                        $stmt_ass_areas->bind_param("s", $lrn);
                        $stmt_ass_areas->execute();
                        $ass_areas = $stmt_ass_areas->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt_ass_areas->close();
                        ?>

                        <div class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="fw-bold mb-0 d-flex align-items-center gap-2">
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;">
                                            <i class="bi bi-check2-square"></i>
                                        </span>
                                        Assessment Checklist
                                    </h5>
                                    <?php if ($ass_last_updated): ?>
                                        <div class="small text-muted mt-1">Last Updated: <?= date('F j, Y g:i A', strtotime($ass_last_updated)) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="assessmentPrint.php?id=<?= urlencode($lrn) ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-printer"></i> Print Checklist</a>
                                    <?php if ($can_edit): ?>
                                        <a href="assessment_form.php?id=<?= urlencode($lrn) ?>" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-pencil-square"></i> Edit Checklist</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row g-4 mt-1">
                                <div class="col-md-4 text-center border-end">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light mb-2" style="width: 100px; height: 100px;">
                                        <div class="text-center">
                                            <span class="fs-2 fw-bold text-primary"><?= $ass_percent ?>%</span>
                                        </div>
                                    </div>
                                    <h6 class="fw-bold mb-1">Overall Progress</h6>
                                    <p class="text-muted small mb-0"><?= $ass_total_rated ?> of <?= $ass_total_items ?> skills assessed</p>
                                </div>

                                <div class="col-md-8">
                                    <h6 class="fw-bold mb-3">Progress by Learning Area</h6>
                                    <div class="row g-3">
                                        <?php foreach ($ass_areas as $area): 
                                            $area_pct = $area['total_count'] > 0 ? (int)round(($area['rated_count'] / $area['total_count']) * 100) : 0;
                                            $area_label = ucwords(strtolower(str_replace('_', ' ', $area['learning_area'])));
                                        ?>
                                            <div class="col-md-6">
                                                <div class="d-flex justify-content-between mb-1 small">
                                                    <span class="fw-semibold text-secondary"><?= htmlspecialchars($area_label) ?></span>
                                                    <span class="text-muted"><?= $area['rated_count'] ?> / <?= $area['total_count'] ?></span>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $area_pct ?>%" aria-valuenow="<?= $area_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div> <!-- END OF tab-content -->

                <div class="border-top pt-5 mt-5">
                    <div class="row g-4">
                        <!-- Documents Section -->
                        <div class="col-lg-6">
                            <div class="card main-card p-4 border-0 bg-white shadow-sm mb-4 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-file-earmark-text"></i></span> Uploaded Documents</h5>
                                    <?php if ($can_edit): ?>
                                        <button class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal"><i class="bi bi-upload"></i> Upload Document</button>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($learner_docs)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="bi bi-file-earmark-text" style="font-size: 3rem;"></i>
                                        <p class="mt-2">No documents uploaded for this learner.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 small">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Document Type</th>
                                                    <th>Original Filename</th>
                                                    <th>Uploaded Date</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($learner_docs as $ld): ?>
                                                    <tr>
                                                        <td><span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($ld['document_type']) ?></span></td>
                                                        <td><?= htmlspecialchars($ld['original_filename']) ?></td>
                                                        <td><?= date('M d, Y', strtotime($ld['created_at'])) ?></td>
                                                        <td class="text-end">
                                                            <a href="download_doc.php?id=<?= $ld['id'] ?>" class="btn btn-sm btn-primary p-1" title="Download"><i class="bi bi-download"></i></a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- History Section -->
                        <div class="col-lg-6">
                            <!-- Cross-School Transfers -->
                            <?php 
                            $can_request_transfer = user_is_coordinator($user_role) && !user_is_teacher($user_role) && ($learner['school_id'] == $user_school_id);
                            $stmt_check_pending = $conn->prepare("
                                SELECT tr.*, COALESCE(s.school_name, tr.external_destination) AS destination_name 
                                FROM transfer_requests tr 
                                LEFT JOIN schools s ON tr.to_school_id = s.id
                                WHERE tr.learner_id = ? AND tr.status = 'pending' 
                                LIMIT 1
                            ");
                            $stmt_check_pending->bind_param("s", $lrn);
                            $stmt_check_pending->execute();
                            $pending_request = $stmt_check_pending->get_result()->fetch_assoc();

                            $stmt_latest_approved_transfer = $conn->prepare("
                                SELECT id
                                FROM transfer_requests
                                WHERE learner_id = ? AND status = 'approved'
                                ORDER BY reviewed_at DESC, requested_at DESC
                                LIMIT 1
                            ");
                            $stmt_latest_approved_transfer->bind_param("s", $lrn);
                            $stmt_latest_approved_transfer->execute();
                            $latest_approved_transfer = $stmt_latest_approved_transfer->get_result()->fetch_assoc();
                            ?>

                            <div class="card main-card p-4 border-0 bg-white shadow-sm mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="fw-bold mb-0 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-arrow-left-right"></i></span> Cross-School Transfers</h5>
                                    <?php if ($pending_request): ?>
                                        <?php if ($pending_request['requested_by'] == $_SESSION['user_id'] || user_is_admin($user_role)): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="cancelTransferRequest(<?= $pending_request['id'] ?>)"><i class="bi bi-x-circle"></i> Cancel Pending Transfer</button>
                                        <?php endif; ?>
                                    <?php elseif ($can_request_transfer): ?>
                                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#requestTransferModal"><i class="bi bi-send-plus"></i> Request Transfer</button>
                                    <?php endif; ?>
                                    <?php if ($latest_approved_transfer): ?>
                                        <a href="printForm137.php?lrn=<?= urlencode($lrn) ?>&request_id=<?= (int)$latest_approved_transfer['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill px-3"><i class="bi bi-file-earmark-text"></i> Form 137</a>
                                    <?php endif; ?>
                                </div>

                                <?php if ($pending_request): ?>
                                    <div class="alert alert-warning border rounded-3 p-3 mb-4">
                                        <strong>Pending Transfer Request Details:</strong><br>
                                        Destination: <?= htmlspecialchars($pending_request['destination_name'] ?: 'Transferred Out') ?><br>
                                        Effective School Year: SY <?= htmlspecialchars($pending_request['effective_sy']) ?><br>
                                        Reason: <?= htmlspecialchars($pending_request['reason']) ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Transfer History Table -->
                                <h6 class="fw-bold text-dark mb-2">Provenance Records</h6>
                                <?php
                                $is_admin = user_is_admin($user_role) ? 1 : 0;
                                $sess_school_id = $_SESSION['school_id'] ?? 0;
                                $stmt_trans = $conn->prepare("
                                    SELECT lt.*, 
                                           tr.status as request_status, 
                                           tr.effective_sy,
                                           s_from.school_name as from_school_name,
                                           s_to.school_name as to_school_name,
                                           u_req.full_name as requester_name,
                                           u_rev.full_name as reviewer_name
                                    FROM learner_transfers lt
                                    LEFT JOIN transfer_requests tr ON lt.transfer_request_id = tr.id
                                    LEFT JOIN users u_req ON tr.requested_by = u_req.id
                                    LEFT JOIN users u_rev ON tr.reviewed_by = u_rev.id
                                    LEFT JOIN schools s_from ON lt.from_school_id = s_from.id
                                    LEFT JOIN schools s_to ON lt.to_school_id = s_to.id
                                    WHERE lt.learner_id = ?
                                      AND (? = 1 OR lt.from_school_id = ? OR lt.to_school_id = ?)
                                    ORDER BY lt.transfer_date DESC
                                ");
                                $stmt_trans->bind_param("siii", $lrn, $is_admin, $sess_school_id, $sess_school_id);
                                $stmt_trans->execute();
                                $transfers = $stmt_trans->get_result()->fetch_all(MYSQLI_ASSOC);
                                ?>
                                <?php if (empty($transfers)): ?>
                                    <p class="text-muted small mb-0">No transfer history records associated with this learner.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm align-middle small mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>From School</th>
                                                    <th>To School</th>
                                                    <th>Effective SY</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transfers as $t): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($t['from_school_name'] ?: 'External') ?></td>
                                                        <td><?= htmlspecialchars($t['to_school_name'] ?: 'External') ?></td>
                                                        <td><code><?= htmlspecialchars($t['effective_sy'] ?: 'N/A') ?></code></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Enrollment History -->
                            <div class="card main-card p-4 border-0 bg-white shadow-sm mb-0">
                                <h5 class="fw-bold mb-3 d-flex align-items-center gap-2"><span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:28px;height:28px;background:var(--color-primary-light);color:var(--color-primary);font-size:.85rem;"><i class="bi bi-clock-history"></i></span> Enrollment History</h5>
                                <?php if (empty($enrollment_history)): ?>
                                    <p class="text-muted small mb-0">No enrollment history records found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 small">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>SY</th>
                                                    <th>School</th>
                                                    <th>Grade</th>
                                                    <th>Placement</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($enrollment_history as $eh): ?>
                                                    <tr>
                                                        <td><code><?= htmlspecialchars($eh['school_year']) ?></code></td>
                                                        <td><?= htmlspecialchars($eh['school_name'] ?: '—') ?></td>
                                                        <td><?= htmlspecialchars($eh['grade_level'] ?: '—') ?></td>
                                                        <td><?= htmlspecialchars($eh['placement_type'] ?: '—') ?></td>
                                                        <td>
                                                            <?php if (($eh['status'] ?? '') === 'transferred_out'): ?>
                                                                <span class="badge bg-dark">Transferred Out</span>
                                                            <?php elseif (($eh['status'] ?? '') === 'transferred'): ?>
                                                                <span class="badge bg-info text-dark">Transferred</span>
                                                            <?php elseif ($eh['is_historical']): ?>
                                                                <span class="badge bg-secondary">Historical</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REQUEST TRANSFER MODAL -->
    <?php if ($can_request_transfer): ?>
        <div class="modal fade" id="requestTransferModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-3 border-0 shadow">
                    <form id="transferRequestForm" onsubmit="submitTransferRequest(event)" enctype="multipart/form-data">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="submit">
                        <input type="hidden" name="learner_id" value="<?= htmlspecialchars($lrn) ?>">

                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i> Send Transfer Request</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        
                        <div class="modal-body py-3">
                            <p class="small text-muted mb-3">Send a transfer request for <strong><?= htmlspecialchars($learner['first_name'] . ' ' . $learner['last_name']) ?></strong>. The learner moves after approval.</p>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Transfer Type *</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="transfer_type" id="transferTypeInternal" value="internal" checked>
                                        <label class="form-check-label" for="transferTypeInternal">Within Valenzuela</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="transfer_type" id="transferTypeExternal" value="external">
                                        <label class="form-check-label" for="transferTypeExternal">Transferred Out</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3" id="internalTransferDestinationWrap">
                                <label for="to_school_id" class="form-label fw-bold">New School *</label>
                                <select class="form-select rounded-3" name="to_school_id" id="to_school_id" required>
                                    <option value="">Select new school</option>
                                    <?php
                                    $stmt_other_schools = $conn->prepare("
                                        SELECT s.id, s.school_name, s.district,
                                               (SELECT COUNT(*) FROM enrollments e WHERE e.school_id = s.id AND e.is_deleted = 0 AND e.is_historical = 0) as student_count
                                        FROM schools s
                                        WHERE s.id != ?
                                        ORDER BY s.school_name ASC
                                    ");
                                    $stmt_other_schools->bind_param("i", $learner['school_id']);
                                    $stmt_other_schools->execute();
                                    $other_schools = $stmt_other_schools->get_result()->fetch_all(MYSQLI_ASSOC);
                                    
                                    foreach ($other_schools as $os) {
                                        $os_principal = get_current_principal($conn, $os['id']) ?: 'No Principal assigned';
                                        echo '<option value="' . $os['id'] . '">' . htmlspecialchars($os['school_name'] . ' (' . $os_principal . ' · ' . $os['student_count'] . ' LWDs)') . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="mb-3 d-none" id="externalTransferDestinationWrap">
                                <label for="external_destination" class="form-label fw-bold">Transferred Out To *</label>
                                <input type="text" class="form-control rounded-3" name="external_destination" id="external_destination" placeholder="e.g. Outside Valenzuela Division">
                            </div>

                            <div class="mb-3">
                                <label for="effective_sy" class="form-label fw-bold">Effective School Year *</label>
                                <select class="form-select rounded-3" name="effective_sy" id="effective_sy" required>
                                    <?= school_year_options($learner['school_year']) ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="reason" class="form-label fw-bold">Reason for Transfer *</label>
                                <textarea class="form-control rounded-3" name="reason" id="reason" rows="3" placeholder="Enter reason (e.g. Relocated to another district)" required></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="supporting_document" class="form-label fw-bold">Supporting Document (Optional)</label>
                                <input type="file" class="form-control rounded-3" name="supporting_document" id="supporting_document" accept=".docx,.pdf,.txt,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.xlsx,.xls,.csv">
                            </div>
                        </div>

                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold">Send transfer request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
            try { bootstrap.Tab.getOrCreateInstance(btn); } catch(e) {}
        });

        // Handle URL Tab parameters
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabButton = document.getElementById(activeTab + '-tab');
            if (tabButton) {
                const tabObj = bootstrap.Tab.getOrCreateInstance(tabButton);
                if (tabObj) tabObj.show();
            }
        }

        function toggleTransferTypeFields() {
            const type = document.querySelector('#requestTransferModal input[name="transfer_type"]:checked')?.value || 'internal';
            const internalWrap = document.getElementById('internalTransferDestinationWrap');
            const externalWrap = document.getElementById('externalTransferDestinationWrap');
            const toSchool = document.getElementById('to_school_id');
            const externalDestination = document.getElementById('external_destination');
            if (!internalWrap || !externalWrap || !toSchool || !externalDestination) return;
            const isExternal = type === 'external';
            internalWrap.classList.toggle('d-none', isExternal);
            externalWrap.classList.toggle('d-none', !isExternal);
            toSchool.required = !isExternal;
            externalDestination.required = isExternal;
            if (isExternal) {
                toSchool.value = '';
            } else {
                externalDestination.value = '';
            }
        }
        document.querySelectorAll('#requestTransferModal input[name="transfer_type"]').forEach(input => {
            input.addEventListener('change', toggleTransferTypeFields);
        });
        toggleTransferTypeFields();

        // AJAX transfer submission
        function submitTransferRequest(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);

            fetch('submitTransferRequest.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showSystemNotice(data.message, 'Transfer Submitted', 'success');
                    location.reload();
                } else {
                    showSystemNotice(data.error, 'Transfer Failed', 'danger');
                }
            })
            .catch(error => {
                showSystemNotice('Server error: ' + error.message, 'Error', 'danger');
            });
        }

        // AJAX transfer cancellation
        function cancelTransferRequest(requestId) {
            if (!confirm('Are you sure you want to cancel this pending transfer request?')) return;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('action', 'cancel');
            formData.append('request_id', requestId);

            fetch('submitTransferRequest.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showSystemNotice(data.message, 'Transfer Cancelled', 'success');
                    location.reload();
                } else {
                    showSystemNotice(data.error, 'Cancellation Failed', 'danger');
                }
            })
            .catch(error => {
                showSystemNotice('Server error: ' + error.message, 'Error', 'danger');
            });
        }

        // Quick Enroll
        function submitQuickEnroll(e) {
            e.preventDefault();
            var btn = document.getElementById('quickEnrollBtn');
            var form = document.getElementById('quickEnrollForm');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enrolling…';
            fetch('viewLearner.php?id=<?= urlencode($lrn) ?>', {
                method: 'POST',
                body: new FormData(form)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('quickEnrollModal'));
                    if (modal) modal.hide();
                    showSystemNotice(data.msg, 'Enrolled', 'success');
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    showSystemNotice(data.error, 'Enrollment Failed', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Enroll';
                }
            })
            .catch(function(err) {
                showSystemNotice('Server error: ' + err.message, 'Error', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Enroll';
            });
            return false;
        }

        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const shell = document.querySelector('.app-shell');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                shell.classList.toggle('sidebar-hidden');
            });
        }

        // Document Preview iframe modal functions
        function openDocumentPreview(title, url) {
            document.getElementById('docPreviewTitle').textContent = title;
            const iframe = document.getElementById('docPreviewIframe');
            iframe.src = url;
            const modal = new bootstrap.Modal(document.getElementById('docPreviewModal'));
            modal.show();
        }

        function printPreviewDocument() {
            const iframe = document.getElementById('docPreviewIframe');
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            }
        }

        // Clear iframe src on modal hide to stop media or load state
        document.addEventListener('DOMContentLoaded', () => {
            const modalEl = document.getElementById('docPreviewModal');
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', () => {
                    document.getElementById('docPreviewIframe').src = 'about:blank';
                });
            }
        });

        const msg = urlParams.get('msg');
        const type = urlParams.get('msg_type') || 'success';
        const title = urlParams.get('msg_title') || 'Notice';
        if (msg && typeof showSystemNotice === 'function') {
            showSystemNotice(msg, title, type);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const enrollPrompt = urlParams.get('enroll_prompt');
            if (enrollPrompt) {
                const enrollModalEl = document.getElementById('quickEnrollModal');
                if (enrollModalEl) {
                    const enrollModal = new bootstrap.Modal(enrollModalEl);
                    enrollModal.show();
                }
            }
        });

        function validateQeGradeLevelPlacement(age) {
            const gradeLevelSelect = document.getElementById('qe_grade_level');
            const gradeNoticeEl = document.getElementById('qeGradeLevelNotice');
            
            if (!gradeLevelSelect || !gradeNoticeEl || age === undefined || age === null) return;
            
            const gradeLevelVal = gradeLevelSelect.value;
            
            gradeNoticeEl.classList.add('d-none');
            gradeNoticeEl.innerHTML = '';
            
            if (!gradeLevelVal) return;
            
            let selectedEnvironment = '';
            const elementaryGrades = ['Kindergarten', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
            const secondaryGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];
            
            if (elementaryGrades.includes(gradeLevelVal)) {
                selectedEnvironment = 'elementary';
            } else if (secondaryGrades.includes(gradeLevelVal) || gradeLevelVal.startsWith('Grade 11') || gradeLevelVal.startsWith('Grade 12')) {
                selectedEnvironment = 'secondary';
            }
            
            if (age >= 5 && age <= 14) {
                if (selectedEnvironment === 'secondary') {
                    gradeNoticeEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Age ' + age + ' is recommended for Elementary environment, but a Secondary grade level is selected.';
                    gradeNoticeEl.classList.remove('d-none');
                }
            } else if (age >= 15 && age <= 24) {
                if (selectedEnvironment === 'elementary') {
                    gradeNoticeEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Age ' + age + ' is recommended for Secondary environment, but an Elementary grade level is selected.';
                    gradeNoticeEl.classList.remove('d-none');
                }
            } else if (age >= 25) {
                gradeNoticeEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Learner is 25+ years old and should be referred to alternative programs (DepEd ALS, DSWD, TESDA, DOLE).';
                gradeNoticeEl.classList.remove('d-none');
            }
        }

        const qeSchoolGradeLevels = <?= json_encode($quick_enroll_grade_levels, JSON_UNESCAPED_SLASHES) ?>;
        const qeGradeLevelGroups = <?= json_encode(sned_grade_level_groups(false), JSON_UNESCAPED_SLASHES) ?>;

        function renderQeGradeLevelOptions(select, allowedValues, selectedValue) {
            if (!select) return;
            const selected = selectedValue || '';
            const allowed = new Set(Array.isArray(allowedValues) ? allowedValues : []);
            select.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = allowed.size > 0 ? '-- Select Grade Level --' : 'No grade levels configured for this school';
            placeholder.disabled = allowed.size === 0;
            select.appendChild(placeholder);

            Object.entries(qeGradeLevelGroups).forEach(([label, options]) => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = label;
                let hasOptions = false;

                Object.entries(options).forEach(([value, text]) => {
                    if (!allowed.has(value)) return;
                    hasOptions = true;
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = text;
                    if (selected === value) {
                        option.selected = true;
                    }
                    optgroup.appendChild(option);
                });

                if (hasOptions) {
                    select.appendChild(optgroup);
                }
            });

            if (selected && !allowed.has(selected)) {
                select.value = '';
            }
        }

        function syncQuickEnrollGradeLevels(preferredValue) {
            const schoolSelect = document.getElementById('qe_school_id');
            const gradeLevelSelect = document.getElementById('qe_grade_level');
            if (!schoolSelect || !gradeLevelSelect) return;
            renderQeGradeLevelOptions(gradeLevelSelect, qeSchoolGradeLevels[schoolSelect.value] || [], preferredValue ?? gradeLevelSelect.value);
            validateQeGradeLevelPlacement(<?= $age ?>);
        }

        function toggleQuickEnrollPlacement() {
            const placementSelect = document.getElementById('qe_placement_type');
            const gradeWrap = document.getElementById('qeGradeLevelWrap');
            const gradeSelect = document.getElementById('qe_grade_level');
            const sclWrap = document.getElementById('qeSclWrap');
            const sclSelect = document.getElementById('qe_self_contained_level');
            if (!placementSelect) return;

            const isNonGraded = placementSelect.value === 'Non-Graded';
            if (gradeWrap) gradeWrap.style.display = isNonGraded ? 'none' : '';
            if (sclWrap) sclWrap.style.display = isNonGraded ? '' : 'none';
            if (gradeSelect) gradeSelect.required = !isNonGraded;
            if (sclSelect) sclSelect.required = isNonGraded;
            if (!isNonGraded) {
                if (sclSelect) sclSelect.value = '';
                syncQuickEnrollGradeLevels();
            }
        }

        function toggleQeAssessmentMode() {
            const withAssess = document.getElementById('qe_assess_yes');
            const hintEl = document.getElementById('qeDisabilityHint');
            if (!hintEl) return;
            if (withAssess && withAssess.checked) {
                hintEl.textContent = 'Select all diagnosed/confirmed disabilities (With Assessment — use clinically confirmed diagnoses only).';
            } else {
                hintEl.textContent = 'Select all applicable functional difficulties (Without Assessment — based on observed behavior and teacher/parent report).';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const qeModalEl = document.getElementById('quickEnrollModal');
            const qeSchoolSelect = document.getElementById('qe_school_id');
            if (qeSchoolSelect) {
                qeSchoolSelect.addEventListener('change', () => syncQuickEnrollGradeLevels(''));
                syncQuickEnrollGradeLevels();
            }
            const qePlacementSelect = document.getElementById('qe_placement_type');
            if (qePlacementSelect) {
                qePlacementSelect.addEventListener('change', toggleQuickEnrollPlacement);
                toggleQuickEnrollPlacement();
            }
            if (qeModalEl) {
                qeModalEl.addEventListener('shown.bs.modal', () => {
                    validateQeGradeLevelPlacement(<?= $age ?>);
                    toggleQeAssessmentMode();
                });
            }
        });
    </script>

    <!-- Quick Enroll Modal -->
    <div class="modal" id="quickEnrollModal" tabindex="-1" aria-labelledby="quickEnrollModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-3">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="quickEnrollModalLabel"><i class="bi bi-mortarboard text-primary me-2"></i>Quick Enroll Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="quickEnrollForm" onsubmit="return submitQuickEnroll(event)">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="quick_enroll">
                    <div class="modal-body p-4">
                        <!-- Dynamic Age Recommendation Notice -->
                        <?php
                        $alert_class = 'alert-info';
                        $alert_icon = 'bi-info-circle-fill text-info';
                        $alert_title = '';
                        $alert_text = '';
                        
                        if ($age < 5) {
                            $alert_class = 'alert-info';
                            $alert_icon = 'bi-info-circle-fill text-info';
                            $alert_title = 'Early Intervention Program (Age ' . $age . ')';
                            $alert_text = 'Learners below 5 years old are generally placed in early intervention or child development centers rather than formal elementary school environments.';
                        } elseif ($age >= 5 && $age <= 14) {
                            $alert_class = 'alert-success';
                            $alert_icon = 'bi-check-circle-fill text-success';
                            $alert_title = 'Recommended Environment: Elementary School (Age ' . $age . ')';
                            $alert_text = 'Based on the learner\'s age group (5 to 14 years old), they should be placed in an <strong>Elementary School</strong> environment.';
                        } elseif ($age >= 15 && $age <= 24) {
                            $alert_class = 'alert-success';
                            $alert_icon = 'bi-check-circle-fill text-success';
                            $alert_title = 'Recommended Environment: Secondary / High School (Age ' . $age . ')';
                            $alert_text = 'Based on the learner\'s age group (15 to 24 years old), they should be placed in a <strong>Secondary School, High School, or Senior High School</strong> environment.';
                        } else { // age >= 25
                            $alert_class = 'alert-warning';
                            $alert_icon = 'bi-exclamation-triangle-fill text-warning';
                            $alert_title = 'Alternative Program Referral Required (Age ' . $age . ')';
                            $alert_text = 'Individuals aged 25 and above are out of school age bounds. They <strong>shall be referred to other options</strong> such as, but not limited to, <strong>DepEd ALS, DSWD, TESDA, and DOLE Programs</strong>.';
                        }
                        ?>
                        <div class="alert <?= $alert_class ?> shadow-sm border-0 rounded-4 p-3 d-flex gap-3 align-items-start mb-3">
                            <div class="fs-4 lh-1 bi <?= $alert_icon ?>"></div>
                            <div>
                                <h6 class="fw-bold mb-1"><?= $alert_title ?></h6>
                                <p class="mb-0 small text-secondary"><?= $alert_text ?></p>
                                <div class="mt-2 text-muted small" style="font-size: 0.75rem; border-top: 1px solid rgba(0,0,0,0.08); padding-top: 0.5rem;">
                                    <strong>Disclaimer:</strong> Placements are recommendations based on age groups. The final educational pathway should align with individual developmental assessments, capabilities, and localized SPED team reviews. Learners aged 25 and above must be referred to community-based and adult livelihood/vocational training programs.
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">School *</label>
                            <select name="school_id" id="qe_school_id" class="form-select rounded-3" required>
                                <option value="">-- Select School --</option>
                                <?php
                                foreach ($quick_enroll_schools as $qsch):
                                    $sel = ($user_school_id && (int)$qsch['id'] === (int)$user_school_id) ? ' selected' : '';
                                ?>
                                    <option value="<?= (int)$qsch['id'] ?>"<?= $sel ?>><?= htmlspecialchars($qsch['school_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase">School Year *</label>
                                <select name="school_year" class="form-select rounded-3" required>
                                    <?= school_year_options($learner['school_year'] ?? null) ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase">Placement Type *</label>
                                <select name="placement_type" id="qe_placement_type" class="form-select rounded-3" required>
                                    <option value="Graded">Graded</option>
                                    <option value="Non-Graded">Non-Graded</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6" id="qeGradeLevelWrap">
                                <label class="form-label fw-bold small text-uppercase">Grade Level *</label>
                                <select name="grade_level" id="qe_grade_level" class="form-select rounded-3" required onchange="validateQeGradeLevelPlacement(<?= $age ?>)">
                                    <?php
                                    $selected_qe_school_id = '';
                                    if (!empty($user_school_id) && isset($quick_enroll_grade_levels[(string)$user_school_id])) {
                                        $selected_qe_school_id = (string)$user_school_id;
                                    }
                                    echo grade_level_options('', $selected_qe_school_id !== '' ? $quick_enroll_grade_levels[$selected_qe_school_id] : [], false);
                                    ?>
                                </select>
                                <div id="qeGradeLevelNotice" class="text-danger small mt-1 d-none"></div>
                            </div>
                            <div class="col-md-6" id="qeSclWrap" style="display:none;">
                                <label class="form-label fw-bold small text-uppercase">Program</label>
                                <select name="self_contained_level" id="qe_self_contained_level" class="form-select rounded-3">
                                    <option value="">-- Not set --</option>
                                    <option value="Kinder">Kinder</option>
                                    <option value="Primary Level 1">Primary Level 1</option>
                                    <option value="Primary Level 2">Primary Level 2</option>
                                    <option value="Primary Level 3">Primary Level 3</option>
                                    <option value="Transition">Transition</option>
                                </select>
                            </div>
                        </div>
                        <!-- Medical/Clinical Assessment Question (required) -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">Does the learner have a medical/clinical assessment? *</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assessment_mode" id="qe_assess_yes" value="With Assessment" required onchange="toggleQeAssessmentMode()">
                                    <label class="form-check-label fw-semibold" for="qe_assess_yes">Yes — With Assessment</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assessment_mode" id="qe_assess_no" value="Without Assessment" required onchange="toggleQeAssessmentMode()">
                                    <label class="form-check-label fw-semibold" for="qe_assess_no">No — Without Assessment</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">Learner Disabilities / Functional Difficulties *</label>
                            <div class="small text-muted mb-1" id="qeDisabilityHint"></div>
                            <?= sned_render_disability_checklist([]) ?>
                            <select name="primary_diagnosis" class="d-none" aria-hidden="true" tabindex="-1">
                                <option value="">Compatibility summary</option>
                                <option value="Autism Spectrum Disorder (ASD)">Autism Spectrum Disorder (ASD)</option>
                                <option value="Intellectual Disability (ID)">Intellectual Disability (ID)</option>
                                <option value="Global Developmental Delay (GDD)">Global Developmental Delay (GDD)</option>
                                <option value="Hearing Impairment">Hearing Impairment</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">Remarks</label>
                            <textarea name="remarks" class="form-control rounded-3" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                        <!-- Prior Schooling & Clinical Assessments -->
                        <div class="border-top pt-3 mt-1">
                            <p class="fw-bold small text-uppercase text-muted mb-2"><i class="bi bi-clock-history me-1"></i> Prior Schooling &amp; Clinical Assessments <span class="fw-normal">(optional)</span></p>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Previous School Name</label>
                                    <input type="text" name="previous_school_name" class="form-control form-control-sm rounded-3" maxlength="255" placeholder="e.g. Valenzuela Elementary School">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Years in School</label>
                                    <input type="text" name="years_in_school" class="form-control form-control-sm rounded-3" maxlength="50" placeholder="e.g. 3 years">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Level of Education</label>
                                    <input type="text" name="current_level_of_education" class="form-control form-control-sm rounded-3" maxlength="255" placeholder="e.g. Elementary">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Interpreter Needed</label>
                                    <input type="text" name="interpreter_needed" class="form-control form-control-sm rounded-3" maxlength="255" placeholder="e.g. Sign Language, none">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Clinical Assessment Institution</label>
                                    <input type="text" name="assessment_institution" class="form-control form-control-sm rounded-3" maxlength="255" placeholder="e.g. Child Hope Center">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Detailed Clinical/Medical Assessment Results</label>
                                    <textarea name="medical_results" class="form-control form-control-sm rounded-3" rows="2" placeholder="Enter clinical assessment notes or medical findings..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 d-flex gap-2">
                        <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold" id="quickEnrollBtn"><i class="bi bi-check-circle me-1"></i> Enroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow rounded-3">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="uploadDocumentModalLabel"><i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>Upload Student Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="saveDocument.php" method="POST" enctype="multipart/form-data">
                    <?= csrf_input() ?>
                    <input type="hidden" name="lrn" value="<?= htmlspecialchars($lrn) ?>">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">File *</label>
                            <input type="file" name="doc_file" accept=".docx,.pdf,.txt,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.xlsx,.xls,.csv" class="form-control rounded-3" required>
                            <div class="form-text">Supported: DOCX, PDF, TXT, RTF, JPG, PNG, GIF, WebP, BMP, XLSX, XLS, CSV</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">Document Type</label>
                            <select name="document_type" class="form-select rounded-3">
                                <option value="IEP">IEP</option>
                                <option value="ILP">ILP</option>
                                <option value="ITP">ITP</option>
                                <option value="SF9">SF9 Report Card</option>
                                <option value="Form137">Form 137</option>
                                <option value="Assessment">Assessment</option>
                                <option value="Medical">Medical</option>
                                <option value="Referral">Referral</option>
                                <option value="MDT Consultation">MDT Consultation</option>
                                <option value="Transfer Support">Transfer Support</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase">Notes</label>
                            <textarea name="notes" class="form-control rounded-3" rows="3" maxlength="500" placeholder="Add some notes about this document..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 d-flex gap-2">
                        <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold"><i class="bi bi-cloud-upload me-1"></i> Upload & Link</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Preview Modal -->
    <div class="modal fade" id="docPreviewModal" tabindex="-1" aria-labelledby="docPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-light border-0 py-3">
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                        <span id="docPreviewTitle">Document Preview</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-2 bg-secondary bg-opacity-10">
                    <iframe id="docPreviewIframe" class="w-100 border-0" style="height: 75vh; background: #fff;" src="about:blank"></iframe>
                </div>
                <div class="modal-footer border-0 py-3 bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" onclick="printPreviewDocument()">
                        <i class="bi bi-printer-fill me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
