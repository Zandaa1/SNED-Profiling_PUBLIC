<?php 
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$is_superadmin_user = user_is_superadmin($user_role);

if (!user_is_admin($user_role)) {
    header("Location: main.php");
    exit();
}

// Pagination setup
$limit = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$filter_user_id = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$where_clauses = [];
if (!$is_superadmin_user) {
    // Admin Office can audit client-side activity, but developer/superadmin accounts stay hidden.
    $where_clauses[] = "u.role <> 'superadmin' AND NOT (l.module = 'USERS' AND l.target_id IN (SELECT id FROM users WHERE role = 'superadmin'))";
}

if ($filter_user_id > 0 && !$is_superadmin_user) {
    $stmt_filter_role = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt_filter_role->bind_param("i", $filter_user_id);
    $stmt_filter_role->execute();
    $filter_row = $stmt_filter_role->get_result()->fetch_assoc();
    if (($filter_row['role'] ?? '') === 'superadmin') {
        $filter_user_id = 0;
    }
}

if ($filter_user_id > 0) {
    $where_clauses[] = "l.user_id = $filter_user_id";
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Fetch users for the filter dropdown
$users_where = $is_superadmin_user ? "" : "WHERE role <> 'superadmin'";
$users_res = $conn->query("SELECT id, username, full_name, role FROM users $users_where ORDER BY username ASC");
$all_users = [];
while ($u = $users_res->fetch_assoc()) {
    $all_users[] = $u;
}

// Get total count for pagination
$count_res = $conn->query("
    SELECT COUNT(*) as total 
    FROM transaction_logs l 
    JOIN users u ON l.user_id = u.id 
    $where_sql
");
$total_rows = $count_res->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

$logs = [];
$res = $conn->query("
    SELECT l.*, u.username as actor_name 
    FROM transaction_logs l 
    JOIN users u ON l.user_id = u.id 
    $where_sql
    ORDER BY l.created_at DESC 
    LIMIT $limit OFFSET $offset
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
}

function get_filter_url($p, $u_id) {
    $params = $_GET;
    $params['page'] = $p;
    if ($u_id > 0) $params['user_id'] = $u_id;
    else unset($params['user_id']);
    return 'transaction_logs.php?' . http_build_query($params);
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
    <title>Transaction Logs | SNED Profiling</title>
    <style>
        @media print {
            @page { size: A4 landscape; margin: 0.5in; }
            body { background: white !important; font-size: 10px; }
            .app-sidebar, header, #sidebarToggle, .pagination-nav {
                display: none !important;
            }
            .app-shell { display: block !important; height: auto !important; }
            .flex-grow-1 { width: 100% !important; padding: 0 !important; overflow: visible !important; height: auto !important; }
            .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
            .table th { background-color: #f8f9fa !important; color: black !important; -webkit-print-color-adjust: exact; }
            .badge { border: 1px solid #ccc !important; color: black !important; background: transparent !important; }
        }
    </style>
</head>
<body class="bg-light">
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column bg-white overflow-auto">
            <header class="p-3 border-bottom shadow-sm bg-white sticky-top d-flex align-items-center">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                    <i class="bi bi-list"></i> Menu
                </button>
                <h5 class="mb-0 ms-3 fw-bold">System Audit Logs</h5>
            </header>
            
            <div class="p-4 container-fluid">
                <div class="row align-items-center mb-4 g-3">
                    <div class="col-md-6">
                        <h2 class="fw-bold mb-0">
                            <i class="bi bi-list-columns text-primary"></i> Transaction Logs
                        </h2>
                        <div class="text-muted small mt-1">
                            Showing <?= count($logs) ?> of <?= number_format($total_rows) ?> entries
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <form method="GET" class="d-inline-flex gap-2">
                            <select name="user_id" class="form-select rounded-pill px-3 shadow-sm border-2" onchange="this.form.submit()">
                                <option value="0">All Users (Actor)</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $filter_user_id === (int)$u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?> (<?= strtoupper($u['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($filter_user_id > 0): ?>
                                <a href="transaction_logs.php" class="btn btn-outline-secondary rounded-pill shadow-sm"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm border-0 mb-4 animate__animated animate__fadeInUp">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="px-4">Date/Time</th>
                                        <th>User Who Did It</th>
                                        <th>Action Type</th>
                                        <th>Module</th>
                                        <th>Full Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr><td colspan="5" class="text-center py-5 text-muted">No logs found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <?php 
                                                $badge_class = 'bg-secondary';
                                                if ($log['action_type'] === 'CREATE') $badge_class = 'bg-success';
                                                elseif ($log['action_type'] === 'UPDATE') $badge_class = 'bg-info text-dark';
                                                elseif ($log['action_type'] === 'DELETE') $badge_class = 'bg-danger';
                                                elseif ($log['action_type'] === 'RESTORE') $badge_class = 'bg-primary';
                                                elseif ($log['action_type'] === 'SECURITY') $badge_class = 'bg-dark';
                                            ?>
                                            <tr>
                                                <td class="px-4 small"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($log['actor_name']) ?></td>
                                                <td><span class="badge <?= $badge_class ?>"><?= $log['action_type'] ?></span></td>
                                                <td class="text-uppercase small fw-bold"><?= $log['module'] ?></td>
                                                <td><?= htmlspecialchars($log['description']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="pagination-nav">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_filter_url($page - 1, $filter_user_id) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo; Previous</span>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="' . get_filter_url(1, $filter_user_id) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= get_filter_url($i, $filter_user_id) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php 
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            echo '<li class="page-item"><a class="page-link" href="' . get_filter_url($total_pages, $filter_user_id) . '">'.$total_pages.'</a></li>';
                        }
                        ?>
                        
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_filter_url($page + 1, $filter_user_id) ?>" aria-label="Next">
                                <span aria-hidden="true">Next &raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
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
