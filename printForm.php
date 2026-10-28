<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
include 'includes/helpers.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

$lrn = $_GET['id'] ?? '';
if (!isset($conn) || empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die('Invalid LRN request.');
}

// Teacher gate
if (!check_teacher_learner_access($conn, $lrn)) {
    header("Location: 403.php");
    exit();
}

// Fetch Learner
$stmt = $conn->prepare("SELECT * FROM learners WHERE lrn = ? AND is_deleted = 0 LIMIT 1");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$result = $stmt->get_result();
$learner = $result ? $result->fetch_assoc() : null;

if (!$learner) {
    die('Learner not found.');
}

// Log Print Transaction
log_transaction($conn, $_SESSION['user_id'], 'print', 'learners', $lrn, "Printed intake case study report for LRN: $lrn");

// Fetch Guardian
$guardian = null;
if (!empty($learner['guardian_id'])) {
    $stmt_g = $conn->prepare("SELECT * FROM guardians WHERE id = ?");
    $stmt_g->bind_param("i", $learner['guardian_id']);
    $stmt_g->execute();
    $res_g = $stmt_g->get_result();
    $guardian = $res_g ? $res_g->fetch_assoc() : null;
}

// Fetch Historical Enrollments
$stmt_e = $conn->prepare("
    SELECT e.*, s.school_name, s.district 
    FROM enrollments e 
    JOIN schools s ON e.school_id = s.id 
    WHERE e.lrn = ? AND e.is_deleted = 0
    ORDER BY e.school_year DESC
");
$stmt_e->bind_param("s", $lrn);
$stmt_e->execute();
$res_e = $stmt_e->get_result();
$enrollments = [];
while ($row = $res_e->fetch_assoc()) {
    $disability_rows = sned_get_enrollment_disabilities($conn, (int)$row['id']);
    if (empty($disability_rows) && !empty($row['primary_diagnosis'])) {
        $disability_rows = sned_disability_rows_from_legacy_diagnosis($row['primary_diagnosis']);
    }
    $row['disability_summary'] = sned_disability_summary($disability_rows, $row['primary_diagnosis'] ?: 'Unassigned');
    $enrollments[] = $row;
}

// Age helper
if (!function_exists('calculate_age')) {
    function calculate_age($birthday) {
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        return $today->diff($birthDate)->y;
    }
}
$age = calculate_age($learner['birthday']);

// As-of date for principal lookup
$as_of = $_GET['as_of'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) $as_of = date('Y-m-d');

// Fetch principal from current enrollment's school
$principal_name = null;
if (!empty($enrollments)) {
    $principal_name = get_principal_at($conn, (int)$enrollments[0]['school_id'], $as_of);
}
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
    <title>Intake Report - LRN <?= htmlspecialchars($lrn) ?> | SNED Profiling</title>
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
    <style>
        /* Local Inter webfont (woff2, matches tokens.css) */
        @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url("css/fonts/Inter-Regular.woff2") format("woff2");
        }
        @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url("css/fonts/Inter-Medium.woff2") format("woff2");
        }
        @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url("css/fonts/Inter-SemiBold.woff2") format("woff2");
        }
        @font-face {
            font-family: "Inter";
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url("css/fonts/Inter-Bold.woff2") format("woff2");
        }

        @page {
            size: A4;
            margin: 0;
        }

        body {
            font-family: 'Inter', Arial, Helvetica, sans-serif;
            background-color: #6b7280;
            font-size: 9.5pt;
            padding: 30px 0;
            color: #000;
            line-height: 1.4;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            max-height: 297mm;
            padding: 8mm 13mm 6mm 13mm;
            margin: 20px auto;
            background: white;
            box-shadow: 0 6px 18px rgba(0,0,0,.22);
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 1.5px solid #000;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }

        .header-logo {
            height: 38px;
            width: auto;
            object-fit: contain;
        }

        .header-text {
            text-align: center;
            flex-grow: 1;
            line-height: 1.25;
        }

        .header-gov {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            font-weight: 600;
        }

        .header-dep {
            font-size: 9.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #000;
        }

        .header-div {
            font-size: 8pt;
            color: #444;
            font-weight: 500;
        }

        .header-sch {
            font-size: 8.5pt;
            font-weight: 700;
            color: #000;
            margin-top: 1px;
            text-transform: uppercase;
        }

        .title-bar {
            background: #d9d9d9;
            border: 1px solid #000;
            padding: 2px 10px;
            text-align: center;
            margin-bottom: 4px;
        }

        .title-bar h1 {
            font-size: 11pt;
            font-weight: 800;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .title-bar .title-school {
            font-size: 8pt;
            color: #333;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 0;
        }

        .hero-band {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid #000;
            padding: 5px 8px;
            margin-bottom: 4px;
        }

        .hero-photo {
            width: 24mm;
            height: 30mm;
            border: 1px solid #000;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            flex-shrink: 0;
        }

        .hero-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .hero-info {
            flex: 1;
            min-width: 0;
        }

        .hero-name {
            font-size: 14pt;
            font-weight: 800;
            color: #000;
            text-transform: uppercase;
            line-height: 1.15;
            margin-bottom: 2px;
        }

        .hero-sub {
            font-size: 9.5pt;
            color: #333;
            line-height: 1.3;
        }

        .hero-sub strong {
            font-weight: 700;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 10px;
            background: #2d6a4f;
            color: #fff;
            font-size: 8pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
            vertical-align: middle;
            margin-left: 6px;
        }

        .status-pill-inactive {
            background: #888;
        }

        .key-facts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            margin-bottom: 4px;
        }

        .key-facts-grid .fact-card {
            border: 1px solid #000;
            padding: 4px 6px;
        }

        .key-facts-grid .fact-card.fact-full {
            grid-column: span 3;
        }

        .fact-label {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .fact-value {
            font-size: 10pt;
            font-weight: 700;
            color: #000;
            word-break: break-word;
            line-height: 1.25;
        }

        .fact-value-sm {
            font-size: 9pt;
        }

        .section-header {
            font-size: 8.5pt;
            font-weight: 700;
            color: #000;
            border: 1px solid #000;
            background: #d9d9d9;
            padding: 1px 5px;
            margin-top: 4px;
            margin-bottom: 3px;
            text-transform: uppercase;
            page-break-after: avoid;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            margin-bottom: 4px;
        }

        .info-grid-2 {
            grid-template-columns: repeat(2, 1fr);
        }

        .info-item {
            display: flex;
            flex-direction: column;
            padding: 3px 5px;
            border: 1px solid #000;
        }

        .info-label {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .info-value {
            font-weight: 600;
            color: #000;
            font-size: 9.5pt;
            word-break: break-word;
            line-height: 1.3;
        }

        .id-strip {
            border-top: 1px solid #000;
            padding-top: 2px;
            margin-top: 3px;
            font-size: 7.5pt;
            color: #444;
            font-family: 'Courier New', Courier, monospace;
            font-weight: 400;
            letter-spacing: 0.3px;
        }

        .id-strip span {
            margin: 0 6px;
            color: #888;
        }

        .page-footer {
            border-top: 1px solid #000;
            padding-top: 3px;
            margin-top: auto;
        }

        .page-footer-text {
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            color: #555;
            font-weight: 500;
        }

        .confidential-block {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 6.5pt;
            color: #444;
            gap: 6px;
        }

        .confidential-text {
            max-width: 78%;
            line-height: 1.25;
            border-left: 2px solid #c00;
            padding-left: 5px;
        }

        .confidential-text strong {
            font-size: 7pt;
            text-transform: uppercase;
            color: #c00;
        }

        .qr-box {
            flex-shrink: 0;
            text-align: center;
            line-height: 1.1;
            padding: 2px;
            border: 1px solid #ccc;
            background: #f5f5f5;
        }

        .running-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .running-header-title {
            font-size: 9pt;
            font-weight: 800;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.75px;
        }

        .running-header-lrn {
            font-size: 8.5pt;
            color: #555;
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
        }

        .timeline {
            list-style: none;
            padding: 0;
            margin: 0 0 10px 0;
        }

        .timeline-entry {
            display: flex;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #ccc;
        }

        .timeline-entry:last-child {
            border-bottom: none;
        }

        .timeline-year {
            font-size: 14pt;
            font-weight: 800;
            color: #000;
            min-width: 80px;
            flex-shrink: 0;
        }

        .timeline-details {
            flex: 1;
            min-width: 0;
        }

        .timeline-program {
            font-size: 10pt;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
        }

        .timeline-school {
            font-size: 8.5pt;
            color: #444;
            margin-top: 1px;
        }

        .timeline-remarks {
            font-size: 8pt;
            color: #555;
            font-style: italic;
            margin-top: 2px;
        }

        .timeline-status {
            display: inline-block;
            padding: 1px 8px;
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 2px;
            vertical-align: middle;
            flex-shrink: 0;
        }

        .timeline-status-active {
            background: #2d6a4f;
            color: #fff;
        }

        .timeline-status-past {
            background: #d9d9d9;
            color: #333;
            border: 1px solid #999;
        }

        .signature-area {
            margin-top: auto;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            text-align: center;
            padding-top: 20px;
        }

        .sig-line {
            border-top: 1.5px solid #000;
            margin-top: 30px;
            font-weight: 700;
            font-size: 9.5pt;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-top: 5px;
        }

        .sig-role {
            font-size: 8.5pt;
            color: #444;
            font-weight: 500;
            margin-top: 4px;
        }

        .sig-date {
            font-size: 8.5pt;
            color: #555;
            text-align: center;
            margin-top: 8px;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
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
                border-radius: 0;
                width: 210mm;
                min-height: 297mm;
                max-height: 297mm;
                page-break-after: always;
                padding: 8mm 13mm 6mm 13mm;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Intake Case Study'); ?>

    <!-- PAGE 1: DEMOGRAPHICS & GUARDIAN PROFILE -->
    <div class="page a4-fixed">
        <!-- Official Header -->
        <div class="header-container">
            <img src="img/deped-logo.webp" class="header-logo" alt="DepEd Logo">
            <div class="header-text">
                <div class="header-gov">Republic of the Philippines</div>
                <div class="header-dep">Department of Education</div>
                <div class="header-div">Schools Division Office of Valenzuela City</div>
                <div class="header-sch"><?= !empty($enrollments) ? htmlspecialchars($enrollments[0]['school_name']) : 'Valenzuela SPED Services' ?></div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="header-logo" alt="Valenzuela Logo">
        </div>

        <!-- Title Bar -->
        <div class="title-bar">
            <h1>Learner Intake Case Study</h1>
        </div>

        <!-- Hero Band: Photo + Name + Status -->
        <div class="hero-band">
            <div class="hero-photo">
                <img src="<?= $photo_src ? htmlspecialchars($photo_src) : 'img/student_placeholder.webp' ?>" alt="Student Photo">
            </div>
            <div class="hero-info">
                <div class="hero-name">
                    <?= htmlspecialchars(rtrim($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name'] . ' ' . $learner['extension_name'])) ?>
                    <?php
                    $is_active = !empty($enrollments) && empty($enrollments[0]['remarks']);
                    $has_enrollment = !empty($enrollments);
                    ?>
                    <?php if ($has_enrollment): ?>
                        <span class="status-pill <?= $is_active ? '' : 'status-pill-inactive' ?>">
                            <?= $is_active ? 'Active' : 'Active' ?>
                        </span>
                    <?php else: ?>
                        <span class="status-pill status-pill-inactive">Not Enrolled</span>
                    <?php endif; ?>
                </div>
                <div class="hero-sub">
                    <strong><?= htmlspecialchars($learner['sex']) ?></strong> &middot;
                    <?= $age ?> years old &middot;
                    Born <?= date('M d, Y', strtotime($learner['birthday'])) ?>
                    <?php if ($age >= 18): ?>
                        <span style="color: #c00; font-weight: 700; font-size: 8.5pt;">[Adult LWD]</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Key Facts Grid -->
        <div class="key-facts-grid">
            <div class="fact-card">
                <div class="fact-label"><i class="bi bi-building"></i> School</div>
                <div class="fact-value fact-value-sm"><?= !empty($enrollments) ? htmlspecialchars($enrollments[0]['school_name']) : 'Not Enrolled' ?></div>
            </div>
            <div class="fact-card">
                <div class="fact-label"><i class="bi bi-mortarboard"></i> Grade / Program</div>
                <div class="fact-value fact-value-sm">
                    <?php if (!empty($enrollments)): ?>
                        <?= htmlspecialchars($enrollments[0]['grade_level'] ?: 'N/A') ?>
                        <?php if (!empty($enrollments[0]['self_contained_level'])): ?>
                            <br><span style="font-weight: 400; font-size: 9pt; color: #333;"><?= htmlspecialchars($enrollments[0]['self_contained_level']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
            </div>
            <div class="fact-card">
                <div class="fact-label"><i class="bi bi-calendar3"></i> School Year</div>
                <div class="fact-value fact-value-sm"><?= !empty($enrollments) ? htmlspecialchars($enrollments[0]['school_year']) : 'N/A' ?></div>
            </div>
            <div class="fact-card fact-full">
                <div class="fact-label"><i class="bi bi-clipboard2-pulse"></i> Disability / Diagnosis</div>
                <div class="fact-value" style="font-weight: 700;"><?= !empty($enrollments) ? htmlspecialchars($enrollments[0]['disability_summary']) : 'N/A' ?></div>
            </div>
        </div>

        <!-- Guardian Section -->
        <div class="section-header"><i class="bi bi-person"></i> Guardian</div>
        <div class="info-grid info-grid-2">
            <div class="info-item" style="grid-column: span 2;">
                <span class="info-label">Guardian Name</span>
                <span class="info-value"><?= htmlspecialchars($guardian ? rtrim(($guardian['last_name'] ?? '') . ', ' . ($guardian['first_name'] ?? '') . ' ' . ($guardian['middle_name'] ?? '') . ' ' . ($guardian['extension_name'] ?? '')) : 'Not on file') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Relationship</span>
                <span class="info-value"><?= htmlspecialchars($guardian['relationship'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label"><i class="bi bi-telephone"></i> Contact Number</span>
                <span class="info-value"><?= htmlspecialchars($guardian['contact_number'] ?? 'N/A') ?></span>
            </div>
            <?php if (!empty($guardian['secondary_contact_number'])): ?>
            <div class="info-item">
                <span class="info-label"><i class="bi bi-telephone"></i> Secondary Contact</span>
                <span class="info-value"><?= htmlspecialchars($guardian['secondary_contact_number']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-item">
                <span class="info-label"><i class="bi bi-geo-alt"></i> Barangay</span>
                <span class="info-value"><?= htmlspecialchars($guardian['barangay'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">House No. &amp; Street</span>
                <span class="info-value"><?= htmlspecialchars($guardian['house_no_street'] ?? 'N/A') ?></span>
            </div>
        </div>

        <!-- Parental Profiles -->
        <?php
        $has_mother = !empty($guardian['mother_first_name']) && empty($guardian['no_mother']);
        $has_father = !empty($guardian['father_first_name']) && empty($guardian['no_father']);
        ?>
        <?php if ($has_mother || $has_father): ?>
        <div class="section-header"><i class="bi bi-people"></i> Parental Profiles</div>
        <div class="info-grid info-grid-2" style="margin-bottom: 0;">
            <?php if ($has_mother): ?>
            <div class="info-item" style="grid-column: span 2;">
                <span class="info-label">Mother's Name</span>
                <span class="info-value"><?= htmlspecialchars(rtrim(($guardian['mother_last_name'] ?? '') . ', ' . ($guardian['mother_first_name'] ?? '') . ' ' . ($guardian['mother_middle_name'] ?? '') . ' ' . ($guardian['mother_extension_name'] ?? ''))) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label"><i class="bi bi-telephone"></i> Mother's Contact</span>
                <span class="info-value"><?= htmlspecialchars($guardian['mother_contact_number'] ?? 'N/A') ?><?= !empty($guardian['mother_same_as_guardian']) ? ' <em style="font-size: 8pt; color: #555;">(Same as Guardian)</em>' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($has_father): ?>
            <div class="info-item" style="grid-column: span 2;">
                <span class="info-label">Father's Name</span>
                <span class="info-value"><?= htmlspecialchars(rtrim(($guardian['father_last_name'] ?? '') . ', ' . ($guardian['father_first_name'] ?? '') . ' ' . ($guardian['father_middle_name'] ?? '') . ' ' . ($guardian['father_extension_name'] ?? ''))) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label"><i class="bi bi-telephone"></i> Father's Contact</span>
                <span class="info-value"><?= htmlspecialchars($guardian['father_contact_number'] ?? 'N/A') ?><?= !empty($guardian['father_same_as_guardian']) ? ' <em style="font-size: 8pt; color: #555;">(Same as Guardian)</em>' : '' ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ID Strip -->
        <div class="id-strip">
            LRN: <?= htmlspecialchars($learner['lrn']) ?>
            <?php if (!empty($learner['profile_id'])): ?>
                <span>&middot;</span> Alt ID: <?= htmlspecialchars($learner['profile_id']) ?>
            <?php endif; ?>
            <?php if (!empty($learner['pwd_id_number'])): ?>
                <span>&middot;</span> PWD ID: <?= htmlspecialchars($learner['pwd_id_number']) ?>
            <?php endif; ?>
        </div>

        <!-- Page 1 Footer: Confidentiality + QR -->
        <div class="page-footer">
            <div class="confidential-block">
                <div class="confidential-text">
                    <strong>CONFIDENTIAL — CASE STUDY INTAKE DOCUMENT</strong><br>
                    This form contains sensitive personal identification details, residential profiles, and private medical diagnosis records of a learner with disability. Management and transmission of this file is strictly regulated under the <strong>Data Privacy Act of 2012 (Republic Act 10173)</strong>.
                </div>
                <div class="qr-box">
                    <?php
                        $qr_options = new QROptions([
                            'version'      => \chillerlan\QRCode\Common\Version::AUTO,
                            'eccLevel'     => EccLevel::M,
                            'addQuietzone' => false,
                            'outputBase64' => true,
                        ]);
                        $qrcode_data = (new QRCode($qr_options))->render($lrn);
                        echo '<img src="' . $qrcode_data . '" style="width: 14mm; height: 14mm; background: white;">';
                    ?>
                    <div style="font-size: 6pt; font-family: monospace; font-weight: 700; color: #333; margin-top: 2px;">LRN: <?= htmlspecialchars($lrn) ?></div>
                </div>
            </div>
            <div class="page-footer-text" style="margin-top: 4px; border-top: 1px solid #ccc; padding-top: 3px;">
                <div>Intake Case Study - Page 1 of 2</div>
                <div>Division of Valenzuela City - Special Needs Education Services</div>
            </div>
        </div>
    </div>

    <!-- PAGE 2: ENROLLMENT HISTORY & SIGNATURES -->
    <div class="page a4-fixed">
        <!-- Running Header -->
        <div class="running-header">
            <div class="running-header-title">Learner Intake Case Study</div>
            <div class="running-header-lrn">LRN: <?= htmlspecialchars($lrn) ?></div>
        </div>

        <!-- Enrollment History Timeline -->
        <div class="section-header"><i class="bi bi-clock-history"></i> Enrollment History</div>

        <?php if (empty($enrollments)): ?>
            <p style="font-size: 9pt; color: #555; text-align: center; padding: 20px 0;">No historical academic enrollment logs found.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($enrollments as $idx => $e): ?>
                    <li class="timeline-entry">
                        <div class="timeline-year"><?= htmlspecialchars($e['school_year']) ?></div>
                        <div class="timeline-details">
                            <div class="timeline-program">
                                <?= htmlspecialchars($e['grade_level'] ?: 'N/A') ?>
                                <?= $e['placement_type'] ? ' &mdash; ' . htmlspecialchars($e['placement_type']) : '' ?>
                                <?= $e['self_contained_level'] ? ' (' . htmlspecialchars($e['self_contained_level']) . ')' : '' ?>
                            </div>
                            <div class="timeline-school"><?= htmlspecialchars($e['school_name']) ?></div>
                            <?php if ($e['remarks']): ?>
                                <div class="timeline-remarks">Placement Remarks: <?= htmlspecialchars($e['remarks']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-status <?= $idx === 0 ? 'timeline-status-active' : 'timeline-status-past' ?>">
                            <?= $idx === 0 ? 'Active' : 'Past' ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="signature-area" style="margin-top: auto;">
            <div>
                <div class="sig-line">Prepared By</div>
                <div class="sig-role">SNED Classroom Teacher / Social Worker</div>
                <div class="sig-date">Date: ________________________</div>
            </div>
            <div>
                <div class="sig-line"><?= htmlspecialchars($principal_name ?? 'Approved By') ?></div>
                <div class="sig-role"><?= $principal_name ? 'School Principal' : 'School Principal / Center Coordinator' ?></div>
                <div class="sig-date">Date: ________________________</div>
            </div>
        </div>

        <!-- Page 2 Footer -->
        <div class="page-footer-text" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 6px;">
            <div>Intake Case Study - Page 2 of 2</div>
            <div>Division of Valenzuela City - Special Needs Education Services</div>
        </div>
    </div>
</body>
</html>
