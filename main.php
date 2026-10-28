<?php 
session_start();
include 'config.php';
include 'includes/helpers.php';

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$display_name = $_SESSION['full_name'] ?? $username;

// Map roles to friendly, non-technical display names
$role_names = [
    'superadmin' => 'Administrator',
    'admin_office' => 'Division Admin',
    'school_coordinator' => 'School Coordinator',
    'teacher' => 'Teacher'
];
$friendly_role = $role_names[$user_role] ?? ucfirst($user_role);

// Determine latest School Year dynamically
$current_year = (int)date('Y');
$current_month = (int)date('n');
$latest_sy = ($current_month >= 6) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;

$res_sy = $conn->query("SELECT school_year FROM enrollments ORDER BY school_year DESC LIMIT 1");
if ($res_sy && $row_sy = $res_sy->fetch_assoc()) {
    $db_sy = $row_sy['school_year'];
    if (strcmp($db_sy, $latest_sy) > 0) {
        $latest_sy = $db_sy;
    }
}

// Compute days until next SY auto-switch (June 1)
$today = new DateTime();
$switch_yr = (int)$today->format('Y');
$switch_yr = (int)$today->format('n') >= 6 ? $switch_yr + 1 : $switch_yr;
$switch_date = new DateTime("{$switch_yr}-06-01");
$days_left = (int)$today->diff($switch_date)->days;
$next_sy = $switch_yr . '-' . ($switch_yr + 1);

// Build scopes for SQL queries
$school_scope = get_school_scope_clause();
$teacher_scope = get_teacher_scope_filter();
$teacher_enrolled_scope = $teacher_scope ? str_replace('l.', 'e.', $teacher_scope) : '';

$learner_school_scope = '';
if (user_is_scoped($user_role) && !empty($_SESSION['school_id'])) {
    $sid = (int)$_SESSION['school_id'];
    $learner_school_scope = " AND l.lrn IN (SELECT lrn FROM enrollments WHERE school_id = $sid AND is_deleted = 0 AND is_historical = 0)";
}

// 1. Fetch metric: Total Enrolled LWDs
$total_enrolled = 0;
$res = $conn->query("SELECT COUNT(DISTINCT e.lrn) as count FROM enrollments e WHERE e.school_year = '$latest_sy' AND e.is_deleted = 0 AND e.is_historical = 0" . $school_scope . $teacher_enrolled_scope);
if ($res) {
    $row = $res->fetch_assoc();
    $total_enrolled = (int)$row['count'];
}

// 2. Fetch metric: Graded vs Non-Graded segmentations
$placements = ['Graded' => 0, 'Non-Graded' => 0];
$res = $conn->query("
    SELECT placement_type, COUNT(DISTINCT lrn) as count 
    FROM enrollments e
    WHERE school_year = '$latest_sy' AND is_deleted = 0 AND is_historical = 0$school_scope$teacher_enrolled_scope
    GROUP BY placement_type
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (isset($placements[$row['placement_type']])) {
            $placements[$row['placement_type']] = (int)$row['count'];
        }
    }
}

