<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id && !empty($_GET['school_id'])) {
    $school_id = (int)$_GET['school_id'];
}

if (!$school_id) {
    echo json_encode(['success' => false, 'message' => 'School ID is required']);
    exit;
}

$current_sy = trim($_GET['current_sy'] ?? '');
$target_sy = trim($_GET['target_sy'] ?? '');

if (empty($current_sy) || empty($target_sy)) {
    echo json_encode(['success' => false, 'message' => 'Required parameters missing']);
    exit;
}

$sql = "
    SELECT e.id AS enrollment_id, e.lrn, l.first_name, l.last_name, l.birthday,
           e.grade_level, e.placement_type, e.self_contained_level, e.school_year, e.primary_diagnosis
    FROM enrollments e
    INNER JOIN learners l ON e.lrn = l.lrn AND l.is_deleted = 0
    WHERE e.school_id = ?
      AND e.school_year = ?
      AND e.is_deleted = 0
      AND e.is_historical = 0
      AND NOT EXISTS (
          SELECT 1 FROM enrollments next_e
          WHERE next_e.lrn = e.lrn
            AND next_e.school_year = ?
            AND next_e.is_deleted = 0
      )
    ORDER BY l.last_name ASC, l.first_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $school_id, $current_sy, $target_sy);
$stmt->execute();
$res = $stmt->get_result();

$results = [];
while ($row = $res->fetch_assoc()) {
    $age = (new DateTime($row['birthday']))->diff(new DateTime())->y;
    $results[] = [
        'enrollment_id'        => (int)$row['enrollment_id'],
        'lrn'                  => $row['lrn'],
        'name'                 => $row['last_name'] . ', ' . $row['first_name'],
        'grade_level'          => $row['grade_level'] ?? '—',
        'placement'            => $row['placement_type'] ?? '—',
        'self_contained_level' => $row['self_contained_level'] ?? '',
        'school_year'          => $row['school_year'] ?? '—',
        'primary_diagnosis'    => $row['primary_diagnosis'] ?? '—',
        'age'                  => $age,
    ];
}

echo json_encode(['success' => true, 'results' => $results]);
