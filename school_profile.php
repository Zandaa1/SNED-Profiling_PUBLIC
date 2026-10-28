<?php
session_start();
include 'config.php';
require_once __DIR__ . '/includes/school_roster_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$user_school_id = $_SESSION['school_id'] ?? null;

function format_grade_levels($str) {
    if (!$str) return '<span class="text-muted">—</span>';
    $parts = array_map('trim', explode(',', $str));
    $labels = [
        'Kinder' => ['label' => 'Kinder', 'color' => '#DDEEF7'],
        'Grade 1-6' => ['label' => 'Grade School (1-6)', 'color' => '#D8F3DC'],
        'Grade 7-10' => ['label' => 'Junior High (7-10)', 'color' => '#FEF3D0'],
        'Grade 11-12' => ['label' => 'Senior High (11-12)', 'color' => '#FCE8E9'],
    ];
    $html = '<div class="d-flex flex-wrap gap-1">';
    foreach ($parts as $p) {
        $info = $labels[$p] ?? ['label' => $p, 'color' => '#F0F5F1'];
        $html .= '<span class="badge rounded-pill px-2 py-1 fw-normal" style="background:' . $info['color'] . ';color:#4A5E54;font-size:0.75rem;">' . htmlspecialchars($info['label']) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

$school_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$show_selector = false;

// School-scoped users can only view their own school
if (user_is_scoped($user_role) && $user_school_id) {
    if ($school_id > 0 && $school_id !== $user_school_id) {
        header('Location: 403.php');
        exit;
    }
    $school_id = $user_school_id;
}

if ($school_id <= 0) {
    if (user_is_coordinator($user_role) && !user_is_scoped($user_role)) {
        $show_selector = true;
    } else {
        // For school-scoped non-admin, default to first school
        $default_school_res = $conn->query("SELECT id FROM schools WHERE is_deleted = 0 ORDER BY id ASC LIMIT 1");
        if ($default_school_res && $default_school_row = $default_school_res->fetch_assoc()) {
            $school_id = (int)$default_school_row['id'];
        }
    }
}

if (!$show_selector) {
    if ($school_id <= 0) {
        header("Location: schools.php");
        exit();
    }

    // Fetch school info
    $stmt = $conn->prepare("SELECT * FROM schools WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $school = $stmt->get_result()->fetch_assoc();

    if (!$school) {
        header("Location: schools.php");
        exit();
    }

    // Fetch all schools for the selector dropdown (admin only)
    $all_schools = [];
    if (user_is_admin($user_role)) {
        $res_all = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
        if ($res_all) {
            while ($row = $res_all->fetch_assoc()) {
                $all_schools[] = $row;
            }
        }
    }

    $default_sy = school_roster_current_school_year();
    $roster_filters = school_roster_filters_from_request($_GET, $default_sy);
    $current_sy = $roster_filters['school_year'];
    $filter_options = school_roster_filter_options($conn, $school_id);

    // Enrollment breakdown by placement
    $breakdown = [];
    $stmt = $conn->prepare("SELECT placement_type, COUNT(DISTINCT lrn) as cnt FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND school_year = ? GROUP BY placement_type");
    $stmt->bind_param("is", $school_id, $current_sy);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) $breakdown[] = $row;
    }

    // Breakdown by sex
    $by_sex = [];
    $stmt = $conn->prepare("SELECT l.sex, COUNT(DISTINCT e.lrn) as cnt FROM enrollments e JOIN learners l ON e.lrn = l.lrn WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0 AND e.school_year = ? AND l.is_deleted = 0 GROUP BY l.sex");
    $stmt->bind_param("is", $school_id, $current_sy);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) $by_sex[] = $row;
    }

    // Total active enrollees
    $total_active = 0;
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT lrn) as cnt FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND school_year = ?");
    $stmt->bind_param("is", $school_id, $current_sy);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $total_active = $res->fetch_assoc()['cnt'];

    // Current principal
    $current_principal = get_current_principal($conn, $school_id);


    $is_cross_school = user_is_scoped($user_role) && $user_school_id && $school_id !== (int)$user_school_id;

    $learners = [];
    if (!$is_cross_school) {
        $learners = school_roster_fetch_learners($conn, $school_id, $roster_filters, true);
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
    <title><?= $show_selector ? 'My School' : htmlspecialchars($school['school_name']) ?> — My School | SNED Profiling</title>
    <style>
/* Hallmark · pre-emit critique: P5 H5 E5 S5 R5 V4
 * macrostructure: Stat-Led · tone: utilitarian · anchor: sage (#40916C)
 * genre: modern-minimal · theme: Indigo (DESIGN.md locked)
 * states: default · hover · focus-visible · contrast: pass (46–50)
 */
html { overflow-x: clip; }
body { background: var(--color-canvas); font-family: var(--font-body); overflow-x: clip; }

/* ── Sticky header bar ───────────────────────────────────────── */
.header-bar {
    display: flex; align-items: center; gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-rule);
    background: var(--color-paper);
    position: sticky; top: 0; z-index: var(--z-sticky);
    flex-wrap: wrap;
}
.header-bar__left  { display: flex; align-items: center; gap: var(--space-sm); flex: 1; min-width: 0; }
.header-bar__right { display: flex; align-items: center; gap: var(--space-sm); }
.header-bar__title {
    font-size: var(--text-xl); font-weight: 700; color: var(--color-ink);
    margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ── Action buttons ──────────────────────────────────────────── */
.btn-action {
    display: inline-flex; align-items: center; gap: var(--space-2xs);
    font-size: var(--text-sm); font-weight: 600;
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-pill);
    border: 1px solid var(--color-rule);
    background: var(--color-paper); color: var(--color-ink);
    text-decoration: none; cursor: pointer; white-space: nowrap;
    transition: background var(--dur-short) var(--ease-out),
                border-color var(--dur-short) var(--ease-out);
}
.btn-action:hover         { background: var(--color-paper-3); border-color: var(--color-muted); color: var(--color-ink); }
.btn-action:active        { transform: translateY(1px); }
.btn-action:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.btn-action:disabled, .btn-action[aria-disabled="true"] { opacity: 0.55; cursor: not-allowed; pointer-events: none; }
.btn-action--primary          { background: var(--color-primary); border-color: var(--color-primary); color: var(--color-primary-ink); }
.btn-action--primary:hover    { background: var(--color-primary-hover); border-color: var(--color-primary-hover); color: var(--color-primary-ink); }
.btn-action--outline-primary  { border-color: var(--color-primary); color: var(--color-primary); }
.btn-action--outline-primary:hover { background: var(--color-primary); color: var(--color-primary-ink); }
.export-item {
    display: block;
    padding: var(--space-md);
    border: 1px solid var(--color-rule-light) !important;
    border-radius: var(--radius-lg);
    color: var(--color-ink);
    text-decoration: none;
    transition: background var(--dur-short) var(--ease-out), border-color var(--dur-short) var(--ease-out), transform var(--dur-short) var(--ease-out);
}
.export-item:hover { background: var(--color-paper-2) !important; border-color: var(--color-primary) !important; color: var(--color-ink); transform: translateY(-1px); }
.export-icon-shell {
    width: 44px; height: 44px; flex: 0 0 44px;
    border-radius: var(--radius-lg);
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--color-primary-light); color: var(--color-primary);
    font-size: 1.15rem;
}
.export-item-title { font-weight: 700; color: var(--color-ink); margin-bottom: 2px; }
.export-item-desc { font-size: var(--text-xs); color: var(--color-muted); line-height: 1.35; }

