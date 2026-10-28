<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

// CSRF validation
if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

$rating_id = isset($_POST['rating_id']) ? (int)$_POST['rating_id'] : 0;
$quarter = $_POST['quarter'] ?? ''; // q1, q2, q3, q4
$value = $_POST['value'] ?? ''; // AP, D, P, NA, NO, or blank

if ($rating_id <= 0 || !in_array($quarter, ['q1', 'q2', 'q3', 'q4'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

if (!in_array($value, ['AP', 'D', 'P', 'B', 'NA', 'NO', ''], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid rating value']);
    exit();
}

// Check learner and school scope for this rating row
$stmt_check = $conn->prepare("
    SELECT r.learner_id, r.school_year as rating_sy, e.school_id, e.school_year as active_sy 
    FROM learner_indicator_ratings r
    LEFT JOIN enrollments e ON r.learner_id = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE r.id = ?
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt_check->bind_param("i", $rating_id);
$stmt_check->execute();
$info = $stmt_check->get_result()->fetch_assoc();

if (!$info) {
    http_response_code(404);
    echo json_encode(['error' => 'Rating record not found']);
    exit();
}

// Reject edits to past school years
if ($info['rating_sy'] !== $info['active_sy']) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot modify ratings for a past or inactive school year']);
    exit();
}

// Enforce school scope for coordinators and teachers
if (!user_is_admin($user_role)) {
    $session_school_id = $_SESSION['school_id'] ?? 0;
    $has_access = user_is_teacher($user_role)
        ? check_teacher_learner_access($conn, $info['learner_id'], true)
        : ((int)$info['school_id'] === (int)$session_school_id);
    if (!$has_access) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied: learner enrolled in another school or not assigned']);
        exit();
    }
}

// Update the rating (coerce empty to SQL NULL)
$db_value = ($value === '') ? null : $value;
$stmt_update = $conn->prepare("
    UPDATE learner_indicator_ratings 
    SET {$quarter} = ?, updated_by = ? 
    WHERE id = ?
");
$stmt_update->bind_param("sii", $db_value, $_SESSION['user_id'], $rating_id);

if ($stmt_update->execute()) {
    log_transaction($conn, $_SESSION['user_id'], 'update', 'sf9', $info['learner_id'], "Updated SF9 indicator rating ID $rating_id: {$quarter} set to " . ($value ?: 'NULL'));
    echo json_encode(['status' => 'success']);
    exit();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save rating']);
    exit();
}
