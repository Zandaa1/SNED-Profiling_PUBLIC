<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role) || user_is_teacher($user_role)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// CSRF validation
if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

$action = $_POST['action'] ?? 'submit';

if ($action === 'submit') {
    $lrn = $_POST['learner_id'] ?? '';
    $transfer_type = $_POST['transfer_type'] ?? 'internal';
    $to_school_id = isset($_POST['to_school_id']) ? (int)$_POST['to_school_id'] : 0;
    $external_destination = trim($_POST['external_destination'] ?? '');
    $effective_sy = $_POST['effective_sy'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (!in_array($transfer_type, ['internal', 'external'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please choose a valid transfer type.']);
        exit();
    }

    if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn) || empty($reason) || empty($effective_sy)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please choose a learner, school year, and reason.']);
        exit();
    }
    if ($transfer_type === 'internal' && $to_school_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Please choose the new school.']);
        exit();
    }
    if ($transfer_type === 'external' && $external_destination === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter where the learner transferred out.']);
        exit();
    }

    // Get current active enrollment
    $stmt = $conn->prepare("
        SELECT school_id 
        FROM enrollments 
        WHERE lrn = ? AND is_deleted = 0 AND is_historical = 0 
        ORDER BY school_year DESC LIMIT 1
    ");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();
    $curr_enroll = $stmt->get_result()->fetch_assoc();

    if (!$curr_enroll) {
        http_response_code(400);
        echo json_encode(['error' => 'This learner has no current school record. Add an active enrollment first.']);
        exit();
    }

    $from_school_id = (int)$curr_enroll['school_id'];

    // Enforce school scope for coordinators
    if (!user_is_admin($user_role)) {
        $session_school_id = $_SESSION['school_id'] ?? 0;
        if ($from_school_id != $session_school_id) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only request transfers for learners currently enrolled in your school.']);
            exit();
        }
    }

    if ($transfer_type === 'internal' && $from_school_id === $to_school_id) {
        http_response_code(400);
        echo json_encode(['error' => 'The new school must be different from the current school.']);
        exit();
    }

    // Check if pending request already exists
    $stmt_pending = $conn->prepare("
        SELECT id FROM transfer_requests 
        WHERE learner_id = ? AND status = 'pending' 
        LIMIT 1
    ");
    $stmt_pending->bind_param("s", $lrn);
    $stmt_pending->execute();
    if ($stmt_pending->get_result()->fetch_assoc()) {
        http_response_code(400);
        echo json_encode(['error' => 'This learner already has a pending transfer request.']);
        exit();
    }

    // Insert request
    $stmt_ins = $conn->prepare("
        INSERT INTO transfer_requests (learner_id, from_school_id, to_school_id, requested_by, request_direction, transfer_type, external_destination, reason, effective_sy, status)
        VALUES (?, ?, ?, ?, 'outbound', ?, ?, ?, ?, 'pending')
    ");
    $to_school_param = $transfer_type === 'internal' ? $to_school_id : null;
    $external_destination_param = $transfer_type === 'external' ? $external_destination : null;
    $stmt_ins->bind_param("siiissss", $lrn, $from_school_id, $to_school_param, $_SESSION['user_id'], $transfer_type, $external_destination_param, $reason, $effective_sy);

    if ($stmt_ins->execute()) {
        $request_id = (int)$stmt_ins->insert_id;
        if (!empty($_FILES['supporting_document']) && ($_FILES['supporting_document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $document_id = sned_store_uploaded_document($conn, $_FILES['supporting_document'], $lrn, 'Transfer Support', 'Optional transfer support document', (int)$_SESSION['user_id']);
            if ($document_id) {
                $stmt_doc = $conn->prepare("INSERT IGNORE INTO transfer_request_documents (transfer_request_id, document_id) VALUES (?, ?)");
                $stmt_doc->bind_param("ii", $request_id, $document_id);
                $stmt_doc->execute();
            }
        }
        $target_text = $transfer_type === 'external' ? $external_destination : ('school ID: ' . $to_school_id);
        log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_REQUEST_SUBMITTED', 'enrollments', $lrn, "Submitted $transfer_type transfer request for LRN: $lrn to $target_text");
        echo json_encode(['status' => 'success', 'message' => 'Transfer request sent. The learner will move after approval.']);
        exit();
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to submit request.']);
        exit();
    }
} elseif ($action === 'cancel') {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

    if ($request_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Request ID.']);
        exit();
    }

    // Get request details
    $stmt_req = $conn->prepare("SELECT * FROM transfer_requests WHERE id = ? LIMIT 1");
    $stmt_req->bind_param("i", $request_id);
    $stmt_req->execute();
    $req = $stmt_req->get_result()->fetch_assoc();

    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Transfer request not found.']);
        exit();
    }

    if ($req['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['error' => 'Only pending transfer requests can be cancelled.']);
        exit();
    }

    // Check scope (must be the requester or admin)
    if (!user_is_admin($user_role) && $req['requested_by'] != $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. You can only cancel transfer requests you initiated.']);
        exit();
    }

    // Update status to cancelled
    $stmt_cancel = $conn->prepare("UPDATE transfer_requests SET status = 'cancelled' WHERE id = ?");
    $stmt_cancel->bind_param("i", $request_id);

    if ($stmt_cancel->execute()) {
        log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_REQUEST_CANCELLED', 'enrollments', $req['learner_id'], "Cancelled transfer request ID: $request_id");
        echo json_encode(['status' => 'success', 'message' => 'Transfer request cancelled successfully.']);
        exit();
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to cancel request.']);
        exit();
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit();
}
