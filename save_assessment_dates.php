<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// 1. Session check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized session.']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// 2. Role check — coordinator or teacher assignment
if (!user_is_coordinator($user_role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit();
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

// 4. CSRF Validation
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || !csrf_validate($csrf_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit();
}

// 5. Input parsing & validation
$learner_id = trim($_POST['learner_id'] ?? '');
if (empty($learner_id) || !preg_match('/^\d{12}$/', $learner_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Learner LRN format.']);
    exit();
}

$pre_date = trim($_POST['pre_date'] ?? '');
if ($pre_date === '') {
    $pre_date = null;
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pre_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid Pre-assessment date format.']);
        exit();
    }
}

$post_date = trim($_POST['post_date'] ?? '');
if ($post_date === '') {
    $post_date = null;
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid Post-assessment date format.']);
        exit();
    }
}

// 6. Scope checks
if (!user_is_admin($user_role) && user_is_scoped($user_role)) {
    if (!check_teacher_learner_access($conn, $learner_id, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to this learner.']);
        exit();
    }
}

// 7. Upsert operation
$conn->begin_transaction();
try {
    $stmt_upsert = $conn->prepare("
        INSERT INTO `learner_assessment_dates`
          (`learner_id`, `pre_date`, `post_date`)
        VALUES
          (?, ?, ?)
        ON DUPLICATE KEY UPDATE
          `pre_date` = VALUES(`pre_date`),
          `post_date` = VALUES(`post_date`)
    ");
    $stmt_upsert->bind_param("sss", $learner_id, $pre_date, $post_date);
    $stmt_upsert->execute();
    $stmt_upsert->close();

    // Transaction log
    log_transaction($conn, $user_id, 'update', 'assessment_dates', $learner_id, "Updated checklist dates for learner LRN: $learner_id");

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database save error: ' . $e->getMessage()]);
}
