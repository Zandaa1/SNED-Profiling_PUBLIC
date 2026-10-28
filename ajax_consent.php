<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'check') {
    $lrn = $_GET['lrn'] ?? '';
    $consent_type = $_GET['consent_type'] ?? '';

    if (!preg_match('/^\d{12}$/', $lrn) || !in_array($consent_type, ['Assessment', 'IEP', 'Program Enrollment'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters']);
        exit();
    }

    // School-scoped users can only check for learners at their school
    if (user_is_scoped($user_role)) {
        $has_school_access = false;
        $user_school_id = $_SESSION['school_id'] ?? null;
        if ($user_school_id) {
            $stmt_gate = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM enrollments 
                WHERE lrn = ? AND school_id = ? AND is_deleted = 0
            ");
            $stmt_gate->bind_param("si", $lrn, $user_school_id);
            $stmt_gate->execute();
            $gate_res = $stmt_gate->get_result()->fetch_assoc();
            $has_school_access = ($gate_res['count'] ?? 0) > 0;
        }
        if (!$has_school_access && !check_teacher_learner_access($conn, $lrn)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit();
        }
    }

    $stmt = $conn->prepare("
        SELECT consent_date, consented_by 
        FROM parental_consents 
        WHERE lrn = ? AND consent_type = ? 
        ORDER BY consent_date DESC, id DESC LIMIT 1
    ");
    $stmt->bind_param("ss", $lrn, $consent_type);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode([
            'has_consent' => true,
            'date' => $row['consent_date'],
            'consented_by' => $row['consented_by']
        ]);
    } else {
        echo json_encode([
            'has_consent' => false
        ]);
    }
    exit();

} elseif ($action === 'save') {
    if (!user_is_coordinator($user_role)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit();
    }

    $lrn = trim($_POST['lrn'] ?? '');
    $consent_type = $_POST['consent_type'] ?? '';
    $consented_by = trim($_POST['consented_by'] ?? '');
    $consent_date = $_POST['consent_date'] ?? '';
    $notes = trim($_POST['notes'] ?? null);

    if (!preg_match('/^\d{12}$/', $lrn) || !in_array($consent_type, ['Assessment', 'IEP', 'Program Enrollment'], true) || $consented_by === '' || $consent_date === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing fields']);
        exit();
    }

    // School-scoped users can only save for learners at their school
    if (user_is_scoped($user_role)) {
        $has_school_access = false;
        $user_school_id = $_SESSION['school_id'] ?? null;
        if ($user_school_id) {
            $stmt_gate = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM enrollments 
                WHERE lrn = ? AND school_id = ? AND is_deleted = 0
            ");
            $stmt_gate->bind_param("si", $lrn, $user_school_id);
            $stmt_gate->execute();
            $gate_res = $stmt_gate->get_result()->fetch_assoc();
            $has_school_access = ($gate_res['count'] ?? 0) > 0;
        }
        if (!$has_school_access && !check_teacher_learner_access($conn, $lrn)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO parental_consents (lrn, consent_type, consented_by, consent_date, notes, recorded_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssi", $lrn, $consent_type, $consented_by, $consent_date, $notes, $_SESSION['user_id']);

    if ($stmt->execute()) {
        log_transaction($conn, $_SESSION['user_id'], 'create', 'consents', $lrn, "Recorded parental consent for type $consent_type");
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save consent record']);
    }
    exit();

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit();
}
?>
