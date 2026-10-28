<?php
session_start();
require 'config.php';
require_once __DIR__ . '/includes/export_report_helpers.php';
require_once __DIR__ . '/includes/print_helpers.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access. Please log in.');
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    die('Access denied. Insufficient permissions.');
}

$scope = strtolower(trim((string)($_GET['scope'] ?? '')));
$requested_school_id = isset($_GET['school_id']) && $_GET['school_id'] !== '' ? (int)$_GET['school_id'] : null;
$require_school = $scope === 'school';

[$school_id, $school] = sned_export_resolve_school_scope($conn, $user_role, $requested_school_id, $require_school);
if ($school_id === null && user_is_scoped($user_role)) {
    [$school_id, $school] = sned_export_resolve_school_scope($conn, $user_role, (int)($_SESSION['school_id'] ?? 0), true);
}

$has_school_year_param = isset($_GET['school_year']) && trim((string)$_GET['school_year']) !== '';
$school_year = ($school_id !== null || $has_school_year_param)
    ? sned_export_normalize_school_year($_GET['school_year'] ?? null)
    : null;

$learners = sned_export_fetch_lwd_rows($conn, $school_id, $school_year, 18);
$school_name = $school['school_name'] ?? 'All Schools';
$report_scope = $school ? $school_name : 'Master List';
$school_year_label = $school_year ?: 'Latest Active Enrollment';
$prepared_by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

$target_label = $school ? $school['school_name'] : 'Master';
$year_log = $school_year ? ' (' . $school_year . ')' : '';
log_transaction($conn, $_SESSION['user_id'], 'print', 'learners', 'lwd_adults', 'Opened printable LWDs 18 years old and above list for ' . $target_label . $year_log);

$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$title = 'LIST OF LWDs 18 YEARS OLD AND ABOVE';
$generated_on = date('F j, Y g:i A');
$asset_version = htmlspecialchars(SYSTEM_VERSION, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($title) ?></title>
    <link href="css/bootstrap.min.css?v=<?= $asset_version ?>" rel="stylesheet">
    <link href="css/bootstrap-icons.css?v=<?= $asset_version ?>" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body {
            margin: 0;
            padding: 18px 0;
            background: #6b7280;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.35;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 14px;
            padding: 14mm 16mm;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .22);
        }
        .report-header { text-align: center; margin-bottom: 12px; }
        .eyebrow { font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; color: #4b5563; font-weight: 700; }
        h1 { margin: 4px 0; font-size: 15pt; letter-spacing: .04em; font-weight: 800; }
        .meta { margin: 10px 0 12px; display: table; width: 100%; }
        .meta-row { display: table-row; }
        .meta-label, .meta-value { display: table-cell; padding: 2px 0; }
        .meta-label { width: 34mm; font-weight: 700; color: #374151; }
        .line-value { border-bottom: 1px solid #111827; min-height: 15px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
        th, td { border: 1px solid #111827; padding: 5px 6px; vertical-align: middle; }
        th { text-align: center; font-weight: 700; background: #f3f4f6; font-size: 9.5pt; }
        td { font-size: 9.5pt; }
        .num { width: 12mm; text-align: center; }
        .age { width: 18mm; text-align: center; }
        .empty { text-align: center; color: #6b7280; padding: 14px; }
        .footer { margin-top: 22px; width: 72mm; page-break-inside: avoid; break-inside: avoid; }
        .signature-line { border-bottom: 1px solid #111827; height: 22px; }
        .signature-name { text-align: center; font-weight: 700; margin-top: 4px; min-height: 15px; }
        .signature-title { text-align: center; font-size: 9pt; color: #374151; }
        .generated { margin-top: 12px; font-size: 8pt; color: #6b7280; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; margin: 0; padding: 14mm 16mm; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print LWD Adult List'); ?>
    <main class="sheet a4-flow">
        <div class="report-header">
            <div class="eyebrow">Special Needs Education</div>
            <h1><?= $escape($title) ?></h1>
        </div>
        <div class="meta">
            <div class="meta-row"><div class="meta-label">Name of School:</div><div class="meta-value line-value"><?= $escape($school_name) ?></div></div>
            <div class="meta-row"><div class="meta-label">Report Scope:</div><div class="meta-value line-value"><?= $escape($report_scope) ?></div></div>
            <div class="meta-row"><div class="meta-label">School Year:</div><div class="meta-value line-value"><?= $escape($school_year_label) ?></div></div>
        </div>
        <table>
            <thead><tr><th class="num">No.</th><th>Name of LWD</th><th class="age">Age</th></tr></thead>
            <tbody>
                <?php if (empty($learners)): ?>
                    <tr><td colspan="3" class="empty">No LWDs 18 years old and above found for this report.</td></tr>
                <?php else: ?>
                    <?php foreach ($learners as $idx => $learner): ?>
                        <?php $name = trim(($learner['l_last_name'] ?? '') . ', ' . ($learner['l_first_name'] ?? '') . ' ' . ($learner['l_middle_name'] ?? '') . ' ' . ($learner['l_extension_name'] ?? '')); ?>
                        <tr>
                            <td class="num"><?= $idx + 1 ?></td>
                            <td><?= $escape($name) ?></td>
                            <td class="age"><?= $escape($learner['age'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer">
            <div>Prepared by:</div>
            <div class="signature-line"></div>
            <div class="signature-name"><?= $escape($prepared_by) ?></div>
            <div class="signature-title">Inclusive Education Focal Person</div>
        </div>
        <div class="generated">Generated on <?= $escape($generated_on) ?></div>
    </main>
</body>
</html>