/* ── School attribute strip ──────────────────────────────────── */
/* Text attributes (district, principal, grade levels) live here, NOT in stat cards */
.school-meta {
    display: flex; flex-wrap: wrap;
    gap: var(--space-lg) var(--space-3xl);
    padding: var(--space-lg) var(--space-xl);
    background: var(--color-paper);
    border: 1px solid var(--color-rule-light);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-xl);
}
.meta-item { display: flex; flex-direction: column; gap: 3px; }
.meta-item__label {
    font-size: var(--text-xs); font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    color: var(--color-muted);
}
.meta-item__value {
    font-size: var(--text-md); font-weight: 600;
    color: var(--color-ink); line-height: 1.3;
    overflow-wrap: anywhere; min-width: 0;
}

/* ── Stat cards — numeric counts only ───────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-card {
    background: var(--color-paper);
    border: 1px solid var(--color-rule-light);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    display: flex; flex-direction: column; gap: var(--space-xs);
    transition: box-shadow var(--dur-short) var(--ease-out),
                transform var(--dur-short) var(--ease-out);
}
.stat-card:hover         { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.stat-card:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.stat-card__icon  { font-size: 1.25rem; }
.stat-card__value {
    /* clamp for numbers only — no text strings in stat cards */
    font-size: clamp(2rem, 4vw, 3.25rem);
    font-weight: 700; line-height: 1;
    letter-spacing: -0.03em; color: var(--color-ink);
}
.stat-card__label { font-size: var(--text-sm); color: var(--color-muted); line-height: 1.4; }
.stat-card__sub   { font-size: var(--text-xs); color: var(--color-muted); }
.stat-card--accent .stat-card__icon  { color: var(--color-primary); }
.stat-card--success .stat-card__icon { color: var(--color-success); }

