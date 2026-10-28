<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$user_school_id = $_SESSION['school_id'] ?? null;

// Restrict access: only coordinators and admins are allowed. Teachers are excluded.
if (!user_is_coordinator($user_role) || user_is_teacher($user_role)) {
    header("Location: 403.php");
    exit();
}

$message = '';

$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'transfer') {
        $lrn = trim($_POST['lrn'] ?? '');
        $new_school_id = (int)($_POST['new_school_id'] ?? 0);
        $school_year = trim($_POST['school_year'] ?? $current_sy);
        $reason = trim($_POST['transfer_reason'] ?? '');

        $conn->begin_transaction();
        try {
            if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn) || $new_school_id <= 0 || empty($reason) || empty($school_year)) {
                throw new Exception("Please choose a learner, school year, new school, and reason.");
            }

            // Get current active enrollment
            $stmt = $conn->prepare("SELECT id, school_id FROM enrollments WHERE lrn = ? AND is_deleted = 0 AND is_historical = 0 AND school_year = ? LIMIT 1");
            $stmt->bind_param("ss", $lrn, $school_year);
            $stmt->execute();
            $res = $stmt->get_result();
            $old_enroll = $res->fetch_assoc();

            if (!$old_enroll) {
                throw new Exception("This learner has no current school record for SY $school_year.");
            }

            $from_school_id = (int)$old_enroll['school_id'];

            if (!user_is_admin($user_role) && $from_school_id != $user_school_id) {
                throw new Exception("You can only request transfers for learners currently enrolled in your school.");
            }

            if ($from_school_id == $new_school_id) {
                throw new Exception("The new school must be different from the current school.");
            }

            // Check if pending request already exists
            $stmt_pending = $conn->prepare("SELECT id FROM transfer_requests WHERE learner_id = ? AND status = 'pending' LIMIT 1");
            $stmt_pending->bind_param("s", $lrn);
            $stmt_pending->execute();
            if ($stmt_pending->get_result()->fetch_assoc()) {
                throw new Exception("This learner already has a pending transfer request.");
            }

            // Insert request
            $stmt_ins = $conn->prepare("
                INSERT INTO transfer_requests (learner_id, from_school_id, to_school_id, requested_by, request_direction, transfer_type, reason, effective_sy, status)
                VALUES (?, ?, ?, ?, 'outbound', 'internal', ?, ?, 'pending')
            ");
            $stmt_ins->bind_param("siiiss", $lrn, $from_school_id, $new_school_id, $_SESSION['user_id'], $reason, $school_year);
            $stmt_ins->execute();

            log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_REQUEST_SUBMITTED', 'enrollments', $lrn, "Submitted transfer request for LRN: $lrn to school ID: $new_school_id (SY $school_year)");

            $conn->commit();
            $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Transfer request sent. The learner will move after approval.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } elseif ($action === 'cancel_request') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT from_school_id, status FROM transfer_requests WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            
            if (!$req) {
                throw new Exception("Transfer request not found.");
            }
            if ($req['status'] !== 'pending') {
                throw new Exception("Only pending requests can be cancelled.");
            }
            if (!user_is_admin($user_role) && $req['from_school_id'] != $user_school_id) {
                throw new Exception("Unauthorized to cancel this request.");
            }
            
            $stmt_upd = $conn->prepare("UPDATE transfer_requests SET status = 'cancelled' WHERE id = ?");
            $stmt_upd->bind_param("i", $request_id);
            $stmt_upd->execute();
            
            log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_REQUEST_CANCELLED', 'transfer_requests', $request_id, "Cancelled transfer request ID: $request_id");
            
            $conn->commit();
            $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Transfer request cancelled successfully.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Load static lists
$schools = [];
$res = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) $schools[] = $row;
}

