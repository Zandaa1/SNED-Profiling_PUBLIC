<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

header('Content-Type: application/json');

$school_id = (int)($_GET['school_id'] ?? 0);

$results = [];

$sql = "
    SELECT l.lrn, l.first_name, l.last_name, l.birthday, l.is_deleted,
           MAX(e.grade_level) as grade_level, MAX(e.placement_type) as placement_type,
           MAX(e.self_contained_level) as self_contained_level, MAX(e.school_year) as school_year,
           s.school_name, MAX(s.id) as school_id
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.is_deleted = 0
";

if ($school_id) {
    $sql .= " AND e.school_id = " . $school_id;
}

$sql .= "
    GROUP BY l.lrn, l.first_name, l.last_name, l.birthday, l.is_deleted, s.school_name
    ORDER BY l.last_name ASC
";

$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    $age = (new DateTime($row['birthday']))->diff(new DateTime())->y;
    $results[] = [
        'lrn'           => $row['lrn'],
        'name'          => $row['last_name'] . ', ' . $row['first_name'],
        'grade_level'   => $row['grade_level'] ?? '—',
        'school_name'   => $row['school_name'] ?? '—',
        'school_id'     => $row['school_id'],
        'placement'     => $row['placement_type'] ?? '—',
        'age'           => $age,
    ];
}

echo json_encode(['success' => true, 'results' => $results]);
