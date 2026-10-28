<?php 
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$is_superadmin_user = user_is_superadmin($user_role);

if (!user_is_admin($user_role)) {
    header("Location: main.php");
    exit();
}

$message = '';
$pre_role = $_GET['role'] ?? '';
$pre_school_id = $_GET['school_id'] ?? '';
$csrf_valid = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_valid = csrf_validate($_POST['csrf_token'] ?? null);
}

function admin_user_uppercase_name($value): string {
    $value = trim((string)($value ?? ''));
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

// Handle creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && $csrf_valid) {
    $new_full_name = admin_user_uppercase_name($_POST['new_full_name'] ?? '');
    $new_username = trim($_POST['new_username']);
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $check->bind_param("s", $new_username);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        $message = "<div class='alert alert-danger'>Username <strong>" . htmlspecialchars($new_username) . "</strong> already exists.</div>";
    } else {
        $hashed_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $role = $_POST['new_role'];
        $valid_roles = ['admin_office', 'school_coordinator', 'teacher'];
        if ($is_superadmin_user) {
            $valid_roles[] = 'superadmin';
        }
        if (!in_array($role, $valid_roles, true)) {
            $message = "<div class='alert alert-danger'>Invalid role selected.</div>";
        } else {
            $school_id = ($role === 'school_coordinator' || $role === 'teacher') && !empty($_POST['new_school_id']) ? (int)$_POST['new_school_id'] : null;
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, school_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $new_full_name, $new_username, $hashed_pass, $role, $school_id);
            if ($stmt->execute()) {
                log_transaction($conn, $_SESSION['user_id'], 'CREATE', 'USERS', $conn->insert_id, "Created user {$new_username} (hashed)");
                $message = "<div class='alert alert-success'>User created.</div>";
            }
        }
    }
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user']) && $csrf_valid) {
    $admin_pass = $_POST['admin_password_confirm'];
    $target_id = (int)$_POST['target_user_id'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $admin_row = $stmt->get_result()->fetch_assoc();
    
    $admin_verified = false;
    if ($admin_row) {
        if (password_verify($admin_pass, $admin_row['password']) || $admin_pass === $admin_row['password']) {
            $admin_verified = true;
        }
    }

    if ($admin_verified) {
        $updated_full_name = admin_user_uppercase_name($_POST['updated_full_name'] ?? '');
        $updated_username = trim($_POST['updated_username'] ?? '');
        $updated_role = $_POST['updated_role'] ?? '';
        $updated_school_id = ($updated_role === 'school_coordinator' || $updated_role === 'teacher') && !empty($_POST['updated_school_id']) ? (int)$_POST['updated_school_id'] : null;
        $updated_password = $_POST['updated_password'] ?? '';

        $stmt_check = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt_check->bind_param("i", $target_id);
        $stmt_check->execute();
        $target_row = $stmt_check->get_result()->fetch_assoc();
        $target_current_role = $target_row['role'] ?? '';

        $valid_roles = ['admin_office', 'school_coordinator', 'teacher'];
        if ($is_superadmin_user) {
            $valid_roles[] = 'superadmin';
        }

        if (!$target_row) {
            $message = "<div class='alert alert-danger'>User account not found.</div>";
        } elseif ($target_current_role === 'superadmin' && !$is_superadmin_user) {
            $message = "<div class='alert alert-danger'>This account cannot be edited from Admin Office.</div>";
            log_transaction($conn, $_SESSION['user_id'], 'SECURITY', 'USERS', $target_id, "Blocked attempt to edit protected system account ID $target_id");
        } elseif (empty($updated_full_name) || empty($updated_username) || empty($updated_role)) {
            $message = "<div class='alert alert-danger'>Full Name, Username, and Role are required.</div>";
        } elseif (!in_array($updated_role, $valid_roles, true)) {
            $message = "<div class='alert alert-danger'>Invalid role selected.</div>";
        } else {
            if (!empty($updated_password)) {
                $hashed_updated = password_hash($updated_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, school_id = ?, password = ? WHERE id = ?");
                $update_stmt->bind_param("sssisi", $updated_full_name, $updated_username, $updated_role, $updated_school_id, $hashed_updated, $target_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role = ?, school_id = ? WHERE id = ?");
                $update_stmt->bind_param("sssii", $updated_full_name, $updated_username, $updated_role, $updated_school_id, $target_id);
            }

            if ($update_stmt->execute()) {
                log_transaction($conn, $_SESSION['user_id'], 'UPDATE', 'USERS', $target_id, "Updated user ID $target_id (Unified edit)");
                $message = "<div class='alert alert-success'>User updated successfully.</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to update user.</div>";
            }
        }
    } else {
        $message = "<div class='alert alert-danger'>Incorrect admin password. Update denied.</div>";
        log_transaction($conn, $_SESSION['user_id'], 'SECURITY', 'FAILED VERIFY', $target_id, "Failed admin verification for update on user ID $target_id");
    }
}

// Handle learner assignment save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments']) && $csrf_valid) {
    $teacher_id = (int)$_POST['assign_teacher_id'];
    $lrns = json_decode($_POST['assigned_lrns'] ?? '[]', true);
    $conn->query("DELETE FROM learner_teachers WHERE teacher_id = $teacher_id");
    if (!empty($lrns)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO learner_teachers (teacher_id, lrn, assigned_by) VALUES (?, ?, ?)");
        foreach ($lrns as $lrn) {
            $lrn = trim($lrn);
            if (preg_match('/^\d{12}$/', $lrn)) {
                $stmt->bind_param("isi", $teacher_id, $lrn, $_SESSION['user_id']);
                $stmt->execute();
            }
        }
    }
    log_transaction($conn, $_SESSION['user_id'], 'UPDATE', 'USERS', $teacher_id, "Updated learner assignments for teacher ID $teacher_id");
    $message = "<div class='alert alert-success'>Learner assignments updated.</div>";
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && $csrf_valid) {
    $admin_pass = $_POST['admin_password_confirm'];
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $admin_row = $stmt->get_result()->fetch_assoc();
    
    $admin_verified = false;
    if ($admin_row) {
        if (password_verify($admin_pass, $admin_row['password']) || $admin_pass === $admin_row['password']) {
            $admin_verified = true;
        }
    }

    if ($admin_verified) {
        $target_id = (int)$_POST['target_user_id'];
        $stmt_target = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt_target->bind_param("i", $target_id);
        $stmt_target->execute();
        $target_row = $stmt_target->get_result()->fetch_assoc();

        if (!$target_row) {
            $message = "<div class='alert alert-danger'>User account not found.</div>";
        } elseif (($target_row['role'] ?? '') === 'superadmin' && !$is_superadmin_user) {
            $message = "<div class='alert alert-danger'>This account cannot be deleted from Admin Office.</div>";
            log_transaction($conn, $_SESSION['user_id'], 'SECURITY', 'USERS', $target_id, "Blocked attempt to delete protected system account ID $target_id");
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $target_id);
            $delete_stmt->execute();

            if ($conn->error && str_contains($conn->error, 'foreign key constraint')) {
                $message = "<div class='alert alert-danger'>Cannot delete this user because they have existing records (e.g. behavior reports). Run the migration <code>migrations/fix_bir_fk_on_delete.sql</code> first, or remove the linked records manually.</div>";
            } else {
                log_transaction($conn, $_SESSION['user_id'], 'DELETE', 'USERS', $target_id, "Deleted user ID $target_id");
                $message = "<div class='alert alert-success'>User deleted.</div>";
            }
            $delete_stmt->close();
        }
    } else {
        $message = "<div class='alert alert-danger'>Incorrect admin password. Delete denied.</div>";
        log_transaction($conn, $_SESSION['user_id'], 'SECURITY', 'FAILED VERIFY', $_POST['target_user_id'], "Failed admin verification for deletion of user ID {$_POST['target_user_id']}");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Manage Users | SNED Profiling</title>
    <style>
        body { background: var(--color-paper-3); }

        .admin-page-wrap {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .assign-results { max-height: 300px; overflow-y: auto; }

        .selection-card {
            cursor: pointer;
            transition: transform var(--dur-short) var(--ease-out), box-shadow var(--dur-short) var(--ease-out), border-color var(--dur-short) var(--ease-out);
            border: 2px solid transparent;
            border-radius: var(--radius-lg);
        }
        .selection-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--color-primary);
        }
        .icon-box {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
        }

        .user-table-shell {
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-rule-light);
            overflow: hidden;
            background: var(--color-paper);
            width: 100%;
        }

        .user-table-shell .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .user-table-shell table {
            margin-bottom: 0;
            min-width: 820px;
        }

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

        .btn-row-edit {
            color: #5b6573;
        }

        .btn-row-delete {
            color: #7A2028;
        }

        @media (max-width: 768px) {
            .p-4 {
                padding: 1rem !important;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>
        <div class="flex-grow-1 d-flex flex-column bg-white overflow-auto">
            <header class="p-3 border-bottom shadow-sm d-flex align-items-center sticky-top bg-white">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                    <i class="bi bi-list"></i> Menu
                </button>
                <h5 class="fw-bold ms-3 mb-0"><i class="bi bi-shield-lock text-primary me-2"></i>User Management</h5>
            </header>
            <div class="p-4">
                <div class="admin-page-wrap">
                <?= $message ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="fw-bold mb-0">Manage Users</h2>
                        <p class="text-muted mb-0">Create, edit, and manage system access.</p>
                    </div>
                </div>

                <div class="section-panel mb-4">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <span class="icon-shell"><i class="bi bi-person-plus-fill"></i></span>
                        <div>
                            <h2 class="section-panel-title">Create New Account</h2>
                            <p class="section-panel-subtitle">Set up credentials and assign a role to a new system user.</p>
                        </div>
                    </div>

                    <form method="POST" autocomplete="off" id="createUserForm">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="create_user" value="1">

                        <div class="section-header"><i class="bi bi-person-badge me-1"></i> Account Credentials</div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="new_full_name" class="form-control" maxlength="255" placeholder="e.g. Juan Dela Cruz" required autocomplete="new-password" data-force-uppercase="true">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="new_username" class="form-control" maxlength="255" placeholder="e.g. juan.delacruz" required autocomplete="new-password">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Minimum 8 characters" required autocomplete="new-password">
                            </div>
                        </div>

                        <div class="section-header"><i class="bi bi-shield-lock me-1"></i> Role & Assignment</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Role</label>
                                <select name="new_role" id="createRoleSelect" class="form-select" required onchange="toggleCreateSchool()">
                                    <option value="">Select Role</option>
                                    <option value="school_coordinator" <?= $pre_role === 'school_coordinator' ? 'selected' : '' ?>>School Coordinator</option>
                                    <option value="admin_office" <?= $pre_role === 'admin_office' ? 'selected' : '' ?>>Admin Office</option>
                                </select>
                                <div id="createRoleHint" class="form-text" style="display:none;"></div>
                            </div>
                            <div class="col-md-4" id="createSchoolBlock" style="display: none;">
                                <label class="form-label">School Assignment</label>
                                <select name="new_school_id" id="createSchoolSelect" class="form-select">
                                    <option value="">Select School</option>
                                    <?php
                                    $sch_q = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
                                    while ($s = $sch_q->fetch_assoc()):
                                    ?>
                                    <option value="<?= $s['id'] ?>" <?= (int)$pre_school_id === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['school_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
 
                        <div class="form-actions mt-4">
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold"><i class="bi bi-person-plus-fill me-1"></i> Create Account</button>
                        </div>
                    </form>
                </div>
 
                <div class="card rounded-4 border-0 shadow-sm user-table-shell">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="px-4">Full Name</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>School</th>
                                    <th class="text-end px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $user_sql = "SELECT u.*, s.school_name as user_school FROM users u LEFT JOIN schools s ON u.school_id = s.id";
                                if (!$is_superadmin_user) {
                                    $user_sql .= " WHERE u.role <> 'superadmin'";
                                }
                                $user_sql .= " ORDER BY u.id DESC";
                                $res = $conn->query($user_sql);
                                while ($row = $res->fetch_assoc()):
                                    if (in_array($row['role'], ['superadmin','admin_office'], true) && !user_is_admin($user_role)) continue;
                                    if ($row['role'] === 'superadmin' && !$is_superadmin_user) continue;
                                    $is_me = ($row['id'] == $_SESSION['user_id']);
                                ?>
                                <tr>
                                    <td class="px-4"><?= htmlspecialchars($row['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                    <td><span class="badge bg-secondary rounded-pill"><?= strtoupper($row['role']) ?></span></td>
                                    <td><?= $row['user_school'] ? htmlspecialchars($row['user_school']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end px-4">
                                        <div class="row-actions-panel">
                                            <button type="button" class="btn-row-action btn-row-edit" onclick='openEditModal(<?= json_encode($row) ?>)'><i class="bi bi-pencil-fill"></i> Edit user</button>
                                            <button type="button" class="btn-row-action btn-row-delete" onclick="promptDelete(<?= $row['id'] ?>, '<?= addslashes($row['username']) ?>')" <?= $is_me?'disabled':'' ?>><i class="bi bi-trash3-fill"></i> Delete user</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
 
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" class="modal-content border-0 shadow-lg" autocomplete="off">
                <?= csrf_input(); ?>
                <input type="hidden" name="update_user" value="1">
                <input type="hidden" name="target_user_id" id="editTargetId">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="editModalTitle"><i class="bi bi-pencil-square text-primary me-2"></i> Edit User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase">Full Name *</label>
                            <input class="form-control rounded-3" name="updated_full_name" id="editFull" required data-force-uppercase="true">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase">Username *</label>
                            <input class="form-control rounded-3" name="updated_username" id="editUser" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase">Role *</label>
                            <select class="form-select rounded-3" name="updated_role" id="editRole" required onchange="toggleEditSchool()">
                                <option value="school_coordinator">School Coordinator</option>
                                <option value="admin_office">Admin Office</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="editSchoolBlock" style="display: none;">
                            <label class="form-label fw-bold small text-uppercase">School Assignment *</label>
                            <select class="form-select rounded-3" name="updated_school_id" id="editSchool">
                                <option value="">Select School</option>
                                <?php
                                $sch_q2 = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
                                while ($s2 = $sch_q2->fetch_assoc()):
                                ?>
                                <option value="<?= $s2['id'] ?>"><?= htmlspecialchars($s2['school_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">New Password (Optional)</label>
                        <input type="password" class="form-control rounded-3" name="updated_password" id="editPass" placeholder="Leave blank to keep current password" autocomplete="new-password">
                    </div>
                    <div class="mt-4 pt-3 border-top">
                        <label class="form-label fw-bold small text-uppercase text-danger">Your Admin Password *</label>
                        <p class="text-muted small mb-2">Enter your own password to verify and confirm these changes.</p>
                        <input type="password" name="admin_password_confirm" id="adminConfirmPass" class="form-control rounded-3" required autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
 
    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content border-0 shadow-lg" autocomplete="off">
                <?= csrf_input(); ?>
                <input type="hidden" name="delete_user" value="1">
                <input type="hidden" name="target_user_id" id="deleteTargetId">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-trash me-1"></i> Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-danger d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Confirm deletion of user account: <strong id="deleteUserName"></strong>?
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-bold small text-uppercase text-danger">Your Admin Password *</label>
                        <p class="text-muted small mb-2">Enter your own password to verify and confirm this deletion.</p>
                        <input type="password" name="admin_password_confirm" id="deleteAdminConfirmPass" class="form-control rounded-3" required autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-5 fw-bold"><i class="bi bi-trash me-1"></i> Confirm Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Learner Assignment Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" class="modal-content border-0 shadow-lg" autocomplete="off">
                <?= csrf_input(); ?>
                <input type="hidden" name="save_assignments" value="1">
                <input type="hidden" name="assign_teacher_id" id="assignTeacherId">
                <input type="hidden" name="assigned_lrns" id="assignedLrnsInput">
                <div class="modal-header bg-white border-bottom rounded-top-4">
                    <h5 class="modal-title fw-bold"><i class="bi bi-people text-primary me-1"></i> Manage Learner Assignments</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted mb-3">Assign learners to <strong id="assignTeacherName"></strong>.</p>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Search Learners</label>
                        <input type="text" class="form-control rounded-3" id="assignLearnerSearch" placeholder="Type name or LRN to filter..." oninput="filterAssignLearners()">
                    </div>

                    <div id="assignLearnerResults" class="assign-results mb-3">
                        <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading learners...</div>
                    </div>

                    <hr class="my-3 opacity-25">
                    <h6 class="fw-bold mb-2"><i class="bi bi-check2-square me-1"></i> Currently Assigned</h6>
                    <div id="currentlyAssigned" class="text-muted small">
                        <span><span class="spinner-border spinner-border-sm me-1" role="status"></span> Loading...</span>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="bi bi-check-lg me-1"></i> Save Assignments</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const editUserModal = new bootstrap.Modal(document.getElementById('editUserModal'));
        const deleteUserModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
        
        let currentUserData = null;

        // School scope toggle for create form
        function toggleCreateSchool() {
            const role = document.getElementById('createRoleSelect').value;
            const block = document.getElementById('createSchoolBlock');
            const select = document.getElementById('createSchoolSelect');
            const hint = document.getElementById('createRoleHint');
            if (role === 'school_coordinator' || role === 'teacher') {
                block.style.display = '';
                select.required = true;
            } else {
                block.style.display = 'none';
                select.required = false;
                select.value = '';
            }
            const hints = {
                school_coordinator: 'Write access to all learner data within the assigned school.',
                teacher: 'View and manage learners assigned to them at their school.',
                admin_office: 'Full admin tools: user management, audit logs, and data export.'
            };
            if (hints[role]) {
                hint.textContent = hints[role];
                hint.style.display = '';
            } else {
                hint.style.display = 'none';
            }
        }

        // School scope toggle for edit form
        function toggleEditSchool() {
            const role = document.getElementById('editRole').value;
            const block = document.getElementById('editSchoolBlock');
            const select = document.getElementById('editSchool');
            if (role === 'school_coordinator' || role === 'teacher') {
                block.style.display = '';
                select.required = true;
            } else {
                block.style.display = 'none';
                select.required = false;
                select.value = '';
            }
        }

        function openEditModal(user) {
            currentUserData = user;
            document.getElementById('editTargetId').value = user.id;
            document.getElementById('editFull').value = user.full_name || '';
            document.getElementById('editUser').value = user.username || '';
            document.getElementById('editPass').value = '';
            document.getElementById('adminConfirmPass').value = '';
            
            // Re-render role options dynamically (allowing teacher only if current user is a teacher)
            const roleSelect = document.getElementById('editRole');
            let optionsHtml = `
                <option value="school_coordinator">School Coordinator</option>
                <option value="admin_office">Admin Office</option>
            `;
            <?php if ($is_superadmin_user): ?>
            if (user.role === 'superadmin') {
                optionsHtml += `<option value="superadmin">Superadmin</option>`;
            }
            <?php endif; ?>
            if (user.role === 'teacher') {
                optionsHtml += `<option value="teacher">Teacher (ReadOnly role assignment)</option>`;
            }
            roleSelect.innerHTML = optionsHtml;
            roleSelect.value = user.role;

            toggleEditSchool();
            document.getElementById('editSchool').value = user.school_id || '';
            
            document.getElementById('editModalTitle').innerHTML = `<i class="bi bi-pencil-square text-primary me-2"></i> Edit User Account: ${user.username}`;
            editUserModal.show();
        }

        function promptDelete(id, username) {
            document.getElementById('deleteTargetId').value = id;
            document.getElementById('deleteUserName').innerText = username;
            document.getElementById('deleteAdminConfirmPass').value = '';
            deleteUserModal.show();
        }

        document.getElementById('sidebarToggle').addEventListener('click', () => {
            document.querySelector('.app-shell').classList.toggle('sidebar-hidden');
        });

        // Assign Modal Logic
        const assignModal = new bootstrap.Modal(document.getElementById('assignModal'));
        let assignedSet = new Set();
        let allLearners = [];

        function renderLearners(filter) {
            const container = document.getElementById('assignLearnerResults');
            const q = (filter || '').toLowerCase();
            const filtered = q ? allLearners.filter(l =>
                l.name.toLowerCase().includes(q) || l.lrn.includes(q)
            ) : allLearners;
            if (!filtered.length) {
                container.innerHTML = '<div class="text-muted text-center py-3">No learners found.</div>';
                return;
            }
            let html = '';
            filtered.forEach(learner => {
                const checked = assignedSet.has(learner.lrn) ? 'checked' : '';
                html += `
                    <div class="d-flex align-items-center gap-2 p-2 border-bottom">
                        <input type="checkbox" class="form-check-input" value="${learner.lrn}" ${checked} onchange="toggleAssign('${learner.lrn}', this.checked)">
                        <div>
                            <strong>${learner.name}</strong>
                            <br>
                            <small class="text-muted">${learner.lrn} · ${learner.grade_level} · ${learner.school_name}</small>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function filterAssignLearners() {
            renderLearners(document.getElementById('assignLearnerSearch').value);
        }

        function openAssignModal(userId, userName, teacherSchoolId) {
            document.getElementById('assignTeacherId').value = userId;
            document.getElementById('assignTeacherName').innerText = userName;
            assignedSet = new Set();
            document.getElementById('assignLearnerSearch').value = '';
            document.getElementById('assignLearnerResults').innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading learners...</div>';

            // Load learners from the teacher's school
            const url = teacherSchoolId ? 'ajax_learners_by_school.php?school_id=' + teacherSchoolId : 'ajax_learners_by_school.php';
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    allLearners = data.success ? data.results : [];
                    renderLearners('');
                })
                .catch(() => {
                    document.getElementById('assignLearnerResults').innerHTML = '<div class="text-danger text-center py-3">Failed to load learners.</div>';
                });

            // Load currently assigned
            fetch('ajax_teacher_assignments.php?teacher_id=' + userId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        assignedSet = new Set(data.assigned);
                        renderAssigned();
                        renderAssignedList();
                    }
                })
                .catch(() => {
                    document.getElementById('currentlyAssigned').innerHTML = '<span class="text-danger">Failed to load assignments.</span>';
                });

            assignModal.show();
        }

        function renderAssigned() {
            document.getElementById('assignedLrnsInput').value = JSON.stringify([...assignedSet]);
        }

        function renderAssignedList() {
            const container = document.getElementById('currentlyAssigned');
            if (assignedSet.size === 0) {
                container.innerHTML = '<span class="text-muted">No learners assigned yet.</span>';
                return;
            }
            let html = '<div class="d-flex flex-wrap gap-2">';
            assignedSet.forEach(lrn => {
                html += `<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">${lrn} <i class="bi bi-x-circle text-danger" style="cursor:pointer" onclick="removeAssigned('${lrn}')"></i></span>`;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function removeAssigned(lrn) {
            assignedSet.delete(lrn);
            renderAssigned();
            renderAssignedList();
            renderLearners(document.getElementById('assignLearnerSearch').value);
        }

        function toggleAssign(lrn, checked) {
            if (checked) {
                assignedSet.add(lrn);
            } else {
                assignedSet.delete(lrn);
            }
            renderAssigned();
            renderAssignedList();
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleCreateSchool();
        });
    </script>
</body>
</html>
