<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    header("Location: main.php");
    exit();
}

$user_school_id = $_SESSION['school_id'] ?? null;
$lrn = $_GET['learner_id'] ?? '';
$school_year = $_GET['sy'] ?? '';
$quarter = $_GET['q'] ?? '';
$bir_id_param = $_GET['id'] ?? '';

if (empty($lrn)) {
    header("Location: main.php");
    exit();
}

// Fetch Learner details with guardian info
$stmt_learner = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.grade_level, e.placement_type, e.school_year, e.primary_diagnosis,
           e.assessment_mode, e.medical_results, s.school_name,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle, g.extension_name AS g_ext
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    LEFT JOIN guardians g ON l.guardian_id = g.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt_learner->bind_param("s", $lrn);
$stmt_learner->execute();
$learner = $stmt_learner->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

// Access gate for scoped roles
if (!check_teacher_learner_access($conn, $lrn, true)) {
    header("Location: 403.php");
    exit();
}

// Default school year
$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
if (empty($school_year)) {
    $school_year = $learner['school_year'] ?: $current_sy;
}

$error_msg = '';
$duplicate_msg = '';

// Check for duplicate redirect
if (isset($_GET['duplicate'])) {
    $duplicate_msg = 'A BIR already exists for this learner in this quarter. You are now editing the existing record.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }

    $bir_id = (int)($_POST['bir_id'] ?? 0);
    $date_of_observation = $_POST['date_of_observation'] ?? '';
    $teacher_name = trim($_POST['teacher_name'] ?? '');
    $quarter = $_POST['quarter'] ?? '';
    $parent_guardian_conforme = trim($_POST['parent_guardian_conforme'] ?? '');
    $baseline_data = trim($_POST['baseline_data'] ?? '');
    $difficulty_disability = trim($_POST['difficulty_disability'] ?? '');
    $with_medical_assessment = isset($_POST['with_medical_assessment']) ? 1 : 0;
    $medical_results = trim($_POST['medical_results'] ?? '');
    $educational_placement = $_POST['educational_placement'] ?? null;

    $antecedent_home = trim($_POST['antecedent_home'] ?? '');
    $antecedent_school = trim($_POST['antecedent_school'] ?? '');
    $antecedent_other = trim($_POST['antecedent_other'] ?? '');

    $observable_home = trim($_POST['observable_home'] ?? '');
    $observable_school = trim($_POST['observable_school'] ?? '');
    $observable_other = trim($_POST['observable_other'] ?? '');

    $consequence_home = trim($_POST['consequence_home'] ?? '');
    $consequence_school = trim($_POST['consequence_school'] ?? '');
    $consequence_other = trim($_POST['consequence_other'] ?? '');

    $interventions_done = trim($_POST['interventions_done'] ?? '');
    $proactive_strategies = trim($_POST['proactive_strategies'] ?? '');
    $reactive_strategies = trim($_POST['reactive_strategies'] ?? '');
    $targeted_behavior = trim($_POST['targeted_behavior'] ?? '');
    $specific_objectives = trim($_POST['specific_objectives'] ?? '');
    $basic_intervention = trim($_POST['basic_intervention'] ?? '');

    // Validate required fields, including official print sections that must not render blank.
    $required = [
        'quarter' => 'Quarter',
        'date_of_observation' => 'Date of observation',
        'teacher_name' => 'Teacher name',
        'educational_placement' => 'Educational placement',
        'antecedent_home' => 'Home antecedent',
        'antecedent_school' => 'School antecedent',
        'antecedent_other' => 'Other setting antecedent',
        'observable_home' => 'Home observable behavior',
        'observable_school' => 'School observable behavior',
        'observable_other' => 'Other setting observable behavior',
        'consequence_home' => 'Home consequence',
        'consequence_school' => 'School consequence',
        'consequence_other' => 'Other setting consequence',
        'targeted_behavior' => 'Targeted behavior',
        'specific_objectives' => 'Specific objectives',
        'proactive_strategies' => 'Proactive strategies',
        'reactive_strategies' => 'Reactive strategies',
    ];
    $missing = [];
    foreach ($required as $field => $label) {
        if (trim((string)($_POST[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        $error_msg = 'Required fields missing: ' . implode(', ', $missing);
    } elseif ($bir_id > 0) {
        // Check for duplicate (but exclude current record)
        $stmt_check = $conn->prepare("SELECT id FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? AND quarter = ? AND id != ?");
        $stmt_check->bind_param("sssi", $lrn, $school_year, $quarter, $bir_id);
        $stmt_check->execute();
        $existing = $stmt_check->get_result()->fetch_assoc();

        if ($existing) {
            header("Location: bir_form.php?id=" . $existing['id'] . "&duplicate=1&learner_id=" . urlencode($lrn) . "&sy=" . urlencode($school_year));
            exit();
        }

        $user_id = (int)$_SESSION['user_id'];
        $stmt_save = $conn->prepare("
            UPDATE behavior_intervention_reports SET
                quarter = ?,
                date_of_observation = ?,
                teacher_name = ?,
                parent_guardian_conforme = ?,
                baseline_data = ?,
                difficulty_disability = ?,
                with_medical_assessment = ?,
                medical_results = ?,
                educational_placement = ?,
                antecedent_home = ?,
                antecedent_school = ?,
                antecedent_other = ?,
                observable_home = ?,
                observable_school = ?,
                observable_other = ?,
                consequence_home = ?,
                consequence_school = ?,
                consequence_other = ?,
                interventions_done = ?,
                proactive_strategies = ?,
                reactive_strategies = ?,
                targeted_behavior = ?,
                specific_objectives = ?,
                basic_intervention = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $stmt_save->bind_param("ssssssssssssssssssssssssii",
            $quarter, $date_of_observation, $teacher_name, $parent_guardian_conforme,
            $baseline_data, $difficulty_disability, $with_medical_assessment, $medical_results,
            $educational_placement,
            $antecedent_home, $antecedent_school, $antecedent_other,
            $observable_home, $observable_school, $observable_other,
            $consequence_home, $consequence_school, $consequence_other,
            $interventions_done, $proactive_strategies, $reactive_strategies,
            $targeted_behavior, $specific_objectives, $basic_intervention,
            $user_id, $bir_id
        );

        if ($stmt_save->execute()) {
            log_transaction($conn, $_SESSION['user_id'], 'update', 'behavior_intervention_reports', $lrn, "Update BIR for LRN $lrn, SY $school_year, Quarter $quarter");

            header("Location: viewLearner.php?id=" . urlencode($lrn) . "&msg=" . urlencode("Behavior Intervention Report saved successfully."));
            exit();
        } else {
            $error_msg = "Error saving report: " . $stmt_save->error;
        }
    } else {
        // Check for duplicate before insert
        $stmt_check = $conn->prepare("SELECT id FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? AND quarter = ?");
        $stmt_check->bind_param("sss", $lrn, $school_year, $quarter);
        $stmt_check->execute();
        $existing = $stmt_check->get_result()->fetch_assoc();

        if ($existing) {
            header("Location: bir_form.php?id=" . $existing['id'] . "&duplicate=1&learner_id=" . urlencode($lrn) . "&sy=" . urlencode($school_year));
            exit();
        }

        $school_id = (int)$learner['school_id'];
        $user_id = (int)$_SESSION['user_id'];
        $stmt_save = $conn->prepare("
            INSERT INTO behavior_intervention_reports (
                lrn, school_year, quarter, school_id, date_of_observation, teacher_name,
                parent_guardian_conforme, baseline_data, difficulty_disability,
                with_medical_assessment, medical_results, educational_placement,
                antecedent_home, antecedent_school, antecedent_other,
                observable_home, observable_school, observable_other,
                consequence_home, consequence_school, consequence_other,
                interventions_done, proactive_strategies, reactive_strategies,
                targeted_behavior, specific_objectives, basic_intervention,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_save->bind_param("sssssssssisssssssssssssssssi",
            $lrn, $school_year, $quarter, $school_id, $date_of_observation, $teacher_name,
            $parent_guardian_conforme, $baseline_data, $difficulty_disability,
            $with_medical_assessment, $medical_results, $educational_placement,
            $antecedent_home, $antecedent_school, $antecedent_other,
            $observable_home, $observable_school, $observable_other,
            $consequence_home, $consequence_school, $consequence_other,
            $interventions_done, $proactive_strategies, $reactive_strategies,
            $targeted_behavior, $specific_objectives, $basic_intervention,
            $user_id
        );

        if ($stmt_save->execute()) {
            log_transaction($conn, $_SESSION['user_id'], 'create', 'behavior_intervention_reports', $lrn, "Create BIR for LRN $lrn, SY $school_year, Quarter $quarter");

            header("Location: viewLearner.php?id=" . urlencode($lrn) . "&msg=" . urlencode("Behavior Intervention Report saved successfully."));
            exit();
        } else {
            $error_msg = "Error saving report: " . $stmt_save->error;
        }
    }
}

// Fetch existing report
$bir = [];
if (!empty($bir_id_param)) {
    $stmt_bir = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE id = ?");
    $stmt_bir->bind_param("i", $bir_id_param);
} elseif (!empty($quarter)) {
    $stmt_bir = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? AND quarter = ?");
    $stmt_bir->bind_param("sss", $lrn, $school_year, $quarter);
} else {
    $stmt_bir = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? ORDER BY id DESC LIMIT 1");
    $stmt_bir->bind_param("ss", $lrn, $school_year);
}
$stmt_bir->execute();
$bir = $stmt_bir->get_result()->fetch_assoc() ?: [];

// Auto-fill quarter from observation date if not set
$default_quarter = $bir['quarter'] ?? '';
if (empty($default_quarter) && !empty($bir['date_of_observation'])) {
    $default_quarter = sned_bir_quarter_from_date($bir['date_of_observation']);
}

// Build parent conforme default from guardian data
$default_parent_conforme = $bir['parent_guardian_conforme'] ?? '';
if (empty($default_parent_conforme) && (!empty($learner['g_first']) || !empty($learner['g_last']))) {
    $parts = array_filter([
        $learner['g_last'] ?? '',
        $learner['g_first'] ?? '',
        $learner['g_middle'] ?? '',
        $learner['g_ext'] ?? '',
    ]);
    if (!empty($parts)) {
        $last = array_shift($parts);
        $default_parent_conforme = trim($last . ', ' . implode(' ', $parts));
    }
}

$disability_rows = sned_get_enrollment_disabilities($conn, isset($learner['enrollment_id']) ? (int)$learner['enrollment_id'] : null);
if (empty($disability_rows) && !empty($learner['primary_diagnosis'])) {
    $disability_rows = sned_disability_rows_from_legacy_diagnosis($learner['primary_diagnosis']);
}
$disability_summary = sned_disability_summary($disability_rows, $learner['primary_diagnosis'] ?? '');
$latest_assessment = sned_latest_assessment_prefill($conn, $lrn);
$stmt_latest_context = $conn->prepare("SELECT disability_impact, meeting_comments FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY school_year DESC, id DESC LIMIT 1");
$stmt_latest_context->bind_param("s", $lrn);
$stmt_latest_context->execute();
$latest_iep_context = $stmt_latest_context->get_result()->fetch_assoc() ?: [];

$default_teacher_name = sned_prefill_value('', $bir['teacher_name'] ?? '', $_SESSION['full_name'] ?? '');
$default_educational_placement = sned_prefill_value('', $bir['educational_placement'] ?? '', (($learner['placement_type'] ?? '') === 'Non-Graded') ? 'Self-Contained' : 'Inclusion');
$default_difficulty_disability = sned_prefill_value('', $bir['difficulty_disability'] ?? '', $disability_summary);
$default_baseline_data = sned_prefill_value('', $bir['baseline_data'] ?? '', $latest_iep_context['disability_impact'] ?? '', $latest_iep_context['meeting_comments'] ?? '');
$default_medical_results = sned_prefill_value('', $bir['medical_results'] ?? '', $learner['medical_results'] ?? '', $latest_assessment['results'] ?? '');
$default_with_medical_assessment = array_key_exists('with_medical_assessment', $bir)
    ? !empty($bir['with_medical_assessment'])
    : (!empty($learner['assessment_mode'])
        ? ($learner['assessment_mode'] === 'With Assessment')
        : !empty($latest_assessment['assessment_type']));
$bir_prefilled_notice = empty($bir);
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
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Annex 3: Behavior Intervention Report Form — <?= htmlspecialchars($learner['first_name'] . ' ' . $learner['last_name']) ?></title>
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
                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>" class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2">
                        <i class="bi bi-arrow-left"></i> Back to Profile
                    </a>
                    <h4 class="fw-bold mb-0 d-none d-md-inline-block"><i class="bi bi-clipboard-pulse text-success me-2"></i> Behavior Intervention Report</h4>
                </div>
                <div class="text-end small text-muted">
                    <strong><?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name']) ?></strong><br>
                    LRN: <?= htmlspecialchars($learner['lrn']) ?> · Grade: <?= htmlspecialchars($learner['grade_level'] . ' - ' . $learner['placement_type']) ?> · SY <?= htmlspecialchars($school_year) ?>
                </div>
            </header>

            <div class="p-4 container-fluid mx-auto" style="max-width: 1200px;">
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger shadow-sm rounded-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg) ?></div>
                <?php endif; ?>

                <?php if ($duplicate_msg): ?>
                    <div class="alert alert-info shadow-sm rounded-4"><i class="bi bi-info-circle-fill me-2"></i><?= htmlspecialchars($duplicate_msg) ?></div>
                <?php endif; ?>

                <?php if ($bir_prefilled_notice): ?>
                    <div class="alert alert-info shadow-sm rounded-4 small"><i class="bi bi-info-circle-fill me-2"></i>Some blank fields were pre-filled from the learner profile, enrollment, or latest assessment. Please review before saving.</div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_input() ?>
                    <input type="hidden" name="bir_id" value="<?= htmlspecialchars($bir['id'] ?? 0) ?>">
                    <input type="hidden" name="sy" value="<?= htmlspecialchars($school_year) ?>">

                    <!-- Part 1: Administrative Details -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-info-square text-success"></i> I. Observation Details</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Quarter *</label>
                                    <select name="quarter" class="form-select" required>
                                        <option value="">Select quarter...</option>
                                        <?php foreach (sned_bir_quarter_options() as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= ($default_quarter === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Date of Observation *</label>
                                    <input type="date" name="date_of_observation" class="form-control" value="<?= htmlspecialchars($bir['date_of_observation'] ?? date('Y-m-d')) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Observer / Teacher Name *</label>
                                    <input type="text" name="teacher_name" class="form-control" placeholder="e.g. Teacher Maria Clara" value="<?= htmlspecialchars($default_teacher_name) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Educational Placement *</label>
                                    <select name="educational_placement" class="form-select" required>
                                        <option value="">Select placement...</option>
                                        <option value="Self-Contained" <?= ($default_educational_placement === 'Self-Contained') ? 'selected' : '' ?>>Self-Contained</option>
                                        <option value="Inclusion" <?= ($default_educational_placement === 'Inclusion') ? 'selected' : '' ?>>Inclusion</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Parent/Guardian Conforme</label>
                                    <input type="text" name="parent_guardian_conforme" class="form-control" placeholder="Auto-filled from guardian record" value="<?= htmlspecialchars($default_parent_conforme) ?>">
                                    <div class="form-hint">Auto-filled from guardian data. Edit if needed.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Part 2: Background / Context -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-activity text-success"></i> II. Background and Context</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Difficulty / Disability Description</label>
                                    <textarea name="difficulty_disability" class="form-control" rows="3" placeholder="Describe the learner's developmental difficulty or diagnosed disability..."><?= htmlspecialchars($default_difficulty_disability) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Baseline Behavior Data</label>
                                    <textarea name="baseline_data" class="form-control" rows="3" placeholder="Describe the frequency, duration, or intensity of behaviors before intervention..."><?= htmlspecialchars($default_baseline_data) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="card border rounded-3 p-3 bg-light">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="with_medical_assessment" id="with_medical_assessment" value="1" <?= $default_with_medical_assessment ? 'checked' : '' ?> onchange="toggleMedicalResults(this.checked)">
                                            <label class="form-check-label fw-bold text-secondary" for="with_medical_assessment">With Medical / Psychological Assessment</label>
                                        </div>
                                        <div id="medical_results_container" style="<?= $default_with_medical_assessment ? '' : 'display: none;' ?>">
                                            <label class="form-label fw-bold small mt-2">Medical / Assessment Results Details</label>
                                            <textarea name="medical_results" id="medical_results" class="form-control" rows="3" placeholder="Specify clinical findings or medical assessments..."><?= htmlspecialchars($default_medical_results) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Part 3: A-B-C Chart -->
                    <div class="sned-card border-left-primary mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-house-door text-success"></i> III. A. Antecedents <span class="fw-normal text-muted small">(what triggered the behavior)</span></h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Home Setting</label>
                                    <textarea name="antecedent_home" class="form-control" placeholder="Describe what triggered the behavior at home..."><?= htmlspecialchars($bir['antecedent_home'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">School Setting</label>
                                    <textarea name="antecedent_school" class="form-control" placeholder="Describe what triggered the behavior at school..."><?= htmlspecialchars($bir['antecedent_school'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Other Settings</label>
                                    <textarea name="antecedent_other" class="form-control" placeholder="Describe what triggered the behavior in other settings..."><?= htmlspecialchars($bir['antecedent_other'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sned-card border-left-accent mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-eye text-success"></i> III. B. Observable Behavior <span class="fw-normal text-muted small">(what the student did)</span></h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Home Setting</label>
                                    <textarea name="observable_home" class="form-control" placeholder="Describe what the student did at home..."><?= htmlspecialchars($bir['observable_home'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">School Setting</label>
                                    <textarea name="observable_school" class="form-control" placeholder="Describe what the student did at school..."><?= htmlspecialchars($bir['observable_school'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Other Settings</label>
                                    <textarea name="observable_other" class="form-control" placeholder="Describe what the student did in other settings..."><?= htmlspecialchars($bir['observable_other'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sned-card border-left-fern mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-arrow-right-circle text-success"></i> III. C. Consequence <span class="fw-normal text-muted small">(what occurred immediately after)</span></h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Home Setting</label>
                                    <textarea name="consequence_home" class="form-control" placeholder="Describe what occurred immediately after at home..."><?= htmlspecialchars($bir['consequence_home'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">School Setting</label>
                                    <textarea name="consequence_school" class="form-control" placeholder="Describe what occurred immediately after at school..."><?= htmlspecialchars($bir['consequence_school'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Other Settings</label>
                                    <textarea name="consequence_other" class="form-control" placeholder="Describe what occurred immediately after in other settings..."><?= htmlspecialchars($bir['consequence_other'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Part 4: Intervention Plan -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-patch-check text-success"></i> IV. Behavior Intervention Plan</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Targeted Behavior for Modification *</label>
                                    <textarea name="targeted_behavior" class="form-control" placeholder="What specific problem behaviors are we looking to modify/eliminate?" required><?= htmlspecialchars($bir['targeted_behavior'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Specific Objectives *</label>
                                    <textarea name="specific_objectives" class="form-control" placeholder="What positive replacement behaviors or objectives do we want to achieve?" required><?= htmlspecialchars($bir['specific_objectives'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Proactive Strategies *</label>
                                    <textarea name="proactive_strategies" class="form-control" placeholder="Prevention strategies (environmental modifications, schedules, visual supports)..." required><?= htmlspecialchars($bir['proactive_strategies'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Reactive Strategies *</label>
                                    <textarea name="reactive_strategies" class="form-control" placeholder="De-escalation or response protocols when the behavior occurs..." required><?= htmlspecialchars($bir['reactive_strategies'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Basic Interventions Handled</label>
                                    <textarea name="basic_intervention" class="form-control" placeholder="Basic modifications and techniques currently applied..."><?= htmlspecialchars($bir['basic_intervention'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Past Interventions Done &amp; Efficacy</label>
                                    <textarea name="interventions_done" class="form-control" placeholder="What has been tried previously, and what were the outcomes?"><?= htmlspecialchars($bir['interventions_done'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 border-top pt-3">
                        <a href="viewLearner.php?id=<?= urlencode($lrn) ?>" class="btn btn-pill-navigation btn-pill-secondary">Cancel</a>
                        <button type="submit" class="btn btn-pill-navigation btn-pill-primary">
                            <i class="bi bi-cloud-arrow-up me-2"></i>Save Report
                        </button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleMedicalResults(checked) {
            var container = document.getElementById('medical_results_container');
            if (checked) {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
                document.getElementById('medical_results').value = '';
            }
        }
    </script>
    <script>
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }
    </script>
</body>
</html>
