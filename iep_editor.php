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

$lrn = $_GET['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner LRN.");
}

// Load learner & active enrollment
$stmt = $conn->prepare("
    SELECT l.*, e.id as enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level, e.grade_level, e.primary_diagnosis,
           e.interpreter_needed, e.medical_results,
           s.school_name, s.address AS school_address,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle, g.extension_name AS g_ext,
           g.contact_number AS g_contact, g.house_no_street AS g_house, g.barangay AS g_barangay,
           g.workplace AS g_workplace, g.landline AS g_landline, g.mother_tongue AS g_mother_tongue,
           lg.full_name AS primary_contact_name, lg.contact_number AS primary_contact_number,
           lg.email AS primary_contact_email, lg.address AS primary_contact_address, lg.occupation AS primary_contact_occupation
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    LEFT JOIN guardians g ON l.guardian_id = g.id
    LEFT JOIN learner_guardians lg ON lg.id = (
        SELECT id
        FROM learner_guardians
        WHERE lrn = l.lrn AND is_primary_contact = 1
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    )
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();
if (!$learner) {
    die("Learner not found.");
}

// School scope check
if (!check_teacher_learner_access($conn, $lrn, true)) {
    header("Location: 403.php");
    exit();
}

$school_year = trim($_GET['sy'] ?? $_GET['school_year'] ?? ($learner['school_year'] ?? ''));

// Load existing IEP if any
$iep = null;
$barriers = [];
$goals = [];
$transitions = [];
$stmt_i = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? ORDER BY id DESC LIMIT 1");
$stmt_i->bind_param("ss", $lrn, $school_year);
$stmt_i->execute();
$iep = $stmt_i->get_result()->fetch_assoc();
$iep_is_new = !$iep;

if ($iep) {
    $stmt_r = $conn->prepare("SELECT * FROM learner_iep_annex6_rows WHERE iep_id = ? ORDER BY section_type ASC, sort_order ASC, id ASC");
    $stmt_r->bind_param("i", $iep['id']);
    $stmt_r->execute();
    $rows = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        if ($r['section_type'] === 'barrier') $barriers[] = $r;
        elseif ($r['section_type'] === 'goal') $goals[] = $r;
        elseif ($r['section_type'] === 'transition') $transitions[] = $r;
    }
}

// Copy-forward from previous school year
$copy_from_sy = $_GET['copyFromSy'] ?? null;
$copy_applied = null;
if ($iep_is_new && $copy_from_sy && preg_match('/^\d{4}-\d{4}$/', $copy_from_sy) && $copy_from_sy !== $school_year) {
    $stmt_copy = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? ORDER BY id DESC LIMIT 1");
    $stmt_copy->bind_param("ss", $lrn, $copy_from_sy);
    $stmt_copy->execute();
    $source_iep = $stmt_copy->get_result()->fetch_assoc();
    if ($source_iep) {
        $copy_applied = $copy_from_sy;
        $source_iep_id = (int)$source_iep['id'];
        unset($source_iep['id'], $source_iep['created_at'], $source_iep['updated_at']);
        $source_iep['school_year'] = $school_year;
        $source_iep['meeting_date'] = null;
        $source_iep['iep_review_date'] = null;
        $source_iep['date_of_last_iep'] = null;
        $iep = $source_iep;

        $stmt_r_copy = $conn->prepare("SELECT * FROM learner_iep_annex6_rows WHERE iep_id = ? ORDER BY section_type ASC, sort_order ASC, id ASC");
        $stmt_r_copy->bind_param("i", $source_iep_id);
        $stmt_r_copy->execute();
        $rows = $stmt_r_copy->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            if ($r['section_type'] === 'barrier') $barriers[] = $r;
            elseif ($r['section_type'] === 'goal') $goals[] = $r;
            elseif ($r['section_type'] === 'transition') $transitions[] = $r;
        }
    }
}

$age = (new DateTime($learner['birthday']))->diff(new DateTime())->y;
$difficulty_labels = [
    'diff_seeing' => 'Difficulty in Seeing',
    'diff_hearing' => 'Difficulty in Hearing',
    'diff_communicating' => 'Difficulty in Communicating',
    'diff_mobility' => 'Difficulty in Mobility/Walking',
    'diff_interpersonal' => 'Difficulty in Displaying Interpersonal Behaviors',
    'diff_adaptive' => 'Difficulty in Performing Adaptive Skills',
    'diff_basic_skills' => 'Difficulty in Basic Skills and Applying Knowledge',
    'diff_remembering' => 'Difficulty in Remembering/Concentrating',
];

// Auto-fill from student active enrollment disabilities/difficulties
$mapped_difficulties = [];
$mapped_diagnosed_list = [];
if (!$iep && empty($_POST)) {
    $enrollment_disabilities = [];
    if (!empty($learner['enrollment_id'])) {
        $enrollment_disabilities = sned_get_enrollment_disabilities($conn, (int)$learner['enrollment_id']);
    }
    foreach ($enrollment_disabilities as $ed) {
        if (($ed['category'] ?? '') === 'difficulty') {
            $dis = $ed['disability'] ?? '';
            if ($dis === 'Seeing') $mapped_difficulties['diff_seeing'] = true;
            elseif ($dis === 'Hearing') $mapped_difficulties['diff_hearing'] = true;
            elseif ($dis === 'Basic Learning/Applying Knowledge') $mapped_difficulties['diff_basic_skills'] = true;
            elseif ($dis === 'Remembering/Concentrating/Attention/Understanding') $mapped_difficulties['diff_remembering'] = true;
            elseif ($dis === 'Adaptive Skills') $mapped_difficulties['diff_adaptive'] = true;
            elseif ($dis === 'Interpersonal Behavior') $mapped_difficulties['diff_interpersonal'] = true;
            elseif ($dis === 'Mobility') $mapped_difficulties['diff_mobility'] = true;
            elseif ($dis === 'Communicating') $mapped_difficulties['diff_communicating'] = true;
            elseif ($dis === 'Other') {
                $mapped_difficulties['diff_others_check'] = true;
                $mapped_difficulties['diff_others_text'] = $ed['other_text'] ?? '';
            }
        } elseif (($ed['category'] ?? '') === 'diagnosed') {
            if ($ed['disability'] === 'Other') {
                if (!empty($ed['other_text'])) {
                    $mapped_diagnosed_list[] = $ed['other_text'];
                } else {
                    $mapped_diagnosed_list[] = 'Other';
                }
            } else {
                $mapped_diagnosed_list[] = $ed['disability'];
            }
        }
    }
}
$is_new_form = !$iep && empty($_POST);
$has_diagnosed = !empty($mapped_diagnosed_list);
$diagnosed_str = implode(', ', $mapped_diagnosed_list);
if ($is_new_form && !empty($learner['medical_results'])) {
    if ($diagnosed_str !== '') {
        $diagnosed_str .= ' (Results: ' . $learner['medical_results'] . ')';
    } else {
        $diagnosed_str = $learner['medical_results'];
    }
}
$default_med_diag = ($is_new_form && ($has_diagnosed || !empty($learner['medical_results']))) ? '1' : '0';

