<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['lrn'] ?? $_GET['learner_id'] ?? '';
$school_year = trim($_GET['school_year'] ?? $_GET['sy'] ?? '');
$quarter = $_GET['quarter'] ?? 'all';

if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid LRN request.");
}

if ($school_year === '') {
    die("School year is required.");
}

if (!in_array($quarter, ['all', '1', '2', '3', '4'], true)) {
    die("Invalid quarter request.");
}

$stmt = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.grade_level, e.placement_type, e.school_year,
           s.school_name, s.district, s.address AS school_address,
           g.first_name AS guardian_first_name, g.middle_name AS guardian_middle_name,
           g.last_name AS guardian_last_name, g.extension_name AS guardian_extension_name
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.school_year = ? AND e.is_deleted = 0
    LEFT JOIN schools s ON e.school_id = s.id
    LEFT JOIN guardians g ON l.guardian_id = g.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.is_historical ASC, e.id DESC
    LIMIT 1
");
$stmt->bind_param("ss", $school_year, $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

$user_role = $_SESSION['role'] ?? 'user';
if (user_is_scoped($user_role)) {
    $has_access = user_is_teacher($user_role)
        ? check_teacher_learner_access($conn, $lrn)
        : (!empty($learner['school_id']) && (int)$learner['school_id'] === (int)($_SESSION['school_id'] ?? 0));
    if (!$has_access) {
        header("Location: 403.php");
        exit();
    }
}

sned_block_unless_non_graded($learner, 'Non Graded Progress Report');

$principal_name = '';
if (!empty($learner['school_id'])) {
    $principal_name = get_current_principal($conn, (int)$learner['school_id']) ?: '';
}

$stmt_ilp = $conn->prepare("
    SELECT u.full_name AS adviser_name
    FROM learner_ilp ilp
    LEFT JOIN users u ON ilp.adviser_id = u.id
    WHERE ilp.learner_id = ? AND ilp.school_year = ?
    ORDER BY ilp.id DESC
    LIMIT 1
");
$stmt_ilp->bind_param("ss", $lrn, $school_year);
$stmt_ilp->execute();
$adviser_name = $stmt_ilp->get_result()->fetch_assoc()['adviser_name'] ?? '';

$stmt_card = $conn->prepare("SELECT * FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
$stmt_card->bind_param("ss", $lrn, $school_year);
$stmt_card->execute();
$report_card = $stmt_card->get_result()->fetch_assoc() ?: [];
$report_card_id = (int)($report_card['id'] ?? 0);

$attendance = [];
foreach (sf9_months() as $order => $label) {
    $attendance[$order] = ['school_days' => '', 'days_present' => '', 'days_absent' => ''];
}
if ($report_card_id) {
    $stmt_attendance = $conn->prepare("SELECT * FROM sf9_attendance WHERE report_card_id = ? ORDER BY month_order ASC");
    $stmt_attendance->bind_param("i", $report_card_id);
    $stmt_attendance->execute();
    $res_attendance = $stmt_attendance->get_result();
    while ($row = $res_attendance->fetch_assoc()) {
        $attendance[(int)$row['month_order']] = $row;
    }
}

$quarter_notes = [];
for ($q = 1; $q <= 4; $q++) {
    $quarter_notes[$q] = ['teacher_remarks' => '', 'parent_comments' => '', 'parent_signature' => ''];
}
if ($report_card_id) {
    $stmt_notes = $conn->prepare("SELECT * FROM sf9_quarter_notes WHERE report_card_id = ? ORDER BY quarter ASC");
    $stmt_notes->bind_param("i", $report_card_id);
    $stmt_notes->execute();
    $res_notes = $stmt_notes->get_result();
    while ($row = $res_notes->fetch_assoc()) {
        $quarter_notes[(int)$row['quarter']] = $row;
    }
}

$stmt_ratings = $conn->prepare("
    SELECT domain, subdomain, indicator, q1, q2, q3, q4
    FROM learner_indicator_ratings
    WHERE learner_id = ? AND school_year = ?
    ORDER BY id ASC
");
$stmt_ratings->bind_param("ss", $lrn, $school_year);
$stmt_ratings->execute();
$ratings_result = $stmt_ratings->get_result();

// Build lookup of existing ratings by domain|subdomain|indicator key
$existing_ratings = [];
while ($row = $ratings_result->fetch_assoc()) {
    $key = sf9_rating_key($row['domain'] ?? '', $row['subdomain'] ?? '', $row['indicator'] ?? '');
    $existing_ratings[$key] = $row;
}

// Map master indicator domain names to internal domain keys
$master_to_domain_key = [
    'Daily Living Skills' => 'daily_living',
    'Socio-Emotional' => 'socio_emotional',
    'Language' => 'language',
    'Psychomotor' => 'psychomotor',
    'Cognitive' => 'cognitive',
    'Aesthetic & Creative' => 'aesthetic',
    'Behavior' => 'behavior',
    'Orientation & Mobility' => 'orientation',
];

$domains = [
    'daily_living' => ['title' => 'DAILY LIVING SKILLS DOMAIN', 'groups' => []],
    'socio_emotional' => ['title' => 'SOCIO-EMOTIONAL DOMAIN', 'groups' => []],
    'language' => ['title' => 'LANGUAGE DEVELOPMENT DOMAIN', 'groups' => []],
    'psychomotor' => ['title' => 'PSYCHOMOTOR DOMAIN', 'groups' => []],
    'cognitive' => ['title' => 'COGNITIVE DOMAIN', 'groups' => []],
    'aesthetic' => ['title' => 'AESTHETIC AND CREATIVE DOMAIN', 'groups' => []],
    'behavior' => ['title' => 'BEHAVIORAL DEVELOPMENT', 'groups' => []],
    'orientation' => ['title' => 'ORIENTATION AND MOBILITY', 'groups' => []],
];

// Populate domains with ALL master indicators first, overlaying existing ratings
foreach (sf9_master_indicators() as $master_domain => $subdomains) {
    $domain_key = $master_to_domain_key[$master_domain] ?? null;
    if (!$domain_key) continue;
    
    foreach ($subdomains as $subdomain => $indicators) {
        $sub_val = $subdomain === '' ? '' : $subdomain;
        foreach ($indicators as $indicator) {
            $key = sf9_rating_key($master_domain, $sub_val, $indicator);
            if (isset($existing_ratings[$key])) {
                // Use existing rating data
                $row = $existing_ratings[$key];
                $db_subdomain = trim((string)($row['subdomain'] ?? ''));
                $domains[$domain_key]['groups'][$db_subdomain][] = $row;
            } else {
                // Create blank row from master indicator
                $blank_row = [
                    'domain' => $master_domain,
                    'subdomain' => $sub_val,
                    'indicator' => $indicator,
                    'q1' => null,
                    'q2' => null,
                    'q3' => null,
                    'q4' => null,
                ];
                $domains[$domain_key]['groups'][$sub_val][] = $blank_row;
            }
        }
    }
}

$domain_pairs = [
    ['daily_living', 'socio_emotional'],
    ['language', 'psychomotor'],
    ['cognitive', 'aesthetic'],
    ['behavior', 'orientation'],
];

$show_quarters = ($quarter === 'all') ? [1, 2, 3, 4] : [(int)$quarter];
$body_class = $quarter === 'all' ? 'show-all' : 'show-q' . $quarter;

$full_name = sned_learner_name($learner, 'display');
$birthday = !empty($learner['birthday']) ? date('F d, Y', strtotime($learner['birthday'])) : '';
$age = !empty($learner['birthday']) ? (new DateTime($learner['birthday']))->diff(new DateTime())->y : '';
$sex = $learner['sex'] ?? '';

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('line_value')) {
    function line_value(?string $value, string $class = ''): string
    {
        return '<span class="line-value ' . h($class) . '">' . h($value) . '</span>';
    }
}

if (!function_exists('render_empty_cells')) {
    function render_empty_cells(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            echo '<td>&nbsp;</td>';
        }
    }
}

if (!function_exists('render_attendance_cells')) {
    function render_attendance_cells(array $attendance, string $field): void
    {
        $total = 0;
        $months = sf9_months();
        $has_numeric = false;
        foreach ($months as $order => $label) {
            if ($order === 12) break;
            $value = $attendance[$order][$field] ?? '';
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $total += (int)$value;
                $has_numeric = true;
            }
            echo '<td>' . (($value === null || $value === '') ? '&nbsp;' : h((string)$value)) . '</td>';
        }
        echo '<td class="fw-bold">' . ($has_numeric ? (string)$total : '&nbsp;') . '</td>';
    }
}

if (!function_exists('ordinal_quarter')) {
    function ordinal_quarter(int $quarter): string
    {
        return $quarter === 1 ? '1st' : ($quarter === 2 ? '2nd' : ($quarter === 3 ? '3rd' : '4th'));
    }
}

if (!function_exists('render_quarter_headers')) {
    function render_quarter_headers(array $show_quarters): void
    {
        for ($q = 1; $q <= 4; $q++) {
            $hidden = in_array($q, $show_quarters, true) ? '' : ' q-hidden';
            echo '<th class="q-col q' . $q . $hidden . '">' . $q . '</th>';
        }
    }
}

if (!function_exists('render_quarter_cells')) {
    function render_quarter_cells(array $row, array $show_quarters): void
    {
        for ($q = 1; $q <= 4; $q++) {
            $hidden = in_array($q, $show_quarters, true) ? '' : ' q-hidden';
            $value = $row['q' . $q] ?? '';
            echo '<td class="q-col q' . $q . $hidden . '">' . (($value === null || $value === '') ? '&nbsp;' : h($value)) . '</td>';
        }
    }
}

if (!function_exists('render_domain_table')) {
    function render_domain_table(array $domain, array $show_quarters): void
    {
        $colspan = 1 + count($show_quarters);
        echo '<section class="domain-section">';
        echo '<h2>' . h($domain['title']) . '</h2>';
        echo '<table class="domain-table">';
        echo '<thead><tr><th class="indicator-col">Performance Indicators</th>';
        render_quarter_headers($show_quarters);
        echo '</tr></thead><tbody>';

        if (empty($domain['groups'])) {
            echo '<tr><td colspan="' . $colspan . '" class="empty-domain">No indicators recorded for this school year.</td></tr>';
        } else {
            foreach ($domain['groups'] as $subdomain => $rows) {
                if ($subdomain !== '') {
                    echo '<tr><td colspan="' . $colspan . '" class="subdomain-row">' . h(strtoupper($subdomain)) . '</td></tr>';
                }
                foreach ($rows as $row) {
                    echo '<tr><td class="indicator-col">' . h($row['indicator'] ?? '') . '</td>';
                    render_quarter_cells($row, $show_quarters);
                    echo '</tr>';
                }
            }
        }

        echo '</tbody></table></section>';
    }
}

log_transaction($conn, $_SESSION['user_id'], 'print', 'sf9', $lrn, "Printed Non Graded Progress Report for LRN: $lrn (SY $school_year, Quarter: $quarter)");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non Graded Progress Report - LRN <?= h($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        :root {
            --deped-blue: #111;
            --border-color: #111;
            --table-header-bg: #f5f5f5;
        }
        
        body, html {
            margin: 0;
            padding: 0;
            background: #6b7280;
            font-family: "Times New Roman", Times, serif;
            font-size: 7.5pt;
            color: var(--deped-blue);
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Screen Preview Styles */
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px;
            gap: 10px;
        }

        .spread {
            width: 297mm;
            height: 210mm;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0,0,0,0.22);
            position: relative;
            box-sizing: border-box;
            overflow: hidden;
            border-collapse: collapse;
            table-layout: fixed;
            border: none;
        }

        .spread td.panel:last-child {
            border-left: 1px dashed #555 !important;
        }

        .panel {
            width: 50%;
            height: 100%;
            padding: 5mm 8mm;
            box-sizing: border-box;
            vertical-align: top;
            border: none;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 0;
            }
            body {
                background: none;
                padding: 0;
                display: block;
            }
            .spread {
                box-shadow: none;
                page-break-after: always;
            }
            .spread:last-child {
                page-break-after: auto;
            }
        }

        /* Typography & Headings */
        h1, h2, h3 {
            margin: 0;
            padding: 0;
            text-align: center;
            font-weight: 700;
        }

        .section-title, .domain-section h2 {
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0;
            margin-bottom: 1.5mm;
            background: var(--table-header-bg);
            padding: 0.8mm 0;
            border: 1px solid var(--border-color);
        }

        .cover-title {
            font-size: 13pt;
            margin: 3mm 0;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        /* Cover Page Header */
        .cover-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 2mm;
        }

        .cover-header svg {
            width: 40px;
            height: 40px;
        }

        .deped-header-text {
            text-align: center;
            line-height: 1.1;
        }

        .deped-header-text .republic {
            font-size: 8pt;
        }

        .deped-header-text .department {
            font-size: 10pt;
            font-weight: 700;
            font-family: "Times New Roman", Times, serif;
            margin: 2px 0;
        }
        
        .sf-label {
            position: absolute;
            top: 5mm;
            right: 8mm;
            font-size: 7pt;
            font-weight: 600;
        }

        /* Fill-in Lines */
        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 1mm;
            margin-bottom: 2mm;
        }

        .line-row {
            display: flex;
            align-items: flex-end;
            gap: 1mm;
            width: 100%;
        }

        .line-row label {
            white-space: nowrap;
            font-weight: 600;
            font-size: 8.5pt;
        }

        .line-value {
            flex-grow: 1;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            font-size: 9pt;
            font-family: "Times New Roman", Times, serif;
            font-weight: 700;
            padding-bottom: 1px;
            min-height: 12pt;
        }
        
        .line-value.short {
            flex-grow: 0;
            width: 40px;
        }

        .line-value.medium {
            flex-grow: 0;
            width: 100px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 2mm;
        }

        th, td {
            border: 1px solid var(--border-color);
            padding: 0.8mm;
            vertical-align: middle;
            line-height: 1.15;
        }

        th {
            background-color: var(--table-header-bg);
            font-weight: 700;
            text-align: center;
            font-size: 7pt;
        }

        td {
            font-size: 7pt;
        }

        /* Specific Table Adjustments */
        .attendance-table th, .attendance-table td {
            text-align: center;
            padding: 0.5mm;
        }
        .attendance-table .label-col {
            text-align: left;
            width: 28%;
            font-weight: 600;
        }

        .guide-table .symbol-col { width: 12%; text-align: center; font-weight: bold; }
        .guide-table .description-col { width: 28%; }
        
        .remarks-table .quarter-col, .comments-table .quarter-col { width: 15%; text-align: center; font-weight: bold; }
        .comments-table .signature-col { width: 30%; }
        .blank-row td { height: 5mm; }

        /* Domain Tables */
        .domain-table .indicator-col { text-align: left; width: auto; font-size: 6.5pt; }
        .domain-table th.indicator-col { text-align: center; }
        .domain-table .q-col { width: 5.5mm; text-align: center; font-weight: bold; font-family: "Times New Roman", Times, serif; font-size: 7pt; }
        .domain-table .subdomain-row { font-weight: 700; background: #fafafa; font-style: italic; font-size: 6.5pt; }
        .empty-domain { text-align: center; height: 10mm; color: #444; font-style: italic; display: none; }
        .q-hidden { display: none; }

        /* Signatures & Blocks */
        .letter-box {
            border: 1px solid var(--border-color);
            padding: 2mm;
            margin-bottom: 2mm;
            font-size: 7.5pt;
            text-align: justify;
            line-height: 1.3;
            background: #fff;
        }

        .signature-pair {
            margin-top: 3mm;
            text-align: center;
        }

        .sig-block {
            width: 40%;
        }

        .sig-line {
            width: 100%;
            border-bottom: 1px solid var(--border-color);
            min-height: 8pt;
            margin-bottom: 0.5mm;
            font-family: "Times New Roman", Times, serif;
            font-weight: bold;
            font-size: 8pt;
        }

        .sig-label {
            font-size: 6.5pt;
            color: #333;
        }

        /* Checkboxes */
        .checkbox-group {
            display: inline-block;
            vertical-align: middle;
        }
        
        .checkbox-item {
            display: inline-block;
            vertical-align: middle;
        }
        
        .check-box {
            display: inline-block;
            width: 8px;
            height: 8px;
            border: 1px solid var(--border-color);
            line-height: 8px;
            text-align: center;
            font-size: 8px;
            font-family: monospace;
        }

        /* Print Controls */
        .print-controls {
            text-align: center;
            margin-bottom: 10px;
            width: 100%;
        }

        /* Utilities */
        .flex-1 { flex: 1; }
        .mt-2 { margin-top: 1.5mm; }
        .mb-2 { margin-bottom: 1.5mm; }

        @media print {
            .print-controls { display: none; }
        }
    </style>
    <?php print_a4_styles('landscape'); ?>
</head>
<body class="<?= h($body_class) ?>">
    <?php print_a4_toolbar('Print Report Card'); ?>

    <table class="spread a4-fixed outer-cover">
        <tr>
            <td class="panel admin-panel">
                <section class="admin-section">
                    <h1 class="section-title">ATTENDANCE RECORD</h1>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th class="label-col">&nbsp;</th>
                                <th>June</th><th>July</th><th>Aug</th><th>Sept</th><th>Oct</th><th>Nov</th><th>Dec</th><th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td class="label-col">No. of School Days</td><?php render_attendance_cells($attendance, 'school_days'); ?></tr>
                            <tr><td class="label-col">No. of School Days Present</td><?php render_attendance_cells($attendance, 'days_present'); ?></tr>
                            <tr><td class="label-col">No. of School Days Absent</td><?php render_attendance_cells($attendance, 'days_absent'); ?></tr>
                        </tbody>
                    </table>
                </section>

                <section class="admin-section">
                    <h1 class="section-title">GUIDE FOR RATING</h1>
                    <table class="guide-table">
                        <thead><tr><th class="symbol-col">SYMBOL</th><th class="description-col">Description</th><th>EXPLANATION</th></tr></thead>
                        <tbody>
                            <tr><td class="symbol-col">P</td><td>Proficient</td><td>The child always manifests the skills</td></tr>
                            <tr><td class="symbol-col">AP</td><td>Approaching Proficiency</td><td>The child manifests the skills most of the time</td></tr>
                            <tr><td class="symbol-col">D</td><td>Developing</td><td>The child sometimes manifests the skills</td></tr>
                            <tr><td class="symbol-col">B</td><td>Beginning</td><td>The child seldom manifests the skills</td></tr>
                            <tr><td class="symbol-col">NO/NA</td><td>Not Observed / Not Applicable</td><td>No manifestation of skills at all / Not Applicable</td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="admin-section">
                    <h1 class="section-title">TEACHER'S REMARKS</h1>
                    <table class="remarks-table">
                        <thead><tr><th class="quarter-col">QUARTER</th><th>REMARKS</th></tr></thead>
                        <tbody>
                            <?php for ($q = 1; $q <= 4; $q++): ?>
                                <tr class="blank-row"><td class="quarter-col"><?= ordinal_quarter($q) ?></td><td><?= nl2br(h($quarter_notes[$q]['teacher_remarks'] ?? '')) ?: '&nbsp;' ?></td></tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </section>

                <section class="admin-section">
                    <h1 class="section-title">PARENT'S/ GUARDIAN'S COMMENTS AND SIGNATURE</h1>
                    <table class="comments-table">
                        <thead><tr><th class="quarter-col">QUARTER</th><th>COMMENTS</th><th class="signature-col">SIGNATURE</th></tr></thead>
                        <tbody>
                            <?php for ($q = 1; $q <= 4; $q++): ?>
                                <tr class="blank-row">
                                    <td class="quarter-col"><?= ordinal_quarter($q) ?></td>
                                    <td><?= nl2br(h($quarter_notes[$q]['parent_comments'] ?? '')) ?: '&nbsp;' ?></td>
                                    <td><?= h($quarter_notes[$q]['parent_signature'] ?? '') ?: '&nbsp;' ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </section>
            </td>

            <td class="panel cover-panel">
                <table class="cover-header-table" style="width: 100%; border: none; border-collapse: collapse; margin-bottom: 2mm;">
                    <tr>
                        <td style="width: 50px; border: none; padding: 0; vertical-align: middle; text-align: left;">
                            <img src="img/deped-logo.webp" style="height: 45px; width: 45px; object-fit: contain;" alt="DepEd Logo">
                        </td>
                        <td style="border: none; padding: 0; vertical-align: middle; text-align: center;">
                            <div class="deped-header-text">
                                <div class="republic" style="font-size: 8pt;">Republic of the Philippines</div>
                                <div class="department" style="font-size: 10pt; font-weight: 700; font-family: 'Times New Roman', Times, serif; margin: 2px 0;">Department of Education</div>
                                <div class="region" style="font-size: 8.5pt;">National Capital Region</div>
                                <div class="division" style="font-size: 8.5pt;">Schools Division Office of Valenzuela City</div>
                            </div>
                        </td>
                        <td style="width: 50px; border: none; padding: 0; vertical-align: middle; text-align: right;">
                            <img src="img/valenzuela_gov_logo.webp" style="height: 45px; width: 45px; object-fit: contain;" alt="Valenzuela Logo">
                        </td>
                    </tr>
                </table>

                <table style="width: 100%; border: none; border-collapse: collapse; margin-bottom: 2mm;" class="form-grid-table">
                    <tr>
                        <td style="width: 12%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">District:</td>
                        <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($learner['district'] ?: '') ?></td>
                        <td style="width: 10%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; padding-left: 2mm; vertical-align: middle;">School:</td>
                        <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($learner['school_name'] ?: '') ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">School Address:</td>
                        <td colspan="3" style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($learner['school_address'] ?: '') ?></td>
                    </tr>
                </table>

                <h1 class="cover-title">PROGRESS REPORT CARD</h1>

                <table style="width: 100%; border: none; border-collapse: collapse; margin-bottom: 2mm;" class="form-grid-table">
                    <tr>
                        <td style="width: 10%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Name:</td>
                        <td colspan="5" style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($full_name) ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Age:</td>
                        <td style="width: 15%; border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($age) ?></td>
                        <td style="width: 20%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; padding-left: 2mm; vertical-align: middle;">Date of Birth:</td>
                        <td style="width: 25%; border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($birthday) ?></td>
                        <td style="width: 10%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; padding-left: 2mm; vertical-align: middle;">Sex:</td>
                        <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($sex) ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">S.Y.:</td>
                        <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($school_year) ?></td>
                        <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; padding-left: 2mm; vertical-align: middle;">LRN:</td>
                        <td colspan="3" style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($learner['lrn'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Assessment:</td>
                        <td colspan="5" style="border: none; padding: 0.5mm 0; vertical-align: middle;">
                            <?php $assessment_status = $report_card['assessment_status'] ?? ''; ?>
                            <span class="checkbox-item" style="display: inline-block; vertical-align: middle; margin-left: 3px;"><span class="check-box" style="display: inline-block; width: 8px; height: 8px; border: 1px solid var(--border-color); line-height: 8px; text-align: center; font-size: 8px; font-family: monospace;"><?= $assessment_status === 'With' ? 'x' : '&nbsp;' ?></span> With</span>
                            <span class="checkbox-item" style="display: inline-block; vertical-align: middle; margin-left: 10px;"><span class="check-box" style="display: inline-block; width: 8px; height: 8px; border: 1px solid var(--border-color); line-height: 8px; text-align: center; font-size: 8px; font-family: monospace;"><?= $assessment_status === 'Without' ? 'x' : '&nbsp;' ?></span> Without</span>
                        </td>
                    </tr>
                </table>

                <div class="letter-box">
                    <strong style="display:block; margin-bottom: 1mm;">Dear Parents/Guardian,</strong>
                    <p>This report card is designed to show your child's progress in the different learning areas of development and character formation. The school welcomes you to confer with the teacher/principal so that we may best understand your child's special educational needs.</p>
                    
                    <table style="width: 100%; border: none; border-collapse: collapse; margin-top: 3mm; text-align: center;" class="signature-pair-table">
                        <tr>
                            <td style="width: 40%; border: none; padding: 0; text-align: center; vertical-align: top;">
                                <div class="sig-line"><?= h($principal_name) ?></div>
                                <div class="sig-label">Principal</div>
                            </td>
                            <td style="width: 20%; border: none; padding: 0;">&nbsp;</td>
                            <td style="width: 40%; border: none; padding: 0; text-align: center; vertical-align: top;">
                                <div class="sig-line"><?= h($adviser_name) ?></div>
                                <div class="sig-label">Teacher</div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="transfer-block">
                    <h1 class="section-title">Certificate of Transfer</h1>
                    <table style="width: 100%; border: none; border-collapse: collapse; margin-bottom: 2mm;" class="form-grid-table">
                        <tr>
                            <td style="width: 25%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Admitted to:</td>
                            <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($report_card['admitted_to'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Eligible for Admission to:</td>
                            <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($report_card['eligible_for_admission_to'] ?? '') ?></td>
                        </tr>
                    </table>
                    <table style="width: 100%; border: none; border-collapse: collapse; margin-top: 3mm; text-align: center;" class="signature-pair-table">
                        <tr>
                            <td style="width: 40%; border: none; padding: 0; text-align: center; vertical-align: top;">
                                <div class="sig-line"><?= h($report_card['transfer_principal_name'] ?? '') ?></div>
                                <div class="sig-label">Principal</div>
                            </td>
                            <td style="width: 20%; border: none; padding: 0;">&nbsp;</td>
                            <td style="width: 40%; border: none; padding: 0; text-align: center; vertical-align: top;">
                                <div class="sig-line"><?= h($report_card['transfer_teacher_name'] ?? '') ?></div>
                                <div class="sig-label">Teacher</div>
                            </td>
                        </tr>
                    </table>
                    
                    <h1 class="section-title" style="margin-top:3mm;">Cancellation of Eligibility to Transfer</h1>
                    <table style="width: 100%; border: none; border-collapse: collapse; margin-bottom: 2mm;" class="form-grid-table">
                        <tr>
                            <td style="width: 25%; border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Admitted in:</td>
                            <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($report_card['cancellation_admitted_in'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 0.5mm 0; font-weight: 600; font-size: 8.5pt; white-space: nowrap; vertical-align: middle;">Date:</td>
                            <td style="border: none; border-bottom: 1px solid var(--border-color); padding: 0.5mm 0; text-align: center; font-size: 9pt; font-weight: bold; vertical-align: middle;"><?= h($report_card['cancellation_date'] ?? '') ?></td>
                        </tr>
                    </table>
                    <table style="width: 100%; border: none; border-collapse: collapse; margin-top: 3mm; text-align: center;" class="signature-pair-table">
                        <tr>
                            <td style="width: 40%; border: none; padding: 0;">&nbsp;</td>
                            <td style="width: 20%; border: none; padding: 0;">&nbsp;</td>
                            <td style="width: 40%; border: none; padding: 0; text-align: center; vertical-align: top;">
                                <div class="sig-line"><?= h($report_card['cancellation_principal_name'] ?? '') ?></div>
                                <div class="sig-label">Principal</div>
                            </td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <?php foreach ($domain_pairs as $pair): ?>
        <table class="spread a4-fixed inside-spread">
            <tr>
                <td class="panel"><?php render_domain_table($domains[$pair[0]], $show_quarters); ?></td>
                <td class="panel"><?php render_domain_table($domains[$pair[1]], $show_quarters); ?></td>
            </tr>
        </table>
    <?php endforeach; ?>
</body>
</html>
