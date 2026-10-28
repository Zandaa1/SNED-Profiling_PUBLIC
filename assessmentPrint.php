<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';
require_once 'includes/print_helpers.php';

// 1. Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';

// 2. Role check — coordinator or above
if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

// 3. Learner validation
$lrn = $_GET['id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid LRN request.");
}

// Scope check
if (!user_is_admin($user_role) && user_is_scoped($user_role)) {
    if (!check_teacher_learner_access($conn, $lrn, false)) {
        header("Location: 403.php");
        exit();
    }
}

// Fetch assessment data using helper
$assessment_data = assessment_load_data($conn, $lrn);
$learner = $assessment_data['learner'];
if (!$learner) {
    die("Learner not found.");
}
$grouped_items = $assessment_data['grouped_items'];
$responses = $assessment_data['responses'];
$dates = $assessment_data['dates'];

$pre_date_formatted = isset($dates['pre_date']) && $dates['pre_date'] ? date('m/d/Y', strtotime($dates['pre_date'])) : '';
$post_date_formatted = isset($dates['post_date']) && $dates['post_date'] ? date('m/d/Y', strtotime($dates['post_date'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Checklist Print — <?= htmlspecialchars(sned_learner_name($learner)) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <?php print_a4_styles('portrait'); ?>
    <style>
        .print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt !important;
            margin-bottom: 12px;
            page-break-inside: auto;
        }
        .print-table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .print-table th {
            border: 1px solid #000;
            padding: 4px 6px;
            font-weight: 700;
            background-color: #f2f2f2 !important;
            text-align: left;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .print-table thead {
            display: table-header-group;
        }
        .print-group-header {
            font-weight: 700;
            background-color: #fafafa !important;
            text-transform: uppercase;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .learning-area-header {
            font-size: 11pt;
            font-weight: 700;
            margin-top: 15px;
            margin-bottom: 8px;
            border-bottom: 2px solid #000;
            padding-bottom: 2px;
            page-break-after: avoid;
        }
        .subsection-header {
            font-size: 9pt;
            font-weight: 700;
            margin-top: 8px;
            margin-bottom: 4px;
            color: #333;
            page-break-after: avoid;
        }
        .rating-col {
            width: 75px;
            text-align: center;
            font-weight: 700;
        }
        .remarks-col {
            width: 150px;
        }
        .legend-box {
            border: 1px solid #000;
            padding: 8px;
            margin-top: 20px;
            font-size: 8pt;
            page-break-inside: avoid;
        }
        /* Custom print optimization */
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .a4-portrait {
                padding: 15mm 15mm !important;
                box-shadow: none !important;
                margin: 0 !important;
                width: auto !important;
                height: auto !important;
            }
        }
    </style>
</head>
<body class="bg-light">

    <?php print_a4_toolbar('Print Assessment Checklist'); ?>

    <main class="page a4-flow a4-portrait shadow-sm bg-white mx-auto my-4 p-5" style="width: 210mm;">
        <!-- Header Info -->
        <div class="row align-items-center mb-2" style="font-size: 9pt;">
            <div class="col-8">
                <strong>Name of Student:</strong> <span class="border-bottom d-inline-block px-1" style="min-width: 250px; border-bottom: 1px solid #000 !important;"><?= htmlspecialchars(sned_learner_name($learner)) ?></span>
            </div>
            <div class="col-4 text-end">
                <strong>LRN:</strong> <span class="border-bottom d-inline-block px-1" style="min-width: 130px; border-bottom: 1px solid #000 !important;">
                    <?php if (empty($learner['lrn_is_temporary'])): ?>
                        <?= htmlspecialchars($learner['lrn']) ?>
                    <?php else: ?>
                        &nbsp;
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="text-center mb-3">
            <h5 class="fw-bold mb-1" style="font-size: 14pt;">Assessment Checklist</h5>
        </div>

        <!-- Overall Pre and Post Dates -->
        <div class="row mb-3 pb-2 border-bottom" style="font-size: 9pt;">
            <div class="col-6">
                <strong>Pre-Assessment Date:</strong> <span class="border-bottom d-inline-block px-1" style="min-width: 150px; border-bottom: 1px solid #000 !important;"><?= htmlspecialchars($pre_date_formatted ?: 'N/A') ?></span>
            </div>
            <div class="col-6 text-end">
                <strong>Post-Assessment Date:</strong> <span class="border-bottom d-inline-block px-1" style="min-width: 150px; border-bottom: 1px solid #000 !important;"><?= htmlspecialchars($post_date_formatted ?: 'N/A') ?></span>
            </div>
        </div>

        <!-- Render each Learning Area -->
        <?php foreach ($grouped_items as $area => $subsections): 
            $area_label = ucwords(strtolower(str_replace('_', ' ', $area)));
        ?>
            <div class="learning-area-header text-uppercase">LEARNING AREA: <?= htmlspecialchars($area_label) ?></div>
            
            <?php foreach ($subsections as $sub => $items_list): ?>
                <?php if (count($grouped_items[$area]) > 1 || $sub !== $area): ?>
                    <div class="subsection-header text-uppercase"><?= htmlspecialchars($sub) ?></div>
                <?php endif; ?>
                
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Skills</th>
                            <th class="text-center" style="width: 12.5%;">Pre-Assessment</th>
                            <th class="text-center" style="width: 12.5%;">Post-Assessment</th>
                            <th style="width: 25%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $prev_group = null;
                        foreach ($items_list as $item):
                            $item_id = $item['id'];
                            $resp = $responses[$item_id] ?? null;
                            $curr_pre_rating = $resp['pre_rating'] ?? '';
                            $curr_post_rating = $resp['post_rating'] ?? '';
                            $curr_remarks = $resp['remarks'] ?? '';
                            
                            if ($item['group_header'] !== null && $item['group_header'] !== $prev_group):
                                $prev_group = $item['group_header'];
                        ?>
                                <tr class="print-group-header">
                                    <td colspan="4" class="fw-bold text-uppercase py-1"><?= htmlspecialchars($prev_group) ?></td>
                                </tr>
                        <?php 
                            endif;
                            if ($item['group_header'] === null) {
                                $prev_group = null;
                            }
                        ?>
                            <tr>
                                <td>
                                    <?php if ($item['item_number']): ?>
                                        <span class="font-monospace fw-bold"><?= htmlspecialchars($item['item_number']) ?>.</span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($item['item_text']) ?>
                                </td>
                                <td class="rating-col"><?= htmlspecialchars($curr_pre_rating) ?></td>
                                <td class="rating-col"><?= htmlspecialchars($curr_post_rating) ?></td>
                                <td class="remarks-col text-uppercase"><?= htmlspecialchars($curr_remarks) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <!-- Legend Footer -->
        <div class="legend-box mt-4">
            <div class="fw-bold mb-1">Legend:</div>
            <div class="d-flex gap-4">
                <div><strong>E</strong> = Evident</div>
                <div><strong>NYE</strong> = Not Yet Evident</div>
                <div><strong>NO</strong> = Not Observed</div>
            </div>
        </div>
    </main>

</body>
</html>
