<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role) || user_is_teacher($user_role)) {
    header("Location: 403.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: transferRequests.php");
    exit();
}

// CSRF validation
if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("Invalid CSRF token.");
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = $_POST['action'] ?? ''; // 'approve' or 'reject'
$admin_notes = trim($_POST['admin_notes'] ?? '');

if ($request_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    die("Invalid action parameters.");
}

// Start database transaction
$conn->begin_transaction();

try {
    // Get request details
    $stmt_req = $conn->prepare("SELECT * FROM transfer_requests WHERE id = ? LIMIT 1");
    $stmt_req->bind_param("i", $request_id);
    $stmt_req->execute();
    $req = $stmt_req->get_result()->fetch_assoc();

    if (!$req) {
        throw new Exception("Transfer request not found.");
    }

    if ($req['status'] !== 'pending') {
        throw new Exception("This request has already been processed.");
    }

    // Authorization check: Admin, OR destination-school coordinator for internal transfers
    $is_authorized = false;
    if (user_is_admin($user_role)) {
        $is_authorized = true;
    } else {
        $user_school_id = (int)($_SESSION['school_id'] ?? 0);
        if (($req['transfer_type'] ?? 'internal') === 'internal' && $user_school_id === (int)$req['to_school_id']) {
            $is_authorized = true;
        }
    }

    if (!$is_authorized) {
        throw new Exception("You do not have permission to review this transfer request.");
    }

    $lrn = $req['learner_id'];
    $from_school_id = (int)$req['from_school_id'];
    $to_school_id = isset($req['to_school_id']) ? (int)$req['to_school_id'] : 0;
    $effective_sy = $req['effective_sy'];
    $transfer_type = $req['transfer_type'] ?? 'internal';

    if ($action === 'approve') {
        // Enforce same-school validation
        if ($transfer_type === 'internal') {
            if ($to_school_id <= 0) {
                throw new Exception("This transfer request has no new school.");
            }
            if ($from_school_id === $to_school_id) {
                throw new Exception("The current school and new school cannot be the same.");
            }
        }

        // 1. Set status to approved, reviewed_by, reviewed_at, admin_notes
        $now = date('Y-m-d H:i:s');
        $stmt_app = $conn->prepare("
            UPDATE transfer_requests 
            SET status = 'approved', reviewed_by = ?, reviewed_at = ?, admin_notes = ? 
            WHERE id = ?
        ");
        $stmt_app->bind_param("issi", $_SESSION['user_id'], $now, $admin_notes, $request_id);
        $stmt_app->execute();

        // 2. Fetch current active enrollment
        $stmt_curr = $conn->prepare("
            SELECT * FROM enrollments 
            WHERE lrn = ? AND is_deleted = 0 AND is_historical = 0 
            ORDER BY school_year DESC LIMIT 1
        ");
        $stmt_curr->bind_param("s", $lrn);
        $stmt_curr->execute();
        $curr_enroll = $stmt_curr->get_result()->fetch_assoc();

        if (!$curr_enroll) {
            throw new Exception("This learner has no current school record. Add an active enrollment first.");
        }
        if ((int)$curr_enroll['school_id'] !== $from_school_id) {
            throw new Exception("This learner is no longer active at the recorded current school.");
        }

        $current_disabilities = sned_get_enrollment_disabilities($conn, (int)$curr_enroll['id']);
        // Update current enrollment to transferred/historical
        $new_status = $transfer_type === 'external' ? 'transferred_out' : 'transferred';
        $stmt_up_curr = $conn->prepare("
            UPDATE enrollments 
            SET is_historical = 1, status = ?, transfer_date = ? 
            WHERE id = ?
        ");
        $stmt_up_curr->bind_param("ssi", $new_status, $now, $curr_enroll['id']);
        $stmt_up_curr->execute();

        $placement_type = $curr_enroll['placement_type'];
        $self_contained_level = $curr_enroll['self_contained_level'];
        $grade_level = $curr_enroll['grade_level'];
        $primary_diagnosis = $curr_enroll['primary_diagnosis'];

        if ($transfer_type === 'internal') {
            // 3. Insert new enrollment row for the learner at to_school_id
            $stmt_ins_enroll = $conn->prepare("
                INSERT INTO enrollments (
                    lrn, school_id, school_year, placement_type, self_contained_level, grade_level, primary_diagnosis, 
                    remarks, updated_by, is_historical, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Transferred from previous school', ?, 0, 'active')
            ");
            $stmt_ins_enroll->bind_param(
                "sisssssi",
                $lrn, $to_school_id, $effective_sy, $placement_type, $self_contained_level, $grade_level, $primary_diagnosis, $_SESSION['user_id']
            );
            $stmt_ins_enroll->execute();
            sned_save_enrollment_disabilities($conn, (int)$stmt_ins_enroll->insert_id, $current_disabilities);

            // 4. Add a learner_transfers provenance record
            $stmt_ins_trans = $conn->prepare("
                INSERT INTO learner_transfers (learner_id, from_school_id, to_school_id, transfer_date, transfer_request_id, notes)
                VALUES (?, ?, ?, CURDATE(), ?, ?)
            ");
            $stmt_ins_trans->bind_param("siiis", $lrn, $from_school_id, $to_school_id, $request_id, $admin_notes);
            $stmt_ins_trans->execute();
        }

        $stmt_profile_update = $conn->prepare("UPDATE learners SET needs_profile_update = 1 WHERE lrn = ?");
        $stmt_profile_update->bind_param("s", $lrn);
        $stmt_profile_update->execute();

        // 5. Audit log
        $target_text = $transfer_type === 'external' ? ('external destination: ' . ($req['external_destination'] ?? 'Transferred Out')) : ('school ID: ' . $to_school_id);
        log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_APPROVED', 'enrollments', $lrn, "Approved $transfer_type transfer request ID: $request_id for LRN: $lrn to $target_text");

        $_SESSION['system_notice'] = $transfer_type === 'external' ? "Learner marked as transferred out." : "Learner moved to the new school.";
        $_SESSION['system_notice_type'] = "success";

    } elseif ($action === 'reject') {
        if (empty($admin_notes)) {
            throw new Exception("Reason for rejection is required.");
        }

        // 1. Set status to rejected, reviewed_by, reviewed_at, admin_notes
        $now = date('Y-m-d H:i:s');
        $stmt_rej = $conn->prepare("
            UPDATE transfer_requests 
            SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, admin_notes = ? 
            WHERE id = ?
        ");
        $stmt_rej->bind_param("issi", $_SESSION['user_id'], $now, $admin_notes, $request_id);
        $stmt_rej->execute();

        // 2. Audit log
        log_transaction($conn, $_SESSION['user_id'], 'TRANSFER_REJECTED', 'enrollments', $lrn, "Rejected transfer request ID: $request_id for LRN: $lrn");

        $_SESSION['system_notice'] = "Transfer request rejected.";
        $_SESSION['system_notice_type'] = "success";
    }

    $conn->commit();
    header("Location: transferRequests.php");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['system_notice'] = "Error: " . $e->getMessage();
    $_SESSION['system_notice_type'] = "error";
    header("Location: transferRequests.php");
    exit();
}
