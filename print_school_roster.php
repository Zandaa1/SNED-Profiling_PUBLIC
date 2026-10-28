<?php
session_start();
require 'config.php';
require_once __DIR__ . '/includes/school_roster_helpers.php';
require_once __DIR__ . '/includes/print_helpers.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access. Please log in.');
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    die('Access denied. Insufficient permissions.');
}

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : (int)($_GET['id'] ?? 0);
if ($school_id <= 0) {
    die('Please provide a school_id parameter.');
}

if (user_is_scoped($user_role) && $school_id !== (int)($_SESSION['school_id'] ?? 0)) {
    die('Access denied. You can only print data for your own school.');
}

$stmt = $conn->prepare('SELECT school_name, district, unit, address FROM schools WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->bind_param('i', $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
if (!$school) {
    die('School not found.');
}

$default_sy = school_roster_current_school_year();
$filters = school_roster_filters_from_request($_GET, $default_sy);
$learners = school_roster_fetch_learners($conn, $school_id, $filters, true);
$filter_summary = school_roster_filter_summary($filters, true);

log_transaction($conn, $_SESSION['user_id'], 'print', 'schools', $school_id, 'Opened printable filtered school roster for ' . $school['school_name'] . ' (' . $filters['school_year'] . ')');

$asset_version = htmlspecialchars(SYSTEM_VERSION, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filtered School Roster</title>
    <link href="css/bootstrap.min.css?v=<?= $asset_version ?>" rel="stylesheet">
    <link href="css/bootstrap-icons.css?v=<?= $asset_version ?>" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body {
            margin: 0;
            padding: 14px 0;
            background: #6b7280;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            line-height: 1.25;
        }
        .sheet {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto 12px;
            padding: 8mm 9mm;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .22);
        }
        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #2d6a4f;
            padding-bottom: 5px;
            margin-bottom: 6px;
            gap: 10px;
        }
        .report-logo { height: 36px; width: 36px; object-fit: contain; flex-shrink: 0; }
        .kicker { text-transform: uppercase; letter-spacing: .08em; color: #6b7280; font-size: 7pt; font-weight: 700; }
        h1 { margin: 2px 0 0; font-size: 13pt; color: #1b4332; font-weight: 800; }
        .meta { color: #4b5563; line-height: 1.35; margin-bottom: 6px; }
        .summary { margin: 6px 0 8px; }
        .pill { display: inline-block; border: 1px solid #d1d5db; border-radius: 999px; padding: 2px 7px; margin: 0 3px 3px 0; background: #f9fafb; color: #374151; font-size: 7.5pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 2.5px 3px; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #eef2ff; color: #1e3a8a; font-size: 6.8pt; text-transform: uppercase; letter-spacing: .02em; text-align: left; }
        td { font-size: 7.2pt; }
        .num { text-align: center; width: 7mm; }
        .lrn { font-family: "Courier New", monospace; white-space: nowrap; width: 22mm; }
        .sex { width: 9mm; }
        .age { width: 9mm; }
        .grade { width: 18mm; }
        .placement { width: 24mm; }
        .assessment { width: 21mm; }
        .interpreter { width: 18mm; }
        .muted { color: #6b7280; text-align: center; padding: 10px; }
        .footer { margin-top: 8px; color: #6b7280; font-size: 7pt; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; margin: 0; padding: 8mm 9mm; }
        }
    </style>
    <?php print_a4_styles('landscape'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print School Roster'); ?>
    <main class="sheet a4-landscape a4-flow">
        <div class="report-header">
            <img src="img/deped-logo.webp" class="report-logo" alt="DepEd Logo">
            <div style="flex-grow: 1; text-align: center; min-width: 0;">
                <div class="kicker">SNED Profiling System · Filtered School Roster</div>
                <h1><?= htmlspecialchars($school['school_name']) ?></h1>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="report-logo" alt="Valenzuela Logo">
        </div>

        <div class="meta">
            School Year: <strong><?= htmlspecialchars($filters['school_year']) ?></strong><br>
            District/Zone: <?= htmlspecialchars($school['district'] ?? '—') ?><?= !empty($school['unit']) ? ' / ' . htmlspecialchars($school['unit']) : '' ?><br>
            Address: <?= htmlspecialchars($school['address'] ?? '—') ?><br>
            Generated: <?= htmlspecialchars(date('M d, Y h:i A')) ?> · Results: <?= count($learners) ?>
        </div>

        <div class="summary">
            <?php if (empty($filter_summary)): ?>
                <span class="pill">No filters selected</span>
            <?php else: ?>
                <?php foreach ($filter_summary as $summary): ?>
                    <span class="pill"><?= htmlspecialchars($summary) ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th class="lrn">LRN</th>
                    <th>Name</th>
                    <th class="sex">Sex</th>
                    <th class="age">Age</th>
                    <th>Address</th>
                    <th class="grade">Grade</th>
                    <th class="placement">Placement</th>
                    <th>Disability / Diagnosis</th>
                    <th class="assessment">Assessment</th>
                    <th class="interpreter">Interpreter</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($learners)): ?>
                    <tr><td colspan="11" class="muted">No active learners match the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($learners as $idx => $learner): ?>
                    <tr>
                        <td class="num"><?= $idx + 1 ?></td>
                        <td class="lrn"><?= htmlspecialchars($learner['lrn']) ?></td>
                        <td><?= htmlspecialchars(trim(($learner['last_name'] ?? '') . ', ' . ($learner['first_name'] ?? '') . ' ' . ($learner['middle_name'] ?? '') . ' ' . ($learner['extension_name'] ?? ''))) ?></td>
                        <td class="sex"><?= htmlspecialchars($learner['sex'] ?? '—') ?></td>
                        <td class="age"><?= htmlspecialchars((string)($learner['age'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars(school_roster_csv_address($learner)) ?></td>
                        <td class="grade"><?= htmlspecialchars($learner['grade_level'] ?? '—') ?></td>
                        <td class="placement"><?= htmlspecialchars(trim(($learner['placement_type'] ?? '') . (!empty($learner['self_contained_level']) ? ' (' . $learner['self_contained_level'] . ')' : '')) ?: '—') ?></td>
                        <td><?= htmlspecialchars($learner['disability_summary'] ?? 'Unassigned') ?></td>
                        <td class="assessment"><?= htmlspecialchars($learner['assessment_mode'] ?? '—') ?></td>
                        <td class="interpreter"><?= htmlspecialchars($learner['interpreter_needed'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">Generated by SNED Profiling System. This report includes active, non-historical enrollments for the selected school year.</div>
    </main>
</body>
</html>