// 3. Fetch list of schools for selecting (admin/office sees all, scoped users see their own)
$dash_schools = [];
if (user_is_scoped($user_role) && !empty($_SESSION['school_id'])) {
    $sid = (int)$_SESSION['school_id'];
    $res_s = $conn->query("
        SELECT id, school_name, district,
               (SELECT COUNT(DISTINCT lrn) FROM enrollments WHERE school_id = id AND is_deleted = 0 AND is_historical = 0 AND school_year = '$latest_sy') as active_enrollees
        FROM schools
        WHERE id = $sid AND is_deleted = 0
    ");
} else {
    $res_s = $conn->query("
        SELECT id, school_name, district,
               (SELECT COUNT(DISTINCT lrn) FROM enrollments WHERE school_id = id AND is_deleted = 0 AND is_historical = 0 AND school_year = '$latest_sy') as active_enrollees
        FROM schools
        WHERE is_deleted = 0
        ORDER BY school_name ASC
    ");
}
if ($res_s) {
    while ($row = $res_s->fetch_assoc()) {
        $dash_schools[] = $row;
    }
}

// 4. Fetch metric: Primary Disability Counts (only counting the primary disability for each learner)
$primary_disabilities = [];
if (sned_table_exists($conn, 'enrollment_disabilities')) {
    $res = $conn->query("
        SELECT ed.disability, ed.other_text, COUNT(DISTINCT e.lrn) as count
        FROM enrollments e
        JOIN enrollment_disabilities ed ON ed.enrollment_id = e.id
        WHERE e.school_year = '$latest_sy' 
          AND e.is_deleted = 0 
          AND e.is_historical = 0
          AND ed.is_primary = 1
          $school_scope$teacher_enrolled_scope
        GROUP BY ed.disability, ed.other_text
        ORDER BY count DESC, ed.disability ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $label = trim((string)$row['disability']);
            if ($label === 'Other' && !empty($row['other_text'])) {
                $label = 'Other: ' . trim((string)$row['other_text']);
            }
            $primary_disabilities[$label] = (int)$row['count'];
        }
    }
}

// 5. Fetch metric: Year-over-Year Trends (Last 3 school years)
$trends = [];
$res = $conn->query("
    SELECT school_year, 
           COUNT(DISTINCT lrn) as total_count,
           SUM(CASE WHEN placement_type = 'Graded' THEN 1 ELSE 0 END) as graded_count,
           SUM(CASE WHEN placement_type = 'Non-Graded' THEN 1 ELSE 0 END) as non_graded_count
    FROM enrollments e
    WHERE e.is_deleted = 0 AND e.is_historical = 0 $school_scope $teacher_enrolled_scope
    GROUP BY school_year
    ORDER BY school_year DESC
    LIMIT 3
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $trends[] = $row;
    }
}
$trends = array_reverse($trends);

// Fetch Recent Learner Intakes (Last 5 registered LWDs)
$recent_learners = [];
$teacher_recent_scope = $teacher_scope ? str_replace('l.', 'l.', $teacher_scope) : '';
$res = $conn->query("
    SELECT l.lrn, l.first_name, l.last_name, l.birthday, l.created_at,
           e.id AS enrollment_id,
           e.primary_diagnosis
    FROM learners l
    LEFT JOIN enrollments e ON e.id = (
        SELECT e2.id
        FROM enrollments e2
        WHERE e2.lrn = l.lrn AND e2.is_deleted = 0 AND e2.is_historical = 0
        ORDER BY e2.school_year DESC, e2.id DESC
        LIMIT 1
    )
    WHERE l.is_deleted = 0 $teacher_recent_scope $learner_school_scope
    ORDER BY l.created_at DESC 
    LIMIT 5
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $disability_rows = sned_get_enrollment_disabilities($conn, isset($row['enrollment_id']) ? (int)$row['enrollment_id'] : null);
        if (empty($disability_rows) && !empty($row['primary_diagnosis'])) {
            $disability_rows = sned_disability_rows_from_legacy_diagnosis($row['primary_diagnosis']);
        }
        $row['disability_rows'] = $disability_rows;
        $row['disability_summary'] = sned_disability_summary($disability_rows, $row['primary_diagnosis'] ?: 'Unassigned');
        $recent_learners[] = $row;
    }
}

