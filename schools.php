<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';

if (!user_is_admin($user_role)) {
    header('Location: 403.php');
    exit();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }

    if (user_is_admin($user_role)) {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $school_name = trim($_POST['school_name'] ?? '');
            $address = trim($_POST['address'] ?? '') ?: null;
            $district = trim($_POST['district'] ?? '');
            $unit = trim($_POST['unit'] ?? '') ?: null;
            $grade_levels = implode(',', $_POST['grade_levels'] ?? []);
            $principal_name = trim($_POST['principal_name'] ?? '');

            if ($school_name !== '' && $district !== '') {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO schools (school_name, address, district, unit, grade_levels_offered) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssss", $school_name, $address, $district, $unit, $grade_levels);
                    $stmt->execute();
                    $new_id = $conn->insert_id;

                    if ($principal_name !== '') {
                        $stmt_p = $conn->prepare("INSERT INTO school_principals (school_id, principal_name, effective_from, recorded_by) VALUES (?, ?, CURDATE(), ?)");
                        $stmt_p->bind_param("isi", $new_id, $principal_name, $_SESSION['user_id']);
                        $stmt_p->execute();
                    }

                    $conn->commit();
                    log_transaction($conn, $_SESSION['user_id'], 'create', 'schools', $new_id, "Registered new school: $school_name ($district)" . ($principal_name ? " | Principal: $principal_name" : ''));
                    $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='bi bi-check-circle-fill me-2'></i> School added successfully.
                        <div class='mt-2'>
                            <strong>Would you like to assign a coordinator?</strong>
                            <a href='adminCreateUser.php?role=school_coordinator&school_id=" . $new_id . "' class='btn btn-success btn-sm rounded-pill ms-2 text-white fw-bold shadow-sm'><i class='bi bi-person-plus-fill me-1'></i> Create Coordinator Account</a>
                        </div>
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Failed to add school: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> School name and district are required.</div>";
            }
        } elseif ($action === 'update') {
            $school_id = (int)($_POST['school_id'] ?? 0);
            $school_name = trim($_POST['school_name'] ?? '');
            $address = trim($_POST['address'] ?? '') ?: null;
            $district = trim($_POST['district'] ?? '');
            $unit = trim($_POST['unit'] ?? '') ?: null;
            $grade_levels = implode(',', $_POST['grade_levels'] ?? []);

            if ($school_id > 0 && $school_name !== '' && $district !== '') {
                $stmt = $conn->prepare("UPDATE schools SET school_name = ?, address = ?, district = ?, unit = ?, grade_levels_offered = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $school_name, $address, $district, $unit, $grade_levels, $school_id);
                if ($stmt->execute()) {
                    log_transaction($conn, $_SESSION['user_id'], 'update', 'schools', $school_id, "Updated school ID $school_id: $school_name ($district)");
                    $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='bi bi-check-circle-fill me-2'></i> School updated successfully.
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
                } else {
                    $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Failed to update school.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> School name and district are required.</div>";
            }
        } elseif ($action === 'update_principal') {
            $school_id = (int)($_POST['school_id'] ?? 0);
            $principal_name = trim($_POST['principal_name'] ?? '');
            $effective_from = trim($_POST['effective_from'] ?? date('Y-m-d'));

            if ($school_id > 0 && $principal_name !== '') {
                // Close any open-ended principal tenure
                $stmt_close = $conn->prepare("UPDATE school_principals SET effective_until = ? WHERE school_id = ? AND effective_until IS NULL");
                $yesterday = date('Y-m-d', strtotime($effective_from . ' -1 day'));
                $stmt_close->bind_param("si", $yesterday, $school_id);
                $stmt_close->execute();

                $stmt = $conn->prepare("INSERT INTO school_principals (school_id, principal_name, effective_from, recorded_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("issi", $school_id, $principal_name, $effective_from, $_SESSION['user_id']);
                if ($stmt->execute()) {
                    log_transaction($conn, $_SESSION['user_id'], 'update', 'schools', $school_id, "Updated principal for school ID $school_id: $principal_name");
                    $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='bi bi-check-circle-fill me-2'></i> Principal updated successfully.
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
                } else {
                    $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Failed to update principal.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Principal name is required.</div>";
            }
        } elseif ($action === 'delete') {
            $school_id = (int)($_POST['school_id'] ?? 0);

            if ($school_id > 0) {
                $stmt_chk = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE school_id = ? AND is_deleted = 0");
                $stmt_chk->bind_param("i", $school_id);
                $stmt_chk->execute();
                $res_chk = $stmt_chk->get_result();
                $enroll_count = 0;
                if ($res_chk) {
                    $enroll_count = $res_chk->fetch_assoc()['count'];
                }

                if ($enroll_count > 0) {
                    $message = "<div class='alert alert-danger alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='bi bi-exclamation-octagon-fill me-2'></i> <strong>Cannot Archive School</strong>: There are $enroll_count active student enrollments at this school. Reassign or transfer them first — <a href='learners.php?school_id=$school_id' class='alert-link'>View enrollees</a>.
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
                } else {
                    $stmt = $conn->prepare("UPDATE schools SET is_deleted = 1 WHERE id = ?");
                    $stmt->bind_param("i", $school_id);
                    if ($stmt->execute()) {
                        log_transaction($conn, $_SESSION['user_id'], 'archive', 'schools', $school_id, "Archived school ID: $school_id");
                        $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                            <i class='bi bi-check-circle-fill me-2'></i> School archived successfully.
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                        </div>";
                    } else {
                        $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Failed to archive school.</div>";
                    }
                }
            }
        }
    } else {
        $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-shield-slash-fill me-2'></i> Unauthorized action.</div>";
    }
}

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

$schools = [];
$user_school_id = $_SESSION['school_id'] ?? null;
if (user_is_scoped($user_role) && $user_school_id) {
    $res_stmt = $conn->prepare("
        SELECT s.id, s.school_name, s.address, s.district, s.unit, s.grade_levels_offered, s.created_at,
               (SELECT COUNT(DISTINCT e.lrn) FROM enrollments e WHERE e.school_id = s.id AND e.is_deleted = 0 AND e.is_historical = 0 AND e.school_year = ?) as active_enrollees
        FROM schools s
        WHERE s.id = ? AND s.is_deleted = 0
        ORDER BY s.school_name ASC
    ");
    $res_stmt->bind_param("si", $latest_sy, $user_school_id);
} else {
    $res_stmt = $conn->prepare("
        SELECT s.id, s.school_name, s.address, s.district, s.unit, s.grade_levels_offered, s.created_at,
               (SELECT COUNT(DISTINCT e.lrn) FROM enrollments e WHERE e.school_id = s.id AND e.is_deleted = 0 AND e.is_historical = 0 AND e.school_year = ?) as active_enrollees
        FROM schools s
        WHERE s.is_deleted = 0
        ORDER BY s.school_name ASC
    ");
    $res_stmt->bind_param("s", $latest_sy);
}
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['current_principal'] = get_current_principal($conn, (int)$row['id']);
        $schools[] = $row;
    }
}

$district_count = count(array_unique(array_filter(array_column($schools, 'district'))));
$active_enrollee_total = array_sum(array_map(function($school) {
    return (int)($school['active_enrollees'] ?? 0);
}, $schools));

function format_grade_levels($str) {
    if (!$str) return '<span class="text-muted">—</span>';
    $parts = array_map('trim', explode(',', $str));
    $labels = [
        'Kinder'                => ['label' => 'Kinder',                'class' => 'grade-badge-kinder'],
        'Grade 1-6'             => ['label' => 'Grade School (1-6)',    'class' => 'grade-badge-elem'],
        'Grade 7-10'            => ['label' => 'Junior High (7-10)',    'class' => 'grade-badge-jhs'],
        'Grade 11-12'           => ['label' => 'Senior High (11-12)',   'class' => 'grade-badge-shs'],
        'Kinder - Grade School' => ['label' => 'Kinder - Grade School', 'class' => 'grade-badge-elem'],
        'Junior High - Senior High' => ['label' => 'Junior High - Senior High', 'class' => 'grade-badge-jhs'],
    ];
    $html = '<div class="d-flex flex-wrap gap-1">';
    foreach ($parts as $p) {
        $info = $labels[$p] ?? ['label' => $p, 'class' => ''];
        $html .= '<span class="badge rounded-pill px-2 py-1 fw-normal ' . $info['class'] . '" style="font-size:0.75rem;">' . htmlspecialchars($info['label']) . '</span>';
    }
    $html .= '</div>';
    return $html;
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
    <title>School Directory | SNED Profiling</title>
    <style>
        /* Hallmark · Directory · system-managed (DESIGN.md)
         * pre-emit critique: P4 H4 E4 S4 R5 V4
         */
        body { background: var(--color-canvas); }

        .grade-badge-kinder  { background: var(--color-grade-kinder-bg); color: var(--color-grade-kinder-ink); }
        .grade-badge-elem    { background: var(--color-grade-elem-bg);   color: var(--color-grade-elem-ink); }
        .grade-badge-jhs     { background: var(--color-grade-jhs-bg);    color: var(--color-grade-jhs-ink); }
        .grade-badge-shs     { background: var(--color-grade-shs-bg);    color: var(--color-grade-shs-ink); }
        .school-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: var(--space-sm);
            margin-bottom: var(--space-lg);
        }
        .school-summary-item {
            background: var(--color-paper);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            padding: var(--space-md);
        }
        .school-directory-command {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--space-lg);
            margin-bottom: var(--space-lg);
        }
        .school-directory-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: var(--space-xs);
            flex-shrink: 0;
        }
        .school-summary-item .metric-value {
            margin-top: var(--space-xs);
        }
        .row-actions-panel {
            display: inline-flex;
            flex-direction: column;
            gap: 2px;
            border: 1px solid var(--color-rule);
            border-radius: 10px;
            padding: 6px 4px;
            background: var(--color-paper);
        }
        .btn-row-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            padding: 4px 8px;
            font-weight: 550;
            text-decoration: none;
            white-space: nowrap;
            background: transparent;
            transition: background var(--dur-short) var(--ease-out);
        }
        .btn-row-action:hover { background: var(--color-paper-3); }
        .btn-row-action:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; }

        .btn-row-view      { color: #1A5276; }
        .btn-row-edit      { color: #1E5C39; }
        .btn-row-archive   { color: #7A2028; }
        .btn-row-secondary { color: #495057; }

        @media (max-width: 1200px) {
            .col-hide-lg { display: none !important; }
        }
        .school-search-panel {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            padding: var(--space-md);
            margin-bottom: var(--space-md);
        }
        .school-search-panel .search-field {
            width: min(420px, 100%);
        }

        @media (prefers-reduced-motion: reduce) {
            .animate__animated { animation: none !important; opacity: 1 !important; }
        }
        .search-field {
            position: relative;
        }
        .search-field .bi-search {
            position: absolute;
            left: var(--space-md);
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-muted);
        }
        .search-field input {
            padding-left: var(--space-2xl) !important;
        }

        /* ── Animation Styles ────────────────────────────────────────── */
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

        /* School Card Redesign */
        .school-card {
            border: 1px solid var(--color-rule-light) !important;
            transition: transform 0.2s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.2s ease, border-color 0.2s ease;
            background: var(--color-paper);
        }
        .school-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05) !important;
            border-color: var(--color-primary-light) !important;
        }
        .school-card-wrapper {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .school-name-title {
            color: var(--color-ink-dark);
            transition: color 0.15s ease;
        }
        .school-card:hover .school-name-title {
            color: var(--color-primary);
        }
        .dropdown-menu {
            border: 1px solid var(--color-rule-light) !important;
        }

        @media (max-width: 768px) {
            .school-directory-command {
                display: block;
            }
            .school-directory-actions {
                justify-content: flex-start;
                margin-top: var(--space-md);
            }
            .school-search-panel {
                display: block;
            }
            .school-search-panel .search-field {
                width: 100%;
                margin-top: var(--space-md);
            }
        }
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

            <main class="app-content">
                <div class="page-toolbar">
                    <div>
                        <h1 class="page-title">School directory</h1>
                        <p class="page-subtitle">Manage municipal schools, grade levels, principals, and active SNED enrollment totals for SY <?= htmlspecialchars($latest_sy) ?>.</p>
                    </div>
                    <?php if (user_is_admin($user_role)): ?>
                        <div class="toolbar-actions">
                            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#addSchoolModal">
                                <i class="bi bi-plus-circle-fill me-1"></i> Add school
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?= $message ?>


                <div class="school-summary">
                    <div class="school-summary-item animate-fade-up">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <p class="metric-label">Schools</p>
                                <p class="metric-value"><?= count($schools) ?></p>
                            </div>
                            <span class="icon-shell"><i class="bi bi-building"></i></span>
                        </div>
                    </div>
                    <div class="school-summary-item animate-fade-up stagger-1">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <p class="metric-label">Districts</p>
                                <p class="metric-value"><?= $district_count ?></p>
                            </div>
                            <span class="icon-shell"><i class="bi bi-diagram-3"></i></span>
                        </div>
                    </div>
                    <div class="school-summary-item animate-fade-up stagger-2">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <p class="metric-label">Active enrollees</p>
                                <p class="metric-value"><?= $active_enrollee_total ?></p>
                            </div>
                            <span class="icon-shell"><i class="bi bi-people-fill"></i></span>
                        </div>
                    </div>
                </div>

                 <div class="workbench-panel school-search-panel animate-fade-up stagger-1">
                    <div>
                        <div class="fw-bold">Directory search</div>
                        <div class="text-muted small">Filter by school name or district without leaving the page.</div>
                    </div>
                    <div class="search-field">
                        <i class="bi bi-search"></i>
                        <input type="text" id="schoolSearchInput" class="form-control rounded-pill pe-5" placeholder="Search school or district">
                        <button type="button" id="clearSearchBtn" class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-secondary d-none border-0 bg-transparent" style="text-decoration: none;">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 animate-fade-up stagger-2" id="schoolsCardGrid">
                    <?php if (empty($schools)): ?>
                        <div class="col-12 text-center text-muted py-5 bg-white rounded-4 shadow-sm border">
                            <i class="bi bi-building fs-1 d-block mb-2 text-secondary"></i>
                            No schools registered in the system.
                        </div>
                    <?php else: ?>
                        <div id="noResultsCard" class="col-12 text-center text-muted py-5 bg-white rounded-4 shadow-sm border d-none">
                            <i class="bi bi-search fs-1 d-block mb-2 text-secondary"></i>
                            No schools match your search.
                        </div>
                        <?php foreach ($schools as $sch): ?>
                            <div class="col school-card-wrapper" data-school-name="<?= htmlspecialchars(strtolower($sch['school_name'])) ?>" data-school-district="<?= htmlspecialchars(strtolower($sch['district'])) ?>">
                                <div class="card h-100 border-0 shadow-sm rounded-4 position-relative school-card transition-hover">
                                    
                                    <!-- Action Ellipsis / Dropdown -->
                                    <?php if (user_is_admin($user_role)): ?>
                                        <div class="position-absolute end-0 top-0 mt-3 me-3" style="z-index: 10;">
                                            <div class="dropdown">
                                                <button class="btn btn-link text-secondary p-0 border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="outline: none; box-shadow: none;">
                                                    <i class="bi bi-three-dots-vertical fs-5"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm rounded-3">
                                                    <li>
                                                        <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2 small fw-semibold" onclick="editSchool(<?= $sch['id'] ?>, '<?= htmlspecialchars($sch['school_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($sch['address'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($sch['district'], ENT_QUOTES) ?>', '<?= htmlspecialchars($sch['unit'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($sch['grade_levels_offered'] ?? '', ENT_QUOTES) ?>')">
                                                            <i class="bi bi-pencil-square text-primary"></i> Edit record
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2 small fw-semibold" onclick="updatePrincipal(<?= $sch['id'] ?>, '<?= htmlspecialchars($sch['current_principal'] ?? '', ENT_QUOTES) ?>')">
                                                            <i class="bi bi-person-badge text-success"></i> Update Principal
                                                        </button>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2 small fw-semibold text-danger" onclick="confirmDelete(<?= $sch['id'] ?>, '<?= htmlspecialchars($sch['school_name'], ENT_QUOTES) ?>')">
                                                            <i class="bi bi-archive-fill"></i> Archive
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Clickable Card Body -->
                                    <a href="school_profile.php?id=<?= $sch['id'] ?>" class="card-body p-4 text-decoration-none text-dark d-flex flex-column h-100">
                                        <div class="mb-3">
                                            <span class="badge bg-light text-muted border font-monospace mb-2" style="font-size: 0.7rem;">
                                                SCH-<?= str_pad($sch['id'], 3, '0', STR_PAD_LEFT) ?>
                                            </span>
                                            <h5 class="card-title fw-bold text-dark mb-1 school-name-title"><?= htmlspecialchars($sch['school_name']) ?></h5>
                                            <p class="text-secondary small mb-0"><i class="bi bi-geo-alt-fill me-1 text-primary"></i> <?= htmlspecialchars($sch['district']) ?><?= !empty($sch['unit']) ? ' / ' . htmlspecialchars($sch['unit']) : '' ?></p>
                                        </div>

                                        <div class="mt-auto border-top pt-3">
                                            <div class="row g-2 text-secondary small mb-3">
                                                <div class="col-6">
                                                    <div class="text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.05em;">Principal</div>
                                                    <div class="text-truncate fw-semibold text-dark mt-1" style="font-size: 0.8rem;"><?= htmlspecialchars($sch['current_principal'] ?? '—') ?></div>
                                                </div>
                                                <div class="col-6 text-end">
                                                    <div class="text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.05em;">Active Enrollees</div>
                                                    <div class="fw-bold text-primary mt-1" style="font-size: 0.85rem;"><?= $sch['active_enrollees'] ?> students</div>
                                                </div>
                                            </div>

                                            <div>
                                                <div class="text-uppercase fw-bold text-muted mb-2" style="font-size: 0.65rem; letter-spacing: 0.05em;">Offerings</div>
                                                <?= format_grade_levels($sch['grade_levels_offered'] ?? '') ?>
                                            </div>
                                        </div>
                                    </a>
                                    
                                    <div class="card-footer bg-white border-0 px-4 pb-3 pt-0 text-muted small" style="font-size: 0.7rem;">
                                        Registered: <?= date('M d, Y', strtotime($sch['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Add School Modal -->
    <div class="modal fade" id="addSchoolModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4" aria-labelledby="addSchoolModalLabel">
                <div class="modal-header border-0 pb-0 pt-3 px-4">
                    <h5 class="fw-bold" id="addSchoolModalLabel"><i class="bi bi-plus-circle-fill text-primary me-1"></i> Add New School</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body px-4 pb-4">
                        <div class="mb-3">
                            <label class="form-label fw-medium small">School Name *</label>
                            <input type="text" name="school_name" class="form-control rounded-3" maxlength="150" placeholder="e.g. Maysan National High School" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium small">School Address</label>
                            <input type="text" name="address" class="form-control rounded-3" maxlength="255" placeholder="e.g. Maysan Road, Maysan, Valenzuela City">
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-medium small">Grade Levels Offered</label>
                            <p class="text-muted small mb-2">Select one school level group available at this school.</p>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="grade_levels[]" value="Kinder - Grade School" id="addElem" onchange="handleGradeLevelChange('add', this.id)">
                                    <label class="form-check-label fw-medium" for="addElem">Kinder - Grade School</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="grade_levels[]" value="Junior High - Senior High" id="addJhsShs" onchange="handleGradeLevelChange('add', this.id)">
                                    <label class="form-check-label fw-medium" for="addJhsShs">Junior High - Senior High</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium small">District / Zone *</label>
                            <select name="district" id="addDistrict" class="form-select rounded-3" required disabled onchange="updateUnitOptions('add')">
                                <option value="">Select District</option>
                            </select>
                        </div>
                        <div class="mb-3" id="addUnitWrap" style="display:none;">
                            <label class="form-label fw-medium small">Unit</label>
                            <select name="unit" id="addUnit" class="form-select rounded-3" disabled>
                                <option value="">Select Unit</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-medium small">Principal Name</label>
                            <input type="text" name="principal_name" class="form-control rounded-3" maxlength="200" placeholder="e.g. Dr. Juan Dela Cruz">
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-3">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-plus-lg me-1"></i> Save School</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit School Modal -->
    <div class="modal fade" id="editSchoolModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4" aria-labelledby="editSchoolModalLabel">
                <div class="modal-header border-0 pb-0 pt-3 px-4">
                    <h5 class="fw-bold" id="editSchoolModalLabel"><i class="bi bi-pencil-square text-primary me-1"></i> Edit School</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="school_id" id="editSchoolId">
                    <div class="modal-body px-4 pb-4">
                        <div class="mb-3">
                            <label class="form-label fw-medium small">School Name *</label>
                            <input type="text" name="school_name" id="editSchoolName" class="form-control rounded-3" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium small">School Address</label>
                            <input type="text" name="address" id="editSchoolAddress" class="form-control rounded-3" maxlength="255">
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-medium small">Grade Levels Offered</label>
                            <p class="text-muted small mb-2">Select one school level group available at this school.</p>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="grade_levels[]" value="Kinder - Grade School" id="editElem" onchange="handleGradeLevelChange('edit', this.id)">
                                    <label class="form-check-label fw-medium" for="editElem">Kinder - Grade School</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="grade_levels[]" value="Junior High - Senior High" id="editJhsShs" onchange="handleGradeLevelChange('edit', this.id)">
                                    <label class="form-check-label fw-medium" for="editJhsShs">Junior High - Senior High</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-medium small">District / Zone *</label>
                            <select name="district" id="editDistrict" class="form-select rounded-3" required onchange="updateUnitOptions('edit')">
                                <option value="">Select District</option>
                            </select>
                        </div>
                        <div class="mb-3" id="editUnitWrap" style="display:none;">
                            <label class="form-label fw-medium small">Unit</label>
                            <select name="unit" id="editUnit" class="form-select rounded-3" disabled>
                                <option value="">Select Unit</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-3">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Principal Modal -->
    <div class="modal fade" id="updatePrincipalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4" aria-labelledby="updatePrincipalModalLabel">
                <div class="modal-header border-0 pb-0 pt-3 px-4">
                    <h5 class="fw-bold" id="updatePrincipalModalLabel"><i class="bi bi-person-badge text-primary me-1"></i> Update Principal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_principal">
                    <input type="hidden" name="school_id" id="principalSchoolId">
                    <div class="modal-body px-4 pb-4">
                        <div class="mb-3">
                            <label class="form-label fw-medium small">Principal Name *</label>
                            <input type="text" name="principal_name" id="principalName" class="form-control rounded-3" maxlength="255" placeholder="e.g. Dr. Maria Santos" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-medium small">Effective Date *</label>
                            <input type="date" name="effective_from" id="principalEffectiveFrom" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-3">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-check-lg me-1"></i> Save Principal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 text-center" aria-labelledby="confirmDeleteModalLabel">
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="school_id" id="deleteSchoolId">
                    <div class="modal-body p-4">
                        <div class="display-2 text-warning mb-3"><i class="bi bi-archive-fill"></i></div>
                        <h3 class="fw-bold" id="confirmDeleteModalLabel">Archive School?</h3>
                        <p class="text-muted mb-4">You are about to archive <strong id="deleteSchoolName"></strong>. Active enrollments must be reassigned first.</p>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" id="deleteSubmitBtn" class="btn btn-warning w-100 rounded-pill py-2 fw-bold shadow">Archive School</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        // Canonical district/unit options.
        const districtOptions = {
            elementary: [
                { value: 'Central District', label: 'Central District' },
                { value: 'North District', label: 'North District' },
                { value: 'East District', label: 'East District' },
                { value: 'South District', label: 'South District' }
            ],
            secondary: [
                { value: 'Congressional I', label: 'Congressional I' },
                { value: 'Congressional II', label: 'Congressional II' }
            ]
        };

        const unitOptions = {
            'Central District': [{ value: 'Unit II', label: 'Unit II' }],
            'North District': [{ value: 'Unit II', label: 'Unit II' }],
            'East District': [{ value: 'Unit II', label: 'Unit II' }],
            'South District': [{ value: 'Unit II', label: 'Unit II' }],
            'Congressional I': [{ value: 'Unit I', label: 'Unit I' }, { value: 'Unit II', label: 'Unit II' }],
            'Congressional II': [{ value: 'Unit I', label: 'Unit I' }, { value: 'Unit II', label: 'Unit II' }]
        };

        function buildDistrictOptions(type, currentDistrict = '') {
            const options = districtOptions[type] ? districtOptions[type].map(opt => ({ ...opt })) : [];
            if (currentDistrict && !options.some(opt => opt.value === currentDistrict)) {
                options.unshift({ value: currentDistrict, label: currentDistrict });
            }
            return options;
        }

        function buildUnitOptions(district, currentUnit = '') {
            const options = unitOptions[district] ? unitOptions[district].map(opt => ({ ...opt })) : [];
            if (currentUnit && !options.some(opt => opt.value === currentUnit)) {
                options.unshift({ value: currentUnit, label: currentUnit });
            }
            return options;
        }

        function getSelectedGradeInput(prefix) {
            const elem = document.getElementById(prefix + 'Elem');
            const jhsShs = document.getElementById(prefix + 'JhsShs');
            if (jhsShs && jhsShs.checked) return jhsShs;
            if (elem && elem.checked) return elem;
            return null;
        }

        function handleGradeLevelChange(prefix, selectedId) {
            const otherId = selectedId === prefix + 'Elem' ? prefix + 'JhsShs' : prefix + 'Elem';
            const selected = document.getElementById(selectedId);
            const other = document.getElementById(otherId);
            if (selected && selected.checked && other) {
                other.checked = false;
            }
            updateSchoolType(prefix);
        }

        function isSecondary(prefix) {
            const selected = getSelectedGradeInput(prefix);
            return selected ? selected.id === prefix + 'JhsShs' : false;
        }

        function anyGradeChecked(prefix) {
            return !!getSelectedGradeInput(prefix);
        }

        function updateSchoolType(prefix, currentDistrict = '', currentUnit = '') {
            const districtSelect = document.getElementById(prefix + 'District');
            if (!districtSelect) return;

            const hasGrade = anyGradeChecked(prefix);

            // Add-school stays gated by grade choice; edit-school stays editable.
            districtSelect.disabled = (prefix === 'add') ? !hasGrade : false;
            if (!hasGrade && prefix === 'add') {
                districtSelect.value = '';
                const unitWrap = document.getElementById(prefix + 'UnitWrap');
                const unitSelect = document.getElementById(prefix + 'Unit');
                if (unitWrap) unitWrap.style.display = 'none';
                if (unitSelect) { unitSelect.value = ''; unitSelect.disabled = true; }
                return;
            }

            const type = isSecondary(prefix) ? 'secondary' : 'elementary';
            const selectedDistrict = currentDistrict || districtSelect.value;
            const options = buildDistrictOptions(type, selectedDistrict);

            districtSelect.innerHTML = '<option value="">Select District</option>';
            options.forEach(function(opt) {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                districtSelect.appendChild(option);
            });

            // Restore previous value if still valid
            if (selectedDistrict) {
                districtSelect.value = selectedDistrict;
            } else {
                districtSelect.value = '';
            }

            updateUnitOptions(prefix, currentUnit);
        }

        function updateUnitOptions(prefix, currentUnit = '') {
            const districtSelect = document.getElementById(prefix + 'District');
            const unitWrap = document.getElementById(prefix + 'UnitWrap');
            const unitSelect = document.getElementById(prefix + 'Unit');
            if (!districtSelect || !unitWrap || !unitSelect) return;

            const district = districtSelect.value;
            const units = buildUnitOptions(district, currentUnit || unitSelect.value);

            if (units && units.length > 0) {
                unitWrap.style.display = '';
                unitSelect.disabled = false;
                unitSelect.innerHTML = '<option value="">Select Unit</option>';
                units.forEach(function(opt) {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.label;
                    unitSelect.appendChild(option);
                });
            } else {
                unitWrap.style.display = 'none';
                unitSelect.disabled = true;
                unitSelect.value = '';
            }
        }

        // Initialize Add modal district on show
        document.getElementById('addSchoolModal').addEventListener('show.bs.modal', function() {
            updateSchoolType('add');
        });

        // Real-time client-side search/filter
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('schoolSearchInput');
            const clearBtn = document.getElementById('clearSearchBtn');
            const noResultsCard = document.getElementById('noResultsCard');
            const schoolCards = document.querySelectorAll('.school-card-wrapper');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const query = searchInput.value.toLowerCase().trim();
                    
                    if (query !== '') {
                        clearBtn.classList.remove('d-none');
                    } else {
                        clearBtn.classList.add('d-none');
                    }

                    let visibleCount = 0;
                    schoolCards.forEach(function(wrapper) {
                        const schoolName = wrapper.getAttribute('data-school-name') || '';
                        const district = wrapper.getAttribute('data-school-district') || '';

                        if (schoolName.includes(query) || district.includes(query)) {
                            wrapper.style.display = '';
                            visibleCount++;
                        } else {
                            wrapper.style.display = 'none';
                        }
                    });

                    if (visibleCount === 0 && schoolCards.length > 0) {
                        if (noResultsCard) noResultsCard.classList.remove('d-none');
                    } else {
                        if (noResultsCard) noResultsCard.classList.add('d-none');
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    clearBtn.classList.add('d-none');
                    schoolCards.forEach(function(wrapper) {
                        wrapper.style.display = '';
                    });
                    if (noResultsCard) {
                        noResultsCard.classList.add('d-none');
                    }
                    searchInput.focus();
                });
            }
        });

        const gradeCheckboxMap = {
            'Kinder - Grade School': 'editElem',
            'Junior High - Senior High': 'editJhsShs',
            'Kinder': 'editElem',
            'Grade 1-6': 'editElem',
            'Grade 7-10': 'editJhsShs',
            'Grade 11-12': 'editJhsShs'
        };

        function setGradeSelection(prefix, gradeLevels) {
            const elem = document.getElementById(prefix + 'Elem');
            const jhsShs = document.getElementById(prefix + 'JhsShs');
            if (elem) elem.checked = false;
            if (jhsShs) jhsShs.checked = false;

            if (!gradeLevels) return;

            const levels = gradeLevels.split(',').map(level => level.trim()).filter(Boolean);
            let selectedId = null;
            for (const level of levels) {
                selectedId = gradeCheckboxMap[level] || null;
                if (selectedId) break;
            }

            if (selectedId) {
                const selected = document.getElementById(selectedId);
                if (selected) selected.checked = true;
            }
        }

        function editSchool(id, name, address, district, unit, gradeLevels) {
            document.getElementById('editSchoolId').value = id;
            document.getElementById('editSchoolName').value = name;
            document.getElementById('editSchoolAddress').value = address;

            setGradeSelection('edit', gradeLevels);

            // Set district options based on school type, then set values
            updateSchoolType('edit', district, unit);
            document.getElementById('editDistrict').value = district;
            updateUnitOptions('edit', unit);
            if (unit) {
                document.getElementById('editUnit').value = unit;
            }

            const modal = new bootstrap.Modal(document.getElementById('editSchoolModal'));
            modal.show();
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteSchoolName').innerText = name;
            document.getElementById('deleteSchoolId').value = id;
            const submitBtn = document.getElementById('deleteSubmitBtn');
            if (submitBtn) {
                submitBtn.textContent = 'Archive ' + name;
            }

            const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
            modal.show();
        }

        function updatePrincipal(schoolId, currentPrincipal) {
            document.getElementById('principalSchoolId').value = schoolId;
            document.getElementById('principalName').value = currentPrincipal && currentPrincipal !== '—' ? currentPrincipal : '';
            document.getElementById('principalEffectiveFrom').value = new Date().toISOString().split('T')[0];

            const modal = new bootstrap.Modal(document.getElementById('updatePrincipalModal'));
            modal.show();
        }

        // Auto-open Add School Modal if 'add' is in query params
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('add')) {
            const addModal = new bootstrap.Modal(document.getElementById('addSchoolModal'));
            addModal.show();
        }
    </script>
</body>
</html>
