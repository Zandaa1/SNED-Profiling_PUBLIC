<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
if (!user_is_coordinator($_SESSION['role'] ?? '')) {
    header('Location: 403.php');
    exit;
}

$message = '';
$parsed = null;
$upload_dir = __DIR__ . '/uploads/documents';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// Learner list for linking
$school_filter = user_get_school_filter_learners($_SESSION['school_id'] ?? null, $_SESSION['role'] ?? 'user');
$learners = [];
$res_l = $conn->query("\n    SELECT DISTINCT l.lrn, l.first_name, l.last_name\n    FROM learners l\n    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0\n    WHERE l.is_deleted = 0 $school_filter\n    ORDER BY l.last_name ASC\n");
if ($res_l) {
    while ($row = $res_l->fetch_assoc()) $learners[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }

    if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['doc_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['docx', 'pdf', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'xlsx', 'xls', 'csv'];

        if (!in_array($ext, $allowed_extensions, true)) {
            $message = "<div class='alert alert-danger'>Unsupported file type. Allowed: " . implode(', ', array_map(fn($e) => '.' . $e, $allowed_extensions)) . "</div>";
        } else {
            $extracted = null;

            if ($ext === 'docx' && extension_loaded('zip')) {
                $za = new ZipArchive();
                if ($za->open($file['tmp_name']) === true) {
                    $xmlContent = $za->getFromName('word/document.xml');
                    $za->close();
                    if ($xmlContent) {
                        libxml_use_internal_errors(true);
                        $xml = simplexml_load_string($xmlContent);
                        if ($xml) {
                            $namespaces = $xml->getNamespaces(true);
                            $w = $namespaces['w'] ?? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                            $paragraphs = [];
                            foreach ($xml->xpath('//w:p') as $p) {
                                $texts = [];
                                foreach ($p->children($w)->r as $r) {
                                    $t = (string)$r->children($w)->t;
                                    if ($t !== '') $texts[] = $t;
                                }
                                $line = implode('', $texts);
                                if (trim($line) !== '') $paragraphs[] = trim($line);
                            }
                            $extracted = implode("\n", $paragraphs);
                        }
                    }
                }
            } elseif ($ext === 'txt' || $ext === 'csv') {
                $extracted = file_get_contents($file['tmp_name']);
            } elseif ($ext === 'rtf') {
                $rtf = file_get_contents($file['tmp_name']);
                if ($rtf !== false) {
                    $text = preg_replace('/\\\\[a-z]{1,32}?(?:-?\d+)? ?/', '', $rtf);
                    $text = preg_replace('/\{[^{}]*\}/', '', $text);
                    $text = str_replace(['\\\\', '\{', '\}', '~', '\nonumber\par', '\par'], '', $text);
                    $extracted = trim($text);
                }
            }

            $stored_name = uniqid('doc_') . '.' . $ext;
            $dest = $upload_dir . '/' . $stored_name;
            move_uploaded_file($file['tmp_name'], $dest);

            // Determine document type
            $doc_type = $_POST['document_type'] ?? 'Other';
            $lrn = trim($_POST['lrn'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $extracted_text = $extracted ?? null;

            // Teacher gate
            if (!preg_match('/^\d{12}$/', $lrn)) {
                $message = "<div class='alert alert-danger'>Invalid LRN.</div>";
            } elseif (user_is_scoped($_SESSION['role'] ?? '')) {
                $has_school_access = false;
                $user_s_id = $_SESSION['school_id'] ?? null;
                if ($user_s_id) {
                    $stmt_g = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE lrn = ? AND school_id = ? AND is_deleted = 0 AND is_historical = 0");
                    $stmt_g->bind_param("si", $lrn, $user_s_id);
                    $stmt_g->execute();
                    $gate_res = $stmt_g->get_result()->fetch_assoc();
                    $has_school_access = ($gate_res['count'] ?? 0) > 0;
                }
                if ($has_school_access || check_teacher_learner_access($conn, $lrn)) {
            $stmt = $conn->prepare("INSERT INTO documents (lrn, document_type, original_filename, stored_filename, file_size, mime_type, extracted_text, notes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $mime = mime_content_type($dest);
            $stmt->bind_param("ssssissii", $lrn, $doc_type, $file['name'], $stored_name, $file['size'], $mime, $extracted_text, $notes, $_SESSION['user_id']);
            if ($stmt->execute()) {
                log_transaction($conn, $_SESSION['user_id'], 'create', 'documents', $stmt->insert_id, "Uploaded document: {$file['name']} for LRN: $lrn");
                $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Document uploaded and linked.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to save document record.</div>";
            }
        } else {
            $message = "<div class='alert alert-danger'>Access denied: learner not assigned.</div>";
        }
    }
    }
    }
}

// Fetch recent documents
$docs = [];
$res_docs = $conn->query("
    SELECT d.*, l.first_name, l.last_name, u.full_name as uploader_name
    FROM documents d
    JOIN learners l ON d.lrn = l.lrn
    LEFT JOIN users u ON d.uploaded_by = u.id
    WHERE l.is_deleted = 0
    ORDER BY d.created_at DESC
    LIMIT 50
");
if ($res_docs) {
    while ($row = $res_docs->fetch_assoc()) $docs[] = $row;
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
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Import Document | SNED Profiling</title>
    <style>
        body { background: #f8fafc; }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>
        <div class="flex-grow-1 d-flex flex-column bg-white overflow-auto">
            <header class="p-3 border-bottom shadow-sm d-flex align-items-center justify-content-between sticky-top bg-white">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button"><i class="bi bi-list"></i> Menu</button>
                    <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>Import Document</h5>
                </div>
            </header>
            <div class="p-4">
                <?= $message ?>
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card rounded-4 border-0 shadow-sm p-4">
                            <h6 class="fw-bold mb-3"><i class="bi bi-upload text-primary me-1"></i> Upload Document</h6>
                            <form method="POST" enctype="multipart/form-data">
                                <?= csrf_input() ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-uppercase">File *</label>
                                    <input type="file" name="doc_file" accept=".docx,.pdf,.txt,.rtf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.xlsx,.xls,.csv" class="form-control rounded-3" required>
                                    <div class="form-text">Supported: DOCX, PDF, TXT, RTF, JPG, PNG, GIF, WebP, BMP, XLSX, XLS, CSV</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-uppercase">Link to Learner *</label>
                                    <select name="lrn" class="form-select rounded-3" required>
                                        <option value="">Select Learner</option>
                                        <?php foreach ($learners as $l): ?>
                                        <option value="<?= htmlspecialchars($l['lrn']) ?>" <?= ($l['lrn'] === ($_GET['lrn'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($l['last_name'] . ', ' . $l['first_name'] . ' (' . $l['lrn'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-uppercase">Document Type</label>
                                    <select name="document_type" class="form-select rounded-3">
                                        <option value="IEP">IEP</option>
                                        <option value="ILP">ILP</option>
                                        <option value="ITP">ITP</option>
                                        <option value="SF9">SF9 Report Card</option>
                                        <option value="Form137">Form 137</option>
                                        <option value="Assessment">Assessment</option>
                                        <option value="Medical">Medical</option>
                                        <option value="Referral">Referral</option>
                                        <option value="Transfer Support">Transfer Support</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-uppercase">Notes</label>
                                    <textarea name="notes" class="form-control rounded-3" rows="2" maxlength="500"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100"><i class="bi bi-cloud-upload me-1"></i> Upload & Link</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card rounded-4 border-0 shadow-sm">
                            <div class="card-body p-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-1 text-primary"></i> Recent Documents</h6>
                                <?php if (empty($docs)): ?>
                                <p class="text-muted small">No documents uploaded yet.</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Learner</th>
                                                <th>Type</th>
                                                <th>Filename</th>
                                                <th>Size</th>
                                                <th>Uploaded By</th>
                                                <th>Date</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($docs as $d): ?>
                                            <tr>
                                                <td><a href="learnerForm.php?id=<?= urlencode($d['lrn']) ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($d['last_name'] . ', ' . $d['first_name']) ?></a></td>
                                                <td><span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($d['document_type']) ?></span></td>
                                                <td class="small text-truncate" style="max-width:200px;"><?= htmlspecialchars($d['original_filename']) ?></td>
                                                <td class="small"><?= number_format($d['file_size']) ?> B</td>
                                                <td class="small text-muted"><?= htmlspecialchars($d['uploader_name'] ?? '?') ?></td>
                                                <td class="small text-muted"><?= date('M d, Y', strtotime($d['created_at'])) ?></td>
                                                <td class="text-end">
                                                    <a href="download_doc.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill" title="Download"><i class="bi bi-download"></i></a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
    </script>
</body>
</html>
