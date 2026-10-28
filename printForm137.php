<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = trim($_GET['lrn'] ?? $_GET['learner_id'] ?? '');
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if (!preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid LRN.");
}

if (!check_teacher_learner_access($conn, $lrn)) {
    header("Location: 403.php");
    exit();
}

if ($request_id > 0) {
    $stmt_req = $conn->prepare("
        SELECT tr.*, s_from.school_name AS from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') AS to_school,
               u.full_name AS requested_by_name,
               u_rev.full_name AS reviewed_by_name
        FROM transfer_requests tr
        JOIN schools s_from ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u ON tr.requested_by = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by = u_rev.id
        WHERE tr.id = ? AND tr.learner_id = ?
        LIMIT 1
    ");
    $stmt_req->bind_param("is", $request_id, $lrn);
} else {
    $stmt_req = $conn->prepare("
        SELECT tr.*, s_from.school_name AS from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') AS to_school,
               u.full_name AS requested_by_name,
               u_rev.full_name AS reviewed_by_name
        FROM transfer_requests tr
        JOIN schools s_from ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u ON tr.requested_by = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by = u_rev.id
        WHERE tr.learner_id = ? AND tr.status = 'approved'
        ORDER BY tr.reviewed_at DESC, tr.requested_at DESC
        LIMIT 1
    ");
    $stmt_req->bind_param("s", $lrn);
}
$stmt_req->execute();
$transfer = $stmt_req->get_result()->fetch_assoc();
if (!$transfer) {
    die("No approved transfer request found for this learner.");
}

$stmt_learner = $conn->prepare("
    SELECT l.*, g.first_name AS g_first, g.last_name AS g_last, g.contact_number AS g_contact
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    LIMIT 1
");
$stmt_learner->bind_param("s", $lrn);
$stmt_learner->execute();
$learner = $stmt_learner->get_result()->fetch_assoc();
if (!$learner) {
    die("Learner not found.");
}

$stmt_enroll = $conn->prepare("
    SELECT *
    FROM enrollments
    WHERE lrn = ? AND is_deleted = 0
    ORDER BY is_historical ASC, school_year DESC, id DESC
    LIMIT 1
");
$stmt_enroll->bind_param("s", $lrn);
$stmt_enroll->execute();
$enrollment = $stmt_enroll->get_result()->fetch_assoc() ?: [];
$disabilities = sned_get_enrollment_disabilities($conn, isset($enrollment['id']) ? (int)$enrollment['id'] : null);
if (empty($disabilities) && !empty($enrollment['primary_diagnosis'])) {
    $disabilities = sned_disability_rows_from_legacy_diagnosis($enrollment['primary_diagnosis']);
}
$disability_summary = sned_disability_summary($disabilities, $enrollment['primary_diagnosis'] ?? 'Unassigned');

$stmt_docs = $conn->prepare("
    SELECT d.*
    FROM transfer_request_documents trd
    JOIN documents d ON trd.document_id = d.id
    WHERE trd.transfer_request_id = ?
    ORDER BY d.created_at ASC, d.id ASC
");
$tid = (int)$transfer['id'];
$stmt_docs->bind_param("i", $tid);
$stmt_docs->execute();
$documents = $stmt_docs->get_result()->fetch_all(MYSQLI_ASSOC);

log_transaction($conn, (int)$_SESSION['user_id'], 'print', 'form137', $lrn, "Printed Form 137 transfer packet for LRN: $lrn");

$learner_name = trim($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . ($learner['middle_name'] ?? ''));
$guardian_name = trim(($learner['g_last'] ?? '') . ', ' . ($learner['g_first'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form 137 Transfer Packet - <?= htmlspecialchars($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        body { background: #f8fafc; color: #111827; }
        .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; background: #fff; padding: 14mm 16mm; border: 1px solid #d1d5db; box-sizing: border-box; }
        .print-title { letter-spacing: .08em; }
        .field-label { width: 32%; color: #4b5563; font-weight: 700; text-transform: uppercase; font-size: .75rem; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { margin: 0; max-width: none; border: 0; padding: 14mm 16mm; width: 210mm; min-height: 297mm; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Form 137'); ?>
    <main class="sheet a4-flow">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4">
            <img src="img/deped-logo.webp" style="height: 50px; object-fit: contain;" alt="DepEd Logo">
            <div class="text-center flex-grow-1 mx-3">
                <div class="small text-uppercase text-muted" style="font-size: 8.5pt;">Republic of the Philippines</div>
                <div class="small text-uppercase fw-bold text-dark">Department of Education</div>
                <div class="small text-uppercase text-muted">Schools Division Office of Valenzuela City</div>
                <h1 class="h3 fw-bold print-title mb-1 mt-1">FORM 137</h1>
                <div class="fw-semibold small">Learner Permanent Record Transfer Packet</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 50px; object-fit: contain;" alt="Valenzuela Logo">
        </div>

        <section class="mb-4">
            <h2 class="h6 fw-bold text-uppercase border-bottom pb-2">Learner Information</h2>
            <table class="table table-sm table-borderless">
                <tr><td class="field-label">LRN</td><td><?= htmlspecialchars($lrn) ?></td></tr>
                <tr><td class="field-label">Name</td><td><?= htmlspecialchars($learner_name) ?></td></tr>
                <tr><td class="field-label">Birthday / Sex</td><td><?= htmlspecialchars($learner['birthday']) ?> / <?= htmlspecialchars($learner['sex']) ?></td></tr>
                <tr><td class="field-label">Guardian</td><td><?= htmlspecialchars($guardian_name ?: 'Not on file') ?></td></tr>
                <tr><td class="field-label">Disabilities</td><td><?= htmlspecialchars($disability_summary) ?></td></tr>
            </table>
        </section>

        <section class="mb-4">
            <h2 class="h6 fw-bold text-uppercase border-bottom pb-2">Transfer Details</h2>
            <table class="table table-sm table-borderless">
                <tr><td class="field-label">Transfer Type</td><td><?= htmlspecialchars(ucfirst($transfer['transfer_type'] ?? 'internal')) ?></td></tr>
                <tr><td class="field-label">From</td><td><?= htmlspecialchars($transfer['from_school']) ?></td></tr>
                <tr><td class="field-label">To / Destination</td><td><?= htmlspecialchars($transfer['to_school']) ?></td></tr>
                <tr><td class="field-label">Effective SY</td><td><?= htmlspecialchars($transfer['effective_sy'] ?: 'N/A') ?></td></tr>
                <tr><td class="field-label">Reason</td><td><?= htmlspecialchars($transfer['reason'] ?: 'N/A') ?></td></tr>
                <tr><td class="field-label">Approved By</td><td><?= htmlspecialchars($transfer['reviewed_by_name'] ?: 'N/A') ?></td></tr>
                <tr><td class="field-label">Admin Notes</td><td><?= htmlspecialchars($transfer['admin_notes'] ?: 'N/A') ?></td></tr>
            </table>
        </section>

        <section>
            <h2 class="h6 fw-bold text-uppercase border-bottom pb-2">Attached Supporting Documents</h2>
            <?php if (empty($documents)): ?>
                <p class="text-muted">No optional supporting documents were attached to this transfer request.</p>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <div class="border rounded-3 p-3 mb-2">
                        <div class="fw-bold"><?= htmlspecialchars($doc['original_filename']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($doc['document_type']) ?> · <?= number_format((int)$doc['file_size']) ?> B</div>
                        <?php if (!empty($doc['notes'])): ?><div><?= htmlspecialchars($doc['notes']) ?></div><?php endif; ?>
                        <?php if (!empty($doc['extracted_text'])): ?><pre class="small mb-0 mt-2"><?= htmlspecialchars($doc['extracted_text']) ?></pre><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
