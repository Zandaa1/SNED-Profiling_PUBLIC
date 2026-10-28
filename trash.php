<?php 
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';

if (!user_is_admin($user_role)) {
    header("Location: main.php");
    exit();
}

$type = $_GET['type'] ?? 'learners';
if (!in_array($type, ['learners', 'enrollments'], true)) {
    $type = 'learners';
}

$message = '';

// Pagination setup
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Handle Restoration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $message = "<div class='alert alert-danger d-flex align-items-center gap-2'><i class='bi bi-exclamation-octagon-fill'></i> Security token mismatch. Please try again.</div>";
    } else {
        if (isset($_POST['restore_learner'])) {
            $lrn = $_POST['lrn'] ?? '';
            if (preg_match('/^\d{12}$/', $lrn)) {
                $stmt = $conn->prepare("UPDATE learners SET is_deleted = 0 WHERE lrn = ?");
                $stmt->bind_param("s", $lrn);
                $stmt->execute();
                
                log_transaction($conn, $_SESSION['user_id'], 'RESTORE', 'LEARNERS', $lrn, "Restored learner profile: $lrn");
                $message = "<div class='alert alert-success d-flex align-items-center gap-2'><i class='bi bi-check-circle-fill'></i> Learner profile restored successfully.</div>";
            } else {
                $message = "<div class='alert alert-danger d-flex align-items-center gap-2'><i class='bi bi-exclamation-octagon-fill'></i> Invalid Learner Reference Number (LRN).</div>";
            }
        }
        if (isset($_POST['restore_enrollment'])) {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $lrn = '';
                $stmt_fetch = $conn->prepare("SELECT lrn FROM enrollments WHERE id = ?");
                $stmt_fetch->bind_param("i", $id);
                $stmt_fetch->execute();
                $res_fetch = $stmt_fetch->get_result();
                if ($row_fetch = $res_fetch->fetch_assoc()) {
                    $lrn = $row_fetch['lrn'];
                }
                
                $stmt = $conn->prepare("UPDATE enrollments SET is_deleted = 0 WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                
                log_transaction($conn, $_SESSION['user_id'], 'RESTORE', 'ENROLLMENTS', $lrn, "Restored enrollment record ID: $id");
                $message = "<div class='alert alert-success d-flex align-items-center gap-2'><i class='bi bi-check-circle-fill'></i> Enrollment record restored successfully.</div>";
            }
        }
    }
}

// Fetch counts for summary cards and pagination.
$archived_counts = [
    'learners' => 0,
    'enrollments' => 0,
];
$count_res = $conn->query("SELECT COUNT(*) as total FROM learners WHERE is_deleted = 1");
if ($count_res && $row = $count_res->fetch_assoc()) {
    $archived_counts['learners'] = (int)$row['total'];
}
$count_res = $conn->query("SELECT COUNT(*) as total FROM enrollments WHERE is_deleted = 1");
if ($count_res && $row = $count_res->fetch_assoc()) {
    $archived_counts['enrollments'] = (int)$row['total'];
}
$total_rows = $archived_counts[$type];
$total_pages = ceil($total_rows / $limit);
$type_label = $type === 'learners' ? 'Learners' : 'Enrollments';
$type_icon = $type === 'learners' ? 'people' : 'mortarboard';