// Fetch active announcements
$announcements = [];
$res_ann = $conn->query("SELECT title, content, type, created_at FROM announcements WHERE is_active = 1 ORDER BY FIELD(type, 'urgent', 'warning', 'info'), created_at DESC LIMIT 5");
if ($res_ann) {
    while ($row = $res_ann->fetch_assoc()) {
        $announcements[] = $row;
    }
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
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Dashboard - SNED Profiling</title>
    <style>
        .fs-7 { font-size: 0.75rem; }
        .badge-disability-highlight {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 10px;
            background: rgba(45, 106, 79, 0.08);
            border: 1px solid rgba(45, 106, 79, 0.2);
            color: #1B4332;
            border-radius: 50rem;
        }
        .privacy-modal .modal-content {
            border-radius: 1.5rem;
            border: none;
            box-shadow: 0 24px 70px rgba(27, 38, 49, 0.2);
        }
        .privacy-modal .modal-header {
            border-bottom: 1px solid var(--color-rule);
            padding: 1.25rem 1.5rem;
        }
        .privacy-modal .modal-body {
            padding: 1.5rem;
        }
        .privacy-modal .modal-footer {
            border-top: 1px solid var(--color-rule);
            padding: 1rem 1.5rem;
        }
        .privacy-notice-text {
            font-size: 0.92rem;
            line-height: 1.6;
            color: var(--color-ink);
        }
        .privacy-checkbox-label {
            font-size: 0.9rem;
            cursor: pointer;
        }
        .privacy-acknowledge-btn {
            min-height: 2.8rem;
            border-radius: 0.85rem;
            font-weight: 600;
        }
        .transition-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(45, 106, 79, 0.12);
            border-color: var(--sned-primary) !important;
        }
        .fs-8 { font-size: 0.7rem; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-up {
            opacity: 0;
            animation: fadeInUp 0.6s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }
        .stagger-1 { animation-delay: 0.1s; }
        .stagger-2 { animation-delay: 0.2s; }
        .stagger-3 { animation-delay: 0.3s; }
        .stagger-4 { animation-delay: 0.4s; }
        .stagger-5 { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>

        <div class="flex-grow-1 d-flex flex-column overflow-auto app-main">
            <header class="app-topbar d-flex align-items-center justify-content-between sticky-top">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                    <i class="bi bi-list"></i> Menu
                </button>
            </header>
            
            <main class="app-content container-fluid py-4">
                <?php
                $hour = (int) date('G');
                if ($hour < 12) {
                    $greeting = 'Good morning';
                } elseif ($hour < 18) {
                    $greeting = 'Good afternoon';
                } else {
                    $greeting = 'Good evening';
                }

                $dash_school_row = null;
                if (user_is_scoped($user_role) && !empty($_SESSION['school_id'])) {
                    $dash_school_stmt = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
                    $dash_school_stmt->bind_param("i", $_SESSION['school_id']);
                    $dash_school_stmt->execute();
                    $dash_school_row = $dash_school_stmt->get_result()->fetch_assoc();
                }
                ?>

                <!-- Hero Section Banner -->
                <div class="card border-0 rounded-4 overflow-hidden mb-4 shadow-sm animate-fade-up" style="background: linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%); color: white;">
                    <div class="card-body p-3 p-md-4">
                        <div class="row align-items-center">
                            <div class="col-md-9 col-12">
                                <span class="badge bg-white text-success fw-bold px-3 py-1 rounded-pill mb-2 text-uppercase tracking-wider fs-7">
                                    <?= htmlspecialchars($friendly_role) ?>
                                </span>
                                <h1 class="h3 fw-bold mb-1">
                                    <?= $greeting ?>, <?= htmlspecialchars($display_name) ?>!
                                </h1>
                                <?php if (user_is_scoped($user_role) && !empty($_SESSION['school_id']) && $dash_school_row): ?>
                                    <p class="lead mb-0 opacity-90 fs-6">
                                        Managing records for <strong class="text-white"><?= htmlspecialchars($dash_school_row['school_name']) ?></strong>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info countdown alert strip -->
                <div class="alert border-0 rounded-4 p-3 d-flex align-items-center justify-content-between mb-4 shadow-sm animate-fade-up stagger-1" style="background-color: var(--sned-dew);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-4 text-success"><i class="bi bi-calendar-event"></i></div>
                        <div>
                            <div class="fw-bold text-dark">School Year <?= htmlspecialchars($latest_sy) ?></div>
                            <p class="mb-0 small text-muted">
                                <?= $days_left ?> day<?= $days_left !== 1 ? 's' : '' ?> remaining until next SY (<?= htmlspecialchars($next_sy) ?>).
                            </p>
                        </div>
                    </div>
                    <div>
                        <a href="promotion.php" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold">
                            <i class="bi bi-arrow-right-circle me-1"></i> Promotion Page
                        </a>
                    </div>
                </div>

                <!-- Core Stats Panel (Single Card, Consolidated) -->
                <div class="card border-0 shadow-sm rounded-4 mb-4 animate-fade-up stagger-2">
                    <div class="card-body p-4">
                        <div class="row g-4 align-items-center">
                            <!-- Metric 1: Total Enrolled -->
                            <div class="col-md-4 border-end-md">
                                <div class="px-2">
                                    <span class="text-muted fw-semibold text-uppercase small tracking-wider">Active Enrollment</span>
                                    <div class="d-flex align-items-baseline gap-2 mt-1">
                                        <span class="display-5 fw-bold mb-0 text-dark"><?= $total_enrolled ?></span>
                                        <span class="text-muted small">students</span>
                                    </div>
                                    <p class="mb-0 small text-muted mt-2">
                                        <i class="bi bi-info-circle me-1"></i> Registered learners for this school year.
                                    </p>
                                </div>
                            </div>
                            <!-- Metric 2: Graded Program Enrollment -->
                            <div class="col-md-4 border-end-md">
                                <div class="px-2">
                                    <span class="text-muted fw-semibold text-uppercase small tracking-wider">Graded Program</span>
                                    <div class="d-flex align-items-baseline gap-2 mt-1">
                                        <span class="display-5 fw-bold mb-0 text-success"><?= $placements['Graded'] ?></span>
                                        <span class="text-muted small">learners</span>
                                    </div>
                                    <?php 
                                    $pct_g = $total_enrolled > 0 ? round(($placements['Graded'] / $total_enrolled) * 100) : 0; 
                                    ?>
                                    <div class="progress rounded-pill mt-2" style="height: 6px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pct_g ?>%" aria-valuenow="<?= $pct_g ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <p class="mb-0 small text-muted mt-2">
                                        <?= $pct_g ?>% of active enrollees are in the Graded track.
                                    </p>
                                </div>
                            </div>
                            <!-- Metric 3: Non-Graded Program Enrollment -->
                            <div class="col-md-4">
                                <div class="px-2">
                                    <span class="text-muted fw-semibold text-uppercase small tracking-wider">Non-Graded Program</span>
                                    <div class="d-flex align-items-baseline gap-2 mt-1">
                                        <span class="display-5 fw-bold mb-0 text-info"><?= $placements['Non-Graded'] ?></span>
                                        <span class="text-muted small">learners</span>
                                    </div>
                                    <?php 
                                    $pct_ng = $total_enrolled > 0 ? round(($placements['Non-Graded'] / $total_enrolled) * 100) : 0; 
                                    ?>
                                    <div class="progress rounded-pill mt-2" style="height: 6px;">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?= $pct_ng ?>%" aria-valuenow="<?= $pct_ng ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <p class="mb-0 small text-muted mt-2">
                                        <?= $pct_ng ?>% of active enrollees are in the Non-Graded track.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Layout Columns -->
                <div class="row g-4 mb-4">
                    <!-- Left: Recent Intakes & YoY Trends -->
                    <div class="col-lg-7 animate-fade-up stagger-3">
                        <!-- Recent Intakes -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2 text-success">
                                        <i class="bi bi-clock-history fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark">Recent Intakes</h5>
                                        <small class="text-muted">Latest registered student profiles</small>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead>
                                            <tr class="text-uppercase text-secondary fs-7 border-bottom">
                                                <th class="ps-0 pb-3">Learner</th>
                                                <th class="pb-3">LRN</th>
                                                <th class="pb-3">Primary Diagnosis</th>
                                                <th class="text-end pe-0 pb-3">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_learners)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-4">No learners found.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($recent_learners as $student): 
                                                    // Parse first primary or show summary
                                                    $primary_dis = 'Unassigned';
                                                    foreach ($student['disability_rows'] as $dr) {
                                                        if (!empty($dr['is_primary'])) {
                                                            $primary_dis = $dr['disability'];
                                                            if ($primary_dis === 'Other' && !empty($dr['other_text'])) {
                                                                $primary_dis = 'Other: ' . $dr['other_text'];
                                                            }
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <tr>
                                                        <td class="ps-0">
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></div>
                                                            <small class="text-muted small">Registered <?= date('M d, Y', strtotime($student['created_at'])) ?></small>
                                                        </td>
                                                        <td><code class="text-success fw-bold"><?= htmlspecialchars($student['lrn']) ?></code></td>
                                                        <td>
                                                            <span class="badge badge-disability-highlight"><?= htmlspecialchars($primary_dis) ?></span>
                                                        </td>
                                                        <td class="text-end pe-0">
                                                            <a href="viewLearner.php?id=<?= urlencode($student['lrn']) ?>" class="btn btn-sm btn-light border rounded-pill hover-success" aria-label="View learner profile">
                                                                <i class="bi bi-eye-fill text-success"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- YoY Trends Card -->
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2 text-success">
                                        <i class="bi bi-graph-up fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark">School Year Trends</h5>
                                        <small class="text-muted">Historical enrollment statistics comparison</small>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr class="text-uppercase text-secondary fs-7 border-bottom">
                                                <th class="ps-0 pb-2">School Year</th>
                                                <th class="pb-2">Enrolled Students</th>
                                                <th class="pb-2">Graded Program</th>
                                                <th class="pb-2">Non-Graded Program</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($trends)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-3">No historical data available.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($trends as $trend): ?>
                                                    <tr>
                                                        <td class="ps-0 fw-bold"><?= htmlspecialchars($trend['school_year']) ?></td>
                                                        <td>
                                                            <span class="fw-semibold text-dark"><?= $trend['total_count'] ?> LWDs</span>
                                                        </td>
                                                        <td><?= (int)$trend['graded_count'] ?></td>
                                                        <td><?= (int)$trend['non_graded_count'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Primary Disabilities & Announcements -->
                    <div class="col-lg-5 animate-fade-up stagger-4">
                        <!-- Primary Disabilities count -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2 text-success">
                                        <i class="bi bi-clipboard2-pulse fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark">Primary Disabilities</h5>
                                        <small class="text-muted">Learners grouped by primary diagnosis</small>
                                    </div>
                                </div>

                                <div class="d-flex flex-column gap-3 mt-3">
                                    <?php if (empty($primary_disabilities)): ?>
                                        <div class="text-center text-muted py-4">No records found.</div>
                                    <?php else: 
                                        $colors = ['#2D6A4F', '#40916C', '#52B788', '#457B9D', '#74C69D', '#8FA898'];
                                        $c_idx = 0;
                                        foreach ($primary_disabilities as $label => $count): 
                                            $col = $colors[$c_idx % count($colors)];
                                            $c_idx++;
                                            $pct = $total_enrolled > 0 ? round(($count / $total_enrolled) * 100) : 0;
                                        ?>
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
                                                    <span class="fw-semibold text-secondary small"><?= htmlspecialchars($label) ?></span>
                                                    <span class="fw-bold text-dark small text-nowrap"><?= $count ?> (<?= $pct ?>%)</span>
                                                </div>
                                                <div class="progress rounded-pill" style="height: 6px;">
                                                    <div class="progress-bar rounded-pill" role="progressbar" style="width: <?= $pct ?>%; background-color: <?= $col ?>;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Active Announcements -->
                        <?php if (!empty($announcements)): ?>
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="bg-success bg-opacity-10 rounded-3 p-2 text-success">
                                        <i class="bi bi-megaphone fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark">Announcements</h5>
                                        <small class="text-muted">Updates from Division Admin</small>
                                    </div>
                                </div>

                                <div class="d-flex flex-column gap-2 mt-3">
                                    <?php foreach ($announcements as $ann):
                                        $ann_styles = [
                                            'info'    => ['alert-primary', 'bi-info-circle-fill', 'text-primary'],
                                            'warning' => ['alert-warning', 'bi-exclamation-triangle-fill', 'text-warning'],
                                            'urgent'  => ['alert-danger', 'bi-exclamation-octagon-fill', 'text-danger'],
                                        ];
                                        $as = $ann_styles[$ann['type']] ?? $ann_styles['info'];
                                    ?>
                                    <div class="alert <?= $as[0] ?> border-0 rounded-4 p-3 d-flex align-items-start gap-2 mb-0 shadow-sm">
                                        <div class="fs-5 <?= $as[2] ?>"><i class="bi <?= $as[1] ?>"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold small text-dark"><?= htmlspecialchars($ann['title']) ?></div>
                                            <p class="mb-0 x-small text-muted"><?= nl2br(htmlspecialchars($ann['content'])) ?></p>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- School Directory Card -->
                <?php if (!empty($dash_schools)): ?>
                <div class="card border-0 shadow-sm rounded-4 mt-4 animate-fade-up stagger-5">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-success bg-opacity-10 rounded-3 p-2 text-success">
                                <i class="bi bi-building fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">School Directory</h5>
                                <small class="text-muted">Select a school to view its detailed profile and enrollment breakdown</small>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <?php foreach ($dash_schools as $school): ?>
                                <div class="col-xl-3 col-lg-4 col-md-6">
                                    <a href="school_profile.php?id=<?= $school['id'] ?>" class="text-decoration-none text-dark card h-100 border rounded-3 p-3 transition-hover" style="background-color: var(--sned-cloud); border-color: var(--color-rule) !important; transition: all 0.2s ease;">
                                        <div class="d-flex flex-column h-100 justify-content-between gap-2">
                                            <div class="min-w-0">
                                                <div class="fw-bold text-truncate text-success" style="font-size: 0.95rem;" title="<?= htmlspecialchars($school['school_name']) ?>"><?= htmlspecialchars($school['school_name']) ?></div>
                                                <div class="text-muted text-truncate x-small mt-1" style="font-size: 0.8rem;"><?= htmlspecialchars($school['district'] ?? 'District') ?></div>
                                            </div>
                                            <div class="mt-1 flex-shrink-0">
                                                <span class="badge bg-success rounded-pill px-2 py-1 fs-8 fw-bold">
                                                    <?= $school['active_enrollees'] ?> LWDs
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Privacy Notice Modal -->
    <div class="modal fade privacy-modal" id="privacyNoticeModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="privacyNoticeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="privacyNoticeModalLabel">
                        <i class="bi bi-shield-lock-fill text-primary me-2"></i>Data Privacy Notice
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="privacy-notice-text">
                        <p>In compliance with the requirements of the <strong>Data Privacy Act of 2012</strong>, the <strong>City Schools Division of Valenzuela</strong> commits to ensure that all personal information obtained will be secured and remain confidential.</p>
                        <p>Collected personal information will only be utilized for purposes of documentation, research, and facilitation of special education services. The personal information shall not be shared or disclosed with other parties without consent unless required by law.</p>
                    </div>
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="privacyAcknowledgeCheck">
                        <label class="form-check-label privacy-checkbox-label fw-semibold" for="privacyAcknowledgeCheck">
                            I acknowledge that I have read and understood this notice, and consent to the use of my personal information.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary privacy-acknowledge-btn w-100" id="privacyAcknowledgeBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i> Acknowledge & Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        (function() {
            const privacyModal = new bootstrap.Modal(document.getElementById('privacyNoticeModal'));
            const acknowledgeCheck = document.getElementById('privacyAcknowledgeCheck');
            const acknowledgeBtn = document.getElementById('privacyAcknowledgeBtn');

            acknowledgeCheck.addEventListener('change', function() {
                acknowledgeBtn.disabled = !this.checked;
            });

            acknowledgeBtn.addEventListener('click', function() {
                if (acknowledgeCheck.checked) {
                    privacyModal.hide();
                    localStorage.setItem('privacyAcknowledged', '1');
                }
            });

            if (!localStorage.getItem('privacyAcknowledged')) {
                privacyModal.show();
            }
        })();
    </script>
</body>
</html>
