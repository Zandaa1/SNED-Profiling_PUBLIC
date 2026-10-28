<?php
session_start();
include 'config.php';

$user_role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? 'Guest';

// Log the access attempt
if (isset($_SESSION['user_id'])) {
    log_transaction($conn, $_SESSION['user_id'], 'ACCESS_DENIED', 'security', null, "Attempted to access {$_SERVER['REQUEST_URI']}");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Access Denied | SNED Profiling</title>
    <style>
        body {
            background: linear-gradient(135deg, var(--sned-cloud) 0%, var(--color-surface-container) 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .error-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(27, 38, 49, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .error-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px rgba(27, 38, 49, 0.12);
        }
        .icon-container {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--sned-danger-light);
            color: var(--sned-danger);
            font-size: 3.5rem;
            margin-bottom: 2rem;
            position: relative;
            animation: pulse-ring 2s infinite;
        }
        .icon-container i {
            transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .error-card:hover .icon-container i {
            transform: scale(1.15) rotate(-10deg);
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(192, 98, 106, 0.4); }
            70% { box-shadow: 0 0 0 15px rgba(192, 98, 106, 0); }
            100% { box-shadow: 0 0 0 0 rgba(192, 98, 106, 0); }
        }
        .btn-return {
            background: linear-gradient(135deg, var(--sned-accent) 0%, var(--sned-primary) 100%);
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 40px;
            border-radius: 50px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(64, 145, 108, 0.2);
        }
        .btn-return:hover {
            background: linear-gradient(135deg, var(--sned-primary) 0%, #1e4635 100%);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(64, 145, 108, 0.3);
        }
        .btn-return:active {
            transform: translateY(0);
        }
        .role-badge {
            background: var(--color-surface-container-high);
            color: var(--sned-ink);
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="d-flex min-vh-100 app-shell">
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php include 'sidebar.php'; ?>
        <?php endif; ?>

        <div class="flex-grow-1 d-flex align-items-center justify-content-center bg-transparent overflow-auto p-4">
            <div class="error-card text-center p-5 w-100" style="max-width: 540px;">
                <div class="icon-container">
                    <i class="bi bi-shield-slash-fill"></i>
                </div>
                <h1 class="fw-bold mb-2 text-dark">403</h1>
                <h3 class="fw-bold mb-3 text-secondary">Access Denied</h3>
                <p class="text-muted mb-3">
                    You do not have permission to access this page.
                    <?php if ($user_role !== 'guest'): ?>
                        Your current role is <span class="role-badge text-uppercase ms-1"><?= htmlspecialchars($user_role) ?></span>.
                    <?php endif; ?>
                </p>
                <p class="text-muted small mb-4">
                    This security incident has been logged. If you believe this is an error, please contact your system administrator.
                </p>
                <a href="main.php" class="btn btn-return shadow-sm">
                    <i class="bi bi-house-fill me-2"></i> Return to Dashboard
                </a>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }
    </script>
</body>
</html>