$purpose_options = ['Initial IEP', 'Interim IEP', 'Term IEP', 'IEP Following 3-Year Reevaluation', 'Revision to IEP', 'Exit/Graduation', 'IEP Revision Without a Meeting'];
$team_roles = [
    'Parent/Guardian',
    'Learner',
    'Principal/School Head',
    'Special Education Teacher Adviser',
    'Other SPED Teachers',
    'Regular Education/Receiving Teacher',
    'School Psychologist',
    'Guidance Counselor',
    'School Nurse',
    'Occupational Therapist',
    'Physical Therapist',
    'Speech Pathologist/Specialist',
    'Speech-Language Interpreter',
    'Other'
];
$special_factor_keys = [
    'sf_behavior' => 'Learner has difficulty relating with people (consider Behavior Intervention Plan)',
    'sf_mobility' => 'Difficulty in moving/walking (describe mobility needs)',
    'sf_vision' => 'Difficulty seeing or with blindness/visual impairment (if yes, describe Braille instruction consideration)',
    'sf_communication' => 'Difficulty in communicating (describe communication needs)',
    'sf_attention' => 'Difficulty in concentrating/paying attention (describe attention span needs)',
    'sf_memory' => 'Difficulty in remembering/understanding (describe understanding needs)',
    'sf_hearing' => 'Difficulty in hearing or deaf/hard of hearing (describe language and communication needs)',
    'sf_assistive_tech' => 'Needs assistive technology devices or services (describe type and usage)',
    'sf_alt_format' => 'Requires alternative format for instructional materials',
];
$sf_json = [];
if ($iep && $iep['special_factors_json']) {
    $sf_json = json_decode($iep['special_factors_json'], true) ?? [];
}

// Fallback auto-fills from student/guardian record
if (empty($sf_json['guardian_name'])) {
    $sf_json['guardian_name'] = sned_guardian_name($learner);
}
if (empty($sf_json['home_address'])) {
    $sf_json['home_address'] = sned_guardian_address($learner);
}
if (empty($sf_json['guardian_mobile'])) {
    $sf_json['guardian_mobile'] = sned_guardian_contact($learner);
}
if (empty($sf_json['guardian_email'])) {
    $sf_json['guardian_email'] = $learner['primary_contact_email'] ?? '';
}
if (empty($sf_json['guardian_workplace'])) {
    $sf_json['guardian_workplace'] = $learner['g_workplace'] ?? $learner['primary_contact_occupation'] ?? '';
}
if (empty($sf_json['mother_tongue'])) {
    $sf_json['mother_tongue'] = $learner['mother_tongue'] ?? '';
}
if (empty($sf_json['learner_phone'])) {
    $sf_json['learner_phone'] = $learner['contact_number'] ?? '';
}
if (empty($sf_json['guardian_landline'])) {
    $sf_json['guardian_landline'] = $learner['g_landline'] ?? '';
}
if (empty($sf_json['guardian_mother_tongue'])) {
    $sf_json['guardian_mother_tongue'] = $learner['g_mother_tongue'] ?? '';
}

$team_members = [];
if ($iep && $iep['iep_team_json']) {
    $team_members = json_decode($iep['iep_team_json'], true) ?? [];
}
if (empty($team_members)) {
    $team_members = array_values(array_filter([
        ['role' => 'Parent/Guardian', 'name' => $sf_json['guardian_name'] ?? ''],
        ['role' => 'Learner', 'name' => sned_learner_name($learner)],
        ['role' => 'Principal/School Head', 'name' => !empty($learner['school_id']) ? get_current_principal($conn, (int)$learner['school_id']) : ''],
        ['role' => 'Special Education Teacher Adviser', 'name' => $_SESSION['full_name'] ?? ''],
    ], static fn($row) => trim((string)$row['name']) !== ''));
}

$selected_transition_point = '';
foreach ([
    'transition_home_to_school',
    'transition_school_to_life',
    'transition_sped_to_inclusion',
    'transition_grade_to_grade',
    'transition_school_to_work',
    'transition_class_to_class',
] as $transition_field) {
    if (!empty($iep[$transition_field])) {
        $selected_transition_point = $transition_field;
        break;
    }
}

$can_edit = user_is_coordinator($user_role);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IEP Record Editor | <?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name']) ?></title>
    <link class="styles" rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