// Fetch Data
$items = [];
if ($type === 'learners') {
    $res = $conn->query("
        SELECT l.lrn, l.first_name, l.last_name, l.created_at,
                (SELECT primary_diagnosis FROM enrollments WHERE lrn = l.lrn ORDER BY school_year DESC LIMIT 1) as primary_diagnosis,
                COALESCE((SELECT MAX(created_at) FROM transaction_logs WHERE LOWER(action_type) = 'delete' AND LOWER(module) = 'learners' AND target_id = l.lrn), l.created_at) as deleted_at
        FROM learners l 
        WHERE l.is_deleted = 1 
        ORDER BY deleted_at DESC 
        LIMIT $limit OFFSET $offset
    ");
} else {
    $res = $conn->query("
        SELECT e.id, e.lrn, e.school_year, e.placement_type, e.grade_level, s.school_name,
                l.first_name, l.last_name,
                COALESCE(
                    (SELECT MAX(created_at) FROM transaction_logs WHERE LOWER(action_type) = 'delete' AND LOWER(module) = 'enrollments' AND target_id = CAST(e.id AS CHAR) COLLATE utf8mb4_unicode_ci),
                    e.updated_at
                ) as deleted_at
        FROM enrollments e
        JOIN learners l ON e.lrn = l.lrn
        JOIN schools s ON e.school_id = s.id
        WHERE e.is_deleted = 1 
        ORDER BY deleted_at DESC 
        LIMIT $limit OFFSET $offset
    ");
}
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
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
    <title>Trash Bin — SNED Profiling</title>
    <style>
        body { background: var(--color-paper-3); }
        .trash-hero {
            background:
                radial-gradient(circle at top left, rgba(var(--bs-warning-rgb), 0.18), transparent 30%),
                linear-gradient(135deg, var(--color-surface), var(--color-paper));
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-xl);
            padding: clamp(1.25rem, 3vw, 2rem);
            box-shadow: var(--shadow-sm);
        }
        .trash-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--sned-warning);
            background: var(--sned-warning-light);
            border: 1px solid rgba(var(--bs-warning-rgb), 0.25);
            border-radius: var(--radius-pill);
            padding: 0.28rem 0.7rem;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .trash-title {
            margin: 0.75rem 0 0.35rem;
            color: var(--color-ink);
            font-size: clamp(1.55rem, 4vw, 2.35rem);
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .trash-copy {
            max-width: 62ch;
            color: var(--color-muted-dark);
            margin: 0;
        }
        .trash-tabs {
            display: inline-flex;
            gap: 0.35rem;
            background: var(--color-paper);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-pill);
            padding: 0.3rem;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .trash-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.52rem 0.95rem;
            border-radius: var(--radius-pill);
            font-size: var(--text-sm);
            font-weight: 750;
            color: var(--color-muted-dark);
            text-decoration: none;
            transition: all var(--dur-short) var(--ease-out);
            background: transparent;
            white-space: nowrap;
        }
        .trash-tab:hover {
            color: var(--color-primary);
            background: var(--color-primary-light);
        }
        .trash-tab.active {
            background: var(--color-primary);
            color: #fff;
            box-shadow: var(--shadow-sm);
        }
        .trash-tab .tab-count {
            min-width: 1.55rem;
            text-align: center;
            font-size: 0.72rem;
            background: var(--color-paper-3);
            color: var(--color-muted-dark);
            padding: 0.12rem 0.42rem;
            border-radius: var(--radius-pill);
            font-weight: 800;
        }
        .trash-tab.active .tab-count {
            background: rgba(255,255,255,0.24);
            color: #fff;
        }
        .trash-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            margin: 1rem 0 1.25rem;
        }
        .trash-stat {
            background: var(--color-surface);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            box-shadow: var(--shadow-xs);
        }
        .trash-stat-icon {
            width: 2.65rem;
            height: 2.65rem;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary-light);
            color: var(--color-primary);
            font-size: 1.15rem;
            flex: 0 0 auto;
        }
        .trash-stat.danger .trash-stat-icon {
            background: var(--sned-danger-light);
            color: var(--sned-danger);
        }
        .trash-stat-value {
            font-size: 1.45rem;
            line-height: 1;
            color: var(--color-ink);
            font-weight: 850;
        }
        .trash-stat-label {
            margin-top: 0.2rem;
            color: var(--color-muted-dark);
            font-size: var(--text-xs);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .trash-table-card {
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .trash-table-head {
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-rule-light);
            padding: 1rem 1.25rem;
        }
        .trash-record-id {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            border-radius: var(--radius-pill);
            background: var(--color-paper-3);
            color: var(--color-primary);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 0.32rem 0.62rem;
        }
        .trash-person {
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }
        .trash-avatar {
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary-light);
            color: var(--color-primary);
            font-weight: 800;
            flex: 0 0 auto;
        }
        .trash-restore-btn {
            border-radius: var(--radius-pill);
            font-weight: 750;
            padding-inline: 0.95rem;
        }
        .empty-state {
            padding: 3.5rem 1rem;
            text-align: center;
        }
        .empty-state-icon {
            width: 64px;
            height: 64px;
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            margin-bottom: 0.85rem;
            background: var(--color-primary-light);
            color: var(--color-primary);
        }
        @media (max-width: 992px) {
            .trash-summary-grid { grid-template-columns: 1fr; }
            .trash-hero { border-radius: var(--radius-lg); }
        }
        @media (max-width: 576px) {
            .trash-tabs { width: 100%; display: grid; grid-template-columns: 1fr 1fr; }
            .trash-tab { justify-content: center; padding-inline: 0.5rem; }
            .trash-table-head { padding-inline: 1rem; }
            .trash-person { align-items: flex-start; }
        }
        @media print {
            @page { size: A4 landscape; margin: 0.5in; }
            body { background: white !important; font-size: 11px; }
            .app-sidebar, header, .trash-hero, .trash-summary-grid, .alert, #sidebarToggle, form, .pagination-nav {
                display: none !important;
            }
            .app-shell { display: block !important; height: auto !important; }
            .flex-grow-1 { width: 100% !important; padding: 0 !important; overflow: visible !important; height: auto !important; }
            .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
            .table th { background-color: #f8f9fa !important; color: black !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column bg-white overflow-auto">
            <header class="app-topbar d-flex align-items-center justify-content-between sticky-top">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-secondary d-flex align-items-center gap-2 btn-sm d-lg-none" id="sidebarToggle" type="button">
                        <i class="bi bi-list"></i>
                    </button>
                    <h5 class="mb-0 fw-bold"><i class="bi bi-recycle text-primary me-2"></i>Trash Bin</h5>
                </div>
            </header>

            <div class="p-4">
                <section class="trash-hero mb-4">
                    <div class="d-flex flex-column flex-xl-row align-items-xl-end justify-content-between gap-4">
                        <div>
                            <span class="trash-eyebrow"><i class="bi bi-archive"></i> Admin recovery</span>
                            <h1 class="trash-title">Restore archived records safely.</h1>
                            <p class="trash-copy">Browse deleted learner profiles and enrollment records. Restoring a record makes it visible again across ValSNED; nothing is permanently deleted here.</p>
                        </div>
                        <div class="trash-tabs">
                            <a href="trash.php?type=learners" class="trash-tab <?= $type === 'learners' ? 'active' : '' ?>">
                                <i class="bi bi-people"></i> Learners <span class="tab-count"><?= number_format($archived_counts['learners']) ?></span>
                            </a>
                            <a href="trash.php?type=enrollments" class="trash-tab <?= $type === 'enrollments' ? 'active' : '' ?>">
                                <i class="bi bi-mortarboard"></i> Enrollments <span class="tab-count"><?= number_format($archived_counts['enrollments']) ?></span>
                            </a>
                        </div>
                    </div>
                </section>

                <?= $message ?>

                <div class="trash-summary-grid">
                    <div class="trash-stat <?= $type === 'learners' ? '' : 'danger' ?>">
                        <span class="trash-stat-icon"><i class="bi bi-people"></i></span>
                        <div>
                            <div class="trash-stat-value"><?= number_format($archived_counts['learners']) ?></div>
                            <div class="trash-stat-label">Archived learners</div>
                        </div>
                    </div>
                    <div class="trash-stat <?= $type === 'enrollments' ? '' : 'danger' ?>">
                        <span class="trash-stat-icon"><i class="bi bi-mortarboard"></i></span>
                        <div>
                            <div class="trash-stat-value"><?= number_format($archived_counts['enrollments']) ?></div>
                            <div class="trash-stat-label">Archived enrollments</div>
                        </div>
                    </div>
                    <div class="trash-stat">
                        <span class="trash-stat-icon"><i class="bi bi-eye"></i></span>
                        <div>
                            <div class="trash-stat-value"><?= number_format($total_rows) ?></div>
                            <div class="trash-stat-label">Viewing <?= strtolower($type_label) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Table Card -->
                <div class="card trash-table-card border-0 mb-4">
                    <div class="trash-table-head d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                        <div>
                            <h6 class="fw-bold mb-1 d-flex align-items-center gap-2"><i class="bi bi-<?= $type_icon ?> text-primary"></i> Archived <?= htmlspecialchars($type_label) ?></h6>
                            <div class="text-muted small">Showing <?= number_format(count($items)) ?> of <?= number_format($total_rows) ?> record<?= $total_rows === 1 ? '' : 's' ?><?= $total_pages > 1 ? ' · Page ' . number_format($page) . ' of ' . number_format($total_pages) : '' ?></div>
                        </div>
                        <span class="badge text-bg-light border rounded-pill px-3 py-2"><i class="bi bi-shield-check me-1"></i> Restore only</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="px-4" style="width: 22%;"><?= $type === 'learners' ? 'Learner LRN' : 'Enrollment ID' ?></th>
                                        <th style="width: 28%;">Record</th>
                                        <th style="width: 30%;">Archived details</th>
                                        <th style="width: 20%;">Archived on</th>
                                        <th class="text-end px-4" style="width: 12%;">Recovery</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="5">
                                                    <div class="empty-state">
                                                        <div class="empty-state-icon">
                                                            <i class="bi bi-check2-circle"></i>
                                                        </div>
                                                        <h6 class="fw-bold text-muted mb-1">No archived <?= htmlspecialchars(strtolower($type_label)) ?></h6>
                                                        <p class="text-muted small mb-0">This recovery list is clear. Deleted records will appear here if they need to be restored.</p>
                                                    </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($items as $item): ?>
                                            <tr>
                                                <td class="px-4">
                                                    <span class="trash-record-id">
                                                        <i class="bi bi-<?= $type === 'learners' ? 'upc-scan' : 'hash' ?>"></i>
                                                        <?= $type === 'learners' ? htmlspecialchars($item['lrn']) : '#' . (int)$item['id'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                        $full_name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                                                        $initials = strtoupper(substr((string)($item['first_name'] ?? 'R'), 0, 1) . substr((string)($item['last_name'] ?? ''), 0, 1));
                                                    ?>
                                                    <div class="trash-person">
                                                        <span class="trash-avatar"><?= htmlspecialchars($initials ?: 'R') ?></span>
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($full_name ?: 'Unnamed record') ?></div>
                                                            <div class="small text-muted"><?= $type === 'learners' ? 'Learner profile' : 'Enrollment record' ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($type === 'learners'): ?>
                                                        <?php
                                                            $diag = $item['primary_diagnosis'] ?? 'No Diagnosis';
                                                            $badge_cls = match(true) {
                                                                str_contains(strtolower($diag), 'autism') => 'bg-info text-dark',
                                                                str_contains(strtolower($diag), 'intellectual') => 'bg-warning text-dark',
                                                                str_contains(strtolower($diag), 'global') => 'bg-secondary',
                                                                default => 'bg-light text-dark border',
                                                            };
                                                        ?>
                                                        <span class="badge <?= $badge_cls ?> rounded-pill px-3 py-2"><?= htmlspecialchars($diag) ?></span>
                                                    <?php else: ?>
                                                        <div class="small">
                                                            <div class="fw-semibold"><?= htmlspecialchars($item['school_name']) ?></div>
                                                            <div class="text-muted"><?= htmlspecialchars($item['school_year']) ?> · <?= htmlspecialchars($item['placement_type']) ?> · <?= htmlspecialchars($item['grade_level']) ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small text-muted">
                                                    <?php if ($item['deleted_at']): ?>
                                                        <i class="bi bi-clock-history me-1"></i><?= date('M d, Y h:i A', strtotime($item['deleted_at'])) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end px-4">
                                                    <form method="POST" class="d-inline">
                                                        <?= csrf_input() ?>
                                                        <?php if ($type === 'learners'): ?>
                                                            <input type="hidden" name="lrn" value="<?= htmlspecialchars($item['lrn']) ?>">
                                                            <input type="hidden" name="restore_learner" value="1">
                                                        <?php else: ?>
                                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                            <input type="hidden" name="restore_enrollment" value="1">
                                                        <?php endif; ?>
                                                        <button type="submit" class="btn btn-sm btn-success trash-restore-btn">
                                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="pagination-nav">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?type=<?= $type ?>&page=<?= $page - 1 ?>">
                                <span>&laquo;</span>
                            </a>
                        </li>
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?type=<?= $type ?>&page=1">1</a></li>
                            <?php if ($start_page > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?type=<?= $type ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?type=<?= $type ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?type=<?= $type ?>&page=<?= $page + 1 ?>">
                                <span>&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }
    </script>
</body>
</html>
