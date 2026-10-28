<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$school_id = $_SESSION['school_id'] ?? null;
$is_superadmin = user_is_superadmin($user_role);

if (!user_is_coordinator($user_role) || user_is_teacher($user_role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// For superadmin, accept school_id from request
if ($is_superadmin && isset($_GET['school_id'])) {
    $school_id = (int)$_GET['school_id'];
}
if ($is_superadmin && isset($_POST['school_id'])) {
    $school_id = (int)$_POST['school_id'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function teacher_management_uppercase_name($value): string {
    $value = trim((string)($value ?? ''));
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

switch ($action) {

    // ── LIST teachers in my school ──
    case 'list_teachers':
        if ($school_id) {
            $stmt = $conn->prepare("
                SELECT u.id, u.full_name, u.username, u.created_at,
                       (SELECT COUNT(*) FROM learner_teachers lt WHERE lt.teacher_id = u.id) AS learner_count
                FROM users u
                WHERE u.role = 'teacher' AND u.school_id = ?
                ORDER BY u.full_name ASC
            ");
            $stmt->bind_param("i", $school_id);
        } else {
            $stmt = $conn->prepare("
                SELECT u.id, u.full_name, u.username, u.created_at,
                       s.school_name,
                       (SELECT COUNT(*) FROM learner_teachers lt WHERE lt.teacher_id = u.id) AS learner_count
                FROM users u
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE u.role = 'teacher'
                ORDER BY s.school_name ASC, u.full_name ASC
            ");
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $teachers = [];
        while ($row = $res->fetch_assoc()) {
            $teachers[] = $row;
        }

        // Count total and unassigned learners
        if ($school_id) {
            $stmt_tot = $conn->prepare("
                SELECT COUNT(DISTINCT l.lrn) AS c 
                FROM learners l 
                JOIN enrollments e ON e.lrn = l.lrn AND e.is_deleted = 0 AND e.is_historical = 0 
                WHERE e.school_id = ? AND l.is_deleted = 0
            ");
            $stmt_tot->bind_param("i", $school_id);
            $stmt_tot->execute();
            $total_learners = $stmt_tot->get_result()->fetch_assoc()['c'] ?? 0;

            $stmt_ass = $conn->prepare("
                SELECT COUNT(DISTINCT lt.lrn) AS c 
                FROM learner_teachers lt 
                JOIN learners l ON l.lrn = lt.lrn AND l.is_deleted = 0
                JOIN enrollments e ON e.lrn = l.lrn AND e.is_deleted = 0 AND e.is_historical = 0 
                WHERE e.school_id = ?
            ");
            $stmt_ass->bind_param("i", $school_id);
            $stmt_ass->execute();
            $assigned_learners = $stmt_ass->get_result()->fetch_assoc()['c'] ?? 0;
        } else {
            $total_learners = $conn->query("
                SELECT COUNT(DISTINCT l.lrn) AS c 
                FROM learners l 
                JOIN enrollments e ON e.lrn = l.lrn AND e.is_deleted = 0 AND e.is_historical = 0 
                WHERE l.is_deleted = 0
            ")->fetch_assoc()['c'] ?? 0;

            $assigned_learners = $conn->query("
                SELECT COUNT(DISTINCT lt.lrn) AS c 
                FROM learner_teachers lt 
                JOIN learners l ON l.lrn = lt.lrn AND l.is_deleted = 0
            ")->fetch_assoc()['c'] ?? 0;
        }
        $unassigned_learners = max(0, $total_learners - $assigned_learners);

        echo json_encode([
            'success' => true, 
            'teachers' => $teachers,
            'total_learners' => (int)$total_learners,
            'assigned_learners' => (int)$assigned_learners,
            'unassigned_learners' => (int)$unassigned_learners
        ]);
        exit;

    // ── CREATE teacher account ──
    case 'create_teacher':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit();
        }
        if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit();
        }

        $full_name = teacher_management_uppercase_name($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($full_name === '' || $username === '' || $password === '') {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit();
        }
        if (mb_strlen($full_name, 'UTF-8') > 255) {
            echo json_encode(['success' => false, 'message' => 'Full name exceeds 255 characters.']);
            exit();
        }
        if (mb_strlen($username, 'UTF-8') > 255) {
            echo json_encode(['success' => false, 'message' => 'Username exceeds 255 characters.']);
            exit();
        }

        // Check duplicate username
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Username already exists.']);
            exit();
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 'teacher';
        $target_school_id = $school_id;
        if ($is_superadmin && isset($_POST['school_id'])) {
            $target_school_id = (int)$_POST['school_id'];
        }
        if (!$target_school_id) {
            echo json_encode(['success' => false, 'message' => 'Please select a school first.']);
            exit();
        }
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, school_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $full_name, $username, $hashed, $role, $target_school_id);
        if ($stmt->execute()) {
            log_transaction($conn, $_SESSION['user_id'], 'CREATE', 'USERS', $stmt->insert_id, "Created teacher {$username}");
            echo json_encode(['success' => true, 'message' => 'Teacher account created.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;

    // ── UPDATE teacher info ──
    case 'update_teacher':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit();
        }
        if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit();
        }

        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $full_name = teacher_management_uppercase_name($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $new_password = $_POST['password'] ?? '';

        if ($full_name === '' || $username === '') {
            echo json_encode(['success' => false, 'message' => 'Full name and username are required.']);
            exit();
        }

        // Verify teacher belongs to coordinator's school (superadmin can edit any)
        if ($is_superadmin) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("i", $teacher_id);
        } else {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("ii", $teacher_id, $school_id);
        }
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found in your school.']);
            exit();
        }

        // Check duplicate username (exclude self)
        $dup = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $dup->bind_param("si", $username, $teacher_id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Username already taken.']);
            exit();
        }

        if ($new_password !== '') {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, password = ? WHERE id = ?");
            $stmt->bind_param("sssi", $full_name, $username, $hashed, $teacher_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
            $stmt->bind_param("ssi", $full_name, $username, $teacher_id);
        }
        if ($stmt->execute()) {
            log_transaction($conn, $_SESSION['user_id'], 'UPDATE', 'USERS', $teacher_id, "Updated teacher {$username}");
            echo json_encode(['success' => true, 'message' => 'Teacher updated.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;

    // ── GET teacher's assigned learners + unassigned learners ──
    case 'get_learners':
        $teacher_id = (int)($_GET['teacher_id'] ?? 0);
        if (!$teacher_id) {
            echo json_encode(['success' => false, 'message' => 'Missing teacher_id.']);
            exit();
        }

        // Verify teacher belongs to coordinator's school (superadmin can manage any)
        if ($is_superadmin) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("i", $teacher_id);
        } else {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("ii", $teacher_id, $school_id);
        }
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found in your school.']);
            exit();
        }

        // Assigned learners
        $stmt_a = $conn->prepare("
            SELECT l.lrn, l.last_name, l.first_name, l.middle_name, e.grade_level
            FROM learner_teachers lt
            JOIN learners l ON l.lrn = lt.lrn AND l.is_deleted = 0
            LEFT JOIN enrollments e ON e.lrn = l.lrn AND e.is_deleted = 0 AND e.is_historical = 0
            WHERE lt.teacher_id = ?
            ORDER BY l.last_name, l.first_name
        ");
        $stmt_a->bind_param("i", $teacher_id);
        $stmt_a->execute();
        $assigned = [];
        $res_a = $stmt_a->get_result();
        while ($row = $res_a->fetch_assoc()) {
            $assigned[] = $row;
        }

        // Unassigned learners in the school
        $stmt_u = $conn->prepare("
            SELECT l.lrn, l.last_name, l.first_name, l.middle_name, e.grade_level
            FROM learners l
            JOIN enrollments e ON e.lrn = l.lrn AND e.is_deleted = 0 AND e.is_historical = 0
            WHERE e.school_id = ? AND l.is_deleted = 0
              AND l.lrn NOT IN (SELECT lrn FROM learner_teachers WHERE teacher_id = ?)
            ORDER BY l.last_name, l.first_name
        ");
        $stmt_u->bind_param("ii", $school_id, $teacher_id);
        $stmt_u->execute();
        $unassigned = [];
        $res_u = $stmt_u->get_result();
        while ($row = $res_u->fetch_assoc()) {
            $unassigned[] = $row;
        }

        echo json_encode(['success' => true, 'assigned' => $assigned, 'unassigned' => $unassigned]);
        exit;

    // ── SAVE learner assignments (full replace) ──
    case 'save_assignments':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit();
        }
        if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit();
        }

        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $lrns_json = $_POST['assigned_lrns'] ?? '[]';
        $lrns = json_decode($lrns_json, true);

        if (!is_array($lrns)) {
            echo json_encode(['success' => false, 'message' => 'Invalid learner data.']);
            exit();
        }

        // Verify teacher belongs to coordinator's school (superadmin can manage any)
        if ($is_superadmin) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("i", $teacher_id);
        } else {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("ii", $teacher_id, $school_id);
        }
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found in your school.']);
            exit();
        }

        // Full replace
        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM learner_teachers WHERE teacher_id = ?");
            $del->bind_param("i", $teacher_id);
            $del->execute();

            $ins = $conn->prepare("INSERT IGNORE INTO learner_teachers (teacher_id, lrn, assigned_by) VALUES (?, ?, ?)");
            foreach ($lrns as $lrn_val) {
                $lrn_str = trim($lrn_val);
                if (preg_match('/^\d{12}$/', $lrn_str)) {
                    $ins->bind_param("isi", $teacher_id, $lrn_str, $_SESSION['user_id']);
                    $ins->execute();
                }
            }

            $conn->commit();
            log_transaction($conn, $_SESSION['user_id'], 'UPDATE', 'learner_teachers', $teacher_id, "Updated learner assignments for teacher ID {$teacher_id}");
            echo json_encode(['success' => true, 'message' => 'Assignments saved.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;

    // ── DELETE teacher ──
    case 'delete_teacher':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit();
        }
        if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit();
        }

        $teacher_id = (int)($_POST['teacher_id'] ?? 0);

        // Verify teacher belongs to coordinator's school (superadmin can delete any)
        if ($is_superadmin) {
            $chk = $conn->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("i", $teacher_id);
        } else {
            $chk = $conn->prepare("SELECT id, username FROM users WHERE id = ? AND school_id = ? AND role = 'teacher' LIMIT 1");
            $chk->bind_param("ii", $teacher_id, $school_id);
        }
        $chk->execute();
        $teacher_row = $chk->get_result()->fetch_assoc();
        if (!$teacher_row) {
            echo json_encode(['success' => false, 'message' => 'Teacher not found in your school.']);
            exit();
        }

        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM learner_teachers WHERE teacher_id = ?");
            $del->bind_param("i", $teacher_id);
            $del->execute();

            $del_u = $conn->prepare("DELETE FROM users WHERE id = ?");
            $del_u->bind_param("i", $teacher_id);
            $del_u->execute();

            $conn->commit();
            log_transaction($conn, $_SESSION['user_id'], 'DELETE', 'USERS', $teacher_id, "Deleted teacher {$teacher_row['username']}");
            echo json_encode(['success' => true, 'message' => 'Teacher deleted.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}
