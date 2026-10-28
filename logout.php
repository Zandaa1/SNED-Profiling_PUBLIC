<?php
session_start();
include 'config.php';

if (isset($_SESSION['user_id'])) {
    log_transaction($conn, $_SESSION['user_id'], 'SECURITY', 'LOGOUT', $_SESSION['user_id'], "User logged out");
}

session_unset();
session_destroy();

header("Location: index.php");
exit();
?>