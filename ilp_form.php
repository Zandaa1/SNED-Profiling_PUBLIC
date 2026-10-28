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

$document_type = $_GET['document_type'] ?? 'ILP';
if (!in_array($document_type, ['IEP', 'ILP'])) {
    $document_type = 'ILP';
}

// Load learner & active enrollment
$stmt = $conn->prepare("
    SELECT l.*, e.id as enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level, e.grade_level, e.primary_diagnosis,
           s.school_name, s.district,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle, g.extension_name AS g_ext,
           g.contact_number AS g_contact, g.house_no_street AS g_house, g.barangay AS g_barangay,
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

// Default school year
$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
$school_year = $_GET['sy'] ?? $learner['school_year'] ?? $current_sy;

// Load existing ILP/IEP if any
$ilp = null;
$barriers = [];
$goals = [];
$team_members = [];
$assessments = [];

$stmt_i = $conn->prepare("
    SELECT ilp.*, u.full_name AS adviser_name_joined
    FROM learner_ilp ilp
    LEFT JOIN users u ON ilp.adviser_id = u.id
    WHERE ilp.learner_id = ? AND ilp.school_year = ? AND ilp.document_type = ?
    LIMIT 1
");
$stmt_i->bind_param("sss", $lrn, $school_year, $document_type);
$stmt_i->execute();
$ilp = $stmt_i->get_result()->fetch_assoc();
$ilp_is_new = !$ilp;

if ($ilp) {
    if (!empty($ilp['barriers_json'])) {
        $barriers = json_decode($ilp['barriers_json'], true) ?? [];
    }

    // Load goals
    $stmt_g = $conn->prepare("SELECT * FROM ilp_goals WHERE ilp_id = ? ORDER BY id ASC");
    $stmt_g->bind_param("i", $ilp['id']);
    $stmt_g->execute();
    $goals = $stmt_g->get_result()->fetch_all(MYSQLI_ASSOC);

    // Load team members
    $stmt_t = $conn->prepare("SELECT * FROM ilp_team_members WHERE ilp_id = ? ORDER BY id ASC");
    $stmt_t->bind_param("i", $ilp['id']);
    $stmt_t->execute();
    $team_members = $stmt_t->get_result()->fetch_all(MYSQLI_ASSOC);

    // Load assessments
    $stmt_a = $conn->prepare("SELECT * FROM learner_ilp_assessments WHERE ilp_id = ? ORDER BY id ASC");
    $stmt_a->bind_param("i", $ilp['id']);
    $stmt_a->execute();
    $assessments = $stmt_a->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Copy-forward from previous school year
$copy_from_sy = $_GET['copyFromSy'] ?? null;
$copy_applied = null;
if ($ilp_is_new && $copy_from_sy && preg_match('/^\d{4}-\d{4}$/', $copy_from_sy) && $copy_from_sy !== $school_year) {
    $stmt_copy = $conn->prepare("SELECT * FROM learner_ilp WHERE learner_id = ? AND school_year = ? AND document_type = ? LIMIT 1");
    $stmt_copy->bind_param("sss", $lrn, $copy_from_sy, $document_type);
    $stmt_copy->execute();
    $source_ilp = $stmt_copy->get_result()->fetch_assoc();
    if ($source_ilp) {
        $copy_applied = $copy_from_sy;
        $source_ilp_id = (int)$source_ilp['id'];
        $source_ilp['school_year'] = $school_year;
        $source_ilp['meeting_date'] = null;
        $source_ilp['review_date'] = null;
        unset($source_ilp['id'], $source_ilp['created_at'], $source_ilp['updated_at']);
        $ilp = $source_ilp;

        $stmt_g_copy = $conn->prepare("SELECT * FROM ilp_goals WHERE ilp_id = ? ORDER BY id ASC");
        $stmt_g_copy->bind_param("i", $source_ilp_id);
        $stmt_g_copy->execute();
        $goals = $stmt_g_copy->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt_t_copy = $conn->prepare("SELECT * FROM ilp_team_members WHERE ilp_id = ? ORDER BY id ASC");
        $stmt_t_copy->bind_param("i", $source_ilp_id);
        $stmt_t_copy->execute();
        $team_members = $stmt_t_copy->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt_a_copy = $conn->prepare("SELECT * FROM learner_ilp_assessments WHERE ilp_id = ? ORDER BY id ASC");
        $stmt_a_copy->bind_param("i", $source_ilp_id);
        $stmt_a_copy->execute();
        $assessments = $stmt_a_copy->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Transition Package options
$transition_package_options = [
    'care_skills' => 'Care Skills',
    'life_skills' => 'Life Skills',
    'functional_academics' => 'Functional Academics',
    'pre_vocational_skills' => 'Pre-Vocational Skills',
    'livelihood_skills' => 'Livelihood Skills',
    'career_skills' => 'Career Skills',
    'enrichment_skills' => 'Enrichment Skills',
];

// Parse saved transition_work_opportunities (JSON array or legacy newline text)
$saved_transition_packages = [];
if (!empty($ilp['transition_work_opportunities'])) {
    $decoded = json_decode($ilp['transition_work_opportunities'], true);
    if (is_array($decoded)) {
        $saved_transition_packages = $decoded;
    }
}

if (!$ilp) {
    $ilp = [];
}
$ilp['adviser_name'] = $ilp['adviser_name'] ?? $ilp['adviser_name_joined'] ?? '';
if (empty($ilp['adviser_name']) && isset($_SESSION['role']) && $_SESSION['role'] === 'teacher') {
    $ilp['adviser_name'] = $_SESSION['full_name'] ?? '';
}

// Fallback auto-fills from student/guardian record
$default_interview_guardian = sned_guardian_name($learner);
$ilp['interview_guardian_name'] = $ilp['interview_guardian_name'] ?? $default_interview_guardian;
$ilp['interview_guardian_contact'] = $ilp['interview_guardian_contact'] ?? sned_guardian_contact($learner);

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

$purpose_options = [
    'Initial' => 'Initial',
    'Term' => 'Term',
    'Revision' => 'Revision',
    'Following 3-Yr Reevaluation' => 'Following 3-Yr Reevaluation',
    'Exit/Graduation' => 'Exit/Graduation'
];

$team_roles = [
    'Parent/Guardian',
    'Learner',
    'School Head',
    'Special Education Teacher Adviser',
    'Other SPED Teachers',
    'Regular/Receiving Teacher',
    'Guidance Counselor',
    'School Nurse',
    'Occupational Therapist',
    'Physical Therapist',
    'Speech Pathologist/Specialist',
    'Speech-Language Interpreter',
    'Other'
];
$principal_name = !empty($learner['school_id']) ? get_current_principal($conn, (int)$learner['school_id']) : '';
if (empty($team_members)) {
    $team_members = array_values(array_filter([
        ['role' => 'Special Education Teacher Adviser', 'full_name' => $_SESSION['full_name'] ?? ''],
        ['role' => 'Parent/Guardian', 'full_name' => $default_interview_guardian],
        ['role' => 'School Head', 'full_name' => $principal_name],
    ], static fn($row) => trim((string)$row['full_name']) !== ''));
}

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
if ($ilp && !empty($ilp['special_factors'])) {
    $sf_json = json_decode($ilp['special_factors'], true) ?? [];
}

if (empty($assessments)) {
    $latest_assessment = sned_latest_assessment_prefill($conn, $lrn);
    if (!empty($latest_assessment)) {
        $assessments[] = [
            'assessment_type' => $latest_assessment['assessment_type'] ?? '',
            'date_administered' => $latest_assessment['assessment_date'] ?? '',
            'chronological_age' => '',
            'administrator' => $latest_assessment['administrator'] ?? '',
            'results' => $latest_assessment['results'] ?? '',
        ];
    }
}

$is_transition_eligible = in_array($learner['grade_level'] ?? '', ['Transition Level 1', 'Transition Level 2', 'Transition Level 3', 'Non-Graded']) || (($learner['placement_type'] ?? '') === 'Non-Graded');

$can_edit = user_is_coordinator($user_role);

$adviser_options = [];
$res_adv = $conn->query("
    SELECT u.id, u.full_name, u.role, s.school_name
    FROM users u
    LEFT JOIN schools s ON u.school_id = s.id
    ORDER BY s.school_name IS NULL, s.school_name ASC, u.full_name ASC
");
if ($res_adv) {
    while ($adv = $res_adv->fetch_assoc()) {
        $adviser_options[] = $adv;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $document_type === 'IEP' ? 'IEP Record Editor' : 'Individual Learner Profile Editor' ?> | <?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name']) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
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
                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>&tab=<?= strtolower($document_type) ?>" class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Profile</a>
                    <h4 class="fw-bold mb-0 d-none d-md-inline-block"><i class="bi bi-clipboard-pulse text-success me-2"></i> <?= $document_type === 'IEP' ? 'Individualized Education Plan Editor' : 'Individual Learner Profile Editor' ?></h4>
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
                        <span class="step-progress-label">Profile &amp; Metadata</span>
                    </div>
                    <div class="step-progress-item" data-step="2" id="stepIndicator2">
                        <span class="step-progress-label">Present Levels</span>
                    </div>
                    <div class="step-progress-item" data-step="3" id="stepIndicator3">
                        <span class="step-progress-label">Narrative Domains</span>
                    </div>
                    <div class="step-progress-item" data-step="4" id="stepIndicator4">
                        <span class="step-progress-label">Special Factors</span>
                    </div>
                    <div class="step-progress-item" data-step="5" id="stepIndicator5">
                        <span class="step-progress-label">Barriers &amp; Supports</span>
                    </div>
                    <div class="step-progress-item" data-step="6" id="stepIndicator6">
                        <span class="step-progress-label">Goals &amp; Roadmap</span>
                    </div>
                    <?php if ($is_transition_eligible): ?>
                        <div class="step-progress-item" data-step="7" id="stepIndicator7">
                            <span class="step-progress-label">Transition</span>
                        </div>
                    <?php endif; ?>
                </div>

                <form id="ilpForm" action="saveILP.php" method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="learner_id" value="<?= htmlspecialchars($lrn) ?>">
                    <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
                    <input type="hidden" name="document_type" value="<?= $document_type ?>">

                    <?php $prev_sy = sned_previous_school_year($school_year); ?>
                    <?php if ($ilp_is_new && !$copy_applied && $prev_sy && empty($_GET['copyFromSy'])): ?>
                    <div class="alert alert-info border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-copy text-info me-2" style="font-size: 1.5rem;"></i>
                            <strong>Start fresh or copy forward?</strong>
                            <span class="ms-2 text-muted">No <?= $document_type === 'IEP' ? 'IEP' : 'ILP' ?> record exists for SY <?= htmlspecialchars($school_year) ?>.</span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="ilp_form.php?learner_id=<?= urlencode($lrn) ?>&document_type=<?= $document_type ?>&sy=<?= urlencode($school_year) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">Start blank</a>
                            <a href="ilp_form.php?learner_id=<?= urlencode($lrn) ?>&document_type=<?= $document_type ?>&sy=<?= urlencode($school_year) ?>&copyFromSy=<?= urlencode($prev_sy) ?>" class="btn btn-info btn-sm rounded-pill px-3 text-white"><i class="bi bi-copy me-1"></i> Copy from SY <?= htmlspecialchars($prev_sy) ?></a>
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

                    <div class="tab-content" id="ilpTabs">
                        <!-- ==================== SECTION 1: Personal Information ==================== -->
                        <div class="tab-pane fade show active" id="sec1" role="tabpanel">
                            <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-person-vcard text-success me-2"></i>Section A: Profile &amp; Metadata</h5>
                        </div>
                                <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Document Type</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= $document_type === 'IEP' ? 'Individualized Education Plan (IEP)' : 'Individual Learner Profile (ILP)' ?>" disabled>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">School Year</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars($school_year) ?>" disabled>
                                </div>
                                <div class="col-md-4">
                                    <label for="adviser_name" class="form-label fw-bold small">Teacher / Adviser</label>
                                    <input type="text" name="adviser_name" id="adviser_name" class="form-control rounded-3" placeholder="Enter teacher/adviser name" value="<?= htmlspecialchars($ilp['adviser_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Age</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= $age ?>" disabled>
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calendar-event me-1"></i> Meeting Details</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Date of Meeting</label>
                                    <input type="date" name="meeting_date" class="form-control rounded-3" value="<?= $ilp['meeting_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">IEP/ILP Review Date</label>
                                    <input type="date" name="review_date" class="form-control rounded-3" value="<?= $ilp['review_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Purpose of Meeting</label>
                                    <select name="meeting_purpose" class="form-select rounded-3">
                                        <option value="">-- Select Purpose --</option>
                                        <?php foreach ($purpose_options as $pk => $pv): ?>
                                            <option value="<?= $pk ?>" <?= (($ilp['meeting_purpose'] ?? '') === $pk) ? 'selected' : '' ?>><?= $pv ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Interview Date (Guardian)</label>
                                    <input type="date" name="interview_date" class="form-control rounded-3" value="<?= $ilp['interview_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Guardian Interviewed</label>
                                    <input type="text" name="interview_guardian_name" class="form-control rounded-3" value="<?= htmlspecialchars($ilp['interview_guardian_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Guardian Contact Number</label>
                                    <input type="text" name="interview_guardian_contact" class="form-control rounded-3" value="<?= htmlspecialchars($ilp['interview_guardian_contact'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Interests &amp; Hobbies Highlight</label>
                                    <input type="text" name="interests_hobbies" class="form-control rounded-3" placeholder="Likes music, gardening, etc." value="<?= htmlspecialchars($ilp['interests_hobbies'] ?? '') ?>">
                                </div>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-people-fill me-1"></i> IEP / ILP Team Members in Attendance</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-bordered table-sm align-middle bg-white" id="teamTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40%">Role</th>
                                            <th style="width:50%">Full Name</th>
                                            <th style="width:10%" class="text-center">Action</th>
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
                                            <td><input type="text" name="team_name[]" class="form-control form-control-sm rounded-2" placeholder="Full name" value="<?= htmlspecialchars($tm['full_name'] ?? '') ?>"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 bg-white" onclick="addTeamRow()"><i class="bi bi-plus-lg"></i> Add Team Member</button>
                            </div>

                            <hr class="my-3">
                            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-pen me-1"></i> Rights Notification</h6>
                            <div class="row g-3">
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="rights_transfer_notified" id="rights_transfer_notified" value="1" <?= (($ilp['rights_transfer_notified'] ?? 0) == 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="rights_transfer_notified">Learner informed of rights transfer at age 18 (at least 1 year before turning 18)</label>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="rights_not_applicable" id="rights_not_applicable" value="1" <?= (($ilp['rights_not_applicable'] ?? 0) == 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="rights_not_applicable">Not Applicable (Learner will not reach 18 within one year)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(2)">Next: Present Levels <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SECTION 2: Present Levels & Assessments ==================== -->
                <div class="tab-pane fade" id="sec2" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-clipboard-data text-success me-2"></i>Section B: Present Levels of Academic Achievement and Functional Performance</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <label class="form-label fw-bold small">Results of Evaluation / Assessments</label>
                                    <textarea name="present_level_results" class="form-control rounded-3" rows="3" placeholder="Results of evaluation and division assessments..."><?= htmlspecialchars($ilp['present_level_results'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Parental Concerns</label>
                                    <textarea name="parental_concerns" class="form-control rounded-3" rows="3" placeholder="Parental concerns regarding child's education..."><?= htmlspecialchars($ilp['parental_concerns'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Disability Impact</label>
                                    <textarea name="disability_impact" class="form-control rounded-3" rows="3" placeholder="Impact of disability on progress in general education curriculum..."><?= htmlspecialchars($ilp['disability_impact'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- Record of Assessments -->
                            <h6 class="fw-bold text-secondary mb-2 mt-4"><i class="bi bi-clipboard2-pulse-fill me-1"></i> Record of Assessments</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-bordered table-sm align-middle bg-white" id="assessmentsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:18%">Type of Assessment</th>
                                            <th style="width:14%">Date Administered</th>
                                            <th style="width:14%">Chronological Age</th>
                                            <th style="width:18%">Administrator</th>
                                            <th style="width:30%">Results / Outcome</th>
                                            <th style="width:6%" class="text-center"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($assessments)): ?>
                                        <tr class="assessment-row">
                                            <td><input type="text" name="assessments[0][assessment_type]" class="form-control form-control-sm rounded-2" placeholder="e.g. Initial Assessment"></td>
                                            <td><input type="date" name="assessments[0][date_administered]" class="form-control form-control-sm rounded-2"></td>
                                            <td><input type="text" name="assessments[0][chronological_age]" class="form-control form-control-sm rounded-2" placeholder="e.g. 8y 3m"></td>
                                            <td><input type="text" name="assessments[0][administrator]" class="form-control form-control-sm rounded-2" placeholder="Administrator name"></td>
                                            <td><textarea name="assessments[0][results]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Assessment results..."></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($assessments as $ai => $a): ?>
                                        <tr class="assessment-row">
                                            <td><input type="text" name="assessments[<?= $ai ?>][assessment_type]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($a['assessment_type'] ?? '') ?>"></td>
                                            <td><input type="date" name="assessments[<?= $ai ?>][date_administered]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($a['date_administered'] ?? '') ?>"></td>
                                            <td><input type="text" name="assessments[<?= $ai ?>][chronological_age]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($a['chronological_age'] ?? '') ?>"></td>
                                            <td><input type="text" name="assessments[<?= $ai ?>][administrator]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($a['administrator'] ?? '') ?>"></td>
                                            <td><textarea name="assessments[<?= $ai ?>][results]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($a['results'] ?? '') ?></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 bg-white" onclick="addAssessmentRow()"><i class="bi bi-plus-lg"></i> Add Assessment</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(1)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(3)">Next: Narrative Domains <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SECTION 3: Narrative Domains Profile ==================== -->
                <div class="tab-pane fade" id="sec3" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-activity text-success me-2"></i>Section B: Functional Narrative Profile (7 Domains)</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3 mb-4">
                                <?php
                                $domains_list = [
                                    'daily_living' => 'Daily Living Skills (Self-feeding, toileting, dressing, grooming and hygiene)',
                                    'language'     => 'Language Development / Communication (Listening, speaking, reading, writing)',
                                    'psychomotor'  => 'Psychomotor (Basic movement, gross motor, fine motor)',
                                    'cognitive'    => 'Cognitive Domain (Colors, shapes, numbers, concepts, memory)',
                                    'aesthetic'    => 'Aesthetic and Creative (Art, music, movement, drama)',
                                    'behavioral'   => 'Behavioral Development (Classroom behavior, attention, task completion)',
                                    'orientation'  => 'Orientation and Mobility (For learners with visual impairment)'
                                ];
                                foreach ($domains_list as $key => $name): ?>
                                    <div class="col-12">
                                        <details open class="sned-details mb-2">
                                            <summary class="fw-bold">
                                                <span><i class="bi bi-bookmark-star text-success me-2"></i><?= $name ?></span>
                                            </summary>
                                            <div class="details-content">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold small">Strengths</label>
                                                        <textarea name="<?= $key ?>_strengths" class="form-control" rows="3" placeholder="Describe strengths..."><?= htmlspecialchars($ilp[$key . '_strengths'] ?? '') ?></textarea>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold small">Needs</label>
                                                        <textarea name="<?= $key ?>_needs" class="form-control" rows="3" placeholder="Describe needs..."><?= htmlspecialchars($ilp[$key . '_needs'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(2)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(4)">Next: Special Factors <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SECTION 4: Special Factors ==================== -->
                <div class="tab-pane fade" id="sec4" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-gear-wide text-success me-2"></i>Section B: Consideration of Special Factors</h5>
                        </div>
                        <div class="sned-card-body">
                            <p class="small text-muted mb-3">For each item, indicate Yes/No and provide a description if applicable.</p>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle bg-white" id="specialFactorsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:5%">#</th>
                                            <th style="width:40%">Special Factor</th>
                                            <th style="width:10%">Yes/No</th>
                                            <th style="width:45%">Description / Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sf_idx = 0; foreach ($special_factor_keys as $sfk => $sfl): $sf_idx++; ?>
                                        <tr>
                                            <td class="text-center small text-muted"><?= $sf_idx ?></td>
                                            <td>
                                                <label class="fw-bold small mb-0"><?= $sfl ?></label>
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
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(3)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(5)">Next: Barriers &amp; Supports <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SECTION 5: Barriers & Accommodations ==================== -->
                <div class="tab-pane fade" id="sec5" role="tabpanel">
                    <div class="sned-card">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-barrier text-success me-2"></i>Section C: Barriers &amp; Enabling Supports</h5>
                        </div>
                        <div class="sned-card-body">
                            <p class="small text-muted mb-3">Describe the environmental barriers, facilitators, and accommodations needed for the difficulties identified. ICF qualifier codes guide: Barriers (.0-.9), Facilitators (+0-+4).</p>

                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle bg-white" id="barriersTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:20%">Difficulty Area</th>
                                            <th style="width:20%">Environmental Barriers</th>
                                            <th style="width:12%">Barrier Qualifier</th>
                                            <th style="width:20%">Environmental Facilitators</th>
                                            <th style="width:12%">Facilitator Qualifier</th>
                                            <th style="width:20%">Accommodations Required</th>
                                            <th style="width:5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($barriers)): ?>
                                        <tr class="barrier-row">
                                            <td>
                                                <select name="barriers[0][difficulty]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Area --</option>
                                                    <?php foreach ($difficulty_labels as $dk => $dl): ?>
                                                    <option value="<?= $dk ?>"><?= $dl ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][barrier_text]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe barriers..."></textarea></td>
                                            <td>
                                                <select name="barriers[0][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="0">0 No barrier</option><option value=".1">.1 Mild</option><option value=".2">.2 Moderate</option>
                                                    <option value=".3">.3 Severe</option><option value=".4">.4 Complete</option><option value=".8">.8 Not specified</option><option value=".9">.9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][facilitator]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe facilitators..."></textarea></td>
                                            <td>
                                                <select name="barriers[0][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="+0">+0 No facilitator</option><option value="+1">+1 Mild</option><option value="+2">+2 Moderate</option>
                                                    <option value="+3">+3 Substantial</option><option value="+4">+4 Complete</option><option value="+8">+8 Not specified</option><option value="+9">+9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[0][accommodations]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Accommodations..."></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($barriers as $bi => $b): ?>
                                        <tr class="barrier-row">
                                            <td>
                                                <select name="barriers[<?= $bi ?>][difficulty]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select Area --</option>
                                                    <?php foreach ($difficulty_labels as $dk => $dl): ?>
                                                    <option value="<?= $dk ?>" <?= ($b['difficulty'] ?? '') === $dk ? 'selected' : '' ?>><?= $dl ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][barrier_text]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['barrier_text'] ?? $b['interest'] ?? '') ?></textarea></td>
                                            <td>
                                                <select name="barriers[<?= $bi ?>][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="0" <?= ($b['barrier_qualifier'] ?? '') === '0' ? 'selected' : '' ?>>0 No barrier</option>
                                                    <option value=".1" <?= ($b['barrier_qualifier'] ?? '') === '.1' ? 'selected' : '' ?>>.1 Mild</option>
                                                    <option value=".2" <?= ($b['barrier_qualifier'] ?? '') === '.2' ? 'selected' : '' ?>>.2 Moderate</option>
                                                    <option value=".3" <?= ($b['barrier_qualifier'] ?? '') === '.3' ? 'selected' : '' ?>>.3 Severe</option>
                                                    <option value=".4" <?= ($b['barrier_qualifier'] ?? '') === '.4' ? 'selected' : '' ?>>.4 Complete</option>
                                                    <option value=".8" <?= ($b['barrier_qualifier'] ?? '') === '.8' ? 'selected' : '' ?>>.8 Not specified</option>
                                                    <option value=".9" <?= ($b['barrier_qualifier'] ?? '') === '.9' ? 'selected' : '' ?>>.9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][facilitator]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['facilitator'] ?? '') ?></textarea></td>
                                            <td>
                                                <select name="barriers[<?= $bi ?>][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                                                    <option value="">--</option>
                                                    <option value="+0" <?= ($b['facilitator_qualifier'] ?? '') === '+0' ? 'selected' : '' ?>>+0 No facilitator</option>
                                                    <option value="+1" <?= ($b['facilitator_qualifier'] ?? '') === '+1' ? 'selected' : '' ?>>+1 Mild</option>
                                                    <option value="+2" <?= ($b['facilitator_qualifier'] ?? '') === '+2' ? 'selected' : '' ?>>+2 Moderate</option>
                                                    <option value="+3" <?= ($b['facilitator_qualifier'] ?? '') === '+3' ? 'selected' : '' ?>>+3 Substantial</option>
                                                    <option value="+4" <?= ($b['facilitator_qualifier'] ?? '') === '+4' ? 'selected' : '' ?>>+4 Complete</option>
                                                    <option value="+8" <?= ($b['facilitator_qualifier'] ?? '') === '+8' ? 'selected' : '' ?>>+8 Not specified</option>
                                                    <option value="+9" <?= ($b['facilitator_qualifier'] ?? '') === '+9' ? 'selected' : '' ?>>+9 Not applicable</option>
                                                </select>
                                            </td>
                                            <td><textarea name="barriers[<?= $bi ?>][accommodations]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($b['accommodations'] ?? '') ?></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 bg-white" onclick="addBarrierRow()"><i class="bi bi-plus-lg"></i> Add Barrier Row</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(4)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(6)">Next: Goals &amp; Roadmap <i class="bi bi-arrow-right ms-1"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                    <!-- ==================== SECTION 6: SMART Goals ==================== -->
                    <div class="tab-pane fade" id="sec6" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-trophy me-2"></i>Section D: Goals &amp; Progress Roadmap</h5>
                            <p class="small text-muted">Provide the specific, measurable, achievable, realistic, and time-bound goals for the student. Focus on area of interest and intervention.</p>

                            <div class="mb-4">
                                <label class="form-label fw-bold small">Priority Learning Needs / Interventions</label>
                                <textarea name="priority_needs" class="form-control rounded-3" rows="4" placeholder="Enter one priority need or intervention per line. The first five lines appear on Annex 1."><?= htmlspecialchars($ilp['priority_needs'] ?? '') ?></textarea>
                                <div class="form-text">Use one line per item to match the numbered Annex 1 print layout.</div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle bg-white" id="goalsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:15%">Domain/Interest</th>
                                            <th style="width:25%">SMART Goal</th>
                                            <th style="width:20%">Interventions</th>
                                            <th style="width:10%">Timeline</th>
                                            <th style="width:15%">Persons Responsible</th>
                                            <th style="width:10%">Remarks</th>
                                            <th style="width:5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($goals)): ?>
                                        <tr class="goal-row">
                                            <td><input type="text" name="goals[0][interest]" class="form-control form-control-sm rounded-2" placeholder="e.g. Cognitive"></td>
                                            <td><textarea name="goals[0][goal]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Specify SMART goal..."></textarea></td>
                                            <td><textarea name="goals[0][interventions]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Interventions..."></textarea></td>
                                            <td><input type="text" name="goals[0][timeline]" class="form-control form-control-sm rounded-2" placeholder="e.g. 6 months"></td>
                                            <td><input type="text" name="goals[0][responsible]" class="form-control form-control-sm rounded-2" placeholder="Responsible persons"></td>
                                            <td><input type="text" name="goals[0][remarks]" class="form-control form-control-sm rounded-2" placeholder="Remarks"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($goals as $gi => $g): ?>
                                        <tr class="goal-row">
                                            <td><input type="text" name="goals[<?= $gi ?>][interest]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['interest'] ?? '') ?>"></td>
                                            <td><textarea name="goals[<?= $gi ?>][goal]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($g['goal'] ?? '') ?></textarea></td>
                                            <td><textarea name="goals[<?= $gi ?>][interventions]" class="form-control form-control-sm rounded-2" rows="2"><?= htmlspecialchars($g['interventions'] ?? '') ?></textarea></td>
                                            <td><input type="text" name="goals[<?= $gi ?>][timeline]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['timeline'] ?? '') ?>"></td>
                                            <td><input type="text" name="goals[<?= $gi ?>][responsible]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['persons_responsible'] ?? '') ?>"></td>
                                            <td><input type="text" name="goals[<?= $gi ?>][remarks]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($g['remarks'] ?? '') ?>"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 bg-white" onclick="addGoalRow()"><i class="bi bi-plus-lg"></i> Add SMART Goal</button>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(5)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <?php if ($is_transition_eligible): ?>
                                    <button type="button" class="btn btn-pill-navigation btn-pill-primary px-5 fw-bold shadow-sm" onclick="goToStep(7)">Next: Transition <i class="bi bi-arrow-right ms-1"></i></button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Save Record</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ==================== SECTION 7: Transition ==================== -->
                    <?php if ($is_transition_eligible): ?>
                    <div class="tab-pane fade" id="sec7" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-signpost-2 me-2"></i>Section E: Transition Planning</h5>
                            <p class="small text-muted">Complete this section for Non-Graded or Transition level students outlining interests, work placement options, and specific skills training.</p>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Transition Interests</label>
                                    <textarea name="transition_interests" class="form-control rounded-3" rows="4" placeholder="Student interests and hobbies relating to future placement..."><?= htmlspecialchars($ilp['transition_interests'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Transition Package</label>
                                    <div class="border rounded-3 p-3 bg-light" style="max-height: 180px; overflow-y: auto;">
                                        <?php foreach ($transition_package_options as $key => $label): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="transition_work_opportunities[]" value="<?= $key ?>" id="tp_<?= $key ?>" <?= in_array($key, $saved_transition_packages) ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="tp_<?= $key ?>"><?= $label ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Enabling Skills &amp; Interventions</label>
                                    <textarea name="transition_skills" class="form-control rounded-3" rows="4" placeholder="Specific vocational skills training or behavioral targets..."><?= htmlspecialchars($ilp['transition_skills'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-pill-navigation btn-pill-secondary px-4" onclick="goToStep(6)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Save Record</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        let currentStep = 1;
        const totalSteps = <?= $is_transition_eligible ? 7 : 6 ?>;
        let barrierIndex = <?= count($barriers) > 0 ? count($barriers) : 1 ?>;
        let goalIndex = <?= count($goals) > 0 ? count($goals) : 1 ?>;
        let assessmentIndex = <?= max(count($assessments), 1) ?>;

        document.addEventListener('DOMContentLoaded', function() {

            // Mutually exclusive rights notification checkboxes
            const rightsNotified = document.getElementById('rights_transfer_notified');
            const rightsNa = document.getElementById('rights_not_applicable');
            if (rightsNotified && rightsNa) {
                rightsNotified.addEventListener('change', function() {
                    if (this.checked) rightsNa.checked = false;
                });
                rightsNa.addEventListener('change', function() {
                    if (this.checked) rightsNotified.checked = false;
                });
            }
        });

        function goToStep(step) {
            if (step < 1 || step > totalSteps) return;
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));
            document.getElementById('sec' + step).classList.add('show', 'active');
            document.querySelectorAll('.step-progress-item').forEach((el, i) => {
                el.classList.remove('active', 'completed');
                if (i + 1 === step) el.classList.add('active');
                else if (i + 1 < step) el.classList.add('completed');
            });
            currentStep = step;
        }

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
                        <option value="">-- Select Area --</option>
                        <?php foreach ($difficulty_labels as $dk => $dl): ?>
                        <option value="<?= $dk ?>"><?= $dl ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][barrier_text]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe barriers..."></textarea></td>
                <td>
                    <select name="barriers[${idx}][barrier_qualifier]" class="form-select form-select-sm rounded-2">
                        <option value="">--</option>
                        <option value="0">0 No barrier</option><option value=".1">.1 Mild</option><option value=".2">.2 Moderate</option>
                        <option value=".3">.3 Severe</option><option value=".4">.4 Complete</option><option value=".8">.8 Not specified</option><option value=".9">.9 Not applicable</option>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][facilitator]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe facilitators..."></textarea></td>
                <td>
                    <select name="barriers[${idx}][facilitator_qualifier]" class="form-select form-select-sm rounded-2">
                        <option value="">--</option>
                        <option value="+0">+0 No facilitator</option><option value="+1">+1 Mild</option><option value="+2">+2 Moderate</option>
                        <option value="+3">+3 Substantial</option><option value="+4">+4 Complete</option><option value="+8">+8 Not specified</option><option value="+9">+9 Not applicable</option>
                    </select>
                </td>
                <td><textarea name="barriers[${idx}][accommodations]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Accommodations..."></textarea></td>
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
                <td><input type="text" name="goals[${idx}][interest]" class="form-control form-control-sm rounded-2" placeholder="e.g. Cognitive"></td>
                <td><textarea name="goals[${idx}][goal]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Specify SMART goal..."></textarea></td>
                <td><textarea name="goals[${idx}][interventions]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Interventions..."></textarea></td>
                <td><input type="text" name="goals[${idx}][timeline]" class="form-control form-control-sm rounded-2" placeholder="e.g. 6 months"></td>
                <td><input type="text" name="goals[${idx}][responsible]" class="form-control form-control-sm rounded-2" placeholder="Responsible persons"></td>
                <td><input type="text" name="goals[${idx}][remarks]" class="form-control form-control-sm rounded-2" placeholder="Remarks"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        function addAssessmentRow() {
            const tbody = document.querySelector('#assessmentsTable tbody');
            const idx = assessmentIndex++;
            const tr = document.createElement('tr');
            tr.className = 'assessment-row';
            tr.innerHTML = `
                <td><input type="text" name="assessments[${idx}][assessment_type]" class="form-control form-control-sm rounded-2" placeholder="e.g. Initial Assessment"></td>
                <td><input type="date" name="assessments[${idx}][date_administered]" class="form-control form-control-sm rounded-2"></td>
                <td><input type="text" name="assessments[${idx}][chronological_age]" class="form-control form-control-sm rounded-2" placeholder="e.g. 8y 3m"></td>
                <td><input type="text" name="assessments[${idx}][administrator]" class="form-control form-control-sm rounded-2" placeholder="Administrator name"></td>
                <td><textarea name="assessments[${idx}][results]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Assessment results..."></textarea></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

    </script>
    <script>
        // --- Stepper Navigation click handler ---
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
