<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

if (!user_is_coordinator($user_role)) {
    header('Location: 403.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: learners.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("CSRF Token validation failed.");
}

$lrn = trim($_POST['lrn'] ?? '');
if (!preg_match('/^\d{12}$/', $lrn)) {
    header("Location: learners.php");
    exit();
}

// Enforce school scope for non-admin coordinators
if (!check_teacher_learner_access($conn, $lrn, true)) {
    header("Location: 403.php");
    exit();
}

$message = '';
$msg_type = 'danger';
$msg_title = 'Upload Failed';

if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
    try {
        $doc_type = $_POST['document_type'] ?? 'Other';
        $notes = trim($_POST['notes'] ?? '');
        $document_id = sned_store_uploaded_document($conn, $_FILES['doc_file'], $lrn, $doc_type, $notes, (int)$_SESSION['user_id']);
        if ($document_id) {
            log_transaction($conn, $_SESSION['user_id'], 'create', 'documents', $document_id, "Uploaded document: {$_FILES['doc_file']['name']} for LRN: $lrn");
            $message = "Document uploaded and linked successfully.";
            $msg_type = 'success';
            $msg_title = 'Success';
        } else {
            $message = "No file selected or upload error occurred.";
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
    }
} else {
    $message = "No file selected or upload error occurred.";
}

header("Location: viewLearner.php?id=" . urlencode($lrn) . "&tab=documents&msg=" . urlencode($message) . "&msg_type=" . urlencode($msg_type) . "&msg_title=" . urlencode($msg_title));
exit();
