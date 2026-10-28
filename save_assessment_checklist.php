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

$item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item ID.']);
    exit();
}

// Check if item exists in catalog
$stmt_chk_item = $conn->prepare("SELECT 1 FROM `assessment_checklist_items` WHERE `id` = ? LIMIT 1");
$stmt_chk_item->bind_param("i", $item_id);
$stmt_chk_item->execute();
$item_exists = $stmt_chk_item->get_result()->num_rows > 0;
$stmt_chk_item->close();

if (!$item_exists) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Assessment checklist item not found in catalog.']);
    exit();
}

// Pre Ratings validate
$pre_rating = trim($_POST['pre_rating'] ?? '');
if ($pre_rating === '') {
    $pre_rating = null;
} elseif (!in_array($pre_rating, ['E', 'NYE', 'NO'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid pre-rating code. Must be E, NYE, or NO.']);
    exit();
}

// Post Ratings validate
$post_rating = trim($_POST['post_rating'] ?? '');
if ($post_rating === '') {
    $post_rating = null;
} elseif (!in_array($post_rating, ['E', 'NYE', 'NO'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid post-rating code. Must be E, NYE, or NO.']);
    exit();
}

// Remarks parse
$remarks = trim($_POST['remarks'] ?? '');
if ($remarks === '') {
    $remarks = null;
} else {
    $remarks = preg_replace('/\s+/', ' ', $remarks);
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
        INSERT INTO `learner_assessment_checklist`
          (`learner_id`, `item_id`, `pre_rating`, `post_rating`, `remarks`, `updated_by`)
        VALUES
          (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          `updated_at` = IF(
            (`pre_rating` <=> VALUES(`pre_rating`)) AND
            (`post_rating` <=> VALUES(`post_rating`)) AND
            (`remarks` <=> VALUES(`remarks`)),
            `updated_at`,
            CURRENT_TIMESTAMP
          ),
          `pre_rating` = VALUES(`pre_rating`),
          `post_rating` = VALUES(`post_rating`),
          `remarks` = VALUES(`remarks`),
          `updated_by` = VALUES(`updated_by`)
    ");
    $stmt_upsert->bind_param("sisssi", $learner_id, $item_id, $pre_rating, $post_rating, $remarks, $user_id);
    $stmt_upsert->execute();
    $stmt_upsert->close();

    // Transaction log
    log_transaction($conn, $user_id, 'update', 'assessment_checklist', $learner_id, "Updated checklist item ID $item_id for learner LRN: $learner_id");

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database save error: ' . $e->getMessage()]);
}
