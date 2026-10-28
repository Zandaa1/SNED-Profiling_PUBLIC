<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
require_once 'includes/helpers.php';
require_once 'includes/itp_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!function_exists('itp_print_guardian_name')) {
    function itp_print_guardian_name(array $learner): string {
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

if (!function_exists('itp_print_address')) {
    function itp_print_address(array $learner): string {
        if (!empty($learner['primary_contact_address'])) {
            return (string)$learner['primary_contact_address'];
        }
        return implode(', ', array_filter([
            trim((string)($learner['g_house'] ?? '')),
            trim((string)($learner['g_barangay'] ?? '')),
        ]));
    }
}

if (!function_exists('itp_print_value')) {
    function itp_print_value(?string $value, string $fallback = '__________'): string {
        $value = trim((string)$value);
        return $value !== '' ? htmlspecialchars($value) : $fallback;
    }
}

if (!function_exists('itp_print_entry_label')) {
    function itp_print_entry_label(array $itp, array $entry_options): ?string {
        if (!empty($itp['entry_point_label'])) {
            return (string)$itp['entry_point_label'];
        }
        foreach ($entry_options as $field => $label) {
            if (!empty($itp[$field])) {
                return $label;
            }
        }
        return null;
    }
}

if (!function_exists('itp_print_team_rows')) {
    function itp_print_team_rows(array $stored_rows, ?string $legacy_json = null): array {
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

        foreach ($stored_rows as $row) {
            $mapped = itp_map_legacy_role((string)($row['role'] ?? ''));
            if (!isset($rows[$mapped])) {
                continue;
            }
            $incoming_name = trim((string)($row['name'] ?? ''));
            if ($incoming_name !== '') {
                $rows[$mapped]['name'] = trim($rows[$mapped]['name']) === '' ? $incoming_name : ($rows[$mapped]['name'] . '; ' . $incoming_name);
            }
            if (!empty($row['contact_details']) && trim((string)$rows[$mapped]['contact_details']) === '') {
                $rows[$mapped]['contact_details'] = (string)$row['contact_details'];
            }
            if (!empty($row['date_started_with_team']) && trim((string)$rows[$mapped]['date_started_with_team']) === '') {
                $rows[$mapped]['date_started_with_team'] = (string)$row['date_started_with_team'];
            }
        }

        if ($legacy_json && json_decode($legacy_json, true)) {
            foreach (json_decode($legacy_json, true) as $member) {
                $mapped = itp_map_legacy_role((string)($member['role'] ?? ''));
                if (!isset($rows[$mapped])) {
                    continue;
                }
                $incoming_name = trim((string)($member['name'] ?? ''));
                if ($incoming_name !== '') {
                    $rows[$mapped]['name'] = trim($rows[$mapped]['name']) === '' ? $incoming_name : ($rows[$mapped]['name'] . '; ' . $incoming_name);
                }
            }
        }

        return array_values($rows);
    }
}

if (!function_exists('itp_print_goal_rows')) {
    function itp_print_goal_rows(array $stored_rows, array $legacy_rows = []): array {
        if (!empty($stored_rows)) {
            return $stored_rows;
        }

        $rows = [];
        foreach ($legacy_rows as $row) {
            $rows[] = [
                'competency' => (string)($row['interest'] ?? ''),
                'activities' => trim(implode("\n", array_filter([
                    trim((string)($row['work_opportunities'] ?? '')),
                    trim((string)($row['interventions'] ?? '')),
                ]))),
                'time_frame' => (string)($row['timeline'] ?? ''),
                'persons_responsible' => (string)($row['responsible'] ?? ''),
                'remarks' => trim(implode("\n", array_filter([
                    trim((string)($row['remarks'] ?? '')),
                    !empty($row['status']) ? 'Status: ' . trim((string)$row['status']) : '',
                ]))),
                'sort_order' => count($rows),
            ];
        }
        return $rows;
    }
}

$lrn = $_GET['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner LRN.");
}

$user_role = $_SESSION['role'] ?? 'user';
$session_school_id = $_SESSION['school_id'] ?? 0;

$stmt = $conn->prepare("
    SELECT l.*, e.school_id, e.grade_level, e.placement_type, e.self_contained_level, e.id AS enrollment_id, e.school_year, e.primary_diagnosis,
           s.school_name, s.district,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle, g.extension_name AS g_ext,
           g.contact_number AS g_contact, g.house_no_street AS g_house, g.barangay AS g_barangay,
           lg.full_name AS primary_contact_name, lg.contact_number AS primary_contact_number, lg.address AS primary_contact_address
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    LEFT JOIN learner_guardians lg ON l.lrn = lg.lrn AND lg.is_primary_contact = 1
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

$learner_disabilities = sned_get_enrollment_disabilities($conn, isset($learner['enrollment_id']) ? (int)$learner['enrollment_id'] : null);
if (empty($learner_disabilities) && !empty($learner['primary_diagnosis'])) {
    $learner_disabilities = sned_disability_rows_from_legacy_diagnosis($learner['primary_diagnosis']);
}
$learner_disability_summary = sned_disability_summary($learner_disabilities, $learner['primary_diagnosis'] ?: 'Unassigned');

if (user_is_scoped($user_role) && (int)$learner['school_id'] !== (int)$session_school_id) {
    if (!check_teacher_learner_access($conn, $lrn)) {
        header("Location: 403.php");
        exit();
    }
}

$school_year = $_GET['school_year'] ?? $learner['school_year'] ?? '';
$entry_options = itp_entry_point_options();
$photo_src = itp_find_photo_src($lrn);

$stmt_itp = $conn->prepare("SELECT * FROM learner_itp WHERE learner_id = ? AND school_year = ? LIMIT 1");
$stmt_itp->bind_param("ss", $lrn, $school_year);
$stmt_itp->execute();
$itp = $stmt_itp->get_result()->fetch_assoc();

if (!$itp) {
    die("No Individual Transition Plan on file for LRN: " . htmlspecialchars($lrn) . " in SY: " . htmlspecialchars($school_year));
}

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

$team_rows = [];
$goal_rows = [];

if (itp_table_exists($conn, 'learner_itp_team_members')) {
    $stmt_team = $conn->prepare("SELECT * FROM learner_itp_team_members WHERE itp_id = ? ORDER BY sort_order ASC, id ASC");
    if ($stmt_team) {
        $stmt_team->bind_param("i", $itp['id']);
        $stmt_team->execute();
        $team_rows = $stmt_team->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (itp_table_exists($conn, 'learner_itp_goal_rows')) {
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

$team_rows = itp_print_team_rows($team_rows, $itp['team_json'] ?? null);
$goal_rows = itp_print_goal_rows($goal_rows, $legacy_goal_rows);
$goal_row_count = max(6, count($goal_rows));
for ($i = count($goal_rows); $i < $goal_row_count; $i++) {
    $goal_rows[] = [
        'competency' => '',
        'activities' => '',
        'time_frame' => '',
        'persons_responsible' => '',
        'remarks' => '',
    ];
}

$itp['parent_guardian_name'] = itp_record_value($itp, ['parent_guardian_name'], itp_print_guardian_name($learner));
$itp['current_level_of_education'] = itp_record_value($itp, ['current_level_of_education'], trim(implode(' / ', array_filter([
    $learner['grade_level'] ?? '',
    $learner['placement_type'] ?? '',
    ($learner['placement_type'] ?? '') === 'Non-Graded' ? ($learner['self_contained_level'] ?? '') : '',
]))));
$itp['previous_school_name'] = itp_record_value($itp, ['previous_school_name'], $previous_school['school_name'] ?? '');
$itp['years_in_school'] = itp_record_value($itp, ['years_in_school'], $years_in_school_default);
$itp['current_address'] = itp_record_value($itp, ['current_address'], itp_print_address($learner));
$itp['contact_number'] = itp_record_value($itp, ['contact_number'], $learner['primary_contact_number'] ?? ($learner['g_contact'] ?? ''));
$itp['strengths'] = itp_record_value($itp, ['strengths', 'learner_strengths']);
$itp['interests'] = itp_record_value($itp, ['interests', 'learner_preferences']);
$itp['talents'] = itp_record_value($itp, ['talents']);
$itp['skills'] = itp_record_value($itp, ['skills']);
$itp['needs'] = itp_record_value($itp, ['needs', 'transition_needs']);
$itp['transition_goal'] = itp_record_value($itp, ['transition_goal', 'post_school_goal']);
$itp['recommendations'] = itp_record_value($itp, ['recommendations'], trim(implode("\n", array_filter([
    $itp['family_supports'] ?? '',
    $itp['community_linkages'] ?? '',
    $itp['follow_up_notes'] ?? '',
]))));
$itp['assessment_mode'] = itp_record_value($itp, ['assessment_mode'], !empty($itp['assessment_institution']) ? 'With Assessment' : 'Without Assessment');
$itp['entry_point_label'] = itp_record_value($itp, ['entry_point_label'], itp_print_entry_label($itp, $entry_options));
$itp['prepared_by_name'] = itp_record_value($itp, ['prepared_by_name']);
$itp['prepared_by_title'] = itp_record_value($itp, ['prepared_by_title'], 'Teacher');
$itp['noted_by_name'] = itp_record_value($itp, ['noted_by_name']);
$itp['noted_by_title'] = itp_record_value($itp, ['noted_by_title'], 'School Principal/School Head');

log_transaction($conn, $_SESSION['user_id'], 'print', 'itp', $lrn, "Printed Annex 5 Individual Transition Plan for LRN: $lrn (SY: $school_year)");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annex 5 Individual Transition Plan - LRN <?= htmlspecialchars($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            background: #4b5563;
            margin: 0;
            padding: 14px 0;
            color: #000;
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }
        .page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto 12px;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
            padding: 8mm 10mm;
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .print-toolbar {
            text-align: center;
            margin-bottom: 1rem;
        }
        .paper-kicker {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9pt;
            margin-bottom: 0.4rem;
        }
        .letterhead {
            position: relative;
            min-height: 76px;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .letterhead-logo {
            height: 50px;
            object-fit: contain;
        }
        .letterhead-center {
            text-align: center;
            max-width: 380px;
            margin: 0 auto;
            line-height: 1.1;
            font-size: 11pt;
            flex-grow: 1;
        }
        .line-fill {
            display: inline-block;
            border-bottom: 1px solid #111827;
            min-width: 110px;
            height: 1.1rem;
            vertical-align: bottom;
            text-align: center;
            padding: 0 4px;
        }
        .page-title {
            text-align: center;
            font-weight: 700;
            letter-spacing: 0;
            margin: 0.5rem 0 0.4rem;
            text-transform: uppercase;
            font-size: 12pt;
            color: #000;
        }
        .paper-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
        }
        .paper-table th,
        .paper-table td {
            border: 1px solid #000;
            padding: 0.15rem 0.25rem;
            vertical-align: top;
        }
        .paper-table .section-head {
            font-weight: 700;
            background: #d9d9d9;
            color: #000;
        }
        .fill-text {
            min-height: 1.1rem;
            border-bottom: 1px solid #000;
            padding: 0.05rem 0;
        }
        .photo-box {
            width: 100px;
            height: 100px;
            border: 1px solid #000;
            border-radius: 0;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 8pt;
            color: #000;
            margin-left: auto;
            background: #fff;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .point-line { display: flex; gap: 0.4rem; margin-bottom: 0.1rem; font-size: 9.5pt; }
        .matrix-table { font-size: 8.5pt; }
        .matrix-table th,
        .matrix-table td { text-align: center; vertical-align: middle; padding: 0.15rem 0.2rem; }
        .matrix-table td:first-child,
        .matrix-table th:first-child { text-align: left; }
        .matrix-note { font-size: 7.8pt; line-height: 1.1; }
        .matrix-highlight {
            background-color: #E8F5E9 !important;
            font-weight: bold;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .matrix-highlight td {
            border-top: 2px solid #2D6A4F !important;
            border-bottom: 2px solid #2D6A4F !important;
        }
        .line-block { display: flex; gap: 0.4rem; margin-bottom: 0.35rem; align-items: center; }
        .line-label { min-width: 110px; font-weight: 700; font-size: 9.5pt; }
        .line-value {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 1.1rem;
            padding-bottom: 0.05rem;
            font-size: 9.5pt;
        }
        .recommendation-block {
            min-height: 50px;
            border-bottom: 1px solid #000;
            white-space: pre-wrap;
            font-size: 9.5pt;
        }
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            margin-top: 1rem;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            min-height: 1.2rem;
            margin-top: 0.4rem;
            text-align: center;
        }
        .footer-page {
            position: absolute;
            right: 10mm;
            bottom: 6mm;
            font-size: 9pt;
            color: #555;
        }
        .section-copy {
            font-size: 10.5pt;
            line-height: 1.25;
            text-align: justify;
        }
        .meta-strip {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 0.3rem;
            font-size: 9.5pt;
        }
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .section-head {
            background: #d9d9d9 !important;
            color: #000 !important;
        }
        @media print {
            body {
                background: none;
                padding: 0;
                margin: 0;
            }
            .page {
                margin: 0;
                box-shadow: none;
                border: none;
                width: 210mm;
                height: 297mm;
                page-break-after: always;
                padding: 8mm 10mm;
                box-sizing: border-box;
            }
            .page:last-of-type { page-break-after: auto; }
            .print-toolbar { display: none !important; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print ITP'); ?>

    <div class="page a4-fixed">
        <div class="letterhead">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead-center">
                <div>Department of Education</div>
                <div>Region <span class="line-fill"><?= htmlspecialchars(DEPED_REGION) ?></span></div>
                <div>Division of <span class="line-fill"><?= htmlspecialchars(DEPED_DIVISION) ?></span></div>
                <div>District of <span class="line-fill" style="min-width: 150px;"><?= htmlspecialchars($learner['district'] ?? '') ?></span></div>
                <div><span class="line-fill" style="min-width: 250px;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></span> School</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <div style="position: relative; margin-top: 1rem; min-height: 100px; display: flex; align-items: center; justify-content: center;">
            <div class="text-center">
                <h1 class="page-title" style="margin: 0 0 0.2rem 0;">INDIVIDUAL TRANSITION PLAN</h1>
                <div class="fw-bold" style="font-size: 12pt;">School Year <?= htmlspecialchars($school_year) ?></div>
                <div class="meta-strip">
                    <span>Plan Date: <?= htmlspecialchars($itp['plan_date'] ?: '__________') ?></span>
                    <span>Review Date: <?= htmlspecialchars($itp['review_date'] ?: '__________') ?></span>
                    <span>Target Exit: <?= htmlspecialchars($itp['target_exit_date'] ?: '__________') ?></span>
                </div>
            </div>
            <div class="photo-box" style="position: absolute; right: 0; top: 0;">
                <?php if ($photo_src !== 'img/student_placeholder.webp'): ?>
                    <img src="<?= htmlspecialchars($photo_src) ?>" alt="Learner photo">
                <?php else: ?>
                    <div>2x2 picture<br>(recent picture)</div>
                <?php endif; ?>
            </div>
        </div>

        <table class="paper-table mt-2">
            <tr>
                <td colspan="3" class="section-head py-1" style="font-size: 11pt;">I. PERSONAL INFORMATION</td>
            </tr>
            <tr>
                <td style="width:42%;">
                    <div>Name:</div>
                    <div class="fill-text"><?= htmlspecialchars(sned_learner_name($learner)) ?></div>
                </td>
                <td style="width:29%;">
                    <div>Date of Birth:</div>
                    <div class="fill-text"><?= htmlspecialchars(date('F d, Y', strtotime($learner['birthday']))) ?></div>
                </td>
                <td style="width:29%;">
                    <div>LRN:</div>
                    <div class="fill-text"><?= htmlspecialchars($lrn) ?></div>
                </td>
            </tr>
            <tr>
                <td>
                    <div>Name of Parents/Guardian:</div>
                    <div class="fill-text"><?= itp_print_value($itp['parent_guardian_name'] ?? itp_print_guardian_name($learner)) ?></div>
                </td>
                <td>
                    <div>Current Level of Education:</div>
                    <div class="fill-text"><?= itp_print_value($itp['current_level_of_education'] ?? '') ?></div>
                </td>
                <td>
                    <div>Name of Previous School (if there is):</div>
                    <div class="fill-text"><?= itp_print_value($itp['previous_school_name'] ?? '') ?></div>
                    <div class="mt-2">No. of years in School:</div>
                    <div class="fill-text"><?= itp_print_value($itp['years_in_school'] ?? '') ?></div>
                </td>
            </tr>
            <tr>
                <td>
                    <div>Religion:</div>
                    <div class="fill-text"><?= itp_print_value($itp['religion'] ?? '') ?></div>
                </td>
                <td colspan="2">
                    <div>Gender:</div>
                    <div class="pt-2">
                        <span><?= ($learner['sex'] ?? '') === 'Male' ? '[X]' : '[&nbsp;]' ?> Male</span>
                        <span class="ms-5"><?= ($learner['sex'] ?? '') === 'Female' ? '[X]' : '[&nbsp;]' ?> Female</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div>Current Address:</div>
                    <div class="fill-text"><?= itp_print_value($itp['current_address'] ?? '') ?></div>
                </td>
                <td>
                    <div>Phone/Contact No.:</div>
                    <div class="fill-text"><?= itp_print_value($itp['contact_number'] ?? '') ?></div>
                </td>
            </tr>
            <tr>
                <td>
                    <div>Exceptionality / Disabilities:</div>
                    <div class="fill-text"><?= itp_print_value($learner_disability_summary) ?></div>
                </td>
                <td>
                    <div>With Assessment: <?= ($itp['assessment_mode'] ?? '') === 'With Assessment' ? '[X]' : '[&nbsp;]' ?></div>
                    <div>Without Assessment: <?= ($itp['assessment_mode'] ?? '') === 'Without Assessment' ? '[X]' : '[&nbsp;]' ?></div>
                    <div class="mt-2">Name of Institution:</div>
                    <div class="fill-text"><?= itp_print_value($itp['assessment_institution'] ?? '') ?></div>
                    <div class="small">(See attached Assessment Result)</div>
                </td>
                <td></td>
            </tr>
            <tr>
                <td colspan="3">
                    <div class="mb-2">Point of Entry: Please check the point of entry appropriate to the status of the learner.</div>
                    <?php foreach ($entry_options as $label): ?>
                        <div class="point-line">
                            <span><?= ($itp['entry_point_label'] ?? '') === $label ? '[X]' : '[&nbsp;]' ?></span>
                            <span><?= htmlspecialchars($label) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>
        <div class="footer-page">1 | Page</div>
    </div>

    <div class="page a4-fixed">

        <div class="letterhead">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead-center">
                <div>Department of Education</div>
                <div>Region <span class="line-fill"><?= htmlspecialchars(DEPED_REGION) ?></span></div>
                <div>Division of <span class="line-fill"><?= htmlspecialchars(DEPED_DIVISION) ?></span></div>
                <div>District of <span class="line-fill" style="min-width: 150px;"><?= htmlspecialchars($learner['district'] ?? '') ?></span></div>
                <div><span class="line-fill" style="min-width: 250px;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></span> School</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <table class="paper-table mt-2">
            <tr>
                <td colspan="4" class="section-head py-1" style="font-size: 11pt;">II. TRANSITION TEAM</td>
            </tr>
            <tr>
                <th style="width:26%;">Team Members</th>
                <th style="width:30%;">Name</th>
                <th style="width:24%;">Contact details (email and/or phone)</th>
                <th style="width:20%;">Date started working with team</th>
            </tr>
            <?php foreach ($team_rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['role']) ?></td>
                    <td><?= nl2br(htmlspecialchars($row['name'] ?? '')) ?></td>
                    <td><?= nl2br(htmlspecialchars($row['contact_details'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($row['date_started_with_team'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="mt-2" style="font-size: 9.5pt; font-style: italic; line-height: 1.25;">
            Instruction: List down learner's current strengths, interests, talent and skills as observed. Give recommendations based on the learner's potentials and needs.
        </div>

        <div class="mt-2">
            <div class="line-block">
                <span class="line-label">Strengths:</span>
                <div class="line-value"><?= nl2br(htmlspecialchars($itp['strengths'] ?? '')) ?></div>
            </div>
            <div class="line-block">
                <span class="line-label">Interests:</span>
                <div class="line-value"><?= nl2br(htmlspecialchars($itp['interests'] ?? '')) ?></div>
            </div>
            <div class="line-block">
                <span class="line-label">Talents:</span>
                <div class="line-value"><?= nl2br(htmlspecialchars($itp['talents'] ?? '')) ?></div>
            </div>
            <div class="line-block">
                <span class="line-label">Skills:</span>
                <div class="line-value"><?= nl2br(htmlspecialchars($itp['skills'] ?? '')) ?></div>
            </div>
            <div class="line-block">
                <span class="line-label">Needs:</span>
                <div class="line-value"><?= nl2br(htmlspecialchars($itp['needs'] ?? '')) ?></div>
            </div>
            <div class="fw-bold mt-2" style="font-size: 10pt;">Recommendations:</div>
            <div class="recommendation-block"><?= nl2br(htmlspecialchars($itp['recommendations'] ?? '')) ?></div>
        </div>
        <div class="footer-page">2 | Page</div>
    </div>

    <div class="page a4-fixed">

        <div class="letterhead">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead-center">
                <div>Department of Education</div>
                <div>Region <span class="line-fill"><?= htmlspecialchars(DEPED_REGION) ?></span></div>
                <div>Division of <span class="line-fill"><?= htmlspecialchars(DEPED_DIVISION) ?></span></div>
                <div>District of <span class="line-fill" style="min-width: 150px;"><?= htmlspecialchars($learner['district'] ?? '') ?></span></div>
                <div><span class="line-fill" style="min-width: 250px;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></span> School</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <div class="mt-2 mb-2 text-center fst-italic" style="font-size: 9.5pt; line-height: 1.2;">
            The table below shows options for provision of specific transition program to targeted levels of entry / exit points.
        </div>

        <table class="paper-table matrix-table">
            <tr>
                <th style="width:34%;">Type of Learner based on Entry/Exit Point in the Transition Program</th>
                <th>Functional Academics Skills</th>
                <th>Pre-Vocational Skills</th>
                <th>Life Skills</th>
                <th>Enrichment Skills</th>
                <th>Livelihood Skills</th>
                <th>Care Skills</th>
                <th>Career Skills</th>
            </tr>
            <?php foreach (itp_transition_matrix() as $matrix_row): 
                $is_matched = itp_matrix_row_matches_entry_point($matrix_row['label'], $itp['entry_point_label'] ?? '');
            ?>
                <tr class="<?= $is_matched ? 'matrix-highlight' : '' ?>">
                    <td>
                        <?php if ($is_matched): ?>
                            <strong>✓ </strong>
                        <?php endif; ?>
                        <?= htmlspecialchars($matrix_row['label']) ?>
                    </td>
                    <?php foreach ($matrix_row['cells'] as $cell): ?>
                        <td>
                            <?php if ($cell === 'check'): ?>
                                X
                            <?php elseif ($cell !== ''): ?>
                                <div class="matrix-note"><?= htmlspecialchars($cell) ?></div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        <div class="footer-page">3 | Page</div>
    </div>

    <div class="page a4-fixed">

        <div class="letterhead">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead-center">
                <div>Department of Education</div>
                <div>Region <span class="line-fill"><?= htmlspecialchars(DEPED_REGION) ?></span></div>
                <div>Division of <span class="line-fill"><?= htmlspecialchars(DEPED_DIVISION) ?></span></div>
                <div>District of <span class="line-fill" style="min-width: 150px;"><?= htmlspecialchars($learner['district'] ?? '') ?></span></div>
                <div><span class="line-fill" style="min-width: 250px;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></span> School</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <h2 class="page-title">INDIVIDUAL TRANSITION GOAL PLAN</h2>

        <div class="line-block">
            <span class="line-label">Goal:</span>
            <div class="line-value"><?= nl2br(htmlspecialchars($itp['transition_goal'] ?? '')) ?></div>
        </div>
        <div class="line-block">
            <span class="line-label">Entry Point:</span>
            <div class="line-value"><?= nl2br(htmlspecialchars($itp['entry_point_label'] ?? '')) ?></div>
        </div>
        <div class="line-block">
            <span class="line-label">Learning Package/s:</span>
            <div class="line-value"><?= nl2br(htmlspecialchars($itp['learning_packages'] ?? '')) ?></div>
        </div>

        <table class="paper-table mt-3">
            <tr>
                <th style="width:21%;">COMPETENCY</th>
                <th style="width:23%;">ACTIVITIES</th>
                <th style="width:18%;">TIME FRAME</th>
                <th style="width:22%;">PERSONS RESPONSIBLE</th>
                <th style="width:16%;">REMARKS</th>
            </tr>
            <?php foreach ($goal_rows as $goal_row): ?>
                <tr>
                    <td><?= nl2br(htmlspecialchars($goal_row['competency'] ?? '')) ?></td>
                    <td><?= nl2br(htmlspecialchars($goal_row['activities'] ?? '')) ?></td>
                    <td><?= nl2br(htmlspecialchars($goal_row['time_frame'] ?? '')) ?></td>
                    <td><?= nl2br(htmlspecialchars($goal_row['persons_responsible'] ?? '')) ?></td>
                    <td><?= nl2br(htmlspecialchars($goal_row['remarks'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="fw-bold mt-2" style="font-size: 10pt;">Recommendations:</div>
        <div class="recommendation-block" style="min-height: 40px; font-size: 9.5pt;"><?= nl2br(htmlspecialchars($itp['recommendations'] ?? '')) ?></div>
        <div class="mt-1" style="font-size: 9pt; font-style: italic;">(This will serve as the Lesson Plan of the Teacher.)</div>

        <div class="signature-grid">
            <div>
                <div class="fw-bold" style="font-size: 10pt;">Prepared by:</div>
                <div class="signature-line"><?= htmlspecialchars($itp['prepared_by_name'] ?? '') ?></div>
                <div class="text-center mt-1" style="font-size: 9pt;"><?= htmlspecialchars($itp['prepared_by_title'] ?? 'Teacher') ?></div>
            </div>
            <div>
                <div class="fw-bold" style="font-size: 10pt;">Noted:</div>
                <div class="signature-line"><?= htmlspecialchars($itp['noted_by_name'] ?? '') ?></div>
                <div class="text-center mt-1" style="font-size: 9pt;"><?= htmlspecialchars($itp['noted_by_title'] ?? 'School Principal/School Head') ?></div>
            </div>
        </div>
        <div class="footer-page">4 | Page</div>
    </div>

    <div class="page a4-fixed">

        <div class="letterhead">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead-center">
                <div>Department of Education</div>
                <div>Region <span class="line-fill"><?= htmlspecialchars(DEPED_REGION) ?></span></div>
                <div>Division of <span class="line-fill"><?= htmlspecialchars(DEPED_DIVISION) ?></span></div>
                <div>District of <span class="line-fill" style="min-width: 150px;"><?= htmlspecialchars($learner['district'] ?? '') ?></span></div>
                <div><span class="line-fill" style="min-width: 250px;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></span> School</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <h2 class="page-title">CONSOLIDATED FORM FOR TRANSITION PROGRAM</h2>

        <table class="paper-table mt-4">
            <thead>
                <tr>
                    <th style="width: 30%;">Name of Learner</th>
                    <th style="width: 25%;">Entry Point</th>
                    <th style="width: 25%;">Learning Package</th>
                    <th style="width: 20%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= htmlspecialchars(sned_learner_name($learner)) ?></td>
                    <td><?= htmlspecialchars($itp['entry_point_label'] ?? '') ?></td>
                    <td><?= htmlspecialchars($itp['learning_packages'] ?? '') ?></td>
                    <td><?= htmlspecialchars($itp['recommendations'] ?? '') ?></td>
                </tr>
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <tr>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <div class="footer-page">5 | Page</div>
    </div>
</body>
</html>
