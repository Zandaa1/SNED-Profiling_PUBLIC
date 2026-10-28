<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role    = $_SESSION['role'] ?? 'user';
$is_admin     = user_is_admin($user_role);
$is_coord     = !$is_admin && user_is_coordinator($user_role) && !user_is_teacher($user_role); // head / coordinator

// Must be at least coordinator (not teacher)
if (!$is_admin && !$is_coord) {
    header("Location: 403.php");
    exit();
}

$user_school_id = (int)($_SESSION['school_id'] ?? 0);

// ── ADMIN: unrestricted queries ─────────────────────────────────────────────
if ($is_admin) {
    // Fetch schools list for filter dropdown
    $schools = [];
    $res_sch = $conn->query("SELECT id, school_name FROM schools ORDER BY school_name ASC");
    if ($res_sch) {
        while ($row = $res_sch->fetch_assoc()) $schools[] = $row;
    }

    // Pending requests
    $stmt_pending = $conn->prepare("
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        WHERE tr.status = 'pending'
        ORDER BY tr.requested_at DESC
    ");
    $stmt_pending->execute();
    $pending_list = $stmt_pending->get_result()->fetch_all(MYSQLI_ASSOC);

    // Processed requests
    $stmt_history = $conn->prepare("
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name,
               u_rev.full_name    as reviewer_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by    = u_rev.id
        WHERE tr.status IN ('approved', 'cancelled', 'rejected')
        ORDER BY tr.reviewed_at DESC, tr.requested_at DESC
    ");
    $stmt_history->execute();
    $history_list = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);

    // Filter params for All History tab
    $filter_school     = isset($_GET['filter_school']) ? (int)$_GET['filter_school'] : 0;
    $filter_status     = $_GET['filter_status']     ?? '';
    $filter_start_date = $_GET['filter_start_date'] ?? '';
    $filter_end_date   = $_GET['filter_end_date']   ?? '';

    // Build All History query
    $sql_all = "
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name,
               u_rev.full_name    as reviewer_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by    = u_rev.id
        WHERE 1=1
    ";
    $params_all = [];
    $types_all  = '';

    if ($filter_school > 0) {
        $sql_all .= " AND (tr.from_school_id = ? OR tr.to_school_id = ?)";
        $params_all[] = $filter_school;
        $params_all[] = $filter_school;
        $types_all   .= 'ii';
    }
    if (!empty($filter_status)) {
        $sql_all .= " AND tr.status = ?";
        $params_all[] = $filter_status;
        $types_all   .= 's';
    }
    if (!empty($filter_start_date)) {
        $sql_all .= " AND tr.requested_at >= ?";
        $params_all[] = $filter_start_date . ' 00:00:00';
        $types_all   .= 's';
    }
    if (!empty($filter_end_date)) {
        $sql_all .= " AND tr.requested_at <= ?";
        $params_all[] = $filter_end_date . ' 23:59:59';
        $types_all   .= 's';
    }
    $sql_all .= " ORDER BY tr.requested_at DESC";

    $stmt_all = $conn->prepare($sql_all);
    if (!empty($params_all)) {
        $stmt_all->bind_param($types_all, ...$params_all);
    }
    $stmt_all->execute();
    $all_list = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);

// ── COORDINATOR: school-scoped queries ──────────────────────────────────────
} else {
    // 1. Requests this school needs to review.
    $stmt_incoming = $conn->prepare("
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        WHERE tr.status = 'pending'
          AND tr.transfer_type = 'internal'
          AND tr.to_school_id = ?
        ORDER BY tr.requested_at DESC
    ");
    $stmt_incoming->bind_param("i", $user_school_id);
    $stmt_incoming->execute();
    $incoming_list = $stmt_incoming->get_result()->fetch_all(MYSQLI_ASSOC);

    // 2. Requests sent by this school.
    $stmt_sent = $conn->prepare("
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name,
               u_rev.full_name    as reviewer_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by    = u_rev.id
        WHERE tr.from_school_id = ?
        ORDER BY tr.requested_at DESC
    ");
    $stmt_sent->bind_param("i", $user_school_id);
    $stmt_sent->execute();
    $sent_list = $stmt_sent->get_result()->fetch_all(MYSQLI_ASSOC);

    // 3. Completed/History:
    // Requests involving this school that are approved, rejected, or cancelled
    $stmt_completed = $conn->prepare("
        SELECT tr.*,
               l.first_name, l.last_name, l.lrn,
               s_from.school_name as from_school,
               COALESCE(s_to.school_name, tr.external_destination, 'Transferred Out') as to_school,
               u.full_name        as requester_name,
               u_rev.full_name    as reviewer_name
        FROM transfer_requests tr
        JOIN learners l       ON tr.learner_id   = l.lrn
        JOIN schools s_from   ON tr.from_school_id = s_from.id
        LEFT JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u          ON tr.requested_by   = u.id
        LEFT JOIN users u_rev ON tr.reviewed_by    = u_rev.id
        WHERE tr.status IN ('approved', 'rejected', 'cancelled') AND (
            tr.from_school_id = ? OR tr.to_school_id = ?
        )
        ORDER BY tr.reviewed_at DESC, tr.requested_at DESC
    ");
    $stmt_completed->bind_param("ii", $user_school_id, $user_school_id);
    $stmt_completed->execute();
    $completed_list = $stmt_completed->get_result()->fetch_all(MYSQLI_ASSOC);

    // Filter counts for badges
    $pending_incoming_count = count($incoming_list);
    $pending_sent_count = count(array_filter($sent_list, fn($r) => $r['status'] === 'pending'));
}

// Retrieve system notice if set
$notice = $_SESSION['system_notice'] ?? '';
$notice_type = $_SESSION['system_notice_type'] ?? 'success';
unset($_SESSION['system_notice'], $_SESSION['system_notice_type']);

function get_status_badge($status) {
    $class = 'soft-badge-neutral';
    if ($status === 'pending') $class = 'soft-badge-warning';
    elseif ($status === 'approved') $class = 'soft-badge-success';
    elseif ($status === 'rejected') $class = 'soft-badge-danger';
    return '<span class="soft-badge ' . $class . ' text-uppercase">' . htmlspecialchars($status) . '</span>';
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
    <style>
        .row-actions-panel {
            display: inline-flex;
            flex-direction: column;
            gap: 2px;
            border: 1px solid var(--color-rule);
            border-radius: 10px;
            padding: 6px 4px;
            background: var(--color-paper);
        }

        .btn-row-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            padding: 4px 8px;
            font-weight: 550;
            text-decoration: none;
            white-space: nowrap;
            background: transparent;
            transition: background var(--dur-short) var(--ease-out);
        }

        .btn-row-action:hover {
            background: var(--color-paper-3);
        }

        .btn-row-action:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: 1px;
        }

        .btn-row-approve {
            color: #1E5C39;
        }

        .btn-row-reject {
            color: #7A2028;
        }

        .x-small {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
        }
    </style>
    <title>School Transfer Requests | SNED Profiling</title>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column overflow-auto app-main">
            <header class="app-topbar d-flex align-items-center justify-content-between sticky-top">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                    <i class="bi bi-list"></i> Menu
                </button>
            </header>

            <main class="app-content">
                <?php if ($notice): ?>
                    <div class="alert alert-<?= $notice_type === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
                        <i class="bi bi-<?= $notice_type === 'error' ? 'exclamation-octagon-fill' : 'check-circle-fill' ?> me-2"></i> <?= htmlspecialchars($notice) ?>
                        <button type="button" class="btn-close" data-bs-dismiss='alert'></button>
                    </div>
                <?php endif; ?>

                <div class="page-toolbar">
                    <div>
                        <h1 class="page-title">School Transfer Requests</h1>
                        <?php if ($is_admin): ?>
                            <p class="page-subtitle">Review school transfer requests and keep a clear record of learner moves.</p>
                        <?php else: ?>
                            <p class="page-subtitle">Review requests sent to your school and track learners moved in or out.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-4" id="transferTabs" role="tablist">
                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-pane" type="button" role="tab">
                                Pending Approval
                                <?php if (count($pending_list) > 0): ?>
                                    <span class="badge bg-danger ms-1 rounded-pill"><?= count($pending_list) ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab">Processed Requests</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-pane" type="button" role="tab">All History &amp; Filters</button>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <button class="nav-link active" id="incoming-tab" data-bs-toggle="tab" data-bs-target="#incoming-pane" type="button" role="tab">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Requests to Review
                                <?php if ($pending_incoming_count > 0): ?>
                                    <span class="badge bg-danger ms-1 rounded-pill"><?= $pending_incoming_count ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent-pane" type="button" role="tab">
                                <i class="bi bi-box-arrow-right me-1"></i>Requests Sent
                                <?php if ($pending_sent_count > 0): ?>
                                    <span class="badge bg-warning text-dark ms-1 rounded-pill"><?= $pending_sent_count ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed-pane" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i>Transfer History
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>

                <!-- Tabs Content -->
                <div class="tab-content" id="transferTabsContent">

                <?php if ($is_admin): ?>

                    <!-- PENDING PANE (admin) -->
                    <div class="tab-pane fade show active" id="pending-pane" role="tabpanel">
                        <?php if (empty($pending_list)): ?>
                            <div class="text-center py-5 text-muted bg-white border rounded-4 animate-fade-up">
                                <i class="bi bi-patch-check text-primary mb-3" style="font-size: 3rem;"></i>
                                <h5 class="fw-bold">No Pending Requests</h5>
                                <p class="mb-0 text-secondary">All transfer requests have been processed.</p>
                            </div>
                        <?php else: ?>
                            <div class="data-table-shell animate-fade-up">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Student (LRN)</th>
                                                <th>From School</th>
                                                <th>To School</th>
                                                <th>Requested By</th>
                                                <th>Requested Date</th>
                                                <th>Reason</th>
                                                <th class="text-end pe-4">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></div>
                                                        <small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td><span class="fw-semibold text-dark"><?= htmlspecialchars($row['from_school']) ?></span></td>
                                                    <td>
                                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        <div class="mt-1 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <small class="text-muted">Effective: SY <?= htmlspecialchars($row['effective_sy']) ?></small>
                                                    </td>
                                                    <td class="text-muted small"><?= date('F d, Y · h:i A', strtotime($row['requested_at'])) ?></td>
                                                    <td style="max-width: 250px; white-space: normal; word-break: break-word;" class="text-secondary small">
                                                        <?= htmlspecialchars($row['reason']) ?>
                                                     </td>
                                                     <td class="text-end pe-4">
                                                         <div class="row-actions-panel">
                                                             <button type="button" class="btn-row-action btn-row-approve" title="Approve request" data-row="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" onclick="openReviewModal(this, 'approve')">
                                                                 <i class="bi bi-check-lg"></i> Approve request
                                                             </button>
                                                             <button type="button" class="btn-row-action btn-row-reject" title="Reject request" data-row="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" onclick="openReviewModal(this, 'reject')">
                                                                 <i class="bi bi-x-lg"></i> Reject request
                                                             </button>
                                                         </div>
                                                     </td>
                                                 </tr>
                                             <?php endforeach; ?>
                                         </tbody>
                                     </table>
                                 </div>
                             </div>
                         <?php endif; ?>
                     </div>

                    <!-- PROCESSED HISTORICAL PANE -->
                    <div class="tab-pane fade" id="history-pane" role="tabpanel">
                        <?php if (empty($history_list)): ?>
                            <div class="text-center py-5 text-muted bg-white border rounded-4 animate-fade-up">
                                <i class="bi bi-clock-history mb-3 text-secondary" style="font-size: 3rem;"></i>
                                <h5 class="fw-bold">No History Record</h5>
                                <p class="mb-0 text-secondary">Processed records will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="data-table-shell animate-fade-up">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Student</th>
                                                <th>Route</th>
                                                <th>Requested By</th>
                                                <th>Reviewed By</th>
                                                <th>Status</th>
                                                <th>Admin Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($history_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></div>
                                                        <small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="soft-badge soft-badge-neutral text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['from_school']) ?></span>
                                                            <i class="bi bi-arrow-right text-muted"></i>
                                                            <span class="soft-badge soft-badge-primary text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        </div>
                                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <small class="text-muted"><?= date('M d, Y', strtotime($row['requested_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['reviewer_name']): ?>
                                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($row['reviewer_name']) ?></div>
                                                            <small class="text-muted"><?= date('M d, Y', strtotime($row['reviewed_at'])) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= get_status_badge($row['status']) ?></td>
                                                    <td style="max-width: 200px;" class="text-wrap small text-secondary">
                                                        <?= htmlspecialchars($row['admin_notes'] ?: 'No notes') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ALL HISTORY & FILTERS PANE -->
                    <div class="tab-pane fade" id="all-pane" role="tabpanel">
                        <div class="workbench-panel p-4 mb-4 animate-fade-up">
                            <form method="GET" action="transferRequests.php" class="row g-3">
                                <input type="hidden" name="tab" value="all">
                                <div class="col-md-3">
                                    <label class="form-label">School Filter</label>
                                    <select class="form-select" name="filter_school">
                                        <option value="0">All Schools</option>
                                        <?php foreach ($schools as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= $filter_school === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['school_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="filter_status">
                                        <option value="">All Statuses</option>
                                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="date" class="form-control" name="filter_start_date" value="<?= htmlspecialchars($filter_start_date) ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="date" class="form-control" name="filter_end_date" value="<?= htmlspecialchars($filter_end_date) ?>">
                                </div>
                                <div class="col-md-3 d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4 w-100 fw-bold">
                                        <i class="bi bi-filter"></i> Apply Filters
                                    </button>
                                    <a href="transferRequests.php?tab=all" class="btn btn-outline-secondary rounded-pill px-3">
                                        Reset
                                    </a>
                                </div>
                            </form>
                        </div>

                        <div class="data-table-shell animate-fade-up stagger-1">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-4">Student</th>
                                            <th>From School</th>
                                            <th>To School</th>
                                            <th>Requested Date</th>
                                            <th>Status</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($all_list)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">No request found matching the search criteria.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($all_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></div>
                                                        <small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td><span class="fw-semibold text-dark"><?= htmlspecialchars($row['from_school']) ?></span></td>
                                                    <td>
                                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        <div class="mt-1 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="text-muted small"><?= date('M d, Y · h:i A', strtotime($row['requested_at'])) ?></td>
                                                    <td><?= get_status_badge($row['status']) ?></td>
                                                    <td>
                                                        <div class="small text-secondary"><strong>By:</strong> <?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <?php if ($row['reviewer_name']): ?>
                                                            <div class="small text-secondary"><strong>Reviewer:</strong> <?= htmlspecialchars($row['reviewer_name']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div><!-- /#all-pane -->

                <?php else: /* ═══ COORDINATOR VIEW ═══ */ ?>

                    <!-- INCOMING: Requests to review -->
                    <div class="tab-pane fade show active" id="incoming-pane" role="tabpanel">
                        <?php if (empty($incoming_list)): ?>
                            <div class="text-center py-5 text-muted bg-white border rounded-4 animate-fade-up">
                                <i class="bi bi-patch-check text-primary mb-3" style="font-size: 3rem;"></i>
                                <h5 class="fw-bold">No Requests to Review</h5>
                                <p class="mb-0 text-secondary">There are no transfer requests waiting for your school.</p>
                            </div>
                        <?php else: ?>
                            <div class="data-table-shell animate-fade-up">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Student (LRN)</th>
                                                <th>Current School</th>
                                                <th>New School</th>
                                                <th>Requested By</th>
                                                <th>Requested Date</th>
                                                <th>Reason</th>
                                                <th class="text-end pe-4">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($incoming_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark">
                                                            <?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?>
                                                        </div>
                                                        <br><small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td><span class="fw-semibold text-dark"><?= htmlspecialchars($row['from_school']) ?></span></td>
                                                    <td>
                                                        <span class="fw-semibold text-primary"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        <div class="mt-1 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <small class="text-muted">Effective: SY <?= htmlspecialchars($row['effective_sy']) ?></small>
                                                    </td>
                                                    <td class="text-muted small"><?= date('F d, Y · h:i A', strtotime($row['requested_at'])) ?></td>
                                                    <td style="max-width: 250px; white-space: normal; word-break: break-word;" class="text-secondary small">
                                                        <?= htmlspecialchars($row['reason']) ?>
                                                    </td>
                                                    <td class="text-end pe-4">
                                                        <div class="row-actions-panel">
                                                            <button type="button" class="btn-row-action btn-row-approve" title="Approve request" data-row="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" onclick="openReviewModal(this, 'approve')">
                                                                <i class="bi bi-check-lg"></i> Approve request
                                                            </button>
                                                            <button type="button" class="btn-row-action btn-row-reject" title="Reject request" data-row="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>" onclick="openReviewModal(this, 'reject')">
                                                                <i class="bi bi-x-lg"></i> Reject request
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- SENT: Requests Sent -->
                    <div class="tab-pane fade" id="sent-pane" role="tabpanel">
                        <?php if (empty($sent_list)): ?>
                            <div class="text-center py-5 text-muted bg-white border rounded-4 animate-fade-up">
                                <i class="bi bi-box-arrow-right mb-3 text-primary" style="font-size: 3rem;"></i>
                                <h5 class="fw-bold">No Requests Sent</h5>
                                <p class="mb-0 text-secondary">Your school has not sent a transfer request.</p>
                            </div>
                        <?php else: ?>
                            <div class="data-table-shell animate-fade-up">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Student (LRN)</th>
                                                <th>Route</th>
                                                <th>Requested By</th>
                                                <th>Effective SY</th>
                                                <th>Reason</th>
                                                <th>Status</th>
                                                <th>Remarks / Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sent_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark">
                                                            <?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?>
                                                        </div>
                                                        <br><small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="soft-badge soft-badge-neutral text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['from_school']) ?></span>
                                                            <i class="bi bi-arrow-right text-muted"></i>
                                                            <span class="soft-badge soft-badge-primary text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        </div>
                                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <small class="text-muted"><?= date('M d, Y', strtotime($row['requested_at'])) ?></small>
                                                    </td>
                                                    <td><span class="fw-semibold text-dark"><?= htmlspecialchars($row['effective_sy']) ?></span></td>
                                                    <td style="max-width: 220px; white-space: normal; word-break: break-word;" class="text-secondary small">
                                                        <?= htmlspecialchars($row['reason']) ?>
                                                    </td>
                                                    <td><?= get_status_badge($row['status']) ?></td>
                                                    <td>
                                                        <?php if ($row['status'] === 'pending'): ?>
                                                            <?php if ($row['requested_by'] == $_SESSION['user_id']): ?>
                                                                <button class="btn btn-sm btn-danger rounded-pill px-3 fw-bold" onclick="cancelRequest(<?= $row['id'] ?>)">
                                                                    <i class="bi bi-x-circle"></i> Cancel
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted fst-italic">Awaiting review</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-secondary small"><?= htmlspecialchars($row['admin_notes'] ?: 'No review notes') ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- COMPLETED: Transfer History -->
                    <div class="tab-pane fade" id="completed-pane" role="tabpanel">
                        <?php if (empty($completed_list)): ?>
                            <div class="text-center py-5 text-muted bg-white border rounded-4 animate-fade-up">
                                <i class="bi bi-clock-history mb-3 text-secondary" style="font-size: 3rem;"></i>
                                <h5 class="fw-bold">No Completed Records</h5>
                                <p class="mb-0 text-secondary">Historical transfer logs will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="data-table-shell animate-fade-up">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="ps-4">Student (LRN)</th>
                                                <th>Route</th>
                                                <th>Requested</th>
                                                <th>Reviewed By</th>
                                                <th>Status</th>
                                                <th>Review Notes / Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($completed_list as $row): ?>
                                                <tr>
                                                    <td class="ps-4">
                                                        <div class="fw-bold text-dark">
                                                            <?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?>
                                                        </div>
                                                        <br><small class="text-muted font-monospace"><?= htmlspecialchars($row['lrn']) ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="soft-badge soft-badge-neutral text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['from_school']) ?></span>
                                                            <i class="bi bi-arrow-right text-muted"></i>
                                                            <span class="soft-badge soft-badge-primary text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['to_school']) ?></span>
                                                        </div>
                                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                                            <span class="soft-badge <?= ($row['transfer_type'] ?? 'internal') === 'external' ? 'soft-badge-warning' : 'soft-badge-primary' ?>"><?= htmlspecialchars(ucfirst($row['transfer_type'] ?? 'internal')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['requester_name']) ?></div>
                                                        <small class="text-muted"><?= date('M d, Y', strtotime($row['requested_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['reviewer_name']): ?>
                                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($row['reviewer_name']) ?></div>
                                                            <small class="text-muted"><?= date('M d, Y', strtotime($row['reviewed_at'])) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= get_status_badge($row['status']) ?></td>
                                                    <td style="max-width: 200px;" class="text-wrap small text-secondary">
                                                        <?= htmlspecialchars($row['admin_notes'] ?: 'No notes') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endif; /* end coordinator view */ ?>

                </div><!-- /.tab-content -->
            </main>
        </div>
    </div>

    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow-lg p-4">
                <form action="approveTransfer.php" method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="request_id" id="modalRequestId">
                    <input type="hidden" name="action" id="modalAction">
                    
                    <div class="modal-header border-0 pb-0 ps-0">
                        <h4 class="modal-title fw-bold" id="modalTitle">Review Transfer Request</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body py-3 ps-0 pe-0">
                        <div class="alert p-3 rounded-4 mb-4 d-flex align-items-center gap-2" id="modalAlertBox" style="background-color: var(--color-paper-2); border: 1px solid var(--color-rule);">
                            <i class="bi bi-info-circle-fill text-primary" id="modalAlertIcon"></i>
                            <p class="mb-0 text-secondary small">You are about to <span class="fw-bold" id="modalActionText"></span> the transfer request for <strong id="modalStudentName" class="text-dark"></strong>.</p>
                        </div>
                        
                        <div class="card bg-light rounded-4 border p-3 mb-4">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-person-circle text-secondary fs-4"></i>
                                <div>
                                    <h6 class="fw-bold text-dark mb-0" id="modalLrnName">John Doe</h6>
                                    <small class="text-muted">LRN: <code id="modalLrnCode">000000000000</code></small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <!-- Before (Current School) Column -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded-4 bg-white h-100">
                                    <div class="d-flex align-items-center gap-2 mb-3 text-secondary">
                                        <i class="bi bi-arrow-left-circle-fill"></i>
                                        <span class="fw-bold text-uppercase small">Before (Current)</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Current School</label>
                                        <span class="text-dark fw-semibold" id="confirmBeforeSchool">—</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Requested By</label>
                                        <span class="text-dark fw-semibold" id="confirmBeforeRequester">—</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Reason</label>
                                        <span class="text-secondary small d-block" id="confirmBeforeReason" style="max-height: 80px; overflow-y: auto; white-space: normal; word-break: break-word;">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- After (Proposed Destination) Column -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded-4 h-100" id="confirmAfterCol">
                                    <div class="d-flex align-items-center gap-2 mb-3" id="confirmAfterHeader">
                                        <i class="bi bi-arrow-right-circle-fill"></i>
                                        <span class="fw-bold text-uppercase small" id="confirmAfterHeaderText">After (Proposed)</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Destination School</label>
                                        <span class="fw-bold" id="confirmAfterSchool">—</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Effective SY</label>
                                        <span class="fw-bold" id="confirmAfterSy">—</span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Transfer Type</label>
                                        <span class="badge py-2 px-3 fs-7" id="confirmAfterTransferType">—</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="admin_notes" class="form-label" id="adminNotesLabel">Review / Administrative Notes (Optional)</label>
                            <textarea class="form-control" name="admin_notes" id="admin_notes" rows="3" placeholder="Enter notes or feedback regarding this transfer..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-0 pt-0 pe-0 gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold" id="modalSubmitBtn">Submit Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        // Handle active tabs on reload
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab === 'all') {
            const allTabEl = document.querySelector('#all-tab');
            if (allTabEl) (new bootstrap.Tab(allTabEl)).show();
        } else if (activeTab === 'history') {
            const histTabEl = document.querySelector('#history-tab');
            if (histTabEl) (new bootstrap.Tab(histTabEl)).show();
        } else if (activeTab === 'incoming') {
            const tabEl = document.querySelector('#incoming-tab');
            if (tabEl) (new bootstrap.Tab(tabEl)).show();
        } else if (activeTab === 'sent') {
            const tabEl = document.querySelector('#sent-tab');
            if (tabEl) (new bootstrap.Tab(tabEl)).show();
        } else if (activeTab === 'completed') {
            const tabEl = document.querySelector('#completed-tab');
            if (tabEl) (new bootstrap.Tab(tabEl)).show();
        }

        const reviewModal = new bootstrap.Modal(document.getElementById('reviewModal'));
        
        function openReviewModal(button, action) {
            const row = JSON.parse(button.getAttribute('data-row'));
            
            document.getElementById('modalRequestId').value = row.id;
            document.getElementById('modalAction').value = action;
            
            // Set student names
            const fullName = row.first_name + ' ' + row.last_name;
            document.getElementById('modalStudentName').textContent = fullName;
            document.getElementById('modalLrnName').textContent = fullName;
            document.getElementById('modalLrnCode').textContent = row.lrn;
            
            // Set Before elements
            document.getElementById('confirmBeforeSchool').textContent = row.from_school;
            document.getElementById('confirmBeforeRequester').textContent = row.requester_name;
            document.getElementById('confirmBeforeReason').textContent = row.reason || 'No reason provided';
            
            // Set After elements
            document.getElementById('confirmAfterSchool').textContent = row.to_school;
            document.getElementById('confirmAfterSy').textContent = 'SY ' + (row.effective_sy || '—');
            
            const transTypeBadge = document.getElementById('confirmAfterTransferType');
            transTypeBadge.textContent = row.transfer_type.toUpperCase();
            transTypeBadge.className = 'badge py-2 px-3 fs-7 ' + (row.transfer_type === 'external' ? 'bg-warning text-dark' : 'bg-primary');
            
            const actionText = document.getElementById('modalActionText');
            const submitBtn = document.getElementById('modalSubmitBtn');
            const notesLabel = document.getElementById('adminNotesLabel');
            const notesTextarea = document.getElementById('admin_notes');
            
            const afterCol = document.getElementById('confirmAfterCol');
            const afterHeader = document.getElementById('confirmAfterHeader');
            const afterHeaderText = document.getElementById('confirmAfterHeaderText');
            const afterSchool = document.getElementById('confirmAfterSchool');
            const afterSy = document.getElementById('confirmAfterSy');
            
            if (action === 'approve') {
                actionText.textContent = 'approve and move';
                actionText.className = 'fw-bold text-success';
                submitBtn.textContent = 'Approve and move learner';
                submitBtn.className = 'btn btn-success rounded-pill px-5 fw-bold';
                if (notesLabel) notesLabel.innerHTML = 'Review Notes / Remarks (Optional)';
                if (notesTextarea) notesTextarea.removeAttribute('required');
                
                // Color columns for approval
                afterCol.className = 'p-3 border border-success-subtle rounded-4 bg-success-subtle bg-opacity-10 h-100';
                afterHeader.className = 'd-flex align-items-center gap-2 mb-3 text-success';
                afterHeaderText.textContent = 'After (New Enrollment)';
                afterSchool.className = 'fw-bold text-success';
                afterSy.className = 'fw-bold text-success';
            } else {
                actionText.textContent = 'reject';
                actionText.className = 'fw-bold text-danger';
                submitBtn.textContent = 'Reject request';
                submitBtn.className = 'btn btn-danger rounded-pill px-5 fw-bold';
                if (notesLabel) notesLabel.innerHTML = 'Reason for Rejection <span class="text-danger">*</span>';
                if (notesTextarea) notesTextarea.setAttribute('required', 'required');
                
                // Color columns for rejection
                afterCol.className = 'p-3 border border-danger-subtle rounded-4 bg-danger-subtle bg-opacity-10 h-100';
                afterHeader.className = 'd-flex align-items-center gap-2 mb-3 text-danger';
                afterHeaderText.textContent = 'After (Rejected)';
                afterSchool.className = 'fw-bold text-danger';
                afterSchool.textContent = 'No transfer (Stay at ' + row.from_school + ')';
                afterSy.className = 'fw-bold text-danger';
                afterSy.textContent = 'N/A';
                transTypeBadge.className = 'badge py-2 px-3 fs-7 bg-danger';
                transTypeBadge.textContent = 'REJECTED';
            }
            
            reviewModal.show();
        }

        function cancelRequest(requestId) {
            if (!confirm('Are you sure you want to cancel this transfer request?')) {
                return;
            }
            const formData = new FormData();
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('action', 'cancel');
            formData.append('request_id', requestId);

            fetch('submitTransferRequest.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showSystemNotice(data.message || 'Request cancelled successfully.', 'Success', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showSystemNotice(data.error || 'Failed to cancel request.', 'Error', 'danger');
                }
            })
            .catch(() => {
                showSystemNotice('Server error while cancelling request.', 'Error', 'danger');
            });
        }
    </script>
</body>
</html>
