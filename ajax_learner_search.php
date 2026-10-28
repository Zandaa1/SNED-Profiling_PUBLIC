<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query too short']);
    exit;
}

$search_term = "%$q%";

$school_filter = '';
$user_school_id = $_SESSION['school_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'user';

if ($user_school_id && user_is_scoped($user_role)) {
    $school_filter = " AND e.school_id = " . intval($user_school_id);
    $has_school_filter = true;
}

$teacher_filter = get_teacher_scope_filter('l');

// Try LRN first (exact or prefix match)
$results = [];

$sql = "
    SELECT l.lrn, l.first_name, l.last_name, l.birthday, l.is_deleted,
           e.id as enrollment_id, e.grade_level, e.placement_type, e.self_contained_level, e.school_year, e.primary_diagnosis,
           s.school_name, s.id as school_id, s.grade_levels_offered
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.is_deleted = 0
      AND (l.lrn LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ?)
      $school_filter
      $teacher_filter
    ORDER BY l.last_name ASC
    LIMIT 20
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $search_term, $search_term, $search_term);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $age = (new DateTime($row['birthday']))->diff(new DateTime())->y;
    $disability_rows = sned_get_enrollment_disabilities($conn, isset($row['enrollment_id']) ? (int)$row['enrollment_id'] : null);
    if (empty($disability_rows) && !empty($row['primary_diagnosis'])) {
        $disability_rows = sned_disability_rows_from_legacy_diagnosis($row['primary_diagnosis']);
    }
    $results[] = [
        'lrn'           => $row['lrn'],
        'name'          => $row['last_name'] . ', ' . $row['first_name'],
        'grade_level'   => $row['grade_level'] ?? '—',
        'school_name'   => $row['school_name'] ?? '—',
        'school_id'     => $row['school_id'],
        'school_grade_levels' => sned_grade_levels_from_school_offerings($row['grade_levels_offered'] ?? ''),
        'placement'     => $row['placement_type'] ?? '—',
        'self_contained_level' => $row['self_contained_level'] ?? '',
        'school_year'   => $row['school_year'] ?? '—',
        'primary_diagnosis' => sned_disability_summary($disability_rows, $row['primary_diagnosis'] ?? '—'),
        'age'           => $age,
    ];
}

echo json_encode(['success' => true, 'results' => $results]);
