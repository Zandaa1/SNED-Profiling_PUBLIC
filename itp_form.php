<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';
require_once 'includes/itp_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!function_exists('itp_map_legacy_role')) {
    function itp_map_legacy_role(string $role): string {
        $normalized = strtolower(trim($role));
        return match (true) {
            $normalized === 'itp coordinator' => 'ITP Coordinator',
            in_array($normalized, ['school head', 'principal', 'school principal'], true) => 'School Head',
            in_array($normalized, ['sped teacher', 'special education teacher'], true) => 'SPED Teacher',
            in_array($normalized, ['parent/guardian', 'guardian', 'parent'], true) => 'Parent/Guardian',
            $normalized === 'learner' => 'Learner',
            in_array($normalized, ['regular/receiving teacher', 'regular/guidance teacher', 'guidance counselor', 'regular teacher'], true) => 'Regular/Guidance Teacher',
            default => 'Linkages',
        };
    }
}

if (!function_exists('itp_compose_guardian_name')) {
    function itp_compose_guardian_name(array $learner): string {
        if (!empty($learner['primary_contact_name'])) {
            return (string)$learner['primary_contact_name'];
        }
        $parts = array_filter([
            $learner['g_last'] ?? '',
            $learner['g_first'] ?? '',
            $learner['g_middle'] ?? '',
            $learner['g_ext'] ?? '',
        ]);
        if (empty($parts)) {
            return '';
        }
        $last = array_shift($parts);
        return trim($last . ', ' . implode(' ', $parts));
    }
}

if (!function_exists('itp_compose_guardian_address')) {
    function itp_compose_guardian_address(array $learner): string {
        if (!empty($learner['primary_contact_address'])) {
            return (string)$learner['primary_contact_address'];
        }
        return implode(', ', array_filter([
            trim((string)($learner['g_house'] ?? '')),
            trim((string)($learner['g_barangay'] ?? '')),
        ]));
    }
}

if (!function_exists('itp_compose_current_level')) {
    function itp_compose_current_level(array $learner): string {
        $parts = [];
        if (!empty($learner['grade_level'])) {
            $parts[] = (string)$learner['grade_level'];
        }
        if (!empty($learner['placement_type'])) {
            $parts[] = (string)$learner['placement_type'];
        }
        if (($learner['placement_type'] ?? '') === 'Non-Graded' && !empty($learner['self_contained_level'])) {
            $parts[] = (string)$learner['self_contained_level'];
        }
        return implode(' / ', array_unique(array_filter($parts)));
    }
}

if (!function_exists('itp_existing_entry_label')) {
    function itp_existing_entry_label(array $data, array $entry_options): ?string {
        if (!empty($data['entry_point_label'])) {
            return (string)$data['entry_point_label'];
        }
        foreach ($entry_options as $field => $label) {
            if (!empty($data[$field])) {
                return $label;
            }
        }
        return null;
    }
}

if (!function_exists('itp_prefill_team_rows')) {
    function itp_prefill_team_rows(array $existing_rows, ?string $legacy_json = null): array {
        $rows = [];
        foreach (itp_fixed_team_roles() as $index => $role) {
            $rows[$role] = [
                'role' => $role,
                'name' => '',
                'contact_details' => '',
                'date_started_with_team' => '',
                'sort_order' => $index,
            ];
        }

        foreach ($existing_rows as $row) {
            $mapped_role = itp_map_legacy_role((string)($row['role'] ?? ''));
            if (!isset($rows[$mapped_role])) {
                continue;
            }
            $existing_name = trim((string)$rows[$mapped_role]['name']);
            $incoming_name = trim((string)($row['name'] ?? ''));
            if ($incoming_name !== '') {
                $rows[$mapped_role]['name'] = $existing_name === '' ? $incoming_name : ($existing_name . '; ' . $incoming_name);
            }
            if (!empty($row['contact_details']) && trim((string)$rows[$mapped_role]['contact_details']) === '') {
                $rows[$mapped_role]['contact_details'] = (string)$row['contact_details'];
            }
            if (!empty($row['date_started_with_team']) && trim((string)$rows[$mapped_role]['date_started_with_team']) === '') {
                $rows[$mapped_role]['date_started_with_team'] = (string)$row['date_started_with_team'];
            }
        }

        if ($legacy_json && json_decode($legacy_json, true)) {
            foreach (json_decode($legacy_json, true) as $member) {
                $mapped_role = itp_map_legacy_role((string)($member['role'] ?? ''));
                if (!isset($rows[$mapped_role])) {
                    continue;
                }
                $existing_name = trim((string)$rows[$mapped_role]['name']);
                $incoming_name = trim((string)($member['name'] ?? ''));
                if ($incoming_name !== '') {
                    $rows[$mapped_role]['name'] = $existing_name === '' ? $incoming_name : ($existing_name . '; ' . $incoming_name);
                }
            }
        }

        return array_values($rows);
    }
}

