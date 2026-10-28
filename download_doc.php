<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Invalid document ID.");
}

$stmt = $conn->prepare("SELECT d.*, l.first_name, l.last_name FROM documents d JOIN learners l ON d.lrn = l.lrn WHERE d.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    die("Document not found.");
}

// School-scoped check
$user_school_id = $_SESSION['school_id'] ?? null;
if ($user_school_id && user_is_scoped($_SESSION['role'] ?? '')) {
    $stmt_chk = $conn->prepare("SELECT COUNT(*) as cnt FROM enrollments e WHERE e.lrn = ? AND e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0");
    $stmt_chk->bind_param("si", $doc['lrn'], $user_school_id);
    $stmt_chk->execute();
    $row = $stmt_chk->get_result()->fetch_assoc();
    if (!$row || $row['cnt'] === 0) {
        header('Location: 403.php');
        exit;
    }
}

$filepath = __DIR__ . '/uploads/documents/' . $doc['stored_filename'];
if (!file_exists($filepath)) {
    die("File not found on server.");
}

log_transaction($conn, $_SESSION['user_id'], 'download', 'documents', $id, "Downloaded document: {$doc['original_filename']} for LRN: {$doc['lrn']}");

$ext = strtolower(pathinfo($doc['original_filename'], PATHINFO_EXTENSION));
$mime = $doc['mime_type'] ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($doc['original_filename']) . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