/* ── Breakdown cards ─────────────────────────────────────────── */
.breakdown-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.breakdown-card { background: var(--color-paper); border: 1px solid var(--color-rule-light); border-radius: var(--radius-lg); overflow: hidden; }
.breakdown-card__header {
    padding: var(--space-sm) var(--space-lg);
    border-bottom: 1px solid var(--color-rule-light);
    font-weight: 700; font-size: var(--text-sm); color: var(--color-ink);
    display: flex; align-items: center; gap: var(--space-xs);
    background: var(--color-paper-2);
}
.breakdown-card__body { padding: var(--space-xs) var(--space-lg); }
.breakdown-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--color-rule-light);
    gap: var(--space-md);
}
.breakdown-row:last-child  { border-bottom: none; }
.breakdown-row__label { font-size: var(--text-sm); color: var(--color-ink); }
.breakdown-row__count {
    font-size: var(--text-sm); font-weight: 700;
    font-variant-numeric: tabular-nums;
    background: var(--color-paper-3);
    border-radius: var(--radius-pill);
    padding: 2px 12px; color: var(--color-ink);
}

/* ── Section heading ─────────────────────────────────────────── */
.section-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: var(--space-md);
    flex-wrap: wrap; gap: var(--space-sm);
}
.section-head__title {
    font-size: var(--text-xl); font-weight: 700; color: var(--color-ink);
    margin: 0; display: flex; align-items: center; gap: var(--space-xs);
}
.section-head__actions { display: flex; gap: var(--space-xs); align-items: center; }

/* ── Data table ──────────────────────────────────────────────── */
.data-card { background: var(--color-paper); border: 1px solid var(--color-rule-light); border-radius: var(--radius-lg); overflow: hidden; }
.data-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.data-table th {
    text-align: left; padding: var(--space-sm) var(--space-md);
    font-weight: 700; color: var(--color-muted);
    font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em;
    border-bottom: 2px solid var(--color-rule);
    background: var(--color-paper-2); white-space: nowrap;
}
.data-table td { padding: var(--space-sm) var(--space-md); border-bottom: 1px solid var(--color-rule-light); vertical-align: middle; }
.data-table tbody tr { transition: background var(--dur-short) var(--ease-out); }
.data-table tbody tr:hover { background: var(--color-primary-light); }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table td code { font-family: var(--font-mono); font-size: var(--text-xs); background: var(--color-paper-3); padding: 2px 6px; border-radius: var(--radius-sm); color: var(--color-ink); }
.data-table td a { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.data-table td a:hover { text-decoration: underline; }
.data-table td a:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: 2px; }
.badge-dx { display: inline-block; font-size: var(--text-xs); font-weight: 600; padding: 2px 10px; border-radius: var(--radius-pill); background: var(--color-paper-3); color: var(--color-muted); border: 1px solid var(--color-rule-light); }

/* ── Empty state ─────────────────────────────────────────────── */
.empty-state { text-align: center; padding: var(--space-3xl) var(--space-lg); color: var(--color-muted); }
.empty-state__icon { font-size: 2rem; margin-bottom: var(--space-sm); opacity: 0.4; }
.empty-state__text { font-size: var(--text-sm); }

/* ── Inline search ───────────────────────────────────────────── */
.table-search {
    font-size: var(--text-sm);
    padding: var(--space-xs) var(--space-md);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-pill);
    background: var(--color-paper); color: var(--color-ink);
    outline: none; width: 220px;
    transition: border-color var(--dur-short) var(--ease-out),
                box-shadow var(--dur-short) var(--ease-out);
}
.table-search:focus { border-color: var(--color-primary); box-shadow: 0 0 0 3px var(--color-primary-light); }

.filter-card {
    background: var(--color-paper);
    border: 1px solid var(--color-rule-light);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
    box-shadow: var(--shadow-sm);
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-md);
}
.filter-field label {
    display: block;
    font-size: var(--text-xs);
    font-weight: 700;
    color: var(--color-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: var(--space-2xs);
}
.filter-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-top: var(--space-md);
}
.filter-summary {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2xs);
    margin-top: var(--space-sm);
}
.filter-pill {
    background: var(--color-paper-3);
    color: var(--color-muted);
    border: 1px solid var(--color-rule-light);
    border-radius: var(--radius-pill);
    padding: 2px 10px;
    font-size: var(--text-xs);
    font-weight: 600;
}

.school-record-command {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.school-record-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-xs);
    flex-shrink: 0;
/* ── Search Input & Select Card Improvements ─────────────────── */
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
.school-select-card {
    background: var(--color-paper);
    border: 1px solid var(--color-rule-light) !important;
    border-radius: var(--radius-lg);
    transition: transform var(--dur-short) var(--ease-out),
                box-shadow var(--dur-short) var(--ease-out),
                border-color var(--dur-short) var(--ease-out);
}
.school-select-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md) !important;
    border-color: var(--color-primary) !important;
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

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 576px) {
    .school-record-command { display: block; }
    .school-record-actions { justify-content: flex-start; margin-top: var(--space-md); }
    .header-bar__title { font-size: var(--text-md); }
    .school-meta { gap: var(--space-md); }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .table-search { width: 100%; }
    .section-head { flex-direction: column; align-items: flex-start; }
    .section-head__actions { width: 100%; flex-wrap: wrap; }
}
    </style>
