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

$q = trim($_GET['q'] ?? '');

$results = [];

if ($q === '') {
    // Return top 12 active schools by default if query is empty
    $sql = "
        SELECT s.id, s.school_name, s.district, COUNT(DISTINCT e.lrn) as enrollment_count
        FROM schools s
        LEFT JOIN enrollments e ON e.school_id = s.id AND e.is_deleted = 0 AND e.is_historical = 0
        WHERE s.is_deleted = 0
        GROUP BY s.id, s.school_name, s.district
        ORDER BY enrollment_count DESC, s.school_name ASC
        LIMIT 12
    ";
    $stmt = $conn->prepare($sql);
} else {
    $search_term = "%$q%";
    $sql = "
        SELECT s.id, s.school_name, s.district, COUNT(DISTINCT e.lrn) as enrollment_count
        FROM schools s
        LEFT JOIN enrollments e ON e.school_id = s.id AND e.is_deleted = 0 AND e.is_historical = 0
        WHERE s.is_deleted = 0
          AND (s.school_name LIKE ? OR s.district LIKE ?)
        GROUP BY s.id, s.school_name, s.district
        ORDER BY enrollment_count DESC, s.school_name ASC
        LIMIT 12
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $search_term, $search_term);
}

if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = [
            'id' => (int)$row['id'],
            'school_name' => $row['school_name'],
            'district' => $row['district'] ?: '',
            'enrollment_count' => (int)$row['enrollment_count']
        ];
    }
    echo json_encode(['success' => true, 'data' => $results]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query error']);
}
exit;