</head>
<body>
    <div class="d-flex vh-100 app-shell sidebar-collapsed sidebar-hidden">
        <?php include 'sidebar.php'; ?>
        <div class="flex-grow-1 d-flex flex-column overflow-auto bg-light">
            <!-- Header -->
            <header class="p-3 border-bottom shadow-sm d-flex align-items-center justify-content-between sticky-top bg-white mb-4">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                        <i class="bi bi-list"></i> <span id="sidebarToggleText">Show menu</span>
                    </button>
                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>" class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Profile</a>
                    <h4 class="fw-bold mb-0 d-none d-md-inline-block"><i class="bi bi-clipboard-pulse text-success me-2"></i> Individualized Education Plan Editor</h4>
                </div>
                <div class="text-end small text-muted">
                    <strong><?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name']) ?></strong><br>
                    LRN: <?= htmlspecialchars($lrn) ?> · Grade: <?= htmlspecialchars($learner['grade_level'] ?: 'None') ?> · <?= $age ?> yrs old
                </div>
            </header>

            <div class="p-4 container-fluid mx-auto" style="max-width: 1100px;">
                <!-- Stepper Navigation -->
                <div class="step-progress-wrapper mb-4" id="stepperWrapper">
                    <div class="step-progress-item active" data-step="1" id="stepIndicator1">
                        <span class="step-progress-label">Personal Info</span>
                    </div>
                    <div class="step-progress-item" data-step="2" id="stepIndicator2">
                        <span class="step-progress-label">Present Levels</span>
                    </div>
                    <div class="step-progress-item" data-step="3" id="stepIndicator3">
                        <span class="step-progress-label">Barriers &amp; Supports</span>
                    </div>
                    <div class="step-progress-item" data-step="4" id="stepIndicator4">
                        <span class="step-progress-label">Goals</span>
                    </div>
                    <div class="step-progress-item" data-step="5" id="stepIndicator5">
                        <span class="step-progress-label">Transition</span>
                    </div>
                </div>

                <form id="iepForm" action="save_iep_annex6.php" method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="learner_id" value="<?= htmlspecialchars($lrn) ?>">
                    <input type="hidden" name="enrollment_id" value="<?= $learner['enrollment_id'] ?? '' ?>">
                    <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">

                    <?php $prev_sy = sned_previous_school_year($school_year); ?>
                    <?php if ($iep_is_new && !$copy_applied && $prev_sy && empty($_GET['copyFromSy'])): ?>
                    <div class="alert alert-info border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-copy text-info me-2" style="font-size: 1.5rem;"></i>
                            <strong>Start fresh or copy forward?</strong>
                            <span class="ms-2 text-muted">No IEP record exists for SY <?= htmlspecialchars($school_year) ?>.</span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="iep_editor.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($school_year) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">Start blank</a>
                            <a href="iep_editor.php?learner_id=<?= urlencode($lrn) ?>&sy=<?= urlencode($school_year) ?>&copyFromSy=<?= urlencode($prev_sy) ?>" class="btn btn-info btn-sm rounded-pill px-3 text-white"><i class="bi bi-copy me-1"></i> Copy from SY <?= htmlspecialchars($prev_sy) ?></a>
                        </div>
                    </div>
                    <?php elseif ($copy_applied): ?>
                    <div class="alert alert-success border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 1.5rem;"></i>
                            <strong>Copied from SY <?= htmlspecialchars($copy_applied) ?>.</strong>
                            <span class="ms-2 text-muted">Review and update the fields below, then save.</span>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm rounded-pill px-3 fw-bold"><i class="bi bi-cloud-arrow-up me-1"></i> Save now</button>
                    </div>
                    <?php endif; ?>

                    <div class="tab-content" id="iepTabs">
                        <!-- ==================== SECTION 1: Personal Information ==================== -->
                        <div class="tab-pane fade show active" id="sec1" role="tabpanel">
                            <div class="sned-card">
                                <div class="sned-card-header">
                                    <h5><i class="bi bi-person-vcard text-success me-2"></i>Section A: Personal Information</h5>
                                </div>
                                <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Learner Full Name</label>
                                    <input type="text" class="form-control rounded-3" value="<?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . ($learner['middle_name'] ?? '')) ?>" disabled>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Date of Birth</label>
                                    <input type="date" class="form-control rounded-3" value="<?= $learner['birthday'] ?? '' ?>" disabled>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Age</label>
                                    <input type="text" class="form-control rounded-3" value="<?= $age ?>" disabled>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Sex</label>
                                    <input type="text" class="form-control rounded-3" value="<?= $learner['sex'] ?? '' ?>" disabled>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">LRN <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control rounded-3" value="<?= htmlspecialchars($lrn) ?>" disabled>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label fw-bold small">Grade</label>
                                    <input type="text" class="form-control rounded-3" value="<?= htmlspecialchars($learner['grade_level'] ?? '') ?>" disabled>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Current School Name</label>
                                    <input type="text" class="form-control rounded-3" value="<?= htmlspecialchars($learner['school_name'] ?? '') ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">School Address</label>
                                    <input type="text" class="form-control rounded-3" value="<?= htmlspecialchars($learner['school_address'] ?? '') ?>" placeholder="School address" disabled>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Mother Tongue Spoken (Learner) <span class="text-danger">*</span></label>
                                    <input type="text" name="mother_tongue" class="form-control rounded-3" placeholder="e.g. Filipino, English" value="<?= htmlspecialchars($_POST['mother_tongue'] ?? $sf_json['mother_tongue'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Home Address <span class="text-danger">*</span></label>
                                    <input type="text" name="home_address" class="form-control rounded-3" placeholder="House no., street, barangay, city" value="<?= htmlspecialchars($_POST['home_address'] ?? $sf_json['home_address'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Learner's Phone No.</label>
                                    <input type="text" name="learner_phone" class="form-control rounded-3" placeholder="Contact number" value="<?= htmlspecialchars($_POST['learner_phone'] ?? $sf_json['learner_phone'] ?? '') ?>">
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-people me-1"></i> Parent/Guardian Information</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Parent/Guardian Name <span class="text-danger">*</span></label>
                                    <input type="text" name="guardian_name" class="form-control rounded-3" placeholder="Full name" value="<?= htmlspecialchars($_POST['guardian_name'] ?? $sf_json['guardian_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Workplace</label>
                                    <input type="text" name="guardian_workplace" class="form-control rounded-3" placeholder="Company/office" value="<?= htmlspecialchars($_POST['guardian_workplace'] ?? $sf_json['guardian_workplace'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Email</label>
                                    <input type="email" name="guardian_email" class="form-control rounded-3" placeholder="e.g. demo@example.invalid" value="<?= htmlspecialchars($_POST['guardian_email'] ?? $sf_json['guardian_email'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="row g-3 mt-2">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Landline</label>
                                    <input type="text" name="guardian_landline" class="form-control rounded-3" placeholder="Landline" value="<?= htmlspecialchars($_POST['guardian_landline'] ?? $sf_json['guardian_landline'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Mobile</label>
                                    <input type="text" name="guardian_mobile" class="form-control rounded-3" placeholder="Mobile no." value="<?= htmlspecialchars($_POST['guardian_mobile'] ?? $sf_json['guardian_mobile'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Mother Tongue (Parent/Guardian)</label>
                                    <input type="text" name="guardian_mother_tongue" class="form-control rounded-3" placeholder="e.g. Filipino" value="<?= htmlspecialchars($_POST['guardian_mother_tongue'] ?? $sf_json['guardian_mother_tongue'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Interpreter / Accommodations</label>
                                    <input type="text" name="interpreter_needed" class="form-control rounded-3" placeholder="e.g. Sign language interpreter" value="<?= htmlspecialchars($_POST['interpreter_needed'] ?? $iep['interpreter_needed'] ?? $learner['interpreter_needed'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="row g-3 mt-2">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Medical Diagnosis</label>
                                    <div class="d-flex gap-3 align-items-center">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="medical_diagnosis" id="md_yes" value="1" <?= (($_POST['medical_diagnosis'] ?? $iep['medical_diagnosis'] ?? $default_med_diag) == '1') ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="md_yes">Yes</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="medical_diagnosis" id="md_no" value="0" <?= (($_POST['medical_diagnosis'] ?? $iep['medical_diagnosis'] ?? $default_med_diag) == '0') ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="md_no">No</label>
                                        </div>
                                        <input type="text" name="medical_diagnosis_specify" class="form-control form-control-sm rounded-3 flex-grow-1" placeholder="If yes, specify" value="<?= htmlspecialchars($_POST['medical_diagnosis_specify'] ?? $iep['medical_diagnosis_specify'] ?? ($is_new_form ? $diagnosed_str : '')) ?>">
                                    </div>
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Areas of Difficulty <span class="text-danger">*</span></h6>
                            <div class="row row-cols-2 row-cols-md-3 g-2 mb-3">
                                <?php foreach ($difficulty_labels as $key => $label): ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input class="form-check-input difficulty-check" type="checkbox" name="difficulties[]" value="<?= $key ?>" id="diff_<?= $key ?>"
                                            <?= (in_array($key, ($_POST['difficulties'] ?? [])) || ($iep && $iep[$key]) || ($is_new_form && isset($mapped_difficulties[$key]))) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="diff_<?= $key ?>"><?= $label ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input class="form-check-input difficulty-check" type="checkbox" name="difficulties[]" value="diff_others_check" id="diff_others_check"
                                            <?= (in_array('diff_others_check', ($_POST['difficulties'] ?? [])) || ($iep && $iep['diff_others']) || ($is_new_form && isset($mapped_difficulties['diff_others_check']))) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="diff_others_check">Others (specify)</label>
                                    </div>
                                    <input type="text" name="diff_others_text" class="form-control form-control-sm rounded-3 mt-1" placeholder="Specify other difficulties" value="<?= htmlspecialchars($_POST['diff_others_text'] ?? $iep['diff_others'] ?? ($is_new_form ? ($mapped_difficulties['diff_others_text'] ?? '') : '')) ?>">
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calendar-event me-1"></i> Meeting Information</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Date of Meeting <span class="text-danger">*</span></label>
                                    <input type="date" name="meeting_date" class="form-control rounded-3" value="<?= $_POST['meeting_date'] ?? $iep['meeting_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Date of Last IEP <span class="text-danger">*</span></label>
                                    <input type="date" name="date_of_last_iep" class="form-control rounded-3" value="<?= $_POST['date_of_last_iep'] ?? $iep['date_of_last_iep'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">IEP Review Date <span class="text-danger">*</span></label>
                                    <input type="date" name="iep_review_date" class="form-control rounded-3" value="<?= $_POST['iep_review_date'] ?? $iep['iep_review_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Purpose of Meeting <span class="text-danger">*</span></label>
                                    <select name="purpose_of_meeting" class="form-select rounded-3">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($purpose_options as $opt): ?>
                                        <option value="<?= $opt ?>" <?= (($_POST['purpose_of_meeting'] ?? $iep['purpose_of_meeting'] ?? '') === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Comments</label>
                                    <textarea name="meeting_comments" class="form-control rounded-3" rows="2" placeholder="Additional notes about the meeting"><?= htmlspecialchars($_POST['meeting_comments'] ?? $iep['meeting_comments'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-people-fill me-1"></i> IEP Team Members in Attendance</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-bordered table-sm align-middle" id="teamTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:35%">Role</th>
                                            <th style="width:50%">Name</th>
                                            <th style="width:15%" class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($team_members)): ?>
                                        <tr>
                                            <td>
                                                <select name="team_role[]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Role --</option>
                                                    <?php foreach ($team_roles as $tr): ?>
                                                    <option value="<?= $tr ?>"><?= $tr ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="text" name="team_name[]" class="form-control form-control-sm rounded-2" placeholder="Full name"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($team_members as $tm): ?>
                                        <tr>
                                            <td>
                                                <select name="team_role[]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Role --</option>
                                                    <?php foreach ($team_roles as $tr): ?>
                                                    <option value="<?= $tr ?>" <?= ($tm['role'] ?? '') === $tr ? 'selected' : '' ?>><?= $tr ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="text" name="team_name[]" class="form-control form-control-sm rounded-2" placeholder="Full name" value="<?= htmlspecialchars($tm['name'] ?? '') ?>"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addTeamRow()"><i class="bi bi-plus-lg"></i> Add Team Member</button>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-pen me-1"></i> Signatures &amp; Rights Notification</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Learner Signature Date <span class="text-danger">*</span></label>
                                    <input type="date" name="learner_signature_date" class="form-control rounded-3" value="<?= $_POST['learner_signature_date'] ?? $iep['learner_signature_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Parent/Guardian Signature Date <span class="text-danger">*</span></label>
                                    <input type="date" name="parent_signature_date" class="form-control rounded-3" value="<?= $_POST['parent_signature_date'] ?? $iep['parent_signature_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-4 d-flex align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="rights_notified" id="rights_notified" value="1" <?= (($_POST['rights_notified'] ?? $iep['rights_notified'] ?? '') == '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="rights_notified">Not Applicable / Learner has been informed of rights (at least one year before age 18)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(2)">Next: Present Levels <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- ==================== SECTION 2: Present Levels & Special Factors ==================== -->
                    <div class="tab-pane fade" id="sec2" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-clipboard-data me-2"></i>Section B-II — Present Levels of Academic Achievement and Functional Performance</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <label class="form-label fw-bold small">(a) Results of initial or most recent evaluation <span class="text-danger">*</span></label>
                                    <textarea name="present_eval_results" class="form-control rounded-3" rows="3"><?= htmlspecialchars($_POST['present_eval_results'] ?? $iep['present_eval_results'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">(b) Description of academic/developmental/functional strengths <span class="text-danger">*</span></label>
                                    <textarea name="academic_strengths" class="form-control rounded-3" rows="3"><?= htmlspecialchars($_POST['academic_strengths'] ?? $iep['academic_strengths'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">(c) Description of academic/developmental/functional needs <span class="text-danger">*</span></label>
                                    <textarea name="academic_needs" class="form-control rounded-3" rows="3"><?= htmlspecialchars($_POST['academic_needs'] ?? $iep['academic_needs'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">(d) Parental concerns regarding child's education <span class="text-danger">*</span></label>
                                    <textarea name="parental_concerns" class="form-control rounded-3" rows="3"><?= htmlspecialchars($_POST['parental_concerns'] ?? $iep['parental_concerns'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">(e) Impact of disability on involvement and progress in the general education curriculum <span class="text-danger">*</span></label>
                                    <textarea name="disability_impact" class="form-control rounded-3" rows="3"><?= htmlspecialchars($_POST['disability_impact'] ?? $iep['disability_impact'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <h5 class="fw-bold text-primary mb-3 mt-4 border-bottom pb-2"><i class="bi bi-gear-wide me-2"></i>Consideration of Special Factors</h5>
                            <p class="small text-muted mb-3">For each item, indicate Yes/No and provide a description if applicable.</p>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="specialFactorsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:5%">#</th>
                                            <th style="width:40%">Special Factor</th>
                                            <th style="width:8%">Yes/No</th>
                                            <th style="width:42%">Description / Notes</th>
                                            <th style="width:5%">Sub-option</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sf_idx = 0; foreach ($special_factor_keys as $sfk => $sfl): $sf_idx++; ?>
                                        <tr>
                                            <td class="text-center small text-muted"><?= $sf_idx ?></td>
                                            <td>
                                                <label class="fw-bold small mb-0"><?= $sfl ?></label>
                                                <?php if ($sfk === 'sf_behavior'): ?>
                                                <div class="small text-muted">(consider Behavior Intervention Plan)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <select name="sf[<?= $sfk ?>][yesno]" class="form-select form-select-sm rounded-2 sf-yesno" style="min-width:70px">
                                                    <option value="0" <?= (($sf_json[$sfk]['yesno'] ?? '0') == '0') ? 'selected' : '' ?>>No</option>
                                                    <option value="1" <?= (($sf_json[$sfk]['yesno'] ?? '0') == '1') ? 'selected' : '' ?>>Yes</option>
                                                </select>
                                            </td>
                                            <td>
                                                <textarea name="sf[<?= $sfk ?>][notes]" class="form-control form-control-sm rounded-2" rows="1" placeholder="Describe..."><?= htmlspecialchars($sf_json[$sfk]['notes'] ?? '') ?></textarea>
                                            </td>
                                            <td>
                                                <?php if ($sfk === 'sf_behavior'): ?>
                                                <select name="sf[<?= $sfk ?>][bip]" class="form-select form-select-sm rounded-2">
                                                    <option value="">BIP?</option>
                                                    <option value="1" <?= (($sf_json[$sfk]['bip'] ?? '') == '1') ? 'selected' : '' ?>>Yes</option>
                                                    <option value="0" <?= (($sf_json[$sfk]['bip'] ?? '') === '0') ? 'selected' : '' ?>>No</option>
                                                </select>
                                                <?php elseif ($sfk === 'sf_alt_format'): ?>
                                                <div class="d-flex flex-column gap-1 small">
                                                    <label class="form-check mb-0"><input type="checkbox" name="sf[<?= $sfk ?>][braille]" value="1" class="form-check-input" <?= ($sf_json[$sfk]['braille'] ?? '') ? 'checked' : '' ?>> Braille</label>
                                                    <label class="form-check mb-0"><input type="checkbox" name="sf[<?= $sfk ?>][large_type]" value="1" class="form-check-input" <?= ($sf_json[$sfk]['large_type'] ?? '') ? 'checked' : '' ?>> Large type</label>
                                                    <label class="form-check mb-0"><input type="checkbox" name="sf[<?= $sfk ?>][auditory]" value="1" class="form-check-input" <?= ($sf_json[$sfk]['auditory'] ?? '') ? 'checked' : '' ?>> Auditory</label>
                                                    <label class="form-check mb-0"><input type="checkbox" name="sf[<?= $sfk ?>][electronic]" value="1" class="form-check-input" <?= ($sf_json[$sfk]['electronic'] ?? '') ? 'checked' : '' ?>> Electronic text</label>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="goToStep(1)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(3)">Next: Barriers &amp; Supports <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 3: Barriers, Facilitators & Accommodations ==================== -->
                    <div class="tab-pane fade" id="sec3" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-barrier me-2"></i>Section B — Difficulties, Barriers and Enabling Supports</h5>
                            <p class="small text-muted">For each difficulty area identified in Section 1, describe the environmental barriers, facilitators, and accommodations needed. Use the ICF qualifier codes below.</p>

                            <!-- ICF Legend -->
                            <div class="alert alert-light border rounded-3 p-3 mb-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <strong class="small text-uppercase text-danger d-block mb-2">Environmental Barriers</strong>
                                        <ul class="list-group list-group-flush small mb-0">
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">0</span> No barrier</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.1</span> Mild</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.2</span> Moderate</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.3</span> Severe</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.4</span> Complete</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.8</span> Not specified</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">.9</span> Not applicable</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <strong class="small text-uppercase text-success d-block mb-2">Environmental Facilitators</strong>
                                        <ul class="list-group list-group-flush small mb-0">
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+0</span> No facilitator</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+1</span> Mild</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+2</span> Moderate</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+3</span> Substantial</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+4</span> Complete</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+8</span> Not specified</li>
                                            <li class="list-group-item border-0 py-1 px-0"><span class="badge bg-secondary bg-opacity-10 text-dark me-1">+9</span> Not applicable</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle" id="barriersTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:16%">Difficulty <span class="text-danger">*</span></th>
                                            <th style="width:20%">Environmental Barriers <i class="bi bi-info-circle text-muted small" title="Describe each factor restricting participation"></i></th>
                                            <th style="width:12%">Barrier Qualifier</th>
                                            <th style="width:20%">Environmental Facilitators <i class="bi bi-info-circle text-muted small" title="Describe each factor enabling participation"></i></th>
                                            <th style="width:12%">Facilitator Qualifier</th>
                                            <th style="width:20%">Accommodations Required <i class="bi bi-info-circle text-muted small" title="Items, staff resources, infrastructure changes"></i></th>
                                            <th style="width:5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($barriers)): ?>
                                        <tr class="barrier-row">
                                            <td>
                                                <select name="barriers[0][difficulty]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($difficulty_labels as $dk => $dl): ?>
                                                    <option value="<?= $dk ?>"><?= $dl ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="diff_others_check">Others</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][barrier_text]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe environmental barriers..."></textarea></td>
                                            <td>
                                                <select name="barriers[0][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="0">0 No barrier</option><option value=".1">.1 Mild</option><option value=".2">.2 Moderate</option>
                                                    <option value=".3">.3 Severe</option><option value=".4">.4 Complete</option><option value=".8">.8 Not specified</option><option value=".9">.9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][facilitator]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe environmental facilitators..."></textarea></td>
                                            <td>
                                                <select name="barriers[0][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="+0">+0 No facilitator</option><option value="+1">+1 Mild</option><option value="+2">+2 Moderate</option>
                                                    <option value="+3">+3 Substantial</option><option value="+4">+4 Complete</option><option value="+8">+8 Not specified</option><option value="+9">+9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][accommodations]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Items, staff, infrastructure..."></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($barriers as $bi => $b): ?>
                                        <tr class="barrier-row">
                                            <td>
                                                <select name="barriers[<?= $bi ?>][difficulty]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($difficulty_labels as $dk => $dl): ?>
                                                    <option value="<?= $dk ?>" <?= ($b['difficulty'] === $dk) ? 'selected' : '' ?>><?= $dl ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="diff_others_check" <?= ($b['difficulty'] === 'diff_others_check') ? 'selected' : '' ?>>Others</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][barrier_text]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['interest'] ?? '') ?></textarea></td>
                                            <td>
                                                <select name="barriers[<?= $bi ?>][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="0" <?= ($b['barrier_qualifier'] === '0') ? 'selected' : '' ?>>0 No barrier</option>
                                                    <option value=".1" <?= ($b['barrier_qualifier'] === '.1') ? 'selected' : '' ?>>.1 Mild</option>
                                                    <option value=".2" <?= ($b['barrier_qualifier'] === '.2') ? 'selected' : '' ?>>.2 Moderate</option>
                                                    <option value=".3" <?= ($b['barrier_qualifier'] === '.3') ? 'selected' : '' ?>>.3 Severe</option>
                                                    <option value=".4" <?= ($b['barrier_qualifier'] === '.4') ? 'selected' : '' ?>>.4 Complete</option>
                                                    <option value=".8" <?= ($b['barrier_qualifier'] === '.8') ? 'selected' : '' ?>>.8 Not specified</option>
                                                    <option value=".9" <?= ($b['barrier_qualifier'] === '.9') ? 'selected' : '' ?>>.9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][facilitator]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['facilitator'] ?? '') ?></textarea></td>
                                            <td>
                                                <select name="barriers[<?= $bi ?>][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="+0" <?= ($b['facilitator_qualifier'] === '+0') ? 'selected' : '' ?>>+0 No facilitator</option>
                                                    <option value="+1" <?= ($b['facilitator_qualifier'] === '+1') ? 'selected' : '' ?>>+1 Mild</option>
                                                    <option value="+2" <?= ($b['facilitator_qualifier'] === '+2') ? 'selected' : '' ?>>+2 Moderate</option>
                                                    <option value="+3" <?= ($b['facilitator_qualifier'] === '+3') ? 'selected' : '' ?>>+3 Substantial</option>
                                                    <option value="+4" <?= ($b['facilitator_qualifier'] === '+4') ? 'selected' : '' ?>>+4 Complete</option>
                                                    <option value="+8" <?= ($b['facilitator_qualifier'] === '+8') ? 'selected' : '' ?>>+8 Not specified</option>
                                                    <option value="+9" <?= ($b['facilitator_qualifier'] === '+9') ? 'selected' : '' ?>>+9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][accommodations]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['accommodations'] ?? '') ?></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addBarrierRow()"><i class="bi bi-plus-lg"></i> Add Barrier Row</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(2)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(4)">Next: Learner Goals <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>

                <!-- ==================== SECTION 4: Learner Goals ==================== -->
                <div class="tab-pane fade" id="sec4" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-bullseye text-success me-2"></i>Section C: Learner Goals (SMART)</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="alert alert-info border rounded-3 p-3 mb-3 small">
                                <i class="bi bi-info-circle me-1"></i> To support identification of learner goals, confirm: What opportunities are available at the school? What are the student interest areas? What disability-specific skills does the learner need to develop?<br>
                                <strong>SMART:</strong> Strategic, Measurable, Achievable, Realistic, Time-bound
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle" id="goalsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:12%">Interest <span class="text-danger">*</span></th>
                                            <th style="width:18%">Goal (SMART) <span class="text-danger">*</span></th>
                                            <th style="width:18%">Interventions</th>
                                            <th style="width:10%">Timeline</th>
                                            <th style="width:12%">Individuals Responsible</th>
                                            <th style="width:12%">Remarks</th>
                                            <th style="width:12%">Progress / Next Steps</th>
                                            <th style="width:5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($goals)): ?>
                                        <tr class="goal-row">
                                            <td><input type="text" name="goals[0][interest]" class="form-control form-control-sm rounded-2" placeholder="Interest area"></td>
                                            <td><textarea name="goals[0][goal]" class="form-control form-control-sm rounded-2" rows="2" placeholder="SMART goal"></textarea></td>
                                            <td><textarea name="goals[0][interventions]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Interventions"></textarea></td>
                                            <td>
                                                <select name="goals[0][timeline]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Term --</option>
                                                    <option value="1st Term">1st Term</option>
                                                    <option value="2nd Term">2nd Term</option>
                                                    <option value="3rd Term">3rd Term</option>
                                                    <option value="Whole Term">Whole Term</option>
                                                </select>
                                            </td>
                                            <td><input type="text" name="goals[0][responsible]" class="form-control form-control-sm rounded-2" placeholder="Who is responsible?"></td>
                                            <td>
                                                <select name="goals[0][remarks]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Rating --</option>
                                                    <option value="Advancing (A)">Advancing (A)</option>
                                                    <option value="Benchmarking (B)">Benchmarking (B)</option>
                                                    <option value="Connecting (C)">Connecting (C)</option>
                                                    <option value="Developing (D)">Developing (D)</option>
                                                    <option value="Emerging (E)">Emerging (E)</option>
                                                </select>
                                            </td>
                                            <td><input type="text" name="goals[0][progress]" class="form-control form-control-sm rounded-2" placeholder="Progress"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($goals as $gi => $g): ?>
                                        <tr class="goal-row">
                                            <td><input type="text" name="goals[<?= $gi ?>][interest]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['interest'] ?? '') ?>"></td>
                                            <td><textarea name="goals[<?= $gi ?>][goal]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($g['goal'] ?? '') ?></textarea></td>
                                            <td><textarea name="goals[<?= $gi ?>][interventions]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($g['interventions'] ?? '') ?></textarea></td>
                                            <td>
                                                <select name="goals[<?= $gi ?>][timeline]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Term --</option>
                                                    <option value="1st Term" <?= ($g['timeline'] ?? '') === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                                                    <option value="2nd Term" <?= ($g['timeline'] ?? '') === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                                                    <option value="3rd Term" <?= ($g['timeline'] ?? '') === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
                                                    <option value="Whole Term" <?= ($g['timeline'] ?? '') === 'Whole Term' ? 'selected' : '' ?>>Whole Term</option>
                                                </select>
                                            </td>
                                            <td><input type="text" name="goals[<?= $gi ?>][responsible]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['responsible'] ?? '') ?>"></td>
                                            <td>
                                                <select name="goals[<?= $gi ?>][remarks]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Rating --</option>
                                                    <option value="Advancing (A)" <?= ($g['remarks'] ?? '') === 'Advancing (A)' ? 'selected' : '' ?>>Advancing (A)</option>
                                                    <option value="Benchmarking (B)" <?= ($g['remarks'] ?? '') === 'Benchmarking (B)' ? 'selected' : '' ?>>Benchmarking (B)</option>
                                                    <option value="Connecting (C)" <?= ($g['remarks'] ?? '') === 'Connecting (C)' ? 'selected' : '' ?>>Connecting (C)</option>
                                                    <option value="Developing (D)" <?= ($g['remarks'] ?? '') === 'Developing (D)' ? 'selected' : '' ?>>Developing (D)</option>
                                                    <option value="Emerging (E)" <?= ($g['remarks'] ?? '') === 'Emerging (E)' ? 'selected' : '' ?>>Emerging (E)</option>
                                                </select>
                                            </td>
                                            <td><input type="text" name="goals[<?= $gi ?>][progress]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['progress'] ?? '') ?>"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addGoalRow()"><i class="bi bi-plus-lg"></i> Add Goal Row</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(3)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(5)">Next: Transition <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SECTION 5: Transition ==================== -->
                <div class="tab-pane fade" id="sec5" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-box-arrow-right text-success me-2"></i>Section D: Learner Transition</h5>
                        </div>
                        <div class="sned-card-body">

                            <h6 class="fw-bold text-secondary mb-3">Part A — Transition Point Checkboxes</h6>
                            <p class="small text-muted">Select the transition point that applies to this learner.</p>
                            <div class="row g-2 mb-4">
                                <?php
                                $trans_checkboxes = [
                                    'transition_home_to_school' => 'Transition from home to school',
                                    'transition_school_to_life' => 'Transition from school to functional life',
                                    'transition_sped_to_inclusion' => 'Transition from SPED Center/SPED Classes to Inclusion Classes',
                                    'transition_grade_to_grade' => 'Transition from one grade level to the next grade level',
                                    'transition_school_to_work' => 'Transition from school to employment or entrepreneurship',
                                    'transition_class_to_class' => 'Transition from one class to another in the same grade level',
                                ];
                                foreach ($trans_checkboxes as $tck => $tcl):
                                ?>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="transition_point" value="<?= $tck ?>" id="tp_<?= $tck ?>"
                                            <?= (($_POST['transition_point'] ?? $selected_transition_point) === $tck) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="tp_<?= $tck ?>"><?= $tcl ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <hr>
                            <h6 class="fw-bold text-secondary mb-3">Part B — Transition Plan <span class="small text-muted fw-normal">(for learners exiting school and transitioning to work)</span></h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle" id="transitionsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:18%">Interest <span class="text-danger">*</span></th>
                                            <th style="width:20%">Work Opportunities</th>
                                            <th style="width:25%">Interventions / Transition Skills</th>
                                            <th style="width:15%">Individuals Responsible</th>
                                            <th style="width:15%">Remarks</th>
                                            <th style="width:5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($transitions)): ?>
                                        <tr class="transition-row">
                                            <td><input type="text" name="transitions[0][interest]" class="form-control form-control-sm rounded-2" placeholder="Interest area"></td>
                                            <td><input type="text" name="transitions[0][work_opportunities]" class="form-control form-control-sm rounded-2" placeholder="Work opportunities"></td>
                                            <td><textarea name="transitions[0][interventions]" class="form-control form-control-sm rounded-2" rows="1" placeholder="Interventions/transition skills"></textarea></td>
                                            <td><input type="text" name="transitions[0][responsible]" class="form-control form-control-sm rounded-2" placeholder="Who?"></td>
                                            <td><input type="text" name="transitions[0][remarks]" class="form-control form-control-sm rounded-2" placeholder="Remarks"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($transitions as $ti => $t): ?>
                                        <tr class="transition-row">
                                            <td><input type="text" name="transitions[<?= $ti ?>][interest]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($t['interest'] ?? '') ?>"></td>
                                            <td><input type="text" name="transitions[<?= $ti ?>][work_opportunities]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($t['work_opportunities'] ?? '') ?>"></td>
                                            <td><textarea name="transitions[<?= $ti ?>][interventions]" class="form-control form-control-sm rounded-2" rows="1"><?= htmlspecialchars($t['interventions'] ?? '') ?></textarea></td>
                                            <td><input type="text" name="transitions[<?= $ti ?>][responsible]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($t['responsible'] ?? '') ?>"></td>
                                            <td><input type="text" name="transitions[<?= $ti ?>][remarks]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($t['remarks'] ?? '') ?>"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addTransitionRow()"><i class="bi bi-plus-lg"></i> Add Transition Row</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(4)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Sticky Bottom Bar -->
                <div class="form-sticky-bottom mt-4">
                    <div class="d-flex align-items-center gap-2">
                        <span id="saveFeedback" class="small text-muted me-2" style="display:none;"><i class="bi bi-check-circle-fill text-success me-1"></i> Saved successfully</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" id="stickyPrevBtn" onclick="goToStep(currentStep - 1)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                        <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" id="stickyNextBtn" onclick="goToStep(currentStep + 1)">Next <i class="bi bi-arrow-right ms-1"></i></button>
                        <button type="submit" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm d-none" id="saveBtn"><i class="bi bi-cloud-arrow-up me-1"></i> Save IEP Record</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        let currentStep = 1;
        const totalSteps = 5;
        let barrierIndex = <?= max((int)count($barriers), 1) ?>;
        let goalIndex = <?= max((int)count($goals), 1) ?>;
        let transitionIndex = <?= max(count($transitions), 1) ?>;
        let teamIndex = <?= count($team_members) ?>;

        function goToStep(step) {
            if (step < 1 || step > totalSteps) return;

            // Validation when leaving Section 1
            if (currentStep === 1 && step > 1) {
                const meetingDate = document.querySelector('[name="meeting_date"]').value;
                const lastIepDate = document.querySelector('[name="date_of_last_iep"]').value;
                if (lastIepDate && meetingDate && meetingDate < lastIepDate) {
                    if (!confirm('The Meeting Date is before the Date of Last IEP. This may be invalid. Continue anyway?')) {
                        return;
                    }
                }
            }

            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));
            document.getElementById('sec' + step).classList.add('show', 'active');
            document.querySelectorAll('.step-progress-item').forEach((el, i) => {
                el.classList.remove('active', 'completed');
                if (i + 1 === step) el.classList.add('active');
                else if (i + 1 < step) el.classList.add('completed');
            });
            currentStep = step;

            // Update sticky bar buttons
            const prevBtn = document.getElementById('stickyPrevBtn');
            const nextBtn = document.getElementById('stickyNextBtn');
            const saveBtn = document.getElementById('saveBtn');
            prevBtn.classList.toggle('d-none', step === 1);
            nextBtn.classList.toggle('d-none', step === totalSteps);
            saveBtn.classList.toggle('d-none', step !== totalSteps);
        }

        // Initialize sticky bar state on page load
        goToStep(1);

        function addTeamRow() {
            const tbody = document.querySelector('#teamTable tbody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <select name="team_role[]" class="form-select form-select-sm rounded-2">
                        <option value="">-- Select Role --</option>
                        <?php foreach ($team_roles as $tr): ?>
                        <option value="<?= $tr ?>"><?= $tr ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="team_name[]" class="form-control form-control-sm rounded-2" placeholder="Full name"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        function addBarrierRow() {
            const tbody = document.querySelector('#barriersTable tbody');
            const idx = barrierIndex++;
            const tr = document.createElement('tr');
            tr.className = 'barrier-row';
            tr.innerHTML = `
                <td>
                    <select name="barriers[${idx}][difficulty]" class="form-select form-select-sm rounded-2">
                        <option value="">-- Select --</option>
                        <?php foreach ($difficulty_labels as $dk => $dl): ?>
                        <option value="<?= $dk ?>"><?= $dl ?></option>
                        <?php endforeach; ?>
                        <option value="diff_others_check">Others</option>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][barrier_text]" class="form-control form-control-sm rounded-2" rows="2"></textarea></td>
                <td>
                    <select name="barriers[${idx}][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                        <option value="">--</option>
                        <option value="0">0 No barrier</option><option value=".1">.1 Mild</option><option value=".2">.2 Moderate</option>
                        <option value=".3">.3 Severe</option><option value=".4">.4 Complete</option><option value=".8">.8 Not specified</option><option value=".9">.9 Not applicable</option>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][facilitator]" class="form-control form-control-sm rounded-2" rows="2"></textarea></td>
                <td>
                    <select name="barriers[${idx}][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                        <option value="">--</option>
                        <option value="+0">+0 No facilitator</option><option value="+1">+1 Mild</option><option value="+2">+2 Moderate</option>
                        <option value="+3">+3 Substantial</option><option value="+4">+4 Complete</option><option value="+8">+8 Not specified</option><option value="+9">+9 Not applicable</option>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][accommodations]" class="form-control form-control-sm rounded-2" rows="2"></textarea></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        function addGoalRow() {
            const tbody = document.querySelector('#goalsTable tbody');
            const idx = goalIndex++;
            const tr = document.createElement('tr');
            tr.className = 'goal-row';
            tr.innerHTML = `
                <td><input type="text" name="goals[${idx}][interest]" class="form-control form-control-sm rounded-2" placeholder="Interest area"></td>
                <td><textarea name="goals[${idx}][goal]" class="form-control form-control-sm rounded-2" rows="2" placeholder="SMART goal"></textarea></td>
                <td><textarea name="goals[${idx}][interventions]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Interventions"></textarea></td>
                <td>
                    <select name="goals[${idx}][timeline]" class="form-select form-select-sm rounded-2">
                        <option value="">-- Select Term --</option>
                        <option value="1st Term">1st Term</option>
                        <option value="2nd Term">2nd Term</option>
                        <option value="3rd Term">3rd Term</option>
                        <option value="Whole Term">Whole Term</option>
                    </select>
                </td>
                <td><input type="text" name="goals[${idx}][responsible]" class="form-control form-control-sm rounded-2" placeholder="Who?"></td>
                <td>
                    <select name="goals[${idx}][remarks]" class="form-select form-select-sm rounded-2">
                        <option value="">-- Select Rating --</option>
                        <option value="Advancing (A)">Advancing (A)</option>
                        <option value="Benchmarking (B)">Benchmarking (B)</option>
                        <option value="Connecting (C)">Connecting (C)</option>
                        <option value="Developing (D)">Developing (D)</option>
                        <option value="Emerging (E)">Emerging (E)</option>
                    </select>
                </td>
                <td><input type="text" name="goals[${idx}][progress]" class="form-control form-control-sm rounded-2" placeholder="Progress"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        function addTransitionRow() {
            const tbody = document.querySelector('#transitionsTable tbody');
            const idx = transitionIndex++;
            const tr = document.createElement('tr');
            tr.className = 'transition-row';
            tr.innerHTML = `
                <td><input type="text" name="transitions[${idx}][interest]" class="form-control form-control-sm rounded-2" placeholder="Interest area"></td>
                <td><input type="text" name="transitions[${idx}][work_opportunities]" class="form-control form-control-sm rounded-2" placeholder="Work opportunities"></td>
                <td><textarea name="transitions[${idx}][interventions]" class="form-control form-control-sm rounded-2" rows="1" placeholder="Interventions/transition skills"></textarea></td>
                <td><input type="text" name="transitions[${idx}][responsible]" class="form-control form-control-sm rounded-2" placeholder="Who?"></td>
                <td><input type="text" name="transitions[${idx}][remarks]" class="form-control form-control-sm rounded-2" placeholder="Remarks"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        // Client-side validation before save
        document.getElementById('iepForm').addEventListener('submit', function(e) {
            const checked = document.querySelectorAll('.difficulty-check:checked');
            if (checked.length === 0) {
                if (!confirm('No areas of difficulty are selected. Are you sure you want to continue?')) {
                    e.preventDefault();
                    return;
                }
            }

            // Validate goals: highlight incomplete required fields and block save
            const goalRows = document.querySelectorAll('.goal-row');
            let firstIncomplete = null;
            const errors = [];
            goalRows.forEach((row, idx) => {
                row.classList.remove('table-danger');
                const interestInput = row.querySelector('input[name*="[interest]"]');
                const goalTextarea = row.querySelector('textarea[name*="[goal]"]');
                const hasInterest = interestInput && interestInput.value.trim() !== '';
                const hasGoal = goalTextarea && goalTextarea.value.trim() !== '';
                const hasAny = hasInterest || hasGoal;

                if (hasAny) {
                    const missing = [];
                    if (!hasInterest) missing.push('Interest');
                    if (!hasGoal) missing.push('Goal (SMART)');
                    if (missing.length > 0) {
                        row.classList.add('table-danger');
                        errors.push('Row ' + (idx + 1) + ': Missing ' + missing.join(', '));
                        if (!firstIncomplete) {
                            firstIncomplete = !hasInterest ? interestInput : goalTextarea;
                        }
                    }
                }
            });
            if (errors.length > 0) {
                e.preventDefault();
                const detail = errors.length + ' incomplete goal row' + (errors.length > 1 ? 's' : '') + ':\n\n' + errors.join('\n') + '\n\nPlease fill in all required fields (Interest and Goal) before saving.';
                showSystemNotice(detail, 'Goals Incomplete', 'warning');
                if (firstIncomplete) {
                    document.getElementById('sec4').classList.add('show', 'active');
                    document.querySelectorAll('.tab-pane').forEach(p => { if (p.id !== 'sec4') p.classList.remove('show', 'active'); });
                    document.querySelectorAll('.step-progress-item').forEach((el, i) => {
                        el.classList.remove('active', 'completed');
                        if (i + 1 === 4) el.classList.add('active');
                        else if (i + 1 < 4) el.classList.add('completed');
                    });
                    currentStep = 4;
                    setTimeout(() => { firstIncomplete.focus(); firstIncomplete.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 300);
                }
                return;
            }

            document.getElementById('saveBtn').disabled = true;
            document.getElementById('saveBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        });

        // Show success feedback from URL param
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('saved') === '1') {
            const fb = document.getElementById('saveFeedback');
            if (fb) { fb.style.display = 'block'; setTimeout(() => { fb.style.display = 'none'; }, 4000); }
        }

        // Stepper Navigation click handler
        document.querySelectorAll('.step-progress-item').forEach(el => {
            el.addEventListener('click', () => {
                const step = parseInt(el.getAttribute('data-step'));
                goToStep(step);
            });
        });
    </script>
    <script>
        // --- Sidebar Collapsed/Expanded Handling ---
        const shell = document.querySelector('.app-shell');
        const toggleBtn = document.getElementById('sidebarToggle');
        const toggleText = document.getElementById('sidebarToggleText');

        if (shell && toggleBtn && toggleText) {
            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const isCollapsed = shell.classList.contains('sidebar-collapsed');
                if (isCollapsed) {
                    shell.classList.remove('sidebar-collapsed');
                    shell.classList.remove('sidebar-hidden');
                    toggleText.textContent = 'Hide menu';
                } else {
                    shell.classList.add('sidebar-collapsed');
                    shell.classList.add('sidebar-hidden');
                    toggleText.textContent = 'Show menu';
                }
            });
        }
    </script>
</body>
</html>