// Fetch outbound requests
$outbound_list = [];
if (user_is_admin($user_role)) {
    $stmt_out = $conn->prepare("
        SELECT tr.*, l.first_name, l.last_name, s_to.school_name as target_school, s_from.school_name as source_school, u.full_name as requester_name
        FROM transfer_requests tr
        JOIN learners l ON tr.learner_id = l.lrn
        JOIN schools s_from ON tr.from_school_id = s_from.id
        JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u ON tr.requested_by = u.id
        ORDER BY tr.requested_at DESC
    ");
} else {
    $stmt_out = $conn->prepare("
        SELECT tr.*, l.first_name, l.last_name, s_to.school_name as target_school, s_from.school_name as source_school, u.full_name as requester_name
        FROM transfer_requests tr
        JOIN learners l ON tr.learner_id = l.lrn
        JOIN schools s_from ON tr.from_school_id = s_from.id
        JOIN schools s_to ON tr.to_school_id = s_to.id
        JOIN users u ON tr.requested_by = u.id
        WHERE tr.from_school_id = ?
        ORDER BY tr.requested_at DESC
    ");
    $stmt_out->bind_param("i", $user_school_id);
}
if ($stmt_out) {
    $stmt_out->execute();
    $outbound_list = $stmt_out->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_status_badge($status) {
    $class = 'soft-badge-neutral';
    if ($status === 'pending') $class = 'soft-badge-warning';
    elseif ($status === 'approved') $class = 'soft-badge-success';
    elseif ($status === 'rejected') $class = 'soft-badge-danger';
    elseif ($status === 'cancelled') $class = 'soft-badge-secondary';
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
    <title>School Transfer | SNED Profiling</title>
    <style>
        body { background: var(--color-canvas); }
        .badge-premium-diagnosis {
            background-color: var(--sned-dew);
            color: var(--sned-accent);
            font-weight: 600;
            border: 1px solid var(--sned-mint);
            border-radius: 50rem;
            white-space: normal;
            display: inline-block;
            line-height: 1.2;
        }
        .x-small {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
        }
        .soft-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35em 0.8em;
            border-radius: 50rem;
            display: inline-block;
            text-align: center;
        }
        .soft-badge-warning { background-color: #fff3cd; color: #856404; }
        .soft-badge-success { background-color: #d4edda; color: #155724; }
        .soft-badge-danger { background-color: #f8d7da; color: #721c24; }
        .soft-badge-secondary { background-color: #e2e3e5; color: #383d41; }
        .soft-badge-neutral { background-color: #f8f9fa; color: #333; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-up {
            opacity: 0;
            animation: fadeInUp 0.6s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }
        .stagger-1 { animation-delay: 0.15s; }
    </style>
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
                <div class="page-toolbar">
                    <div>
                        <h1 class="page-title">School Transfer</h1>
                        <p class="page-subtitle">Submit a request to move active learner enrollment to another school.</p>
                    </div>
                    <div class="toolbar-actions">
                        <span class="status-chip"><i class="bi bi-calendar3"></i> Current SY <?= htmlspecialchars($current_sy) ?></span>
                    </div>
                </div>

                <?= $message ?>

                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs border-bottom-0 mb-4 bg-white p-2 rounded-4 shadow-sm animate-fade-up" id="transferTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill px-4 fw-bold" id="new-tab" data-bs-toggle="tab" data-bs-target="#new-pane" type="button" role="tab" aria-controls="new-pane" aria-selected="true">
                            <i class="bi bi-send-fill me-2"></i>New Transfer Request
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-4 fw-bold" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent-pane" type="button" role="tab" aria-controls="sent-pane" aria-selected="false">
                            <i class="bi bi-clock-history me-2"></i>Outbound Requests (<?= count($outbound_list) ?>)
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="transferTabsContent">
                    <!-- Tab 1: New Request -->
                    <div class="tab-pane fade show active" id="new-pane" role="tabpanel" aria-labelledby="new-tab">
                        <div class="row g-4">
                    <!-- Left Column: Form -->
                    <div class="col-lg-7 animate-fade-up">
                        <div class="card rounded-4 border-0 shadow-sm">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-1">Send transfer request</h5>
                                <p class="text-muted small mb-4">Choose the learner and the new school. The learner moves after the request is approved.</p>

                                <form method="POST" id="transferForm">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="transfer">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small text-uppercase">Learner</label>
                                            <div class="position-relative">
                                                <input type="text" id="transferSearchInput" class="form-control rounded-3" placeholder="Search name or LRN..." autocomplete="off">
                                                <input type="hidden" name="lrn" id="transferSelectedLrn" required>
                                                <div id="transferSearchResults" class="list-group position-absolute w-100 shadow-sm rounded-3" style="z-index: 1000; display: none; max-height: 250px; overflow-y: auto;"></div>
                                            </div>
                                            <div class="form-text">Choose a learner from the list before sending the request.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small text-uppercase">School Year</label>
                                            <select name="school_year" class="form-select rounded-3" required><?= school_year_options($current_sy) ?></select>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase">New School</label>
                                        <select name="new_school_id" class="form-select rounded-3" required>
                                            <option value="">Select new school</option>
                                            <?php foreach ($schools as $s): ?>
                                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['school_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label fw-bold small text-uppercase">Reason for Transfer *</label>
                                        <textarea name="transfer_reason" class="form-control rounded-3" rows="2" placeholder="Example: Change of residence" required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold"><i class="bi bi-send me-1"></i> Send transfer request</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Info Panel -->
                    <div class="col-lg-5 animate-fade-up stagger-1">
                        <div id="transferSelectedLearnerPlaceholder" class="card rounded-4 border-0 shadow-sm p-4 text-center text-muted h-100 d-flex flex-column justify-content-center align-items-center" style="min-height: 350px;">
                            <i class="bi bi-person-bounding-box fs-1 mb-2 text-secondary opacity-50"></i>
                            <p class="mb-0 small text-uppercase fw-bold" style="letter-spacing: 0.05em;">No Learner Selected</p>
                            <span class="d-block small text-muted mt-1">Search and select a student to see profile details.</span>
                        </div>

                        <div id="transferLearnerInfo" class="card rounded-4 border-0 shadow-sm p-4 d-none h-100">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <i class="bi bi-person-check-fill text-warning fs-4"></i>
                                <h6 class="fw-bold mb-0 text-dark">Learner Profile Info</h6>
                            </div>
                            <div class="row g-3 small text-muted">
                                <div class="col-12">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Name</label>
                                    <span class="text-dark fw-bold fs-6" id="transferInfoName"></span>
                                </div>
                                <div class="col-6">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">LRN</label>
                                    <span class="text-dark fw-semibold" id="transferInfoLrn"></span>
                                </div>
                                <div class="col-6">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Age</label>
                                    <span class="text-dark fw-semibold"><span id="transferInfoAge"></span> yrs old</span>
                                </div>
                                <div class="col-12">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Current School</label>
                                    <span class="text-dark fw-semibold" id="transferInfoSchool"></span>
                                </div>
                                <div class="col-6">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Grade Level</label>
                                    <span class="text-dark fw-semibold" id="transferInfoGrade"></span>
                                </div>
                                <div class="col-6">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Placement</label>
                                    <span class="text-dark fw-semibold" id="transferInfoPlacement"></span>
                                </div>
                                <div class="col-12">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Primary Diagnosis</label>
                                    <span class="badge badge-premium-diagnosis py-2 px-3 fs-7" id="transferInfoDiagnosis"></span>
                                </div>
                                <div class="col-12">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Enrollment School Year</label>
                                    <span class="badge bg-light text-dark border py-2 px-3 fs-7" id="transferInfoSy"></span>
                                </div>
                                <div class="col-12">
                                    <label class="text-uppercase text-muted x-small d-block mb-0.5 fw-bold">Enrollment Status</label>
                                    <span id="transferInfoEnrollmentStatus" class="badge py-2 px-3 fs-7"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>

                    <!-- Tab 2: Outbound Requests -->
                    <div class="tab-pane fade" id="sent-pane" role="tabpanel" aria-labelledby="sent-tab">
                        <div class="card rounded-4 border-0 shadow-sm p-4 animate-fade-up">
                            <h5 class="fw-bold mb-3">Outbound Transfer Requests Log</h5>
                            <?php if (empty($outbound_list)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-folder-x fs-1 mb-2 text-secondary opacity-50"></i>
                                    <p class="mb-0 small text-uppercase fw-bold" style="letter-spacing: 0.05em;">No Outbound Transfer Requests Found</p>
                                    <span class="d-block small text-muted mt-1">Pending and processed transfer requests will show up here.</span>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle border">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Learner</th>
                                                <th>Source School</th>
                                                <th>Target School</th>
                                                <th>Effective SY</th>
                                                <th>Reason</th>
                                                <th>Requested By</th>
                                                <th>Status</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($outbound_list as $req): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($req['last_name'] . ', ' . $req['first_name']) ?></div>
                                                        <div class="small text-muted"><code><?= htmlspecialchars($req['learner_id']) ?></code></div>
                                                    </td>
                                                    <td><?= htmlspecialchars($req['source_school']) ?></td>
                                                    <td><?= htmlspecialchars($req['target_school']) ?></td>
                                                    <td>SY <?= htmlspecialchars($req['effective_sy']) ?></td>
                                                    <td class="small text-muted" style="max-width: 250px;"><?= htmlspecialchars($req['reason']) ?></td>
                                                    <td class="small"><?= htmlspecialchars($req['requester_name']) ?></td>
                                                    <td><?= get_status_badge($req['status']) ?></td>
                                                    <td class="text-end">
                                                        <?php if ($req['status'] === 'pending'): ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this transfer request?');">
                                                                <?= csrf_input() ?>
                                                                <input type="hidden" name="action" value="cancel_request">
                                                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                                                                    <i class="bi bi-x-circle me-1"></i>Cancel
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="text-muted small">—</span>
                                                        <?php endif; ?>
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
            </main>
        </div>
    </div>
    <!-- Transfer Confirmation Modal -->
    <div class="modal fade" id="confirmTransferModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="confirmTransferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="confirmTransferModalLabel">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Confirm School Transfer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <p class="text-muted small mb-4">Please review the details below before submitting the transfer request. The student's current enrollment remains active until the destination school coordinator approves this request.</p>
                    
                    <div class="mb-3 p-3 bg-light rounded-4 border">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center text-md-start">
                                <i class="bi bi-person-bounding-box fs-1 text-secondary"></i>
                            </div>
                            <div class="col-md-10">
                                <h6 class="fw-bold text-dark mb-1" id="modalLrnName">John Doe</h6>
                                <p class="text-muted small mb-0">LRN: <code id="modalLrnCode">123456789012</code></p>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Before (Current) Column -->
                        <div class="col-md-6">
                            <div class="p-3 border rounded-4 bg-white h-100">
                                <div class="d-flex align-items-center gap-2 mb-3 text-secondary">
                                    <i class="bi bi-arrow-left-circle-fill"></i>
                                    <span class="fw-bold text-uppercase small">Current School</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">School Name</label>
                                    <span class="text-dark fw-semibold" id="confirmBeforeSchool">Current School</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Grade / Program</label>
                                    <span class="text-dark fw-semibold" id="confirmBeforeLevel">Grade 1</span>
                                </div>
                            </div>
                        </div>

                        <!-- After (Proposed) Column -->
                        <div class="col-md-6">
                            <div class="p-3 border border-warning-subtle rounded-4 bg-warning-subtle bg-opacity-10 h-100">
                                <div class="d-flex align-items-center gap-2 mb-3 text-warning">
                                    <i class="bi bi-arrow-right-circle-fill"></i>
                                    <span class="fw-bold text-uppercase small">Destination School</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Target School Name</label>
                                    <span class="text-warning fw-bold" id="confirmAfterSchool">New School</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Effective School Year</label>
                                    <span class="text-warning fw-bold" id="confirmEffectiveSy">SY 2026-2027</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="btnConfirmTransfer" class="btn btn-warning rounded-pill px-4 fw-bold text-dark">Confirm & Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const currentSy = '<?= $current_sy ?>';
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }

        // --- School Transfer Javascript ---
        const transferSearchInput = document.getElementById('transferSearchInput');
        const transferSearchResults = document.getElementById('transferSearchResults');
        const transferSelectedLrn = document.getElementById('transferSelectedLrn');
        const transferLearnerInfo = document.getElementById('transferLearnerInfo');
        const transferPlaceholder = document.getElementById('transferSelectedLearnerPlaceholder');
        let transferSearchTimeout = null;
        let transferSelectedDisplay = '';

        function clearTransferLearnerSelection() {
            transferSelectedLrn.value = '';
            transferSelectedDisplay = '';
            transferLearnerInfo.classList.add('d-none');
            transferPlaceholder.classList.remove('d-none');
        }

        transferSearchInput.addEventListener('input', function() {
            clearTimeout(transferSearchTimeout);
            const q = this.value.trim();
            if (transferSelectedLrn.value && q !== transferSelectedDisplay) {
                clearTransferLearnerSelection();
            }
            if (q.length < 2) {
                transferSearchResults.style.display = 'none';
                clearTransferLearnerSelection();
                return;
            }
            transferSearchTimeout = setTimeout(function() {
                fetch('ajax_learner_search.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        transferSearchResults.innerHTML = '';
                        if (!data.success || data.results.length === 0) {
                            transferSearchResults.innerHTML = '<div class="list-group-item text-muted small">No learners found.</div>';
                            transferSearchResults.style.display = 'block';
                            return;
                        }
                        data.results.forEach(l => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = '<div class="fw-semibold small">' + l.name + '</div><div class="small text-muted"><code>' + l.lrn + '</code> &middot; ' + l.school_name + ' &middot; ' + l.grade_level + '</div>';
                            item.addEventListener('click', function() {
                                transferSelectedLrn.value = l.lrn;
                                transferSearchInput.value = l.name + ' (' + l.lrn + ')';
                                transferSelectedDisplay = transferSearchInput.value;
                                transferSearchResults.style.display = 'none';

                                // Disable/hide current school in destination dropdown
                                const destSelect = document.querySelector('select[name="new_school_id"]');
                                if (destSelect) {
                                    Array.from(destSelect.options).forEach(opt => {
                                        if (opt.value && parseInt(opt.value) === parseInt(l.school_id)) {
                                            opt.disabled = true;
                                            opt.style.display = 'none';
                                            if (destSelect.value === opt.value) {
                                                destSelect.value = '';
                                            }
                                        } else {
                                            opt.disabled = false;
                                            opt.style.display = '';
                                        }
                                    });
                                }

                                document.getElementById('transferInfoName').textContent = l.name;
                                document.getElementById('transferInfoLrn').textContent = l.lrn;
                                document.getElementById('transferInfoAge').textContent = l.age;
                                document.getElementById('transferInfoSchool').textContent = l.school_name;
                                document.getElementById('transferInfoGrade').textContent = l.grade_level;

                                let placementText = l.placement;
                                if (l.self_contained_level) {
                                    placementText += ' (' + l.self_contained_level + ')';
                                }
                                document.getElementById('transferInfoPlacement').textContent = placementText;
                                document.getElementById('transferInfoDiagnosis').textContent = l.primary_diagnosis;
                                document.getElementById('transferInfoSy').textContent = l.school_year;

                                const statusSpan = document.getElementById('transferInfoEnrollmentStatus');
                                statusSpan.className = 'badge py-2 px-3 fs-7';
                                if (l.school_year === currentSy) {
                                    statusSpan.textContent = 'Already Enrolled for SY ' + currentSy;
                                    statusSpan.classList.add('bg-success-subtle', 'text-success', 'border', 'border-success-subtle');
                                } else {
                                    statusSpan.textContent = 'Not Yet Enrolled for SY ' + currentSy;
                                    statusSpan.classList.add('bg-danger-subtle', 'text-danger', 'border', 'border-danger-subtle');
                                }

                                transferPlaceholder.classList.add('d-none');
                                transferLearnerInfo.classList.remove('d-none');
                            });
                            transferSearchResults.appendChild(item);
                        });
                        transferSearchResults.style.display = 'block';
                    })
                    .catch(() => {
                        transferSearchResults.innerHTML = '<div class="list-group-item text-danger small">Server error.</div>';
                        transferSearchResults.style.display = 'block';
                    });
            }, 300);
        });

        // Global click handler to close dropdown
        document.addEventListener('click', function(e) {
            if (!transferSearchInput.contains(e.target) && !transferSearchResults.contains(e.target)) {
                transferSearchResults.style.display = 'none';
            }
        });

        let isConfirmed = false;
        const transferForm = document.getElementById('transferForm');
        if (transferForm) {
            transferForm.addEventListener('submit', function(event) {
                if (!transferSelectedLrn.value || transferSearchInput.value.trim() !== transferSelectedDisplay) {
                    event.preventDefault();
                    clearTransferLearnerSelection();
                    showSystemNotice('Please choose a learner from the list before sending the request.', 'Learner Required', 'danger');
                    return;
                }
                
                if (!isConfirmed) {
                    event.preventDefault();
                    
                    document.getElementById('modalLrnName').textContent = document.getElementById('transferInfoName').textContent;
                    document.getElementById('modalLrnCode').textContent = document.getElementById('transferInfoLrn').textContent;
                    document.getElementById('confirmBeforeSchool').textContent = document.getElementById('transferInfoSchool').textContent;
                    document.getElementById('confirmBeforeLevel').textContent = document.getElementById('transferInfoGrade').textContent;
                    
                    const destSelect = document.querySelector('select[name="new_school_id"]');
                    document.getElementById('confirmAfterSchool').textContent = destSelect.options[destSelect.selectedIndex].text;
                    document.getElementById('confirmEffectiveSy').textContent = 'SY ' + document.querySelector('select[name="school_year"]').value;
                    
                    const modalEl = document.getElementById('confirmTransferModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            });

            const btnConfirmTransfer = document.getElementById('btnConfirmTransfer');
            if (btnConfirmTransfer) {
                btnConfirmTransfer.addEventListener('click', function() {
                    isConfirmed = true;
                    transferForm.submit();
                });
            }
        }
    </script>
</body>
</html>
