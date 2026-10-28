<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

$lrn = $_GET['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid LRN']));
}

$user_school_id = $_SESSION['school_id'] ?? null;
if (user_is_scoped($user_role)) {
    $has_school_access = false;
    if ($user_school_id) {
        $stmt_g = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE lrn = ? AND school_id = ? AND is_deleted = 0 AND is_historical = 0");
        $stmt_g->bind_param("si", $lrn, $user_school_id);
        $stmt_g->execute();
        $gate_res = $stmt_g->get_result()->fetch_assoc();
        $has_school_access = ($gate_res['count'] ?? 0) > 0;
    }
    if (!$has_school_access && !check_teacher_learner_access($conn, $lrn)) {
        http_response_code(403);
        die(json_encode(['error' => 'Unauthorized']));
    }
}

// Load learner
$stmt = $conn->prepare("
    SELECT l.*, e.grade_level, e.school_year, e.primary_diagnosis, s.school_name
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner || !empty($learner['lrn_is_temporary'])) {
    http_response_code(404);
    die(json_encode(['error' => 'Learner not found or LRN is temporary']));
}

// Load IEP
$stmt_i = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY id DESC LIMIT 1");
$stmt_i->bind_param("s", $lrn);
$stmt_i->execute();
$iep = $stmt_i->get_result()->fetch_assoc();

$data = [
    'meta' => [
        'exported_at' => date('Y-m-d H:i:s'),
        'exported_by' => $_SESSION['username'] ?? 'unknown',
        'document_type' => 'IEP Record',
    ],
    'learner' => [
        'lrn' => $lrn,
        'last_name' => $learner['last_name'],
        'first_name' => $learner['first_name'],
        'middle_name' => $learner['middle_name'],
        'birthday' => $learner['birthday'],
        'sex' => $learner['sex'],
        'grade_level' => $learner['grade_level'],
        'school_name' => $learner['school_name'],
        'school_year' => $learner['school_year'],
        'primary_diagnosis' => $learner['primary_diagnosis'],
    ],
    'iep' => $iep,
];

$rows = [];
if ($iep) {
    $stmt_r = $conn->prepare("SELECT * FROM learner_iep_annex6_rows WHERE iep_id = ? ORDER BY sort_order ASC");
    $stmt_r->bind_param("i", $iep['id']);
    $stmt_r->execute();
    $rows = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);
    $data['barriers'] = array_values(array_filter($rows, fn($r) => $r['section_type'] === 'barrier'));
    $data['goals'] = array_values(array_filter($rows, fn($r) => $r['section_type'] === 'goal'));
    $data['transitions'] = array_values(array_filter($rows, fn($r) => $r['section_type'] === 'transition'));
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="iep_annex6_' . $lrn . '.json"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
log_transaction($conn, $_SESSION['user_id'], 'export_iep_json', 'learners', $lrn, "Exported IEP JSON for LRN: $lrn");