</head>
<body>
    <div class="d-flex vh-100 app-shell">
        <?php include 'sidebar.php'; ?>
        <div class="flex-grow-1 d-flex flex-column overflow-auto">

            <!-- ── Sticky header / Selector ─────────────────────────── -->
            <?php if ($show_selector): ?>
                <!-- ── School Selector Dashboard ──────────────────────── -->
                <header class="header-bar">
                    <div class="header-bar__left">
                        <button class="btn btn-outline-secondary d-flex align-items-center gap-2 rounded-pill px-3" id="sidebarToggle" type="button">
                            <i class="bi bi-list"></i> Menu
                        </button>
                        <h1 class="header-bar__title">
                            <i class="bi bi-building-gear text-primary me-1"></i> My School
                        </h1>
                    </div>
                </header>

                <div class="p-4">

                    <p class="page-subtitle text-muted mb-4">Select a school to view active student enrollment statistics, program details, and roster directories.</p>
                    
                    <div class="main-card animate-fade-up" style="background: var(--color-paper); border: 1px solid var(--color-rule-light); border-radius: var(--radius-lg); padding: var(--space-lg); box-shadow: var(--shadow-sm);">
                        <div class="search-field mb-4">
                            <i class="bi bi-search"></i>
                            <input type="text" id="schoolSearch" class="form-control rounded-pill pe-5" placeholder="Search school name, district, or address..." autocomplete="off">
                            <button type="button" id="clearSearchBtn" class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-secondary d-none border-0 bg-transparent" style="text-decoration: none;">
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>
                        
                        <div id="schoolListContainer">
                            <div class="text-muted small mb-3 px-1 text-uppercase fw-bold" id="schoolListHeader" style="font-size: var(--text-xs); letter-spacing: 0.03em;">Schools Directory</div>
                            
                            <!-- Search list dynamic grid -->
                            <div id="schoolGrid" class="row g-3">
                                <!-- Loaded dynamically via JS -->
                            </div>

                            <!-- Skeleton loader -->
                            <div id="schoolSkeleton" class="row g-3 d-none">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                <div class="col-md-6 col-lg-6">
                                    <div class="card h-100 border-0 shadow-sm" style="padding: var(--space-md); border-radius: var(--radius-lg); background: var(--color-paper); border: 1px solid var(--color-rule-light) !important;">
                                        <div class="d-flex align-items-center gap-3">
                                             <div class="skeleton-avatar" style="width: 48px; height: 48px; border-radius: var(--radius-md); background: #D4DFD6; animation: pulse 1.5s infinite ease-in-out;"></div>
                                            <div class="flex-grow-1">
                                                 <div class="skeleton-text" style="height: 14px; width: 60%; background: #D4DFD6; border-radius: 4px; animation: pulse 1.5s infinite ease-in-out; margin-bottom: 8px;"></div>
                                                 <div class="skeleton-text" style="height: 10px; width: 40%; background: #D4DFD6; border-radius: 4px; animation: pulse 1.5s infinite ease-in-out;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Empty state -->
                            <div id="schoolEmptyState" class="d-none text-center py-5">
                                <div class="display-1 text-muted"><i class="bi bi-building-exclamation"></i></div>
                                <h4 class="fw-bold mt-3">No Schools Found</h4>
                                <p class="text-muted">No schools match the search query. Try another keyword or check spelling.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- ── Normal School Profile ────────────────────────── -->
                <header class="header-bar">
                    <div class="header-bar__left">
                        <button class="btn btn-outline-secondary d-flex align-items-center gap-2 rounded-pill px-3" id="sidebarToggle" type="button">
                            <i class="bi bi-list"></i> Menu
                        </button>
                        <h1 class="header-bar__title">
                            <i class="bi bi-building-gear text-primary me-1"></i><?= htmlspecialchars($school['school_name'] ?? '') ?>
                        </h1>
                    </div>
                    <div class="header-bar__right">
                        <button class="btn-action btn-action--primary" type="button" data-bs-toggle="modal" data-bs-target="#schoolReportExportModal">
                            <i class="bi bi-file-earmark-arrow-down"></i> Export / Print Report
                        </button>
                    </div>
                </header>

                <div class="p-4">
                    <!-- ── School attributes strip ───────────────────── -->
                    <div class="school-meta animate-fade-up">
                        <div class="meta-item">
                            <span class="meta-item__label"><i class="bi bi-geo-alt"></i> District</span>
                            <span class="meta-item__value"><?= htmlspecialchars($school['district'] ?? '—') ?><?= !empty($school['unit']) ? ' / ' . htmlspecialchars($school['unit']) : '' ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-item__label"><i class="bi bi-map"></i> Address</span>
                            <span class="meta-item__value"><?= htmlspecialchars($school['address'] ?? '—') ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-item__label"><i class="bi bi-person-badge"></i> Principal</span>
                            <span class="meta-item__value"><?= htmlspecialchars($current_principal ?? '—') ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-item__label"><i class="bi bi-layers"></i> Grade Levels Offered</span>
                            <div class="meta-item__value">
                                <?php
                                $gl = $school['grade_levels_offered'] ?? '';
                                if ($gl) {
                                    $parts = array_map('trim', explode(',', $gl));
                                    $colors = [
                                        'Kinder'      => ['bg' => '#DDEEF7', 'text' => '#1A5276'],
                                        'Grade 1-6'   => ['bg' => '#D8F3DC', 'text' => '#1E5C39'],
                                        'Grade 7-10'  => ['bg' => '#FEF3D0', 'text' => '#7D5A0A'],
                                        'Grade 11-12' => ['bg' => '#FCE8E9', 'text' => '#7A2028'],
                                    ];
                                    $label_names = [
                                        'Kinder'      => 'Kinder',
                                        'Grade 1-6'   => 'Grades 1–6',
                                        'Grade 7-10'  => 'Grades 7–10',
                                        'Grade 11-12' => 'Grades 11–12',
                                    ];
                                    echo '<div class="d-flex flex-wrap gap-1 mt-1">';
                                    foreach ($parts as $p) {
                                        $c   = $colors[$p] ?? ['bg' => '#F0F5F1', 'text' => '#4A5E54'];
                                        $lab = $label_names[$p] ?? $p;
                                        echo '<span style="background:' . $c['bg'] . ';color:' . $c['text'] . ';'
                                           . 'font-size:var(--text-xs);font-weight:600;padding:3px 10px;'
                                           . 'border-radius:var(--radius-pill);display:inline-block;">'
                                           . htmlspecialchars($lab) . '</span>';
                                    }
                                    echo '</div>';
                                } else {
                                    echo '<span style="color:var(--color-muted)">—</span>';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="meta-item">
                            <span class="meta-item__label"><i class="bi bi-calendar3"></i> School Year</span>
                            <span class="meta-item__value"><?= htmlspecialchars($current_sy ?? '') ?></span>
                        </div>
                    </div>

                    <!-- ── Enrollment breakdown ───────────────────────── -->
                    <div class="breakdown-grid">
                        <div class="breakdown-card animate-fade-up">
                            <div class="breakdown-card__header">
                                <i class="bi bi-people-fill text-primary"></i> Active Enrollees
                            </div>
                            <div class="breakdown-card__body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:var(--space-lg) var(--space-md);">
                                <div style="font-size:2rem;font-weight:700;line-height:1;color:var(--color-ink);letter-spacing:-0.03em;"><?= $total_active ?? 0 ?></div>
                                <div style="font-size:var(--text-xs);color:var(--color-muted);margin-top:var(--space-2xs);">SY <?= htmlspecialchars($current_sy ?? '') ?></div>
                            </div>
                        </div>
                        <div class="breakdown-card animate-fade-up stagger-1">
                            <div class="breakdown-card__header">
                                <i class="bi bi-pie-chart text-primary"></i> By Placement Type
                            </div>
                            <div class="breakdown-card__body">
                                <?php if (empty($breakdown)): ?>
                                <div class="empty-state">
                                    <div class="empty-state__icon"><i class="bi bi-bar-chart"></i></div>
                                    <div class="empty-state__text">No enrollment data for this school year.</div>
                                </div>
                                <?php else: ?>
                                <?php foreach ($breakdown as $b): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-row__label"><?= htmlspecialchars($b['placement_type'] ?? '') ?></span>
                                    <span class="breakdown-row__count"><?= $b['cnt'] ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="breakdown-card animate-fade-up stagger-2">
                            <div class="breakdown-card__header">
                                <i class="bi bi-gender-ambiguous text-primary"></i> By Sex
                            </div>
                            <div class="breakdown-card__body">
                                <?php if (empty($by_sex)): ?>
                                <div class="empty-state">
                                    <div class="empty-state__icon"><i class="bi bi-bar-chart"></i></div>
                                    <div class="empty-state__text">No enrollment data for this school year.</div>
                                </div>
                                <?php else: ?>
                                <?php foreach ($by_sex as $s): ?>
                                <div class="breakdown-row">
                                    <span class="breakdown-row__label"><?= htmlspecialchars($s['sex'] ?? '') ?></span>
                                    <span class="breakdown-row__count"><?= $s['cnt'] ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── Active learner filters ─────────────────────── -->
                    <form class="filter-card animate-fade-up stagger-1" method="GET" action="school_profile.php">
                        <input type="hidden" name="id" value="<?= (int)$school_id ?>">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                            <div>
                                <h2 class="section-head__title mb-1" style="font-size:var(--text-lg);">
                                    <i class="bi bi-funnel text-primary"></i> Filter Active Learners
                                </h2>
                                <div class="text-muted small">Filters apply to the selected school year roster, filtered export, and printable reports.</div>
                            </div>
                            <div class="text-muted small fw-semibold">
                                Showing <?= count($learners ?? []) ?> of <?= (int)($total_active ?? 0) ?> active learners
                            </div>
                        </div>

                        <div class="filter-grid">
                            <div class="filter-field">
                                <label for="schoolYearFilter">School Year</label>
                                <select class="form-select rounded-3" id="schoolYearFilter" name="school_year">
                                    <?= school_year_options($current_sy ?? '') ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="queryFilter">Name or LRN</label>
                                <input class="form-control rounded-3" type="search" id="queryFilter" name="q" value="<?= htmlspecialchars($roster_filters['q'] ?? '') ?>" placeholder="Search roster">
                            </div>
                            <div class="filter-field">
                                <label for="sexFilter">Sex</label>
                                <select class="form-select rounded-3" id="sexFilter" name="sex">
                                    <option value="">All</option>
                                    <?php foreach (['Male', 'Female'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['sex'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="gradeFilter">Grade Level</label>
                                <select class="form-select rounded-3" id="gradeFilter" name="grade_level">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['grade_levels'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['grade_level'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="placementFilter">Placement</label>
                                <select class="form-select rounded-3" id="placementFilter" name="placement_type">
                                    <option value="">All</option>
                                    <?php foreach (['Graded', 'Non-Graded'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['placement_type'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="levelFilter">Self-contained Level</label>
                                <select class="form-select rounded-3" id="levelFilter" name="self_contained_level">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['self_contained_levels'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['self_contained_level'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="disabilityFilter">Disability/Diagnosis</label>
                                <select class="form-select rounded-3" id="disabilityFilter" name="disability">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['disabilities'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['disability'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="barangayFilter">Barangay</label>
                                <select class="form-select rounded-3" id="barangayFilter" name="barangay">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['barangays'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['barangay'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="cityFilter">City</label>
                                <select class="form-select rounded-3" id="cityFilter" name="city">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['cities'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['city'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="ageMinFilter">Age Min</label>
                                <input class="form-control rounded-3" type="number" min="0" max="120" id="ageMinFilter" name="age_min" value="<?= htmlspecialchars($roster_filters['age_min'] ?? '') ?>" placeholder="Any">
                            </div>
                            <div class="filter-field">
                                <label for="ageMaxFilter">Age Max</label>
                                <input class="form-control rounded-3" type="number" min="0" max="120" id="ageMaxFilter" name="age_max" value="<?= htmlspecialchars($roster_filters['age_max'] ?? '') ?>" placeholder="Any">
                            </div>
                            <div class="filter-field">
                                <label for="assessmentModeFilter">Assessment Mode</label>
                                <select class="form-select rounded-3" id="assessmentModeFilter" name="assessment_mode">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['assessment_modes'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['assessment_mode'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="interpreterFilter">Interpreter Needed</label>
                                <select class="form-select rounded-3" id="interpreterFilter" name="interpreter_needed">
                                    <option value="">All</option>
                                    <?php foreach (($filter_options['interpreter_needed'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (($roster_filters['interpreter_needed'] ?? '') === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn-action btn-action--primary" formaction="school_profile.php"><i class="bi bi-check2"></i> Apply Filters</button>
                            <a class="btn-action" href="school_profile.php?id=<?= (int)$school_id ?>&school_year=<?= urlencode($current_sy ?? '') ?>"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                        </div>

                        <?php $filter_summary = school_roster_filter_summary($roster_filters ?? [], false); ?>
                        <?php if (!empty($filter_summary)): ?>
                        <div class="filter-summary" aria-label="Active filters">
                            <?php foreach ($filter_summary as $summary): ?>
                            <span class="filter-pill"><?= htmlspecialchars($summary) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </form>

                    <!-- ── Learner table ──────────────────────────────── -->
                    <div class="section-head animate-fade-up">
                        <h2 class="section-head__title">
                            <i class="bi bi-list-ul text-primary"></i>
                            Active Learners
                            <span class="text-muted fw-normal" style="font-size:var(--text-base)">(<?= count($learners ?? []) ?>)</span>
                        </h2>
                        <div class="section-head__actions">
                            <input type="text" id="learnerSearch" class="table-search" placeholder="Type to filter table...">
                        </div>
                    </div>
                    <div class="data-card animate-fade-up stagger-2">
                        <div class="table-responsive" style="max-height: 540px;">
                            <table class="data-table" id="learnerTable">
                                <thead>
                                    <tr>
                                        <th>LRN</th>
                                        <th>Name</th>
                                        <th>Sex</th>
                                        <th>Age</th>
                                        <th>Address</th>
                                        <th>Grade</th>
                                        <th>Placement</th>
                                        <th>Assessment</th>
                                        <th>Diagnosis</th>
                                    </tr>
                                </thead>
                                <tbody id="learnerTableBody">
                                    <?php if (isset($is_cross_school) && $is_cross_school): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="bi bi-shield-lock-fill text-warning me-2"></i>
                                            Roster access restricted to school staff/admin.
                                        </td>
                                    </tr>
                                    <?php elseif (empty($learners ?? [])): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            No active learners match the selected filters.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($learners as $l): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($l['lrn']) ?></code></td>
                                        <td>
                                            <a href="viewLearner.php?id=<?= urlencode($l['lrn']) ?>">
                                                <?= htmlspecialchars($l['last_name'] . ', ' . $l['first_name']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($l['sex'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars((string)($l['age'] ?? '—')) ?></td>
                                        <td><?= htmlspecialchars(implode(', ', array_filter([
                                            $l['house_no_street'] ?? '',
                                            $l['barangay'] ?? '',
                                            $l['city'] ?? '',
                                            $l['province'] ?? ''
                                        ])) ?: '—') ?></td>
                                        <td><?= htmlspecialchars($l['grade_level']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($l['placement_type']) ?>
                                            <?= $l['self_contained_level']
                                                ? ' <span class="text-muted">(' . htmlspecialchars($l['self_contained_level']) . ')</span>'
                                                : '' ?>
                                        </td>
                                        <td><?= htmlspecialchars($l['assessment_mode'] ?? '—') ?></td>
                                        <td><span class="badge-dx"><?= htmlspecialchars($l['disability_summary'] ?? $l['primary_diagnosis'] ?? 'Unassigned') ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div><!-- /p-4 -->
            <?php endif; ?>
        </div><!-- /main column -->
    </div><!-- /app-shell -->

    <?php if (!$show_selector): ?>
    <!-- School Report Export Modal -->
    <div class="modal fade" id="schoolReportExportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-4" style="border-radius:var(--radius-lg);">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-arrow-down text-primary me-2"></i>Export / Print Report</h5>
                        <div class="text-muted small"><?= htmlspecialchars($school['school_name'] ?? '') ?> · SY <?= htmlspecialchars($current_sy ?? '') ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <form method="GET" action="export_school_roster.php" target="_blank" class="h-100">
                                <input type="hidden" name="school_id" value="<?= (int)$school_id ?>">
                                <input type="hidden" name="mode" value="filtered">
                                <?php foreach (($roster_filters ?? []) as $filter_key => $filter_value): ?>
                                    <input type="hidden" name="<?= htmlspecialchars($filter_key) ?>" value="<?= htmlspecialchars((string)$filter_value) ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="export-item w-100 h-100 text-start border-0 bg-transparent">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="export-icon-shell"><i class="bi bi-funnel"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="export-item-title">School Roster Excel - Filtered</div>
                                            <div class="export-item-desc">Export the roster using the active school-year and learner filters.</div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted fs-6"></i>
                                    </div>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" action="export_school_roster.php" target="_blank" class="h-100">
                                <input type="hidden" name="school_id" value="<?= (int)$school_id ?>">
                                <input type="hidden" name="school_year" value="<?= htmlspecialchars($current_sy ?? '') ?>">
                                <input type="hidden" name="mode" value="all">
                                <button type="submit" class="export-item w-100 h-100 text-start border-0 bg-transparent">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="export-icon-shell"><i class="bi bi-people"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="export-item-title">School Roster Excel - All</div>
                                            <div class="export-item-desc">Export all active learners for the selected school year.</div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted fs-6"></i>
                                    </div>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" action="export_sned_school.php" target="_blank" class="h-100">
                                <input type="hidden" name="school_id" value="<?= (int)$school_id ?>">
                                <input type="hidden" name="school_year" value="<?= htmlspecialchars($current_sy ?? '') ?>">
                                <button type="submit" class="export-item w-100 h-100 text-start border-0 bg-transparent">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="export-icon-shell"><i class="bi bi-building"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="export-item-title">SNED Data Collection Excel</div>
                                            <div class="export-item-desc">Export learner, guardian, contact, and address details.</div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted fs-6"></i>
                                    </div>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="GET" action="export_lwd_list.php" target="_blank" class="h-100">
                                <input type="hidden" name="scope" value="school">
                                <input type="hidden" name="school_id" value="<?= (int)$school_id ?>">
                                <input type="hidden" name="school_year" value="<?= htmlspecialchars($current_sy ?? '') ?>">
                                <button type="submit" class="export-item w-100 h-100 text-start border-0 bg-transparent">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="export-icon-shell"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="export-item-title">School LWD List Excel</div>
                                            <div class="export-item-desc">Export the LWD list using the official guardian/learner layout.</div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted fs-6"></i>
                                    </div>
                                </button>
                            </form>
                        </div>
                        <div class="col-12">
                            <form method="GET" action="export_lwd_adults.php" target="_blank">
                                <input type="hidden" name="scope" value="school">
                                <input type="hidden" name="school_id" value="<?= (int)$school_id ?>">
                                <input type="hidden" name="school_year" value="<?= htmlspecialchars($current_sy ?? '') ?>">
                                <button type="submit" class="export-item w-100 text-start border-0 bg-transparent">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="export-icon-shell"><i class="bi bi-printer"></i></div>
                                        <div class="flex-grow-1">
                                            <div class="export-item-title">LWDs 18 Years Old and Above Printable List</div>
                                            <div class="export-item-desc">Open a browser-printable list with name, age, and prepared-by section.</div>
                                        </div>
                                        <i class="bi bi-chevron-right text-muted fs-6"></i>
                                    </div>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-end pt-0">
                    <button type="button" class="btn btn-light px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        // Sidebar toggle
        const shell  = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }

        // Client-side learner search (no server round-trip)
        const searchInput = document.getElementById('learnerSearch');
        const tbody       = document.getElementById('learnerTableBody');
        if (searchInput && tbody) {
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.toLowerCase().trim();
                tbody.querySelectorAll('tr').forEach(row => {
                    row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
                });
            });
        }
    </script>

    <?php if ($show_selector): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const schoolSearch = document.getElementById('schoolSearch');
            const schoolGrid = document.getElementById('schoolGrid');
            const skeleton = document.getElementById('schoolSkeleton');
            const emptyState = document.getElementById('schoolEmptyState');
            const header = document.getElementById('schoolListHeader');

            let debounceTimeout;

            function fetchSchools(query = '') {
                schoolGrid.innerHTML = '';
                emptyState.classList.add('d-none');
                skeleton.classList.remove('d-none');
                header.innerText = query ? 'Search Results' : 'Schools Directory';

                fetch(`ajax_school_search.php?q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(res => {
                        skeleton.classList.add('d-none');
                        if (res.success && res.data.length > 0) {
                            res.data.forEach(s => {
                                const card = document.createElement('div');
                                card.className = 'col-md-6 col-lg-6 animate-fade-up';
                                card.innerHTML = `
                                    <a href="school_profile.php?id=${s.id}" class="text-decoration-none">
                                        <div class="card h-100 school-select-card shadow-sm">
                                            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                                                <div class="d-flex align-items-center justify-content-center bg-primary-light text-primary rounded-3" style="width: 48px; height: 48px; flex-shrink: 0;">
                                                    <i class="bi bi-building fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 min-width-0">
                                                    <h6 class="fw-bold text-dark mb-1 text-wrap" style="line-height: 1.35; font-size: var(--text-sm);">${escapeHtml(s.school_name)}</h6>
                                                    <div class="small text-muted text-truncate">${escapeHtml(s.district)}</div>
                                                </div>
                                                <div class="text-end flex-shrink-0">
                                                    <span class="badge rounded-pill bg-primary-light text-primary fw-bold" style="font-size:0.75rem; padding: 0.25rem 0.6rem;">
                                                        ${s.enrollment_count} LWDs
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                `;
                                schoolGrid.appendChild(card);
                            });
                        } else {
                            emptyState.classList.remove('d-none');
                        }
                    })
                    .catch(() => {
                        skeleton.classList.add('d-none');
                        emptyState.classList.remove('d-none');
                    });
            }

            function escapeHtml(str) {
                if (!str) return '';
                return str
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            const clearSearchBtn = document.getElementById('clearSearchBtn');

            function toggleClearBtn() {
                if (schoolSearch.value.trim().length > 0) {
                    clearSearchBtn.classList.remove('d-none');
                } else {
                    clearSearchBtn.classList.add('d-none');
                }
            }

            schoolSearch.addEventListener('input', () => {
                toggleClearBtn();
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => {
                    fetchSchools(schoolSearch.value.trim());
                }, 250);
            });

            clearSearchBtn.addEventListener('click', () => {
                schoolSearch.value = '';
                toggleClearBtn();
                fetchSchools();
                schoolSearch.focus();
            });

            // Initial load
            fetchSchools();
        });
    </script>
    <?php endif; ?>
</body>
</html>