if (!function_exists('itp_prefill_goal_rows')) {
    function itp_prefill_goal_rows(array $goal_rows, array $legacy_rows = []): array {
        if (!empty($goal_rows)) {
            return $goal_rows;
        }

        $mapped = [];
        foreach ($legacy_rows as $row) {
            $activities = trim(implode("\n", array_filter([
                trim((string)($row['work_opportunities'] ?? '')),
                trim((string)($row['interventions'] ?? '')),
            ])));
            $remarks = trim(implode("\n", array_filter([
                trim((string)($row['remarks'] ?? '')),
                !empty($row['status']) ? 'Status: ' . trim((string)$row['status']) : '',
            ])));
            $mapped[] = [
                'competency' => (string)($row['interest'] ?? ''),
                'activities' => $activities,
                'time_frame' => (string)($row['timeline'] ?? ''),
                'persons_responsible' => (string)($row['responsible'] ?? ''),
                'remarks' => $remarks,
                'sort_order' => count($mapped),
            ];
        }
        return $mapped;
    }
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

$stmt = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level, e.grade_level, e.primary_diagnosis,
           e.previous_school_name, e.years_in_school, e.current_level_of_education, e.assessment_mode, e.assessment_institution,
           s.school_name, s.address AS school_address, s.district,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle, g.extension_name AS g_ext,
           g.contact_number AS g_contact, g.house_no_street AS g_house, g.barangay AS g_barangay,
           lg.full_name AS primary_contact_name, lg.contact_number AS primary_contact_number,
           lg.email AS primary_contact_email, lg.address AS primary_contact_address
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    LEFT JOIN learner_guardians lg ON lg.id = (
        SELECT id
        FROM learner_guardians
        WHERE lrn = l.lrn AND is_primary_contact = 1
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    )
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

if (!check_teacher_learner_access($conn, $lrn, true)) {
    header("Location: 403.php");
    exit();
}

$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
$school_year = $_GET['school_year'] ?? $learner['school_year'] ?? $current_sy;

// Check if IEP exists for this learner in the selected school year
$stmt_iep_check = $conn->prepare("SELECT id FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? LIMIT 1");
$stmt_iep_check->bind_param("ss", $lrn, $school_year);
$stmt_iep_check->execute();
$iep_exists = (bool)$stmt_iep_check->get_result()->fetch_assoc();

if (!$iep_exists) {
    $error_msg = "An Individualized Education Program (IEP) must be created and saved first before you can fill out the Individual Transition Plan (ITP) for school year " . $school_year . ".";
    header("Location: viewLearner.php?id=" . urlencode($lrn) . "&msg=" . urlencode($error_msg) . "&msg_type=danger&msg_title=" . urlencode("IEP Required"));
    exit();
}

$entry_options = itp_entry_point_options();
$photo_src = itp_find_photo_src($lrn);

$stmt_prev_school = $conn->prepare("
    SELECT s.school_name
    FROM enrollments e
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE e.lrn = ? AND e.is_deleted = 0 AND e.id <> ?
    ORDER BY e.is_historical DESC, e.school_year DESC, e.id DESC
    LIMIT 1
");
$current_enrollment_id = (int)($learner['enrollment_id'] ?? 0);
$stmt_prev_school->bind_param("si", $lrn, $current_enrollment_id);
$stmt_prev_school->execute();
$previous_school = $stmt_prev_school->get_result()->fetch_assoc();

$stmt_years = $conn->prepare("SELECT COUNT(DISTINCT school_year) AS years_count FROM enrollments WHERE lrn = ? AND is_deleted = 0");
$stmt_years->bind_param("s", $lrn);
$stmt_years->execute();
$years_row = $stmt_years->get_result()->fetch_assoc();
$years_in_school_default = !empty($years_row['years_count']) ? (string)$years_row['years_count'] : '';

$stmt_assessment = $conn->prepare("SELECT COUNT(*) AS assessment_count FROM assessments WHERE lrn = ?");
$stmt_assessment->bind_param("s", $lrn);
$stmt_assessment->execute();
$assessment_row = $stmt_assessment->get_result()->fetch_assoc();
$assessment_mode_default = !empty($assessment_row['assessment_count']) ? 'With Assessment' : 'Without Assessment';

$principal_name = !empty($learner['school_id']) ? (get_current_principal($conn, (int)$learner['school_id']) ?? '') : '';
$prepared_by_default = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? '')));

$stmt_itp = $conn->prepare("SELECT * FROM learner_itp WHERE learner_id = ? AND school_year = ? LIMIT 1");
$stmt_itp->bind_param("ss", $lrn, $school_year);
$stmt_itp->execute();
$itp = $stmt_itp->get_result()->fetch_assoc();
$itp_is_new = !$itp;
$has_itp_team_table = itp_table_exists($conn, 'learner_itp_team_members');
$has_itp_goal_table = itp_table_exists($conn, 'learner_itp_goal_rows');

