<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['learner_id'] ?? $_GET['lrn'] ?? $_POST['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner LRN.");
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

$stmt_active = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level,
           e.grade_level, e.primary_diagnosis, s.school_name, s.district, s.address AS school_address
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC
    LIMIT 1
");
$stmt_active->bind_param("s", $lrn);
$stmt_active->execute();
$active_learner = $stmt_active->get_result()->fetch_assoc();
if (!$active_learner) {
    die("Learner not found.");
}

$school_year = trim($_GET['school_year'] ?? $_GET['sy'] ?? $_POST['school_year'] ?? ($active_learner['school_year'] ?? ''));
if ($school_year === '') {
    $cy = (int)date('Y');
    $cm = (int)date('n');
    $school_year = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
}

$stmt_learner = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level,
           e.grade_level, e.primary_diagnosis, e.is_historical,
            s.school_name, s.district, s.address AS school_address,
            g.first_name AS guardian_first_name, g.last_name AS guardian_last_name,
            lg.full_name AS primary_contact_name, lg.contact_number AS primary_contact_number,
            lg.email AS primary_contact_email, lg.occupation AS primary_contact_occupation
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.school_year = ? AND e.is_deleted = 0
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
    ORDER BY e.is_historical ASC, e.id DESC
    LIMIT 1
");
$stmt_learner->bind_param("ss", $school_year, $lrn);
$stmt_learner->execute();
$learner = $stmt_learner->get_result()->fetch_assoc();
if (!$learner) {
    die("Learner not found.");
}

if (empty($learner['enrollment_id'])) {
    $learner = array_merge($active_learner, $learner);
    $learner['school_year'] = $school_year;
}

sned_block_unless_non_graded($learner, 'Non Graded Progress Report');

if (user_is_scoped($user_role)) {
    $has_access = false;
    if (user_is_teacher($user_role)) {
        $has_access = check_teacher_learner_access($conn, $lrn);
    } elseif (!empty($learner['school_id']) && $user_school_id) {
        $has_access = ((int)$learner['school_id'] === (int)$user_school_id);
    } elseif (!empty($active_learner['school_id']) && $user_school_id) {
        $has_access = ((int)$active_learner['school_id'] === (int)$user_school_id);
    }

    if (!$has_access) {
        header("Location: 403.php");
        exit();
    }
}

$is_active_sy = ($school_year === ($active_learner['school_year'] ?? ''));
$can_edit = user_is_coordinator($user_role) && $is_active_sy && check_teacher_learner_access($conn, $lrn, true);

if ($can_edit && !empty($learner['enrollment_id'])) {
    ensure_sf9_indicator_ratings($conn, $lrn, (int)$learner['enrollment_id'], $school_year);
}

function sf9_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sf9_nullable_int($value): ?int {
    if ($value === null || $value === '') return null;
    return max(0, (int)$value);
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit) {
        header("Location: 403.php");
        exit();
    }
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }
    $current_user_id = (int)$_SESSION['user_id'];

    $assessment_status = $_POST['assessment_status'] ?? null;
    if (!in_array($assessment_status, ['With', 'Without', ''], true)) {
        $assessment_status = null;
    }
    if ($assessment_status === '') $assessment_status = null;

    $admitted_to = trim($_POST['admitted_to'] ?? '');
    $eligible_for_admission_to = trim($_POST['eligible_for_admission_to'] ?? '');
    $transfer_teacher_name = trim($_POST['transfer_teacher_name'] ?? '');
    $transfer_principal_name = trim($_POST['transfer_principal_name'] ?? '');
    $cancellation_admitted_in = trim($_POST['cancellation_admitted_in'] ?? '');
    $cancellation_date = trim($_POST['cancellation_date'] ?? '');
    $cancellation_principal_name = trim($_POST['cancellation_principal_name'] ?? '');
    if ($cancellation_date === '') $cancellation_date = null;

    $stmt_card = $conn->prepare("
        INSERT INTO sf9_report_cards (
            lrn, school_year, assessment_status, admitted_to, eligible_for_admission_to,
            transfer_teacher_name, transfer_principal_name, cancellation_admitted_in,
            cancellation_date, cancellation_principal_name, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            assessment_status = VALUES(assessment_status),
            admitted_to = VALUES(admitted_to),
            eligible_for_admission_to = VALUES(eligible_for_admission_to),
            transfer_teacher_name = VALUES(transfer_teacher_name),
            transfer_principal_name = VALUES(transfer_principal_name),
            cancellation_admitted_in = VALUES(cancellation_admitted_in),
            cancellation_date = VALUES(cancellation_date),
            cancellation_principal_name = VALUES(cancellation_principal_name),
            updated_by = VALUES(updated_by),
            id = LAST_INSERT_ID(id)
    ");
    $stmt_card->bind_param(
        "ssssssssssi",
        $lrn,
        $school_year,
        $assessment_status,
        $admitted_to,
        $eligible_for_admission_to,
        $transfer_teacher_name,
        $transfer_principal_name,
        $cancellation_admitted_in,
        $cancellation_date,
        $cancellation_principal_name,
        $current_user_id
    );
    $stmt_card->execute();
    $report_card_id = (int)$conn->insert_id;

    $stmt_att = $conn->prepare("
        INSERT INTO sf9_attendance (report_card_id, month_order, month_label, school_days, days_present, days_absent)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            month_label = VALUES(month_label),
            school_days = VALUES(school_days),
            days_present = VALUES(days_present),
            days_absent = VALUES(days_absent)
    ");
    foreach (sf9_months() as $order => $label) {
        $school_days = sf9_nullable_int($_POST['attendance'][$order]['school_days'] ?? null);
        $days_present = sf9_nullable_int($_POST['attendance'][$order]['days_present'] ?? null);
        $days_absent = sf9_nullable_int($_POST['attendance'][$order]['days_absent'] ?? null);
        $stmt_att->bind_param("iisiii", $report_card_id, $order, $label, $school_days, $days_present, $days_absent);
        $stmt_att->execute();
    }

    $stmt_note = $conn->prepare("
        INSERT INTO sf9_quarter_notes (report_card_id, quarter, teacher_remarks, parent_comments, parent_signature)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            teacher_remarks = VALUES(teacher_remarks),
            parent_comments = VALUES(parent_comments),
            parent_signature = VALUES(parent_signature)
    ");
    for ($quarter = 1; $quarter <= 4; $quarter++) {
        $teacher_remarks = trim($_POST['quarter_notes'][$quarter]['teacher_remarks'] ?? '');
        $parent_comments = trim($_POST['quarter_notes'][$quarter]['parent_comments'] ?? '');
        $parent_signature = trim($_POST['quarter_notes'][$quarter]['parent_signature'] ?? '');
        $stmt_note->bind_param("iisss", $report_card_id, $quarter, $teacher_remarks, $parent_comments, $parent_signature);
        $stmt_note->execute();
    }

    $valid_ratings = array_keys(sf9_rating_options());
    $stmt_allowed = $conn->prepare("
        SELECT id
        FROM learner_indicator_ratings
        WHERE learner_id = ? AND school_year = ?
    ");
    $stmt_allowed->bind_param("ss", $lrn, $school_year);
    $stmt_allowed->execute();
    $allowed_ids = [];
    $res_allowed = $stmt_allowed->get_result();
    while ($row = $res_allowed->fetch_assoc()) {
        $allowed_ids[(int)$row['id']] = true;
    }

    $stmt_rating = $conn->prepare("
        UPDATE learner_indicator_ratings
        SET q1 = ?, q2 = ?, q3 = ?, q4 = ?, updated_by = ?
        WHERE id = ?
    ");
    foreach ($_POST['ratings'] ?? [] as $rating_id => $quarters) {
        $rating_id = (int)$rating_id;
        if (!isset($allowed_ids[$rating_id])) {
            continue;
        }
        $q1 = $quarters['q1'] ?? '';
        $q2 = $quarters['q2'] ?? '';
        $q3 = $quarters['q3'] ?? '';
        $q4 = $quarters['q4'] ?? '';
        foreach (['q1', 'q2', 'q3', 'q4'] as $qkey) {
            if (!in_array($$qkey, $valid_ratings, true)) {
                $$qkey = '';
            }
            if ($$qkey === '') {
                $$qkey = null;
            }
        }
        $stmt_rating->bind_param("ssssii", $q1, $q2, $q3, $q4, $current_user_id, $rating_id);
        $stmt_rating->execute();
    }

    log_transaction($conn, $_SESSION['user_id'], 'update', 'sf9', $lrn, "Saved SF9 report card for LRN: $lrn (SY $school_year)");

    header("Location: sf9_form.php?learner_id=" . urlencode($lrn) . "&school_year=" . urlencode($school_year) . "&saved=1");
    exit();
}

// Copy-forward from previous school year
$copy_from_sy = $_GET['copyFromSy'] ?? null;
$copy_applied = null;
if ($copy_from_sy && preg_match('/^\d{4}-\d{4}$/', $copy_from_sy) && $copy_from_sy !== $school_year) {
    $stmt_copy_card = $conn->prepare("SELECT * FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
    $stmt_copy_card->bind_param("ss", $lrn, $copy_from_sy);
    $stmt_copy_card->execute();
    $source_card = $stmt_copy_card->get_result()->fetch_assoc();
    if ($source_card) {
        $copy_applied = $copy_from_sy;
        $source_card['school_year'] = $school_year;
        unset($source_card['id'], $source_card['created_at'], $source_card['updated_at']);

        $columns = implode(', ', array_keys($source_card));
        $placeholders = implode(', ', array_fill(0, count($source_card), '?'));
        $update_parts = [];
        foreach (array_keys($source_card) as $col) {
            if ($col !== 'lrn' && $col !== 'school_year') {
                $update_parts[] = "$col = VALUES($col)";
            }
        }
        $update_str = implode(', ', $update_parts);
        $types = str_repeat('s', count($source_card));
        $values = array_values($source_card);

        $stmt_upsert = $conn->prepare("INSERT INTO sf9_report_cards ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $update_str");
        $stmt_upsert->bind_param($types, ...$values);
        $stmt_upsert->execute();
        $report_card_id = (int)$conn->insert_id;
        if ($report_card_id === 0) {
            $stmt_get_id = $conn->prepare("SELECT id FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
            $stmt_get_id->bind_param("ss", $lrn, $school_year);
            $stmt_get_id->execute();
            $report_card_id = (int)($stmt_get_id->get_result()->fetch_assoc()['id'] ?? 0);
        }

        $stmt_copy_att = $conn->prepare("SELECT * FROM sf9_attendance WHERE report_card_id = (SELECT id FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1)");
        $stmt_copy_att->bind_param("ss", $lrn, $copy_from_sy);
        $stmt_copy_att->execute();
        $source_att = $stmt_copy_att->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($source_att as $att) {
            $stmt_ins_att = $conn->prepare("INSERT INTO sf9_attendance (report_card_id, month_order, month_label, school_days, days_present, days_absent) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE month_label = VALUES(month_label), school_days = VALUES(school_days), days_present = VALUES(days_present), days_absent = VALUES(days_absent)");
            $stmt_ins_att->bind_param("iisiii", $report_card_id, $att['month_order'], $att['month_label'], $att['school_days'], $att['days_present'], $att['days_absent']);
            $stmt_ins_att->execute();
        }

        $stmt_copy_notes = $conn->prepare("SELECT * FROM sf9_quarter_notes WHERE report_card_id = (SELECT id FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1)");
        $stmt_copy_notes->bind_param("ss", $lrn, $copy_from_sy);
        $stmt_copy_notes->execute();
        $source_notes = $stmt_copy_notes->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($source_notes as $note) {
            $stmt_ins_note = $conn->prepare("INSERT INTO sf9_quarter_notes (report_card_id, quarter, teacher_remarks, parent_comments, parent_signature) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE teacher_remarks = VALUES(teacher_remarks), parent_comments = VALUES(parent_comments), parent_signature = VALUES(parent_signature)");
            $stmt_ins_note->bind_param("iisss", $report_card_id, $note['quarter'], $note['teacher_remarks'], $note['parent_comments'], $note['parent_signature']);
            $stmt_ins_note->execute();
        }

        $stmt_copy_ratings = $conn->prepare("SELECT * FROM learner_indicator_ratings WHERE learner_id = ? AND school_year = ?");
        $stmt_copy_ratings->bind_param("ss", $lrn, $copy_from_sy);
        $stmt_copy_ratings->execute();
        $source_ratings = $stmt_copy_ratings->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($source_ratings as $rating) {
            $stmt_upd = $conn->prepare("UPDATE learner_indicator_ratings SET q1 = ?, q2 = ?, q3 = ?, q4 = ? WHERE learner_id = ? AND school_year = ? AND domain = ? AND (subdomain = ? OR (subdomain IS NULL AND ? IS NULL)) AND indicator = ? LIMIT 1");
            $stmt_upd->bind_param("ssssssssss", $rating['q1'], $rating['q2'], $rating['q3'], $rating['q4'], $lrn, $school_year, $rating['domain'], $rating['subdomain'], $rating['subdomain'], $rating['indicator']);
            $stmt_upd->execute();
        }

        log_transaction($conn, $_SESSION['user_id'], 'create', 'sf9', $lrn, "Copied SF9 from SY $copy_from_sy to SY $school_year for LRN: $lrn");

        header("Location: sf9_form.php?learner_id=" . urlencode($lrn) . "&school_year=" . urlencode($school_year) . "&copied=" . urlencode($copy_from_sy));
        exit();
    }
}

if (isset($_GET['copied'])) {
    $message = "<div class='alert alert-success rounded-3 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Progress report copied from SY " . htmlspecialchars($_GET['copied']) . ". Review and update the ratings below.</div>";
}

if (isset($_GET['saved'])) {
    $message = "<div class='alert alert-success rounded-3 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Progress report saved.</div>";
}

$stmt_sys = $conn->prepare("
    SELECT DISTINCT sy FROM (
        SELECT school_year AS sy FROM enrollments WHERE lrn = ? AND is_deleted = 0
        UNION
        SELECT school_year AS sy FROM learner_indicator_ratings WHERE learner_id = ?
    ) years
    WHERE sy IS NOT NULL AND sy <> ''
    ORDER BY sy DESC
");
$stmt_sys->bind_param("ss", $lrn, $lrn);
$stmt_sys->execute();
$school_years = array_column($stmt_sys->get_result()->fetch_all(MYSQLI_ASSOC), 'sy');
if (!in_array($school_year, $school_years, true)) {
    $school_years[] = $school_year;
    rsort($school_years);
}

$stmt_card_load = $conn->prepare("SELECT * FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
$stmt_card_load->bind_param("ss", $lrn, $school_year);
$stmt_card_load->execute();
$report_card = $stmt_card_load->get_result()->fetch_assoc() ?: [];
$report_card_id = (int)($report_card['id'] ?? 0);

$attendance = [];
foreach (sf9_months() as $order => $label) {
    $attendance[$order] = ['month_label' => $label, 'school_days' => '', 'days_present' => '', 'days_absent' => ''];
}
if ($report_card_id) {
    $stmt_att_load = $conn->prepare("SELECT * FROM sf9_attendance WHERE report_card_id = ? ORDER BY month_order ASC");
    $stmt_att_load->bind_param("i", $report_card_id);
    $stmt_att_load->execute();
    $res_att = $stmt_att_load->get_result();
    while ($row = $res_att->fetch_assoc()) {
        $attendance[(int)$row['month_order']] = $row;
    }
}

$quarter_notes = [];
for ($q = 1; $q <= 4; $q++) {
    $quarter_notes[$q] = ['teacher_remarks' => '', 'parent_comments' => '', 'parent_signature' => ''];
}
if ($report_card_id) {
    $stmt_notes_load = $conn->prepare("SELECT * FROM sf9_quarter_notes WHERE report_card_id = ? ORDER BY quarter ASC");
    $stmt_notes_load->bind_param("i", $report_card_id);
    $stmt_notes_load->execute();
    $res_notes = $stmt_notes_load->get_result();
    while ($row = $res_notes->fetch_assoc()) {
        $quarter_notes[(int)$row['quarter']] = $row;
    }
}

$stmt_ratings = $conn->prepare("
    SELECT *
    FROM learner_indicator_ratings
    WHERE learner_id = ? AND school_year = ?
    ORDER BY id ASC
");
$stmt_ratings->bind_param("ss", $lrn, $school_year);
$stmt_ratings->execute();
$ratings = $stmt_ratings->get_result()->fetch_all(MYSQLI_ASSOC);
$ordered_ratings = sf9_group_ratings($ratings);

$full_name = trim(($learner['first_name'] ?? '') . ' ' . (($learner['middle_name'] ?? '') ? substr($learner['middle_name'], 0, 1) . '. ' : '') . ($learner['last_name'] ?? '') . ' ' . ($learner['extension_name'] ?? ''));
$birthday = !empty($learner['birthday']) ? date('F d, Y', strtotime($learner['birthday'])) : '';
$age = !empty($learner['birthday']) ? (new DateTime($learner['birthday']))->diff(new DateTime())->y : '';
$latest_assessment = sned_latest_assessment_prefill($conn, $lrn);
$default_assessment_status = sned_prefill_value(
    '',
    $report_card['assessment_status'] ?? '',
    !empty($latest_assessment['assessment_type']) ? 'With' : ''
);
$default_transfer_teacher_name = sned_prefill_value('', $report_card['transfer_teacher_name'] ?? '', $_SESSION['full_name'] ?? '');
$default_transfer_principal_name = sned_prefill_value(
    '',
    $report_card['transfer_principal_name'] ?? '',
    !empty($learner['school_id']) ? get_current_principal($conn, (int)$learner['school_id']) : ''
);
$default_parent_signature = sned_guardian_name($learner, 'display');
$prev_sy_sf9 = sned_previous_school_year($school_year);
$has_prev_sf9 = false;
if ($prev_sy_sf9) {
    $stmt_check_prev = $conn->prepare("SELECT id FROM sf9_report_cards WHERE lrn = ? AND school_year = ? LIMIT 1");
    $stmt_check_prev->bind_param("ss", $lrn, $prev_sy_sf9);
    $stmt_check_prev->execute();
    $has_prev_sf9 = (bool)$stmt_check_prev->get_result()->fetch_assoc();
}
if ($default_parent_signature !== '') {
    for ($q = 1; $q <= 4; $q++) {
        if (empty($quarter_notes[$q]['parent_signature'])) {
            $quarter_notes[$q]['parent_signature'] = $default_parent_signature;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non Graded Progress Report | SNED Profiling</title>
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
</head>
<body>
    <div class="d-flex vh-100 app-shell sidebar-collapsed sidebar-hidden">
        <?php include 'sidebar.php'; ?>
        <main class="flex-grow-1 overflow-auto bg-light">
            <!-- Header -->
            <header class="p-3 border-bottom shadow-sm d-flex align-items-center justify-content-between sticky-top bg-white mb-4">
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                        <i class="bi bi-list"></i> <span id="sidebarToggleText">Show menu</span>
                    </button>
                    <a href="viewLearner.php?id=<?= urlencode($lrn) ?>&tab=sf9" class="btn btn-pill-navigation btn-pill-secondary d-flex align-items-center gap-2">
                        <i class="bi bi-arrow-left"></i> Back to Profile
                    </a>
                    <h2 class="h4 fw-bold mb-0 d-none d-md-inline-block"><i class="bi bi-card-checklist text-success me-2"></i> Non Graded Progress Report</h2>
                </div>
                <form method="GET" class="d-flex gap-2 align-items-center mb-0">
                    <input type="hidden" name="learner_id" value="<?= sf9_h($lrn) ?>">
                    <label class="small fw-bold text-muted mb-0" for="school_year">School Year</label>
                    <select name="school_year" id="school_year" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                        <?php foreach ($school_years as $sy): ?>
                            <option value="<?= sf9_h($sy) ?>" <?= $sy === $school_year ? 'selected' : '' ?>>SY <?= sf9_h($sy) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </header>

            <div class="p-4 container-fluid mx-auto" style="max-width: 1200px;">
                <?= $message ?>
                <?php if (!$is_active_sy): ?>
                    <div class="alert alert-info rounded-4 shadow-sm"><i class="bi bi-info-circle-fill me-2"></i>Viewing a historical school year. This report card is read-only.</div>
                <?php elseif (!$can_edit): ?>
                    <div class="alert alert-secondary rounded-4 shadow-sm"><i class="bi bi-lock-fill me-2"></i>Your role can view this report card but cannot edit it.</div>
                <?php endif; ?>

                <?php if ($can_edit && empty($report_card) && !isset($_GET['copied']) && empty($_GET['copyFromSy']) && $has_prev_sf9): ?>
                <div class="alert alert-info border-0 rounded-3 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between">
                    <div>
                        <i class="bi bi-copy text-info me-2" style="font-size: 1.5rem;"></i>
                        <strong>Start fresh or copy forward?</strong>
                        <span class="ms-2 text-muted">No progress report exists for SY <?= sf9_h($school_year) ?>.</span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="sf9_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($school_year) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">Start blank</a>
                        <a href="sf9_form.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($school_year) ?>&copyFromSy=<?= urlencode($prev_sy_sf9) ?>" class="btn btn-info btn-sm rounded-pill px-3 text-white"><i class="bi bi-copy me-1"></i> Copy from SY <?= sf9_h($prev_sy_sf9) ?></a>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="learner_id" value="<?= sf9_h($lrn) ?>">
                    <input type="hidden" name="school_year" value="<?= sf9_h($school_year) ?>">

                    <div class="sned-card mb-4">
                        <div class="sned-card-body d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                            <div>
                                <div class="fw-bold fs-5 text-dark"><?= sf9_h($full_name) ?></div>
                                <div class="small text-muted">LRN <?= sf9_h($lrn) ?> · <?= sf9_h($learner['school_name'] ?? '') ?> · SY <?= sf9_h($school_year) ?></div>
                            </div>
                            <div class="d-flex gap-2">
                                <a class="btn btn-pill-navigation btn-pill-secondary" target="_blank" href="printAnnex2.php?learner_id=<?= urlencode($lrn) ?>&school_year=<?= urlencode($school_year) ?>&quarter=all"><i class="bi bi-printer me-1"></i> Print Report Card</a>
                                <?php if ($can_edit): ?>
                                    <button type="submit" class="btn btn-pill-navigation btn-pill-primary"><i class="bi bi-save me-1"></i> Save Progress Report</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Cover Details -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-person-lines-fill text-success"></i> Cover Details</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" value="<?= sf9_h($full_name) ?>" disabled></div>
                                <div class="col-md-2"><label class="form-label">Age</label><input class="form-control" value="<?= sf9_h((string)$age) ?>" disabled></div>
                                <div class="col-md-3"><label class="form-label">Date of Birth</label><input class="form-control" value="<?= sf9_h($birthday) ?>" disabled></div>
                                <div class="col-md-3"><label class="form-label">Sex</label><input class="form-control" value="<?= sf9_h($learner['sex'] ?? '') ?>" disabled></div>
                                <div class="col-md-4">
                                    <label class="form-label">Assessment Status</label>
                                    <select name="assessment_status" class="form-select" <?= $can_edit ? '' : 'disabled' ?>>
                                        <option value="" <?= empty($default_assessment_status) ? 'selected' : '' ?>>Not specified</option>
                                        <option value="With" <?= $default_assessment_status === 'With' ? 'selected' : '' ?>>With</option>
                                        <option value="Without" <?= $default_assessment_status === 'Without' ? 'selected' : '' ?>>Without</option>
                                    </select>
                                </div>
                                <div class="col-md-4"><label class="form-label">Region</label><input class="form-control" value="National Capital Region" disabled></div>
                                <div class="col-md-4"><label class="form-label">Division</label><input class="form-control" value="Schools Division Office of Valenzuela City" disabled></div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Record -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-calendar-check text-success"></i> Attendance Record</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped report-table text-center align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width: 200px;">Attendance Record</th>
                                            <?php foreach (sf9_months() as $label): ?><th><?= sf9_h($label) ?></th><?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $rows = ['school_days' => 'No. of School Days', 'days_present' => 'No. of School Days Present', 'days_absent' => 'No. of School Days Absent'];
                                        foreach ($rows as $field => $label):
                                        ?>
                                            <tr>
                                                <th class="text-start fs-6 text-secondary"><?= sf9_h($label) ?></th>
                                                <?php foreach (sf9_months() as $order => $month): ?>
                                                    <td><input type="number" min="0" class="form-control text-center" name="attendance[<?= $order ?>][<?= $field ?>]" value="<?= sf9_h((string)($attendance[$order][$field] ?? '')) ?>" <?= $can_edit ? '' : 'disabled' ?>></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Quarter Remarks -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-chat-square-text text-success"></i> Quarter Remarks and Parent Comments</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered report-table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:10%" class="text-center">Quarter</th>
                                            <th>Teacher's Remarks</th>
                                            <th>Parent's/Guardian's Comments</th>
                                            <th style="width:25%">Signature Name</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($q = 1; $q <= 4; $q++): ?>
                                            <tr>
                                                <th class="text-center"><?= $q ?><?= $q === 1 ? 'st' : ($q === 2 ? 'nd' : ($q === 3 ? 'rd' : 'th')) ?></th>
                                                <td><textarea class="form-control" rows="2" name="quarter_notes[<?= $q ?>][teacher_remarks]" <?= $can_edit ? '' : 'disabled' ?>><?= sf9_h($quarter_notes[$q]['teacher_remarks'] ?? '') ?></textarea></td>
                                                <td><textarea class="form-control" rows="2" name="quarter_notes[<?= $q ?>][parent_comments]" <?= $can_edit ? '' : 'disabled' ?>><?= sf9_h($quarter_notes[$q]['parent_comments'] ?? '') ?></textarea></td>
                                                <td><input class="form-control" name="quarter_notes[<?= $q ?>][parent_signature]" value="<?= sf9_h($quarter_notes[$q]['parent_signature'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Certificate of Transfer -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header">
                            <h5><i class="bi bi-arrow-left-right text-success"></i> Certificate of Transfer</h5>
                        </div>
                        <div class="sned-card-body">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Admitted to</label><input class="form-control" name="admitted_to" value="<?= sf9_h($report_card['admitted_to'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-6"><label class="form-label">Eligible for Admission to</label><input class="form-control" name="eligible_for_admission_to" value="<?= sf9_h($report_card['eligible_for_admission_to'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-6"><label class="form-label">Teacher</label><input class="form-control" name="transfer_teacher_name" value="<?= sf9_h($default_transfer_teacher_name) ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-6"><label class="form-label">Principal</label><input class="form-control" name="transfer_principal_name" value="<?= sf9_h($default_transfer_principal_name) ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-5"><label class="form-label">Cancellation: Admitted in</label><input class="form-control" name="cancellation_admitted_in" value="<?= sf9_h($report_card['cancellation_admitted_in'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-3"><label class="form-label">Cancellation Date</label><input type="date" class="form-control" name="cancellation_date" value="<?= sf9_h($report_card['cancellation_date'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                                <div class="col-md-4"><label class="form-label">Cancellation Principal</label><input class="form-control" name="cancellation_principal_name" value="<?= sf9_h($report_card['cancellation_principal_name'] ?? '') ?>" <?= $can_edit ? '' : 'disabled' ?>></div>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Indicators -->
                    <div class="sned-card mb-4">
                        <div class="sned-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5><i class="bi bi-card-checklist text-success"></i> Performance Indicators</h5>
                            <span class="small text-muted fw-bold bg-white px-3 py-1 border rounded-pill">Ratings: P · AP · D · B · NO · NA</span>
                        </div>
                        <div class="sned-card-body p-0">
                            <?php if (empty($ordered_ratings)): ?>
                                <div class="text-center text-muted py-5">No performance indicators are available for this school year.</div>
                            <?php else: ?>
                                <div class="accordion accordion-flush" id="sf9Accordion">
                                    <?php $idx = 0; foreach ($ordered_ratings as $domain => $subdomains): $idx++; ?>
                                        <div class="accordion-item border-bottom">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button <?= $idx === 1 ? '' : 'collapsed' ?> fw-bold py-3 px-4" type="button" data-bs-toggle="collapse" data-bs-target="#domain<?= $idx ?>">
                                                    <i class="bi bi-bookmark-star text-success me-2"></i> <?= sf9_h($domain) ?>
                                                </button>
                                            </h2>
                                            <div id="domain<?= $idx ?>" class="accordion-collapse collapse <?= $idx === 1 ? 'show' : '' ?>" data-bs-parent="#sf9Accordion">
                                                <div class="accordion-body p-0">
                                                    <div class="table-responsive">
                                                        <table class="table table-hover mb-0 align-middle">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th class="ps-4 py-3" style="width: 45%;">Performance Indicator</th>
                                                                    <th class="text-center py-3">Q1</th>
                                                                    <th class="text-center py-3">Q2</th>
                                                                    <th class="text-center py-3">Q3</th>
                                                                    <th class="text-center py-3">Q4</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($subdomains as $subdomain => $rows): ?>
                                                                    <?php if ($subdomain !== ''): ?>
                                                                        <tr class="domain-heading">
                                                                            <td colspan="5" class="ps-4 py-2 fw-bold text-success bg-light text-uppercase" style="font-size: 12px; letter-spacing: 0.05em;"><?= sf9_h($subdomain) ?></td>
                                                                        </tr>
                                                                    <?php endif; ?>
                                                                    <?php foreach ($rows as $row): ?>
                                                                        <tr>
                                                                            <td class="ps-4 py-3 fw-medium text-dark"><?= sf9_h($row['indicator'] ?? '') ?></td>
                                                                            <?php for ($q = 1; $q <= 4; $q++): $field = 'q' . $q; ?>
                                                                                <td class="text-center py-3">
                                                                                    <div class="sf9-rating-pill-group" id="group_<?= (int)$row['id'] ?>_<?= $field ?>">
                                                                                        <?php foreach (sf9_rating_options() as $value => $label): ?>
                                                                                            <?php if ($value === '') continue; ?>
                                                                                            <div class="sf9-rating-pill">
                                                                                                <input type="radio"
                                                                                                       name="ratings[<?= (int)$row['id'] ?>][<?= $field ?>]"
                                                                                                       id="pill_<?= (int)$row['id'] ?>_<?= $field ?>_<?= $value ?>"
                                                                                                       value="<?= sf9_h($value) ?>"
                                                                                                       <?= (($row[$field] ?? '') === $value) ? 'checked' : '' ?>
                                                                                                       <?= $can_edit ? '' : 'disabled' ?>>
                                                                                                <label for="pill_<?= (int)$row['id'] ?>_<?= $field ?>_<?= $value ?>"><?= sf9_h($label) ?></label>
                                                                                            </div>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                </td>
                                                                            <?php endfor; ?>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        // Allow unchecking radio pills to clear ratings
        document.querySelectorAll('.sf9-rating-pill input[type="radio"]').forEach(radio => {
            if (radio.checked) {
                radio.wasChecked = true;
            }
            radio.addEventListener('click', function(e) {
                if (this.wasChecked) {
                    this.checked = false;
                    this.wasChecked = false;
                    this.dispatchEvent(new Event('change'));
                } else {
                    document.querySelectorAll(`input[name="${this.name}"]`).forEach(r => {
                        r.wasChecked = false;
                    });
                    this.wasChecked = true;
                }
            });
        });

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
