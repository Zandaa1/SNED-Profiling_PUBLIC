<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

$teacher_id = (int)($_GET['teacher_id'] ?? 0);
if (!$teacher_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
    exit;
}

$assigned = [];
$stmt = $conn->prepare("SELECT lrn FROM learner_teachers WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $assigned[] = $row['lrn'];
}

echo json_encode(['success' => true, 'assigned' => $assigned]);
