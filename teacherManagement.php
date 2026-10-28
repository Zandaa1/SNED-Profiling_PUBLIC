<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$school_id = $_SESSION['school_id'] ?? null;

if (!user_is_coordinator($user_role) || user_is_teacher($user_role)) {
    header("Location: main.php");
    exit();
}

$is_superadmin = user_is_superadmin($user_role);
if (!$is_superadmin && !$school_id) {
    header("Location: main.php");
    exit();
}

// For superadmin, allow school_id override via GET
if ($is_superadmin && isset($_GET['school_id'])) {
    $school_id = (int)$_GET['school_id'];
}

// Fetch school name
$school_name = '';
if ($school_id) {
    $stmt_s = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $stmt_s->bind_param("i", $school_id);
    $stmt_s->execute();
    $row_s = $stmt_s->get_result()->fetch_assoc();
    if ($row_s) $school_name = $row_s['school_name'];
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
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Teacher Management - SNED Profiling</title>
    <style>
        body { background: var(--color-canvas); }
        
        /* Initials-based avatar circles */
        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            border: 1px solid transparent;
        }
        .avatar-theme-0 { background: var(--color-primary-light); color: var(--color-primary); border-color: var(--sned-mint); }
        .avatar-theme-1 { background: var(--sned-success-light); color: #1E5C39; border-color: var(--sned-mint); }
        .avatar-theme-2 { background: var(--sned-warning-light); color: #7D5A0A; border-color: #F5D98A; }
        .avatar-theme-3 { background: var(--sned-info-light); color: var(--sned-info); border-color: #A9D1E8; }
        
        /* Assignment modals panels */
        .assignment-panel {
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            background: var(--color-paper-2);
            min-height: 380px; 
            max-height: 480px; 
            overflow-y: auto; 
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
            position: relative;
        }
        .assignment-panel.assigned-panel {
            background: linear-gradient(180deg, rgba(82, 183, 136, 0.08), rgba(255, 255, 255, 0.92));
            border-color: rgba(82, 183, 136, 0.22);
        }
        .assignment-panel.available-panel {
            background: linear-gradient(180deg, rgba(13, 110, 253, 0.06), rgba(255, 255, 255, 0.92));
            border-color: rgba(13, 110, 253, 0.14);
        }
        .assignment-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--color-rule);
            font-weight: 700;
            position: sticky;
            top: 0;
            background: var(--color-paper);
            z-index: 2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .assigned-panel .assignment-panel-header {
            background: rgba(82, 183, 136, 0.12);
            border-bottom-color: rgba(82, 183, 136, 0.18);
        }
        .available-panel .assignment-panel-header {
            background: rgba(13, 110, 253, 0.08);
            border-bottom-color: rgba(13, 110, 253, 0.14);
        }
        .assignment-panel-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--color-ink);
        }
        .assignment-panel-heading i {
            font-size: 1rem;
        }
        .assigned-panel .assignment-panel-heading i,
        .assigned-panel .assignment-panel-heading strong {
            color: #1E5C39;
        }
        .available-panel .assignment-panel-heading i,
        .available-panel .assignment-panel-heading strong {
            color: #1A5276;
        }
        .assignment-panel-copy {
            display: block;
            margin-top: 2px;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--color-muted-dark);
        }
        .assignment-panel-body {
            padding: 14px;
        }
        .assignment-count-badge {
            min-width: 34px;
            height: 28px;
            padding: 0 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .assigned-panel .assignment-count-badge {
            color: #1E5C39;
            background: rgba(82, 183, 136, 0.18);
            border-color: rgba(82, 183, 136, 0.28);
        }
        .available-panel .assignment-count-badge {
            color: #1A5276;
            background: rgba(13, 110, 253, 0.12);
            border-color: rgba(13, 110, 253, 0.18);
        }
        
        /* Interactive learner cards in assignments modal */
        .learner-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: var(--color-paper);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-md);
            margin-bottom: 8px;
            cursor: pointer;
            transition: all var(--dur-short) var(--ease-out);
        }
        .learner-item-card:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }
        .learner-item-card.assigned-item {
            border-left: 4px solid var(--color-success);
            background: rgba(82, 183, 136, 0.06);
            border-color: rgba(82, 183, 136, 0.18);
        }
        .learner-item-card.unassigned-item {
            border-left: 4px solid var(--color-primary);
            background: rgba(13, 110, 253, 0.035);
            border-color: rgba(13, 110, 253, 0.12);
        }
        .learner-item-card.assigned-item:hover {
            border-color: rgba(82, 183, 136, 0.34);
            background: rgba(82, 183, 136, 0.11);
        }
        .learner-item-card.unassigned-item:hover {
            border-color: rgba(13, 110, 253, 0.24);
            background: rgba(13, 110, 253, 0.08);
        }
        .learner-item-card i {
            transition: transform var(--dur-short) var(--ease-out);
        }
        .learner-item-card:hover i {
            transform: scale(1.15);
        }
        .learner-item-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .learner-item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.78rem;
            color: var(--color-muted-dark);
        }
        .learner-item-action-hint {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .assigned-item .learner-item-action-hint {
            color: #9B2226;
        }
        .unassigned-item .learner-item-action-hint {
            color: #1A5276;
        }
        .assignment-empty-state {
            min-height: 240px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px 18px;
            border: 1px dashed var(--color-rule);
            border-radius: 16px;
            background: rgba(255,255,255,0.52);
        }
        .assignment-empty-state i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .assignment-empty-state strong {
            display: block;
            margin-bottom: 4px;
            color: var(--color-ink);
        }
        .assigned-panel .assignment-empty-state i {
            color: #2D6A4F;
        }
        .available-panel .assignment-empty-state i {
            color: #1A5276;
        }
        
        .teacher-actions {
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

        .btn-row-manage {
            color: #1E5C39;
        }

        .btn-row-edit {
            color: #5b6573;
        }

        .btn-row-delete {
            color: #7A2028;
        }
        .form-group-spacing {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column app-main overflow-auto">
            <header class="app-topbar d-flex align-items-center justify-content-between sticky-top bg-white">
                <div class="d-flex gap-2">
                    <button class="btn-pill-navigation btn-pill-secondary btn-sm d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                        <i class="bi bi-list"></i> Menu
                    </button>
                    <a href="main.php" class="btn-pill-navigation btn-pill-secondary btn-sm d-flex align-items-center gap-2 text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Dashboard
                    </a>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($is_superadmin): ?>
                    <select id="schoolFilter" class="form-select form-select-sm rounded-pill px-3" style="min-width:220px; height: 38px !important; padding: 4px 16px !important;" onchange="window.location.href='teacherManagement.php?school_id='+this.value">
                        <option value="">All Schools</option>
                        <?php
                        $all_schools = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
                        while ($sch = $all_schools->fetch_assoc()):
                        ?>
                        <option value="<?= $sch['id'] ?>" <?= ($school_id == $sch['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sch['school_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </header>

            <div class="app-content container-fluid">
                <!-- Page Title -->
                <div class="page-toolbar flex-column flex-md-row">
                    <div>
                        <h1 class="page-title">Teacher Management</h1>
                        <p class="page-subtitle">Manage teacher accounts and assign learners for <?= htmlspecialchars($school_name ?: 'All Schools') ?>.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
                        <button class="btn btn-primary btn-pill-navigation px-4" onclick="openCreateModal()">
                            <i class="bi bi-person-plus-fill me-2"></i>Add Teacher
                        </button>
                    </div>
                </div>

                <!-- Search & Filters Toolbar -->
                <div class="filter-card mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-6 col-lg-4">
                            <div class="search-field">
                                <i class="bi bi-search"></i>
                                <input type="text" id="teacherSearch" class="form-control" placeholder="Search teachers by name or username..." oninput="filterTeachers()">
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-8 text-md-end">
                            <span class="soft-badge soft-badge-neutral py-2 px-3">
                                <i class="bi bi-person-video3 me-1"></i> <span id="teacherCountNum" class="fw-bold">0</span> teacher<span id="teacherCountPlural">s</span> listed
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Teacher Table -->
                <div class="data-table-shell">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="teacherTable">
                            <thead>
                                <tr>
                                    <th class="ps-4">Name</th>
                                    <th>Username</th>
                                    <?php if ($is_superadmin && !$school_id): ?>
                                    <th>School</th>
                                    <?php endif; ?>
                                    <th class="text-center" style="width: 180px;">Learners Assigned</th>
                                    <th class="text-end pe-4" style="width: 380px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="teacherTableBody">
                                <tr>
                                    <td colspan="<?= ($is_superadmin && !$school_id) ? 5 : 4 ?>" class="text-center text-muted py-5">
                                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div> Loading teachers...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Teacher Modal -->
    <div class="modal fade" id="createTeacherModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 px-4 pb-4 pt-3">
                <div class="modal-header border-0 pb-2">
                    <h5 class="fw-bold text-primary m-0"><i class="bi bi-person-plus-fill text-primary me-2 fs-5"></i>Add Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">Full Name *</label>
                        <input type="text" id="createFullName" class="form-control" maxlength="255" required placeholder="e.g. Juan Dela Cruz" data-force-uppercase="true">
                    </div>
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">Username *</label>
                        <input type="text" id="createUsername" class="form-control" maxlength="255" required placeholder="e.g. jdelacruz">
                    </div>
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">Password *</label>
                        <div class="input-group">
                            <input type="password" id="createPassword" class="form-control" required placeholder="Enter password" style="border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important;">
                            <button class="btn btn-outline-secondary d-flex align-items-center" type="button" onclick="togglePassword('createPassword', this)" style="border-top-left-radius: 0 !important; border-bottom-left-radius: 0 !important; border-color: var(--color-rule) !important;">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div id="createError" class="alert alert-danger d-none small"></div>
                </div>
                <div class="modal-footer border-0 pt-3">
                    <button type="button" class="btn btn-light rounded-pill px-4 btn-pill-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold btn-pill-primary" onclick="createTeacher()">
                        <i class="bi bi-check-lg me-1"></i>Create Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Teacher Modal -->
    <div class="modal fade" id="editTeacherModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 px-4 pb-4 pt-3">
                <div class="modal-header border-0 pb-2">
                    <h5 class="fw-bold text-primary m-0"><i class="bi bi-pencil-square text-primary me-2 fs-5"></i>Edit Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <input type="hidden" id="editTeacherId">
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">Full Name *</label>
                        <input type="text" id="editFullName" class="form-control" maxlength="255" required data-force-uppercase="true">
                    </div>
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">Username *</label>
                        <input type="text" id="editUsername" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-3 form-group-spacing">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" id="editPassword" class="form-control" placeholder="Leave blank to keep current" style="border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important;">
                            <button class="btn btn-outline-secondary d-flex align-items-center" type="button" onclick="togglePassword('editPassword', this)" style="border-top-left-radius: 0 !important; border-bottom-left-radius: 0 !important; border-color: var(--color-rule) !important;">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="form-text text-muted">Only enter a password if you want to change it.</small>
                    </div>
                    <div id="editError" class="alert alert-danger d-none small"></div>
                </div>
                <div class="modal-footer border-0 pt-3">
                    <button type="button" class="btn btn-light rounded-pill px-4 btn-pill-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold btn-pill-primary" onclick="updateTeacher()">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Manage Learners Modal -->
    <div class="modal fade" id="manageLearnersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 px-4 pb-4 pt-3">
                <div class="modal-header border-0 pb-2">
                    <div>
                        <h5 class="fw-bold text-primary m-0"><i class="bi bi-people-fill text-primary me-2 fs-5"></i>Manage Learners</h5>
                        <small class="text-muted" id="manageTeacherName"></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <input type="hidden" id="manageTeacherId">

                    <!-- Learner search -->
                    <div class="mb-3">
                        <div class="search-field">
                            <i class="bi bi-search"></i>
                            <input type="text" id="learnerSearchInput" class="form-control" placeholder="Search learners by name or LRN..." oninput="filterLearners()">
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Assigned -->
                        <div class="col-md-6">
                            <div class="assignment-panel assigned-panel">
                                <div class="assignment-panel-header">
                                    <div class="assignment-panel-heading">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <div>
                                            <strong>Assigned</strong>
                                            <span class="assignment-panel-copy">Learners currently managed by this teacher</span>
                                        </div>
                                    </div>
                                    <span class="assignment-count-badge" id="assignedCount">0</span>
                                </div>
                                <div class="assignment-panel-body" id="assignedPanel">
                                    <p class="text-muted small text-center mb-0 py-4">No learners assigned yet.</p>
                                </div>
                            </div>
                        </div>
                        <!-- Unassigned -->
                        <div class="col-md-6">
                            <div class="assignment-panel available-panel">
                                <div class="assignment-panel-header">
                                    <div class="assignment-panel-heading">
                                        <i class="bi bi-plus-circle-fill"></i>
                                        <div>
                                            <strong>Available</strong>
                                            <span class="assignment-panel-copy">Learners ready to be assigned</span>
                                        </div>
                                    </div>
                                    <span class="assignment-count-badge" id="unassignedCount">0</span>
                                </div>
                                <div class="assignment-panel-body" id="unassignedPanel">
                                    <p class="text-muted small text-center mb-0 py-4">All learners are assigned.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="manageError" class="alert alert-danger d-none small mt-3"></div>
                </div>
                <div class="modal-footer border-0 pt-3">
                    <button type="button" class="btn btn-light rounded-pill px-4 btn-pill-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm btn-pill-primary" onclick="saveAssignments()">
                        <i class="bi bi-check-lg me-1"></i>Save Assignments
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteTeacherModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 px-4 pb-4 pt-3">
                <div class="modal-header border-0 pb-2">
                    <h5 class="fw-bold text-danger m-0"><i class="bi bi-exclamation-triangle-fill text-danger me-2 fs-5"></i>Delete Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="mb-3">Are you sure you want to delete <strong id="deleteTeacherName" class="text-dark"></strong>?</p>
                    <div class="alert alert-danger border-0 rounded-3 d-flex align-items-start gap-2 mb-0">
                        <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0"></i>
                        <div>
                            <span class="fw-bold d-block">Warning</span>
                            This will remove the teacher account and dissociate all their assigned learners. This action cannot be undone.
                        </div>
                    </div>
                    <div id="deleteError" class="alert alert-danger d-none small mt-3"></div>
                </div>
                <div class="modal-footer border-0 pt-3">
                    <button type="button" class="btn btn-light rounded-pill px-4 btn-pill-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold" onclick="confirmDeleteTeacher()">
                        <i class="bi bi-trash3 me-1"></i>Delete
                    </button>
                </div>
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

        const CSRF_TOKEN = '<?= csrf_token() ?>';
        const API = 'ajax_teacher_management.php';
        const IS_SUPERADMIN = <?= $is_superadmin ? 'true' : 'false' ?>;
        const CURRENT_SCHOOL_ID = <?= $school_id ? (int)$school_id : 'null' ?>;

        let allTeachers = [];
        let currentManageData = { assigned: [], unassigned: [] };

        // Helper to extract initials for avatar
        function getInitials(name) {
            if (!name) return '??';
            const parts = name.trim().split(/\s+/);
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return name.slice(0, 2).toUpperCase();
        }

        // Helper to assign a soft-theme index (0 to 3) based on hashing the name
        function getAvatarTheme(name) {
            let hash = 0;
            for (let i = 0; i < name.length; i++) {
                hash = name.charCodeAt(i) + ((hash << 5) - hash);
            }
            return Math.abs(hash) % 4;
        }

        // ── Load teachers & updates stats ──
        function loadTeachers() {
            let url = `${API}?action=list_teachers`;
            if (CURRENT_SCHOOL_ID) url += `&school_id=${CURRENT_SCHOOL_ID}`;
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        allTeachers = data.teachers;
                        renderTeachers(allTeachers);
                    }
                });
        }

        function renderTeachers(teachers) {
            const tbody = document.getElementById('teacherTableBody');
            const showSchool = !CURRENT_SCHOOL_ID && teachers.length > 0 && teachers[0].school_name !== undefined;
            const colCount = showSchool ? 5 : 4;
            if (teachers.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="${colCount}" class="text-center text-muted py-5">
                            <i class="bi bi-person-video3" style="font-size:2.5rem; color: var(--color-muted);"></i>
                            <p class="mt-2 mb-0 fw-semibold">No teachers found.</p>
                            <small class="text-muted">Add a teacher to get started.</small>
                        </td>
                    </tr>`;
                return;
            }
            const plural = teachers.length === 1 ? '' : 's';
            document.getElementById('teacherCountNum').textContent = teachers.length;
            document.getElementById('teacherCountPlural').textContent = plural;

            tbody.innerHTML = teachers.map(t => {
                const learnerPlural = t.learner_count == 1 ? 'learner' : 'learners';

                return `
                    <tr class="teacher-row">
                        <td class="ps-4">
                            <div class="fw-bold text-dark">${escHtml(t.full_name)}</div>
                        </td>
                        <td><code>${escHtml(t.username)}</code></td>
                        ${showSchool ? `<td><span class="badge bg-secondary rounded-pill">${escHtml(t.school_name || '')}</span></td>` : ''}
                        <td class="text-center">
                            <span class="soft-badge soft-badge-primary py-2 px-3">${t.learner_count} ${learnerPlural}</span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="teacher-actions">
                                <button type="button" class="btn-row-action btn-row-manage" title="Manage learners" onclick="openManageLearners(${t.id}, '${escHtml(t.full_name).replace(/'/g, "\\'")}')">
                                    <i class="bi bi-people-fill"></i> Manage learners
                                </button>
                                <button type="button" class="btn-row-action btn-row-edit" title="Edit teacher" onclick="openEditModal(${t.id}, '${escHtml(t.full_name).replace(/'/g, "\\'")}', '${escHtml(t.username).replace(/'/g, "\\'")}')">
                                    <i class="bi bi-pencil-fill"></i> Edit teacher
                                </button>
                                <button type="button" class="btn-row-action btn-row-delete" title="Delete teacher" onclick="openDeleteModal(${t.id}, '${escHtml(t.full_name).replace(/'/g, "\\'")}')">
                                    <i class="bi bi-trash3-fill"></i> Delete teacher
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function filterTeachers() {
            const q = document.getElementById('teacherSearch').value.toLowerCase();
            const filtered = allTeachers.filter(t =>
                t.full_name.toLowerCase().includes(q) || t.username.toLowerCase().includes(q)
            );
            renderTeachers(filtered);
        }

        // ── Create Teacher ──
        function openCreateModal() {
            document.getElementById('createFullName').value = '';
            document.getElementById('createUsername').value = '';
            document.getElementById('createPassword').value = '';
            document.getElementById('createError').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('createTeacherModal')).show();
        }

        function createTeacher() {
            const full_name = document.getElementById('createFullName').value.trim();
            const username = document.getElementById('createUsername').value.trim();
            const password = document.getElementById('createPassword').value;
            const errEl = document.getElementById('createError');

            if (!full_name || !username || !password) {
                errEl.textContent = 'All fields are required.';
                errEl.classList.remove('d-none');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'create_teacher');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('full_name', full_name);
            fd.append('username', username);
            fd.append('password', password);
            if (CURRENT_SCHOOL_ID) {
                fd.append('school_id', CURRENT_SCHOOL_ID);
            }

            fetch(API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('createTeacherModal')).hide();
                        showSystemNotice(data.message, 'Success', 'success');
                        loadTeachers();
                    } else {
                        errEl.textContent = data.message;
                        errEl.classList.remove('d-none');
                    }
                })
                .catch(() => {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.classList.remove('d-none');
                });
        }

        // ── Edit Teacher ──
        function openEditModal(id, name, username) {
            document.getElementById('editTeacherId').value = id;
            document.getElementById('editFullName').value = name;
            document.getElementById('editUsername').value = username;
            document.getElementById('editPassword').value = '';
            document.getElementById('editError').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('editTeacherModal')).show();
        }

        function updateTeacher() {
            const teacher_id = document.getElementById('editTeacherId').value;
            const full_name = document.getElementById('editFullName').value.trim();
            const username = document.getElementById('editUsername').value.trim();
            const password = document.getElementById('editPassword').value;
            const errEl = document.getElementById('editError');

            if (!full_name || !username) {
                errEl.textContent = 'Full name and username are required.';
                errEl.classList.remove('d-none');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'update_teacher');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('teacher_id', teacher_id);
            fd.append('full_name', full_name);
            fd.append('username', username);
            fd.append('password', password);

            fetch(API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('editTeacherModal')).hide();
                        showSystemNotice(data.message, 'Success', 'success');
                        loadTeachers();
                    } else {
                        errEl.textContent = data.message;
                        errEl.classList.remove('d-none');
                    }
                })
                .catch(() => {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.classList.remove('d-none');
                });
        }

        // ── Delete Teacher ──
        function openDeleteModal(id, name) {
            document.getElementById('deleteTeacherName').textContent = name;
            document.getElementById('deleteTeacherModal').dataset.teacherId = id;
            document.getElementById('deleteError').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('deleteTeacherModal')).show();
        }

        function confirmDeleteTeacher() {
            const teacher_id = document.getElementById('deleteTeacherModal').dataset.teacherId;
            const errEl = document.getElementById('deleteError');

            const fd = new FormData();
            fd.append('action', 'delete_teacher');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('teacher_id', teacher_id);

            fetch(API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('deleteTeacherModal')).hide();
                        showSystemNotice(data.message, 'Success', 'success');
                        loadTeachers();
                    } else {
                        errEl.textContent = data.message;
                        errEl.classList.remove('d-none');
                    }
                })
                .catch(() => {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.classList.remove('d-none');
                });
        }

        // ── Manage Learners ──
        function openManageLearners(teacherId, teacherName) {
            document.getElementById('manageTeacherId').value = teacherId;
            document.getElementById('manageTeacherName').textContent = 'Assign learners to ' + teacherName;
            document.getElementById('learnerSearchInput').value = '';
            document.getElementById('manageError').classList.add('d-none');

            document.getElementById('assignedPanel').innerHTML = '<div class="text-center py-5"><div class="spinner-border spinner-border-sm text-success" role="status"></div></div>';
            document.getElementById('unassignedPanel').innerHTML = '<div class="text-center py-5"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>';

            new bootstrap.Modal(document.getElementById('manageLearnersModal')).show();

            fetch(`${API}?action=get_learners&teacher_id=${teacherId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentManageData = data;
                        renderAssigned(data.assigned);
                        renderUnassigned(data.unassigned);
                    }
                });
        }

        function renderAssigned(learners) {
            document.getElementById('assignedCount').textContent = currentManageData.assigned.length;
            renderAssignedPanel(learners);
        }

        function renderAssignedPanel(learners) {
            const panel = document.getElementById('assignedPanel');
            if (learners.length === 0) {
                panel.innerHTML = `
                    <div class="assignment-empty-state">
                        <i class="bi bi-person-check"></i>
                        <strong>No learners assigned yet</strong>
                        <div class="small text-muted">Assign learners from the Available panel to start building this teacher's caseload.</div>
                    </div>
                `;
                return;
            }
            panel.innerHTML = learners.map(l => `
                <div class="learner-item-card assigned-item" onclick="unassignLearner('${escHtml(l.lrn)}')">
                    <div class="learner-item-copy">
                        <div class="fw-bold">${escHtml(l.last_name)}, ${escHtml(l.first_name)}</div>
                        <div class="learner-item-meta">
                            <span>${escHtml(l.lrn)}</span>
                            <span>Grade ${escHtml(l.grade_level || '—')}</span>
                        </div>
                        <div class="learner-item-action-hint">Click to remove from this teacher</div>
                    </div>
                    <i class="bi bi-dash-circle-fill text-danger fs-5" title="Remove assignment"></i>
                </div>
            `).join('');
        }

        function renderUnassigned(learners) {
            document.getElementById('unassignedCount').textContent = currentManageData.unassigned.length;
            renderUnassignedPanel(learners);
        }

        function renderUnassignedPanel(learners) {
            const panel = document.getElementById('unassignedPanel');
            if (learners.length === 0) {
                panel.innerHTML = `
                    <div class="assignment-empty-state">
                        <i class="bi bi-people"></i>
                        <strong>All learners are already assigned</strong>
                        <div class="small text-muted">There are no extra learners available for this teacher right now.</div>
                    </div>
                `;
                return;
            }
            panel.innerHTML = learners.map(l => `
                <div class="learner-item-card unassigned-item" onclick="assignLearner('${escHtml(l.lrn)}')">
                    <div class="learner-item-copy">
                        <div class="fw-bold">${escHtml(l.last_name)}, ${escHtml(l.first_name)}</div>
                        <div class="learner-item-meta">
                            <span>${escHtml(l.lrn)}</span>
                            <span>Grade ${escHtml(l.grade_level || '—')}</span>
                        </div>
                        <div class="learner-item-action-hint">Click to assign to this teacher</div>
                    </div>
                    <i class="bi bi-plus-circle-fill text-primary fs-5" title="Assign"></i>
                </div>
            `).join('');
        }

        function assignLearner(lrn) {
            const learner = currentManageData.unassigned.find(l => l.lrn === lrn);
            if (!learner) return;
            currentManageData.unassigned = currentManageData.unassigned.filter(l => l.lrn !== lrn);
            currentManageData.assigned.push(learner);
            currentManageData.assigned.sort((a, b) => (a.last_name + a.first_name).localeCompare(b.last_name + b.first_name));
            filterLearners();
        }

        function unassignLearner(lrn) {
            const learner = currentManageData.assigned.find(l => l.lrn === lrn);
            if (!learner) return;
            currentManageData.assigned = currentManageData.assigned.filter(l => l.lrn !== lrn);
            currentManageData.unassigned.push(learner);
            currentManageData.unassigned.sort((a, b) => (a.last_name + a.first_name).localeCompare(b.last_name + b.first_name));
            filterLearners();
        }

        // Filters both lists according to search input
        function filterLearners() {
            const q = document.getElementById('learnerSearchInput').value.toLowerCase();
            const assigned = currentManageData.assigned.filter(l =>
                l.lrn.includes(q) || l.last_name.toLowerCase().includes(q) || l.first_name.toLowerCase().includes(q)
            );
            const unassigned = currentManageData.unassigned.filter(l =>
                l.lrn.includes(q) || l.last_name.toLowerCase().includes(q) || l.first_name.toLowerCase().includes(q)
            );
            document.getElementById('assignedCount').textContent = currentManageData.assigned.length;
            document.getElementById('unassignedCount').textContent = currentManageData.unassigned.length;
            renderAssignedPanel(assigned);
            renderUnassignedPanel(unassigned);
        }

        function saveAssignments() {
            const teacher_id = document.getElementById('manageTeacherId').value;
            const errEl = document.getElementById('manageError');
            const lrns = currentManageData.assigned.map(l => l.lrn);

            const fd = new FormData();
            fd.append('action', 'save_assignments');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('teacher_id', teacher_id);
            fd.append('assigned_lrns', JSON.stringify(lrns));

            fetch(API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('manageLearnersModal')).hide();
                        showSystemNotice(data.message, 'Success', 'success');
                        loadTeachers();
                    } else {
                        errEl.textContent = data.message;
                        errEl.classList.remove('d-none');
                    }
                })
                .catch(() => {
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.classList.remove('d-none');
                });
        }

        // ── Helpers ──
        function escHtml(str) {
            if (!str) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }

        // Initial load
        loadTeachers();

        // Reset Create Teacher modal form on show
        document.getElementById('createTeacherModal').addEventListener('shown.bs.modal', function () {
            document.getElementById('createFullName').value = '';
            document.getElementById('createUsername').value = '';
            document.getElementById('createPassword').value = '';
            document.getElementById('createError').classList.add('d-none');
        });

        // Auto-open Create Teacher Modal if 'add' is in query params
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('add')) {
            new bootstrap.Modal(document.getElementById('createTeacherModal')).show();
        }
    </script>
</body>
</html>