// Copy-forward from previous school year
$copy_from_sy = $_GET['copyFromSy'] ?? null;
$copy_applied = null;
if ($itp_is_new && $copy_from_sy && preg_match('/^\d{4}-\d{4}$/', $copy_from_sy) && $copy_from_sy !== $school_year) {
    $stmt_copy = $conn->prepare("SELECT * FROM learner_itp WHERE learner_id = ? AND school_year = ? LIMIT 1");
    $stmt_copy->bind_param("ss", $lrn, $copy_from_sy);
    $stmt_copy->execute();
    $source_itp = $stmt_copy->get_result()->fetch_assoc();
    if ($source_itp) {
        $copy_applied = $copy_from_sy;
        $source_itp_id = (int)$source_itp['id'];
        unset($source_itp['id'], $source_itp['created_at'], $source_itp['updated_at']);
        $source_itp['school_year'] = $school_year;
        $source_itp['plan_date'] = date('Y-m-d');
        $source_itp['review_date'] = null;
        $itp = $source_itp;

        if ($has_itp_team_table) {
            $stmt_t_copy = $conn->prepare("SELECT * FROM learner_itp_team_members WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
            if ($stmt_t_copy) {
                $stmt_t_copy->bind_param("i", $source_itp_id);
                $stmt_t_copy->execute();
                $team_rows = $stmt_t_copy->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }

        if ($has_itp_goal_table) {
            $stmt_g_copy = $conn->prepare("SELECT * FROM learner_itp_goal_rows WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
            if ($stmt_g_copy) {
                $stmt_g_copy->bind_param("i", $source_itp_id);
                $stmt_g_copy->execute();
                $goal_rows = $stmt_g_copy->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }

        $team_rows = itp_prefill_team_rows($team_rows, $source_itp['team_json'] ?? null);
        $goal_rows = itp_prefill_goal_rows($goal_rows, []);
    }
}

if (!$copy_applied) {
    $team_rows = [];
    $goal_rows = [];
}

if ($itp && !$copy_applied) {
    if ($has_itp_team_table) {
        $stmt_team = $conn->prepare("SELECT * FROM learner_itp_team_members WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
        if ($stmt_team) {
            $stmt_team->bind_param("i", $itp['id']);
            $stmt_team->execute();
            $team_rows = $stmt_team->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    if ($has_itp_goal_table) {
        $stmt_goal = $conn->prepare("SELECT * FROM learner_itp_goal_rows WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
        if ($stmt_goal) {
            $stmt_goal->bind_param("i", $itp['id']);
            $stmt_goal->execute();
            $goal_rows = $stmt_goal->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    $stmt_legacy_goal = $conn->prepare("SELECT * FROM learner_itp_plans WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt_legacy_goal->bind_param("i", $itp['id']);
    $stmt_legacy_goal->execute();
    $legacy_goal_rows = $stmt_legacy_goal->get_result()->fetch_all(MYSQLI_ASSOC);

    $team_rows = itp_prefill_team_rows($team_rows, $itp['team_json'] ?? null);
    $goal_rows = itp_prefill_goal_rows($goal_rows, $legacy_goal_rows);
} else {
    $itp = [
        'school_year' => $school_year,
        'plan_date' => date('Y-m-d'),
        'review_date' => null,
        'target_exit_date' => null,
        'parent_guardian_name' => itp_compose_guardian_name($learner),
        'current_level_of_education' => $learner['current_level_of_education'] ?: itp_compose_current_level($learner),
        'previous_school_name' => $learner['previous_school_name'] ?: ($previous_school['school_name'] ?? ''),
        'years_in_school' => $learner['years_in_school'] ?: $years_in_school_default,
        'religion' => $learner['religion'] ?? '',
        'current_address' => itp_compose_guardian_address($learner),
        'contact_number' => $learner['contact_number'] ?? ($learner['primary_contact_number'] ?? ($learner['g_contact'] ?? '')),
        'assessment_mode' => $learner['assessment_mode'] ?: $assessment_mode_default,
        'assessment_institution' => $learner['assessment_institution'] ?? '',
        'strengths' => '',
        'interests' => '',
        'talents' => '',
        'skills' => '',
        'needs' => '',
        'recommendations' => '',
        'transition_goal' => '',
        'entry_point_label' => null,
        'learning_packages' => '',
        'prepared_by_name' => $prepared_by_default,
        'prepared_by_title' => 'Teacher',
        'noted_by_name' => $principal_name,
        'noted_by_title' => 'School Principal/School Head',
    ];

    $stmt_prefill = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY school_year DESC, id DESC LIMIT 1");
    $stmt_prefill->bind_param("s", $lrn);
    $stmt_prefill->execute();
    $prefill_iep = $stmt_prefill->get_result()->fetch_assoc();

    if ($prefill_iep) {
        $itp['review_date'] = $prefill_iep['iep_review_date'] ?? null;
        $itp['strengths'] = $prefill_iep['academic_strengths'] ?? '';
        $itp['needs'] = $prefill_iep['academic_needs'] ?? '';
        $itp['recommendations'] = $prefill_iep['meeting_comments'] ?? '';
        $itp['entry_point_label'] = itp_existing_entry_label($prefill_iep, $entry_options);

        $stmt_prefill_rows = $conn->prepare("
            SELECT interest, work_opportunities, interventions, responsible, remarks
            FROM learner_iep_annex6_rows
            WHERE iep_id = ? AND section_type = 'transition'
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt_prefill_rows->bind_param("i", $prefill_iep['id']);
        $stmt_prefill_rows->execute();
        $prefill_rows = $stmt_prefill_rows->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($prefill_rows)) {
            $itp['transition_goal'] = $prefill_rows[0]['work_opportunities'] ?? '';
        }
        $goal_rows = itp_prefill_goal_rows([], $prefill_rows);
        $team_rows = itp_prefill_team_rows([], $prefill_iep['iep_team_json'] ?? null);
    } else {
        $stmt_fallback = $conn->prepare("
            SELECT transition_interests, transition_work_opportunities, transition_skills, review_date
            FROM learner_ilp
            WHERE learner_id = ? AND document_type = 'IEP'
            ORDER BY school_year DESC, id DESC LIMIT 1
        ");
        $stmt_fallback->bind_param("s", $lrn);
        $stmt_fallback->execute();
        $fallback_iep = $stmt_fallback->get_result()->fetch_assoc();
        if ($fallback_iep) {
            $itp['review_date'] = $fallback_iep['review_date'] ?? null;
            $itp['interests'] = $fallback_iep['transition_interests'] ?? '';
            $itp['transition_goal'] = $fallback_iep['transition_work_opportunities'] ?? '';
            $itp['needs'] = $fallback_iep['transition_skills'] ?? '';
        }
    }
}

$itp['parent_guardian_name'] = $itp['parent_guardian_name'] ?? itp_compose_guardian_name($learner);
$itp['current_level_of_education'] = $itp['current_level_of_education'] ?? ($learner['current_level_of_education'] ?: itp_compose_current_level($learner));
$itp['previous_school_name'] = $itp['previous_school_name'] ?? ($learner['previous_school_name'] ?: ($previous_school['school_name'] ?? ''));
$itp['years_in_school'] = $itp['years_in_school'] ?? ($learner['years_in_school'] ?: $years_in_school_default);
$itp['religion'] = $itp['religion'] ?? ($learner['religion'] ?? '');
$itp['current_address'] = $itp['current_address'] ?? itp_compose_guardian_address($learner);
$itp['contact_number'] = $itp['contact_number'] ?? ($learner['contact_number'] ?? ($learner['primary_contact_number'] ?? ($learner['g_contact'] ?? '')));
$itp['assessment_mode'] = $itp['assessment_mode'] ?? ($learner['assessment_mode'] ?: $assessment_mode_default);
$itp['assessment_institution'] = $itp['assessment_institution'] ?? ($learner['assessment_institution'] ?? '');
$itp['strengths'] = $itp['strengths'] ?? ($itp['learner_strengths'] ?? '');
$itp['interests'] = $itp['interests'] ?? ($itp['learner_preferences'] ?? '');
$itp['needs'] = $itp['needs'] ?? ($itp['transition_needs'] ?? '');
$itp['transition_goal'] = $itp['transition_goal'] ?? ($itp['post_school_goal'] ?? '');
$itp['recommendations'] = $itp['recommendations'] ?? trim(implode("\n", array_filter([
    $itp['family_supports'] ?? '',
    $itp['community_linkages'] ?? '',
    $itp['follow_up_notes'] ?? '',
])));
$itp['entry_point_label'] = $itp['entry_point_label'] ?? itp_existing_entry_label($itp, $entry_options);
$itp['prepared_by_name'] = $itp['prepared_by_name'] ?? $prepared_by_default;
$itp['prepared_by_title'] = $itp['prepared_by_title'] ?? 'Teacher';
$itp['noted_by_name'] = $itp['noted_by_name'] ?? $principal_name;
$itp['noted_by_title'] = $itp['noted_by_title'] ?? 'School Principal/School Head';

$team_rows = itp_prefill_team_rows($team_rows, $itp['team_json'] ?? null);
$guardian_contact_details = trim(implode(' / ', array_filter([
    $learner['primary_contact_number'] ?? ($learner['g_contact'] ?? ''),
    $learner['primary_contact_email'] ?? '',
])));
foreach ($team_rows as &$team_row) {
    if (($team_row['role'] ?? '') === 'Parent/Guardian') {
        $team_row['name'] = sned_first_non_empty($team_row['name'] ?? '', itp_compose_guardian_name($learner));
        $team_row['contact_details'] = sned_first_non_empty($team_row['contact_details'] ?? '', $guardian_contact_details);
    } elseif (($team_row['role'] ?? '') === 'School Head') {
        $team_row['name'] = sned_first_non_empty($team_row['name'] ?? '', $principal_name);
    } elseif (($team_row['role'] ?? '') === 'SPED Teacher') {
        $team_row['name'] = sned_first_non_empty($team_row['name'] ?? '', $prepared_by_default);
    }
}
unset($team_row);
$goal_rows = itp_prefill_goal_rows($goal_rows);

$minimum_goal_rows = max(1, count($goal_rows));
for ($i = count($goal_rows); $i < $minimum_goal_rows; $i++) {
    $goal_rows[] = [
        'competency' => '',
        'activities' => '',
        'time_frame' => '',
        'persons_responsible' => '',
        'remarks' => '',
        'sort_order' => $i,
    ];
}

$birthDate = new DateTime($learner['birthday']);
$today = new DateTime();
$age = $today->diff($birthDate)->y;
$gender = $learner['sex'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annex 5 ITP Editor — <?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name']) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        .matrix-table { font-size: 0.85rem; }
        .matrix-table th, .matrix-table td { text-align: center; vertical-align: middle; }
        .matrix-table td:first-child, .matrix-table th:first-child { text-align: left; }
        .matrix-note { font-size: 0.78rem; line-height: 1.2; }
    </style>
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
                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>&tab=itp" class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Profile</a>
                    <h4 class="fw-bold mb-0 d-none d-md-inline-block"><i class="bi bi-clipboard-pulse text-success me-2"></i> Individual Transition Plan</h4>
                </div>
                <div class="text-end small text-muted">
                    <strong><?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . ($learner['middle_name'] ?? '')) ?></strong><br>
                    LRN: <?= htmlspecialchars($lrn) ?> · Grade: <?= htmlspecialchars($learner['grade_level'] ?: '—') ?> · <?= $age ?> yrs old
                </div>
            </header>

            <div class="p-4 container-fluid mx-auto" style="max-width: 1100px;">
                <!-- Stepper Navigation -->
                <div class="step-progress-wrapper mb-4" id="stepperWrapper">
                    <div class="step-progress-item active" data-step="1" id="stepIndicator1">
                        <span class="step-progress-label">General &amp; Personal Info</span>
                    </div>
                    <div class="step-progress-item" data-step="2" id="stepIndicator2">
                        <span class="step-progress-label">Transition Team</span>
                    </div>
                    <div class="step-progress-item" data-step="3" id="stepIndicator3">
                        <span class="step-progress-label">Potentials &amp; Needs</span>
                    </div>
                    <div class="step-progress-item" data-step="4" id="stepIndicator4">
                        <span class="step-progress-label">Program Matrix</span>
                    </div>
                    <div class="step-progress-item" data-step="5" id="stepIndicator5">
                        <span class="step-progress-label">Goal Plan</span>
                    </div>
                </div>

                <form id="itpForm" action="saveITP.php" method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="learner_id" value="<?= htmlspecialchars($lrn) ?>">
                    <input type="hidden" name="enrollment_id" value="<?= htmlspecialchars($learner['enrollment_id'] ?? '') ?>">
                    <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">

                    <?php $prev_sy = sned_previous_school_year($school_year); ?>
                    <?php if ($itp_is_new && !$copy_applied && $prev_sy && empty($_GET['copyFromSy'])): ?>
                    <div class="alert alert-info border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-copy text-info me-2" style="font-size: 1.5rem;"></i>
                            <strong>Start fresh or copy forward?</strong>
                            <span class="ms-2 text-muted">No ITP record exists for SY <?= htmlspecialchars($school_year) ?>.</span>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="itp_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($school_year) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">Start blank</a>
                            <a href="itp_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($school_year) ?>&copyFromSy=<?= urlencode($prev_sy) ?>" class="btn btn-info btn-sm rounded-pill px-3 text-white"><i class="bi bi-copy me-1"></i> Copy from SY <?= htmlspecialchars($prev_sy) ?></a>
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

                    <div class="tab-content" id="itpTabs">
                    <!-- ==================== STEP 1: Personal & General Info ==================== -->
                    <div class="tab-pane fade show active" id="sec1" role="tabpanel">
                        <!-- Collapsible Instructions -->
                        <div class="card rounded-4 border-0 bg-light p-3 mb-4 shadow-sm">
                            <div class="d-flex justify-content-between align-items-center cursor-pointer" data-bs-toggle="collapse" data-bs-target="#itpInstructionsCollapse" aria-expanded="false" style="cursor: pointer;">
                                <h6 class="fw-bold text-primary mb-0"><i class="bi bi-info-circle-fill me-2"></i> How to use the Individual Transition Plan Form</h6>
                                <i class="bi bi-chevron-down text-primary"></i>
                            </div>
                            <div class="collapse mt-3" id="itpInstructionsCollapse">
                                <div class="row g-3">
                                    <?php foreach (itp_instruction_sections() as $heading => $text): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="p-3 bg-white rounded-3 border h-100">
                                                <div class="fw-bold text-secondary mb-1"><?= htmlspecialchars($heading) ?></div>
                                                <div class="small text-muted" style="text-align: justify;"><?= htmlspecialchars($text) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Date Inputs & School Snapshot -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4 bg-light border">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-calendar-check me-2"></i>Plan Dates &amp; Metadata</h5>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Plan Date</label>
                                    <input type="date" name="plan_date" class="form-control rounded-3" value="<?= htmlspecialchars($itp['plan_date'] ?? date('Y-m-d')) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Review Date</label>
                                    <input type="date" name="review_date" class="form-control rounded-3" value="<?= htmlspecialchars($itp['review_date'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Target Exit Date</label>
                                    <input type="date" name="target_exit_date" class="form-control rounded-3" value="<?= htmlspecialchars($itp['target_exit_date'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Personal Info (Read Only) & Transition Demographics -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-person-badge me-2"></i>I. Personal Information</h5>

                            <div class="row g-3 bg-light p-3 rounded-4 mb-4 border">
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Learner Name</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . ($learner['middle_name'] ?? '')) ?>" readonly>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted">Date of Birth</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars(date('F d, Y', strtotime($learner['birthday']))) ?>" readonly>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted">LRN</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars($lrn) ?>" readonly>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted">Gender</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars($learner['sex'] ?? '—') ?>" readonly>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted">Exceptionality</label>
                                    <input type="text" class="form-control rounded-3 bg-white" value="<?= htmlspecialchars($learner['primary_diagnosis'] ?? '—') ?>" readonly>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Name of Parents/Guardian</label>
                                    <input type="text" name="parent_guardian_name" class="form-control rounded-3" value="<?= htmlspecialchars($itp['parent_guardian_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Current Level of Education</label>
                                    <input type="text" name="current_level_of_education" class="form-control rounded-3" value="<?= htmlspecialchars($itp['current_level_of_education'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Name of Previous School (if applicable)</label>
                                    <input type="text" name="previous_school_name" class="form-control rounded-3" value="<?= htmlspecialchars($itp['previous_school_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">No. of Years in School</label>
                                    <input type="text" name="years_in_school" class="form-control rounded-3" value="<?= htmlspecialchars($itp['years_in_school'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Religion</label>
                                    <input type="text" name="religion" class="form-control rounded-3" value="<?= htmlspecialchars($itp['religion'] ?? '') ?>">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small">Current Address</label>
                                    <input type="text" name="current_address" class="form-control rounded-3" value="<?= htmlspecialchars($itp['current_address'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Phone/Contact No.</label>
                                    <input type="text" name="contact_number" class="form-control rounded-3" value="<?= htmlspecialchars($itp['contact_number'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Assessment Information -->
                        <div class="sned-card mb-4">
                            <div class="sned-card-header">
                                <h5><i class="bi bi-file-earmark-medical text-success"></i> Assessment Status</h5>
                            </div>
                            <div class="sned-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small d-block">Learner has formal Assessment?</label>
                                        <div class="d-flex gap-3">
                                            <div class="tile-radio-wrapper flex-grow-1">
                                                <input type="radio" name="assessment_mode" id="assess_yes" value="With Assessment" <?= ($itp['assessment_mode'] ?? '') === 'With Assessment' ? 'checked' : '' ?>>
                                                <label class="tile-radio-label" for="assess_yes">
                                                    <span class="tile-checkbox-icon-unchecked"><i class="bi bi-circle me-2 text-muted"></i></span>
                                                    <span class="tile-checkbox-icon-checked"><i class="bi bi-check-circle-fill me-2 text-primary"></i></span>
                                                    <span>Yes (With Assessment)</span>
                                                </label>
                                            </div>
                                            <div class="tile-radio-wrapper flex-grow-1">
                                                <input type="radio" name="assessment_mode" id="assess_no" value="Without Assessment" <?= ($itp['assessment_mode'] ?? '') === 'Without Assessment' ? 'checked' : '' ?>>
                                                <label class="tile-radio-label" for="assess_no">
                                                    <span class="tile-checkbox-icon-unchecked"><i class="bi bi-circle me-2 text-muted"></i></span>
                                                    <span class="tile-checkbox-icon-checked"><i class="bi bi-check-circle-fill me-2 text-primary"></i></span>
                                                    <span>No (Without Assessment)</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Name of Institution (if assessed)</label>
                                        <input type="text" name="assessment_institution" class="form-control" placeholder="e.g. Valenzuela City Child Hope Center" value="<?= htmlspecialchars($itp['assessment_institution'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Point of Entry -->
                        <div class="sned-card mb-4">
                            <div class="sned-card-header">
                                <h5><i class="bi bi-box-arrow-in-right text-success"></i> Point of Entry</h5>
                            </div>
                            <div class="sned-card-body">
                                <p class="small text-muted mb-3">Please check the point of entry appropriate to the status of the learner.</p>
                                <div class="checkbox-tile-grid">
                                    <?php foreach ($entry_options as $field => $label): ?>
                                        <div class="tile-radio-wrapper">
                                            <input type="radio" name="entry_point_label" id="entry_<?= $field ?>" value="<?= htmlspecialchars($label) ?>" <?= ($itp['entry_point_label'] ?? '') === $label ? 'checked' : '' ?>>
                                            <label class="tile-radio-label h-100" for="entry_<?= $field ?>">
                                                <span class="tile-checkbox-icon-unchecked"><i class="bi bi-circle me-2 text-muted"></i></span>
                                                <span class="tile-checkbox-icon-checked"><i class="bi bi-check-circle-fill me-2 text-primary"></i></span>
                                                <span><?= htmlspecialchars($label) ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(2)">Next: Transition Team <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- ==================== STEP 2: Transition Team ==================== -->
                    <div class="tab-pane fade" id="sec2" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-people-fill me-2"></i>II. Transition Team Members</h5>
                            <p class="small text-muted mb-3">Ensure details for all necessary stakeholders are updated below.</p>

                            <div class="table-responsive">
                                <table class="table table-hover table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:25%;">Team Member Role</th>
                                            <th style="width:30%;">Full Name</th>
                                            <th style="width:25%;">Contact Details (Email / Phone)</th>
                                            <th style="width:20%;">Date Started Working with Team</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($team_rows as $index => $team_row): ?>
                                            <tr>
                                                <td class="fw-bold text-secondary small">
                                                    <?= htmlspecialchars($team_row['role']) ?>
                                                    <input type="hidden" name="team_rows[<?= $index ?>][role]" value="<?= htmlspecialchars($team_row['role']) ?>">
                                                </td>
                                                <td>
                                                    <input type="text" name="team_rows[<?= $index ?>][name]" class="form-control form-control-sm rounded-2" placeholder="Full name" value="<?= htmlspecialchars($team_row['name'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="text" name="team_rows[<?= $index ?>][contact_details]" class="form-control form-control-sm rounded-2" placeholder="Contact Details" value="<?= htmlspecialchars($team_row['contact_details'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="date" name="team_rows[<?= $index ?>][date_started_with_team]" class="form-control form-control-sm rounded-2" value="<?= htmlspecialchars($team_row['date_started_with_team'] ?? '') ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="goToStep(1)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                            <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(3)">Next: Potentials &amp; Needs <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- ==================== STEP 3: Potentials, Needs & Recommendations ==================== -->
                    <div class="tab-pane fade" id="sec3" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-card-checklist me-2"></i>Observed Potentials &amp; Needs</h5>
                            <p class="small text-muted mb-3">List down learner's current strengths, interests, talents, and skills as observed, along with identified needs.</p>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-success"><i class="bi bi-graph-up-arrow me-1"></i> Strengths</label>
                                    <input type="text" name="strengths" class="form-control rounded-3" placeholder="e.g. Visual learner, good helper" value="<?= htmlspecialchars($itp['strengths'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-info"><i class="bi bi-heart-fill me-1"></i> Interests</label>
                                    <input type="text" name="interests" class="form-control rounded-3" placeholder="e.g. Drawing, playing bells, gardening" value="<?= htmlspecialchars($itp['interests'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-warning"><i class="bi bi-star-fill me-1"></i> Talents</label>
                                    <input type="text" name="talents" class="form-control rounded-3" placeholder="e.g. Painting, singing, running" value="<?= htmlspecialchars($itp['talents'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-primary"><i class="bi bi-wrench-adjustable-circle me-1"></i> Skills</label>
                                    <input type="text" name="skills" class="form-control rounded-3" placeholder="e.g. Fine motor skills, basic sorting" value="<?= htmlspecialchars($itp['skills'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Needs</label>
                                    <input type="text" name="needs" class="form-control rounded-3" placeholder="e.g. Speech therapy support, daily life scheduling" value="<?= htmlspecialchars($itp['needs'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-chat-left-text me-2"></i>Recommendations</h5>
                            <p class="small text-muted mb-3">Give recommendations based on the learner's potentials and needs. These will sync directly to the Goal Plan recommendations block.</p>
                            <textarea name="recommendations" id="recommendationsField" class="form-control rounded-4" rows="5" placeholder="Enter general recommendations..."><?= htmlspecialchars($itp['recommendations'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="goToStep(2)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                            <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(4)">Next: Program Matrix <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- ==================== STEP 4: Transition Program Matrix ==================== -->
                    <div class="tab-pane fade" id="sec4" role="tabpanel">
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-grid-3x3-gap me-2"></i>Transition Program Matrix Guide</h5>
                            <p class="small text-muted mb-4">Reference table showing options for provision of specific transition program to targeted levels of entry / exit points.</p>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle matrix-table">
                                    <thead class="table-light text-center small text-uppercase">
                                        <tr>
                                            <th style="width:30%;">Type of Learner (Entry/Exit Point)</th>
                                            <th>Functional Academics</th>
                                            <th>Pre-Vocational</th>
                                            <th>Life Skills</th>
                                            <th>Enrichment</th>
                                            <th>Livelihood</th>
                                            <th>Care Skills</th>
                                            <th>Career Skills</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (itp_transition_matrix() as $matrix_row): ?>
                                            <tr>
                                                <td class="fw-semibold text-secondary"><?= htmlspecialchars($matrix_row['label']) ?></td>
                                                <?php foreach ($matrix_row['cells'] as $cell): ?>
                                                    <td>
                                                        <?php if ($cell === 'check'): ?>
                                                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                                        <?php elseif ($cell !== ''): ?>
                                                            <span class="matrix-note d-block text-muted"><?= htmlspecialchars($cell) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="goToStep(3)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                            <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="goToStep(5)">Next: Goal Plan <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- ==================== STEP 5: Goal Plan & Signatures ==================== -->
                    <div class="tab-pane fade" id="sec5" role="tabpanel">
                        <!-- Transition Goal & Packages -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-bookmark-star me-2"></i>Transition Goal &amp; Learning Packages</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Transition Goal</label>
                                    <input type="text" name="transition_goal" class="form-control rounded-3" placeholder="e.g. Independent livelihood, inclusion classes" value="<?= htmlspecialchars($itp['transition_goal'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Entry Point (Mirrored)</label>
                                    <input type="text" id="entryPointMirror" class="form-control rounded-3 bg-light" value="<?= htmlspecialchars($itp['entry_point_label'] ?? '') ?>" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Learning Package/s</label>
                                    <input type="text" name="learning_packages" class="form-control rounded-3" placeholder="e.g. Package A, Package B" value="<?= htmlspecialchars($itp['learning_packages'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic Goal Cards -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-primary mb-0"><i class="bi bi-list-task me-2"></i>Individual Transition Goal Plan Checklist</h5>
                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" onclick="addGoalRow()"><i class="bi bi-plus-lg me-1"></i> Add Goal</button>
                            </div>
                            <div id="goalPlanCards">
                                <?php foreach ($goal_rows as $index => $goal_row): ?>
                                <div class="card border rounded-3 mb-3 goal-plan-card">
                                    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                        <span class="fw-bold small text-secondary">Goal #<?= $index + 1 ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.goal-plan-card').remove()"><i class="bi bi-trash"></i></button>
                                    </div>
                                    <div class="card-body p-3">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Competency <span class="text-danger">*</span></label>
                                                <textarea name="goal_rows[<?= $index ?>][competency]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe the competency area"></textarea>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold small">Activities <span class="text-danger">*</span></label>
                                                <textarea name="goal_rows[<?= $index ?>][activities]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe the activities"></textarea>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold small">Time Frame <span class="text-danger">*</span></label>
                                                <select name="goal_rows[<?= $index ?>][time_frame]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select --</option>
                                                    <option value="1st Term" <?= ($goal_row['time_frame'] ?? '') === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                                                    <option value="2nd Term" <?= ($goal_row['time_frame'] ?? '') === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                                                    <option value="3rd Term" <?= ($goal_row['time_frame'] ?? '') === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
                                                    <option value="Whole Term" <?= ($goal_row['time_frame'] ?? '') === 'Whole Term' ? 'selected' : '' ?>>Whole Term</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold small">Persons Responsible <span class="text-danger">*</span></label>
                                                <textarea name="goal_rows[<?= $index ?>][persons_responsible]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Who is responsible?"></textarea>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-bold small">Remarks</label>
                                                <select name="goal_rows[<?= $index ?>][remarks]" class="form-select form-select-sm rounded-2">
                                                    <option value="">-- Select --</option>
                                                    <option value="Advancing (A)" <?= ($goal_row['remarks'] ?? '') === 'Advancing (A)' ? 'selected' : '' ?>>Advancing (A)</option>
                                                    <option value="Benchmarking (B)" <?= ($goal_row['remarks'] ?? '') === 'Benchmarking (B)' ? 'selected' : '' ?>>Benchmarking (B)</option>
                                                    <option value="Connecting (C)" <?= ($goal_row['remarks'] ?? '') === 'Connecting (C)' ? 'selected' : '' ?>>Connecting (C)</option>
                                                    <option value="Developing (D)" <?= ($goal_row['remarks'] ?? '') === 'Developing (D)' ? 'selected' : '' ?>>Developing (D)</option>
                                                    <option value="Emerging (E)" <?= ($goal_row['remarks'] ?? '') === 'Emerging (E)' ? 'selected' : '' ?>>Emerging (E)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted mt-2">(This will serve as the Lesson Plan of the Teacher.)</div>
                        </div>

                        <!-- Recommendations Mirror -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <h5 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="bi bi-chat-left-text me-2"></i>Recommendations (Mirrored)</h5>
                            <textarea id="recommendationsMirror" class="form-control rounded-4" rows="4" placeholder="Synced recommendations..."><?= htmlspecialchars($itp['recommendations'] ?? '') ?></textarea>
                        </div>

                        <!-- Signatures -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4 bg-light border">
                            <h5 class="fw-bold text-primary mb-4 border-bottom pb-2"><i class="bi bi-pencil-square me-2"></i>Signatures</h5>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="p-3 bg-white rounded-3 border">
                                        <label class="form-label fw-bold small text-secondary">Prepared by (Name)</label>
                                        <input type="text" name="prepared_by_name" class="form-control rounded-3 mb-3" placeholder="Prepared by Name" value="<?= htmlspecialchars($itp['prepared_by_name'] ?? '') ?>">
                                        <label class="form-label fw-bold small text-secondary">Title/Designation</label>
                                        <input type="text" name="prepared_by_title" class="form-control rounded-3" placeholder="Designation" value="<?= htmlspecialchars($itp['prepared_by_title'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-white rounded-3 border">
                                        <label class="form-label fw-bold small text-secondary">Noted by (Name)</label>
                                        <input type="text" name="noted_by_name" class="form-control rounded-3 mb-3" placeholder="Noted by Name" value="<?= htmlspecialchars($itp['noted_by_name'] ?? '') ?>">
                                        <label class="form-label fw-bold small text-secondary">Title/Designation</label>
                                        <input type="text" name="noted_by_title" class="form-control rounded-3" placeholder="Designation" value="<?= htmlspecialchars($itp['noted_by_title'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions bar -->
                        <div class="card rounded-4 shadow-sm border-0 p-4 mb-4">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="text-muted small">
                                    Ensure all transition goal competency items and signatures are correct before saving.
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="goToStep(4)"><i class="bi bi-arrow-left me-1"></i> Previous</button>
                                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>&tab=itp" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
                                    <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Save ITP Record</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const totalSteps = 5;
        let currentStep = 1;
        let goalRowIndex = <?= count($goal_rows) ?>;

        const recommendationsField = document.getElementById('recommendationsField');
        const recommendationsMirror = document.getElementById('recommendationsMirror');
        const entryPointMirror = document.getElementById('entryPointMirror');

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
            // Scroll step container into view
            document.getElementById('stepperWrapper').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function syncRecommendations(source, target) {
            if (!source || !target) return;
            target.value = source.value;
        }

        function syncEntryPoint() {
            if (!entryPointMirror) return;
            const checked = document.querySelector('input[name="entry_point_label"]:checked');
            entryPointMirror.value = checked ? checked.value : '';
        }

        function addGoalRow() {
            const container = document.getElementById('goalPlanCards');
            const cards = container.querySelectorAll('.goal-plan-card');
            const idx = goalRowIndex++;
            const card = document.createElement('div');
            card.className = 'card border rounded-3 mb-3 goal-plan-card';
            card.innerHTML = `
                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                    <span class="fw-bold small text-secondary">Goal #${cards.length + 1}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.goal-plan-card').remove(); renumberGoalCards();"><i class="bi bi-trash"></i></button>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Competency <span class="text-danger">*</span></label>
                            <textarea name="goal_rows[${idx}][competency]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe the competency area"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Activities <span class="text-danger">*</span></label>
                            <textarea name="goal_rows[${idx}][activities]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Describe the activities"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Time Frame <span class="text-danger">*</span></label>
                            <select name="goal_rows[${idx}][time_frame]" class="form-select form-select-sm rounded-2">
                                <option value="">-- Select --</option>
                                <option value="1st Term">1st Term</option>
                                <option value="2nd Term">2nd Term</option>
                                <option value="3rd Term">3rd Term</option>
                                <option value="Whole Term">Whole Term</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Persons Responsible <span class="text-danger">*</span></label>
                            <textarea name="goal_rows[${idx}][persons_responsible]" class="form-control form-control-sm rounded-2" rows="2" placeholder="Who is responsible?"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Remarks</label>
                            <select name="goal_rows[${idx}][remarks]" class="form-select form-select-sm rounded-2">
                                <option value="">-- Select --</option>
                                <option value="Advancing (A)">Advancing (A)</option>
                                <option value="Benchmarking (B)">Benchmarking (B)</option>
                                <option value="Connecting (C)">Connecting (C)</option>
                                <option value="Developing (D)">Developing (D)</option>
                                <option value="Emerging (E)">Emerging (E)</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(card);
            renumberGoalCards();
        }

        function renumberGoalCards() {
            document.querySelectorAll('#goalPlanCards .goal-plan-card').forEach((card, i) => {
                const header = card.querySelector('.card-header span');
                if (header) header.textContent = 'Goal #' + (i + 1);
            });
        }

        if (recommendationsField && recommendationsMirror) {
            recommendationsField.addEventListener('input', () => syncRecommendations(recommendationsField, recommendationsMirror));
            recommendationsMirror.addEventListener('input', () => syncRecommendations(recommendationsMirror, recommendationsField));
        }

        document.querySelectorAll('input[name="entry_point_label"]').forEach((radio) => {
            radio.addEventListener('change', syncEntryPoint);
        });

        syncEntryPoint();

        // Show server-side error popup on redirect
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === 'goal_required') {
            setTimeout(() => {
                showSystemNotice('At least one complete goal row is required.\n\nFill in Competency, Activities, Time Frame, and Persons Responsible for at least one goal before saving.', 'Goal Required', 'warning');
                goToStep(5);
            }, 400);
        }

        // Client-side validation for goal rows
        document.getElementById('itpForm').addEventListener('submit', function(e) {
            const cards = document.querySelectorAll('.goal-plan-card');
            let firstIncomplete = null;
            const errors = [];
            cards.forEach((card, idx) => {
                card.classList.remove('border-danger');
                const competency = card.querySelector('textarea[name*="[competency]"]');
                const activities = card.querySelector('textarea[name*="[activities]"]');
                const timeFrame = card.querySelector('select[name*="[time_frame]"]');
                const personsResponsible = card.querySelector('textarea[name*="[persons_responsible]"]');
                const hasAny = (competency?.value.trim() || activities?.value.trim() || timeFrame?.value || personsResponsible?.value.trim());
                if (hasAny) {
                    const missing = [];
                    if (!competency?.value.trim()) missing.push('Competency');
                    if (!activities?.value.trim()) missing.push('Activities');
                    if (!timeFrame?.value) missing.push('Time Frame');
                    if (!personsResponsible?.value.trim()) missing.push('Persons Responsible');
                    if (missing.length > 0) {
                        card.classList.add('border-danger');
                        errors.push('Goal #' + (idx + 1) + ': Missing ' + missing.join(', '));
                        if (!firstIncomplete) firstIncomplete = competency || activities || timeFrame || personsResponsible;
                    }
                }
            });
            if (errors.length > 0) {
                e.preventDefault();
                showSystemNotice(errors.length + ' incomplete goal row' + (errors.length > 1 ? 's' : '') + ':\n\n' + errors.join('\n') + '\n\nPlease fill in all required fields before saving.', 'Goals Incomplete', 'warning');
                if (firstIncomplete) {
                    document.getElementById('sec5').classList.add('show', 'active');
                    document.querySelectorAll('.tab-pane').forEach(p => { if (p.id !== 'sec5') p.classList.remove('show', 'active'); });
                    document.querySelectorAll('.step-progress-item').forEach((el, i) => {
                        el.classList.remove('active', 'completed');
                        if (i + 1 === 5) el.classList.add('active');
                        else if (i + 1 < 5) el.classList.add('completed');
                    });
                    currentStep = 5;
                    setTimeout(() => { firstIncomplete.focus(); firstIncomplete.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 300);
                }
                return;
            }

            // Also check at least one fully complete row exists
            let hasComplete = false;
            cards.forEach(card => {
                const competency = card.querySelector('textarea[name*="[competency]"]');
                const activities = card.querySelector('textarea[name*="[activities]"]');
                const timeFrame = card.querySelector('select[name*="[time_frame]"]');
                const personsResponsible = card.querySelector('textarea[name*="[persons_responsible]"]');
                if (competency?.value.trim() && activities?.value.trim() && timeFrame?.value && personsResponsible?.value.trim()) {
                    hasComplete = true;
                }
            });
            if (!hasComplete) {
                e.preventDefault();
                showSystemNotice('At least one complete goal row is required. Fill in Competency, Activities, Time Frame, and Persons Responsible for at least one goal.', 'Goals Required', 'warning');
                return;
            }
        });

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
