<?php
session_start();
include 'config.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
$user_school_id = $_SESSION['school_id'] ?? null;

if (!user_is_coordinator($user_role)) {
    header("Location: main.php");
    exit();
}

$message = '';

$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;

// Action Routing Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_is_coordinator($_SESSION['role'] ?? '')) {
        header('Location: 403.php');
        exit;
    }
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die("CSRF Token validation failed.");
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'promote') {
        $lrn = trim($_POST['lrn'] ?? '');
        $next_grade = trim($_POST['next_grade_level'] ?? '');
        $school_year = trim($_POST['school_year'] ?? '');
        $placement_type = $_POST['placement_type'] ?? '';
        $self_contained_level = $_POST['self_contained_level'] ?? null;
        if ($self_contained_level !== null) {
            $self_contained_level = trim($self_contained_level) ?: null;
        }
        if ($placement_type === 'Non-Graded') {
            $next_grade = 'Non-Graded';
        }

        if ($next_grade === '' || $school_year === '') {
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Next grade level and school year are required.</div>";
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id, school_id, primary_diagnosis, grade_level FROM enrollments WHERE lrn = ? AND is_deleted = 0 AND is_historical = 0 ORDER BY school_year DESC LIMIT 1");
                $stmt->bind_param("s", $lrn);
                $stmt->execute();
                $res = $stmt->get_result();
                $old_enroll = $res->fetch_assoc();

                if (!$old_enroll) {
                    throw new Exception("No active enrollment found.");
                }

                if (!user_is_admin($user_role) && $old_enroll['school_id'] != $user_school_id) {
                    throw new Exception("Unauthorized: Student is not enrolled in your school.");
                }
                
                $target_school_id = (int)$old_enroll['school_id'];

                if ($placement_type === 'Non-Graded' && !in_array($self_contained_level, ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'], true)) {
                    throw new Exception("Program: Required for Non-Graded placements.");
                }
                if ($placement_type === 'Graded' && !sned_school_allows_grade_level($conn, $target_school_id, $next_grade)) {
                    throw new Exception("Selected grade level is not offered by the selected school.");
                }

                $stmt = $conn->prepare("UPDATE enrollments SET is_historical = 1, remarks = CONCAT(COALESCE(remarks,''), ' [Promoted to $next_grade on " . date('Y-m-d') . "]') WHERE id = ?");
                $stmt->bind_param("i", $old_enroll['id']);
                $stmt->execute();

                $stmt = $conn->prepare("INSERT INTO enrollments (lrn, school_id, school_year, placement_type, self_contained_level, grade_level, primary_diagnosis, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $remarks = 'Promoted from ' . ($old_enroll['grade_level'] ?? 'previous level') . ' on ' . date('Y-m-d');
                $stmt->bind_param("sisssssss", $lrn, $target_school_id, $school_year, $placement_type, $self_contained_level, $next_grade, $old_enroll['primary_diagnosis'], $remarks, $_SESSION['user_id']);
                $stmt->execute();

                $stmt = $conn->prepare("UPDATE learners SET needs_profile_update = 1 WHERE lrn = ?");
                $stmt->bind_param("s", $lrn);
                $stmt->execute();

                log_transaction($conn, $_SESSION['user_id'], 'promote', 'enrollments', $lrn, "Promoted learner LRN: $lrn to $next_grade in School ID $target_school_id (SY $school_year)");

                $conn->commit();
                $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Learner promoted successfully. <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            } catch (Exception $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    } elseif ($action === 'bulk_promote') {
        $checked_lrns = $_POST['checked_lrns'] ?? [];
        $school_year = trim($_POST['bulk_school_year'] ?? '');
        $promotions = $_POST['promotions'] ?? [];

        if (empty($checked_lrns) || $school_year === '') {
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> No students checked or school year is missing.</div>";
        } else {
            $conn->begin_transaction();
            try {
                $success_count = 0;
                foreach ($checked_lrns as $lrn) {
                    $promo = $promotions[$lrn] ?? null;
                    if (!$promo) continue;

                    $placement_type = $promo['placement_type'] ?? '';
                    $next_grade = $promo['next_grade_level'] ?? '';
                    $self_contained_level = !empty($promo['self_contained_level']) ? trim($promo['self_contained_level']) : null;

                    if ($placement_type === 'Non-Graded') {
                        $next_grade = 'Non-Graded';
                    }

                    if ($next_grade === '') {
                        throw new Exception("Grade level/program is required for student LRN: $lrn");
                    }

                    // Load current active enrollment
                    $stmt = $conn->prepare("SELECT id, school_id, primary_diagnosis, grade_level FROM enrollments WHERE lrn = ? AND is_deleted = 0 AND is_historical = 0 ORDER BY school_year DESC LIMIT 1");
                    $stmt->bind_param("s", $lrn);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $old_enroll = $res->fetch_assoc();

                    if (!$old_enroll) {
                        throw new Exception("No active enrollment found for LRN: $lrn");
                    }

                    if (!user_is_admin($user_role) && $old_enroll['school_id'] != $user_school_id) {
                        throw new Exception("Unauthorized: Student $lrn is not enrolled in your school.");
                    }

                    $target_school_id = (int)$old_enroll['school_id'];

                    if ($placement_type === 'Non-Graded' && !in_array($self_contained_level, ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'], true)) {
                        throw new Exception("Program: Required for Non-Graded LRN: $lrn.");
                    }
                    if ($placement_type === 'Graded' && !sned_school_allows_grade_level($conn, $target_school_id, $next_grade)) {
                        throw new Exception("Selected grade level is not offered by the chosen school for LRN: $lrn.");
                    }

                    // 1. Mark old as historical
                    $stmt = $conn->prepare("UPDATE enrollments SET is_historical = 1, remarks = CONCAT(COALESCE(remarks,''), ' [Promoted to $next_grade on " . date('Y-m-d') . "]') WHERE id = ?");
                    $stmt->bind_param("i", $old_enroll['id']);
                    $stmt->execute();

                    // 2. Insert new enrollment
                    $stmt = $conn->prepare("INSERT INTO enrollments (lrn, school_id, school_year, placement_type, self_contained_level, grade_level, primary_diagnosis, remarks, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $remarks = 'Promoted from ' . ($old_enroll['grade_level'] ?? 'previous level') . ' on ' . date('Y-m-d') . ' (Bulk)';
                    $stmt->bind_param("sisssssss", $lrn, $target_school_id, $school_year, $placement_type, $self_contained_level, $next_grade, $old_enroll['primary_diagnosis'], $remarks, $_SESSION['user_id']);
                    $stmt->execute();

                    // 3. Mark learner needs profile update
                    $stmt = $conn->prepare("UPDATE learners SET needs_profile_update = 1 WHERE lrn = ?");
                    $stmt->bind_param("s", $lrn);
                    $stmt->execute();

                    log_transaction($conn, $_SESSION['user_id'], 'promote', 'enrollments', $lrn, "Promoted learner LRN: $lrn to $next_grade in School ID $target_school_id (SY $school_year, Bulk)");
                    $success_count++;
                }

                $conn->commit();
                $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Successfully promoted $success_count learners in batch. <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            } catch (Exception $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    } elseif ($action === 'revert_promotion') {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        $conn->begin_transaction();
        try {
            // Find the active enrollment we want to revert
            $stmt = $conn->prepare("SELECT id, lrn, school_id, school_year, grade_level FROM enrollments WHERE id = ? AND is_deleted = 0 AND is_historical = 0 LIMIT 1");
            $stmt->bind_param("i", $enrollment_id);
            $stmt->execute();
            $new_enroll = $stmt->get_result()->fetch_assoc();

            if (!$new_enroll) {
                throw new Exception("Enrollment record not found or already deleted.");
            }

            $lrn = $new_enroll['lrn'];

            // Find the most recent historical enrollment for this learner
            $stmt = $conn->prepare("SELECT id, school_id, grade_level FROM enrollments WHERE lrn = ? AND is_deleted = 0 AND is_historical = 1 ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("s", $lrn);
            $stmt->execute();
            $prev_enroll = $stmt->get_result()->fetch_assoc();

            if (!$prev_enroll) {
                throw new Exception("No previous historical enrollment found to revert back to.");
            }

            // Authorization check
            if (!user_is_admin($user_role)) {
                if ($new_enroll['school_id'] != $user_school_id && $prev_enroll['school_id'] != $user_school_id) {
                    throw new Exception("Unauthorized: You do not have management access to this learner's schools.");
                }
            }

            // 1. Mark new enrollment as deleted
            $stmt = $conn->prepare("UPDATE enrollments SET is_deleted = 1 WHERE id = ?");
            $stmt->bind_param("i", $new_enroll['id']);
            $stmt->execute();

            // 2. Restore historical enrollment to active
            $stmt = $conn->prepare("UPDATE enrollments SET is_historical = 0 WHERE id = ?");
            $stmt->bind_param("i", $prev_enroll['id']);
            $stmt->execute();

            log_transaction($conn, $_SESSION['user_id'], 'revert_promote', 'enrollments', $lrn, "Reverted promotion for LRN: $lrn (Removed active enrollment ID $enrollment_id, restored enrollment ID " . $prev_enroll['id'] . ")");

            $conn->commit();
            $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm'><i class='bi bi-check-circle-fill me-2'></i> Promotion reverted successfully. <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Compute target school year
$parts_sy = explode('-', $current_sy);
$target_sy = '';
if (count($parts_sy) === 2) {
    $target_sy = ((int)$parts_sy[0] + 1) . '-' . ((int)$parts_sy[1] + 1);
}

// Query all unpromoted learners
$unpromoted_sql = "
    SELECT e.id AS enrollment_id, e.lrn, l.first_name, l.last_name, e.grade_level, e.self_contained_level, e.placement_type, e.school_year, e.school_id, s.school_name
    FROM enrollments e
    INNER JOIN learners l ON e.lrn = l.lrn AND l.is_deleted = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE e.is_deleted = 0
      AND e.is_historical = 0
      AND e.school_year = ?
      AND (? = 'superadmin' OR ? = 'admin_office' OR e.school_id = ?)
      AND NOT EXISTS (
          SELECT 1 FROM enrollments next_e
          WHERE next_e.lrn = e.lrn
            AND next_e.school_year = ?
            AND next_e.is_deleted = 0
      )
    ORDER BY s.school_name ASC, l.last_name ASC, l.first_name ASC
";

$stmt_unp = $conn->prepare($unpromoted_sql);
$bind_school_id = $user_school_id ? (int)$user_school_id : 0;
$stmt_unp->bind_param("sssis", $current_sy, $user_role, $user_role, $bind_school_id, $target_sy);
$stmt_unp->execute();
$res_unp = $stmt_unp->get_result();
$unpromoted_learners = [];
if ($res_unp) {
    while ($row = $res_unp->fetch_assoc()) {
        $unpromoted_learners[] = $row;
    }
}

// Fetch all active schools in the system for next school selection
$res_schools_all = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
$all_schools = [];
if ($res_schools_all) {
    while ($r = $res_schools_all->fetch_assoc()) {
        $all_schools[] = $r;
    }
}

// Fetch recent promotions history log
$auth_clause = "";
if (user_is_scoped($user_role) && !empty($user_school_id)) {
    $sid = (int)$user_school_id;
    $auth_clause = " AND (e.school_id = $sid OR prev_e.school_id = $sid)";
}

$history_sql = "
    SELECT e.id AS enrollment_id, e.lrn, l.first_name, l.last_name, e.grade_level, e.self_contained_level, e.placement_type, e.school_year, e.updated_at AS created_at, e.school_id, s.school_name,
           u.full_name AS promoter_name,
           prev_e.grade_level AS prev_grade_level, prev_e.school_year AS prev_school_year, prev_e.self_contained_level AS prev_self_contained, prev_e.placement_type AS prev_placement
    FROM enrollments e
    INNER JOIN learners l ON e.lrn = l.lrn AND l.is_deleted = 0
    LEFT JOIN schools s ON e.school_id = s.id
    LEFT JOIN users u ON e.updated_by = u.id
    LEFT JOIN enrollments prev_e ON prev_e.lrn = e.lrn AND prev_e.is_deleted = 0 AND prev_e.is_historical = 1 AND prev_e.remarks LIKE CONCAT('%[Promoted to ', e.grade_level, '%')
    WHERE e.is_deleted = 0
      AND e.is_historical = 0
      AND e.remarks LIKE 'Promoted from%'
      $auth_clause
    ORDER BY e.id DESC
    LIMIT 50
";

$res_hist = $conn->query($history_sql);
$promotion_history = [];
if ($res_hist) {
    while ($row = $res_hist->fetch_assoc()) {
        $promotion_history[] = $row;
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
    <title>Promote Learner | SNED Profiling</title>
    <style>
        body { background: var(--color-canvas); }
        .badge-premium-diagnosis {
            background-color: var(--sned-dew);
            color: var(--sned-accent);
            font-weight: 600;
            border: 1px solid var(--sned-mint);
            border-radius: 50rem;
            white-space: normal;
            display: inline-block;
            line-height: 1.2;
        }
        .x-small {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
        }

        /* Search Results Panel styling */
        #promoteSearchResults {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(64, 145, 108, 0.15);
            box-shadow: 0 10px 25px rgba(27, 38, 49, 0.08) !important;
            transition: all 0.2s ease;
        }
        #promoteSearchResults .list-group-item {
            border: none;
            transition: all 0.2s ease;
        }
        #promoteSearchResults .list-group-item:hover {
            background-color: var(--sned-dew) !important;
            color: var(--sned-primary) !important;
            padding-left: 1.25rem !important;
        }

        /* Profile details container layout */
        .info-item {
            background: var(--color-paper-2);
            border: 1px solid var(--color-outline-variant);
            border-radius: 12px;
            padding: 12px 16px;
            transition: transform 0.2s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.2s cubic-bezier(0.165, 0.84, 0.44, 1), border-color 0.2s ease;
            height: 100%;
        }
        .info-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(45, 106, 79, 0.04);
            border-color: var(--sned-mint);
            background: #ffffff;
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
                        <h1 class="page-title">Learner Promotion</h1>
                        <p class="page-subtitle">Advance active student enrollments into the next school year.</p>
                    </div>
                    <div class="toolbar-actions">
                        <span class="status-chip"><i class="bi bi-calendar3"></i> Current SY <?= htmlspecialchars($current_sy) ?></span>
                    </div>
                </div>

                <?= $message ?>

                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs border-bottom mb-4 animate-fade-up" id="promotionTabs" role="tablist" style="gap: 8px;">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold border-0 px-4 py-2 text-success" id="single-tab" data-bs-toggle="tab" data-bs-target="#single-pane" type="button" role="tab" aria-controls="single-pane" aria-selected="true">
                            <i class="bi bi-person-fill me-1"></i> Single Promotion
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold border-0 px-4 py-2 text-success" id="bulk-tab" data-bs-toggle="tab" data-bs-target="#bulk-pane" type="button" role="tab" aria-controls="bulk-pane" aria-selected="false">
                            <i class="bi bi-people-fill me-1"></i> Bulk Class Promotion
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold border-0 px-4 py-2 text-success" id="unpromoted-tab" data-bs-toggle="tab" data-bs-target="#unpromoted-pane" type="button" role="tab" aria-controls="unpromoted-pane" aria-selected="false">
                            <i class="bi bi-person-x-fill me-1"></i> Not Yet Promoted <span class="badge bg-danger rounded-pill fs-9"><?= count($unpromoted_learners) ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold border-0 px-4 py-2 text-success" id="log-tab" data-bs-toggle="tab" data-bs-target="#log-pane" type="button" role="tab" aria-controls="log-pane" aria-selected="false">
                            <i class="bi bi-journal-text me-1"></i> Promotion History Log
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="promotionTabsContent">
                    <!-- Tab 1: Single Promotion -->
                    <div class="tab-pane fade show active" id="single-pane" role="tabpanel" aria-labelledby="single-tab">
                        <div class="row g-4">
                            <!-- Left Column: Form -->
                            <div class="col-lg-7 animate-fade-up">
                                <div class="card rounded-4 border-0 shadow-sm">
                                    <div class="card-body p-4">
                                        <h5 class="fw-bold mb-1">Advance a learner to the next grade</h5>
                                        <p class="text-muted small mb-4">The current enrollment is marked historical and a new record is created for the next school year.</p>

                                        <form method="POST" id="singlePromotionForm">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="promote">
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold small text-uppercase">Learner</label>
                                                    <div class="position-relative">
                                                        <input type="text" id="promoteSearchInput" class="form-control rounded-3" placeholder="Search name or LRN..." autocomplete="off">
                                                        <input type="hidden" name="lrn" id="promoteSelectedLrn" required>
                                                        <div id="promoteSearchResults" class="list-group position-absolute w-100 shadow-sm rounded-3" style="z-index: 1000; display: none; max-height: 250px; overflow-y: auto;"></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold small text-uppercase">School Year</label>
                                                    <select name="school_year" class="form-select rounded-3" required>
                                                        <option value="" disabled selected>-- Select Learner First --</option>
                                                    </select>
                                                </div>
                                            </div>
                                            

                                            <div class="mb-3" id="nextGradeWrap">
                                                <label class="form-label fw-bold small text-uppercase">Next Grade / Level</label>
                                                <select name="next_grade_level" id="nextGradeLevel" class="form-select rounded-3" required>
                                                    <?= grade_level_options('', [], false) ?>
                                                </select>
                                            </div>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold small text-uppercase">Placement Type</label>
                                                    <select name="placement_type" id="indPlacement" class="form-select rounded-3" required>
                                                        <option value="Graded">Graded</option>
                                                        <option value="Non-Graded">Non-Graded</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6" id="indSelfContainedLevelWrap" style="display: none;">
                                                    <label class="form-label fw-bold small text-uppercase">Program</label>
                                                    <select name="self_contained_level" class="form-select rounded-3">
                                                        <option value="">— Select Program —</option>
                                                        <option value="Kinder">Kinder</option>
                                                        <option value="Primary Level 1">Primary Level 1</option>
                                                        <option value="Primary Level 2">Primary Level 2</option>
                                                        <option value="Primary Level 3">Primary Level 3</option>
                                                        <option value="Transition">Transition</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold"><i class="bi bi-graph-up-arrow me-1"></i> Promote Learner</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column: Info Panel -->
                            <div class="col-lg-5 animate-fade-up stagger-1">
                                <div id="selectedLearnerPlaceholder" class="card rounded-4 border-0 shadow-sm p-4 text-center text-muted h-100 d-flex flex-column justify-content-center align-items-center" style="min-height: 350px;">
                                    <i class="bi bi-person-bounding-box fs-1 mb-2 text-secondary opacity-50"></i>
                                    <p class="mb-0 small text-uppercase fw-bold" style="letter-spacing: 0.05em;">No Learner Selected</p>
                                    <span class="d-block small text-muted mt-1">Search and select a student to see profile details.</span>
                                </div>

                                <div id="promoteLearnerInfo" class="card rounded-4 border-0 shadow-sm p-4 d-none h-100">
                                    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                        <i class="bi bi-person-check-fill text-success fs-4"></i>
                                        <h6 class="fw-bold mb-0 text-dark">Learner Profile Info</h6>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Name</label>
                                                <span class="text-dark fw-bold fs-6" id="infoName"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">LRN</label>
                                                <span class="text-dark fw-semibold" id="infoLrn"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Age</label>
                                                <span class="text-dark fw-semibold"><span id="infoAge"></span> yrs old</span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Current School</label>
                                                <span class="text-dark fw-semibold" id="infoSchool"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Grade Level</label>
                                                <span class="text-dark fw-semibold" id="infoGrade"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Placement</label>
                                                <span class="text-dark fw-semibold" id="infoPlacement"></span>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Primary Diagnosis</label>
                                                <span class="badge badge-premium-diagnosis py-2 px-3 fs-7" id="infoDiagnosis"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Enrollment SY</label>
                                                <span class="badge bg-light text-dark border py-2 px-3 fs-7" id="infoSy"></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-item">
                                                <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Enrollment Status</label>
                                                <span id="infoEnrollmentStatus" class="badge py-2 px-3 fs-7"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Bulk Class Promotion -->
                    <div class="tab-pane fade" id="bulk-pane" role="tabpanel" aria-labelledby="bulk-tab">
                        <div class="card rounded-4 border-0 shadow-sm mb-4 animate-fade-up">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-1">Promote Learners in Batch</h5>
                                <p class="text-muted small mb-4">Select the class filters to load active learners who are not yet promoted for the target school year.</p>

                                <div class="row g-3 mb-4">
                                    <?php if (!user_is_scoped($user_role)): ?>
                                        <div class="col-md-8">
                                            <label class="form-label fw-bold small text-uppercase">School</label>
                                            <select id="bulkSchoolSelect" class="form-select rounded-3">
                                                <option value="">— Select School —</option>
                                                <?php foreach ($all_schools as $school): ?>
                                                    <option value="<?= $school['id'] ?>"><?= htmlspecialchars($school['school_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="button" id="btnLoadRoster" class="btn btn-primary rounded-pill px-4 fw-bold w-100" style="min-height: 48px;">
                                                <i class="bi bi-arrow-repeat me-1"></i> Load Roster
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" id="bulkSchoolSelect" value="<?= $user_school_id ?>">
                                        <div class="col-12 text-end">
                                            <button type="button" id="btnLoadRoster" class="btn btn-primary rounded-pill px-5 fw-bold" style="min-height: 48px;">
                                                <i class="bi bi-arrow-repeat me-1"></i> Load School Roster
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Roster Area -->
                                <div id="bulkRosterPlaceholder" class="text-center text-muted py-5 border rounded-4 bg-light" style="border-style: dashed !important;">
                                    <i class="bi bi-people fs-1 mb-2 opacity-50"></i>
                                    <p class="mb-0 fw-bold small text-uppercase">No Roster Loaded</p>
                                    <span class="small text-muted">Select filters and load the active class enrollees.</span>
                                </div>

                                <form method="POST" id="bulkPromotionForm" class="d-none">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="bulk_promote">
                                    
                                    <div class="alert bg-success-subtle border-success-subtle text-success p-3 rounded-4 mb-4 d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="bi bi-info-circle-fill me-2"></i>
                                            Target Promotion School Year: <strong id="lblBulkTargetSy"></strong>
                                            <input type="hidden" name="bulk_school_year" id="hiddenBulkSchoolYear">
                                        </div>
                                    </div>

                                    <!-- Bulk global adjusters -->
                                    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border align-items-end">
                                        <div class="col-md-9">
                                            <label class="form-label fw-bold small text-uppercase">Set Next Grade for checked</label>
                                            <select id="bulkGlobalGradeSelect" class="form-select rounded-3">
                                                <option value="">— Select Level —</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="button" id="btnApplyBulkGlobal" class="btn btn-outline-success rounded-pill w-100 fw-semibold" style="min-height:48px;">Apply</button>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle border" id="tblBulkRoster">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="40"><input type="checkbox" id="chkSelectAll" checked></th>
                                                    <th>LRN</th>
                                                    <th>Learner Name</th>
                                                    <th>Current Status</th>
                                                    <th>Next Grade / Program</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bulkRosterTbody"></tbody>
                                        </table>
                                    </div>

                                    <button type="submit" class="btn btn-success rounded-pill px-5 py-2.5 fw-bold mt-3"><i class="bi bi-graph-up-arrow me-1"></i> Promote Checked Learners</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Promotion History Log -->
                    <div class="tab-pane fade" id="log-pane" role="tabpanel" aria-labelledby="log-tab">
                        <div class="card rounded-4 border-0 shadow-sm animate-fade-up">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-1">Promotion History Log</h5>
                                <p class="text-muted small mb-4">View recent learner advancements and revert them if necessary.</p>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle border">
                                        <thead class="table-light">
                                            <tr>
                                                <th>LRN</th>
                                                <th>Learner Name</th>
                                                <th>From Grade (SY)</th>
                                                <th>To Grade (SY)</th>
                                                <th>To School</th>
                                                <th>Promoted By</th>
                                                <th>Date Promoted</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($promotion_history)): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">No recent promotions found in the log history.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($promotion_history as $hist): ?>
                                                    <tr>
                                                        <td><code><?= htmlspecialchars($hist['lrn']) ?></code></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($hist['last_name'] . ', ' . $hist['first_name']) ?></td>
                                                        <td>
                                                            <?php 
                                                            if (($hist['prev_placement'] ?? '') === 'Non-Graded') {
                                                                echo htmlspecialchars($hist['prev_self_contained'] ?? 'Non-Graded');
                                                            } else {
                                                                echo htmlspecialchars($hist['prev_grade_level'] ?? 'Graded');
                                                            }
                                                            ?>
                                                            <span class="badge bg-light text-secondary border small">SY <?= htmlspecialchars($hist['prev_school_year']) ?></span>
                                                        </td>
                                                        <td>
                                                            <i class="bi bi-arrow-right text-success mx-1"></i>
                                                            <?php 
                                                            if ($hist['placement_type'] === 'Non-Graded') {
                                                                echo htmlspecialchars($hist['self_contained_level'] ?? 'Non-Graded');
                                                            } else {
                                                                echo htmlspecialchars($hist['grade_level'] ?? 'Graded');
                                                            }
                                                            ?>
                                                            <span class="badge bg-success-subtle text-success border small">SY <?= htmlspecialchars($hist['school_year']) ?></span>
                                                        </td>
                                                        <td><small><?= htmlspecialchars($hist['school_name']) ?></small></td>
                                                        <td><small><?= htmlspecialchars($hist['promoter_name'] ?? 'System') ?></small></td>
                                                        <td><small><?= date('M d, Y g:i A', strtotime($hist['created_at'])) ?></small></td>
                                                        <td class="text-center">
                                                            <form method="POST" onsubmit="return confirm('Are you sure you want to REVERT this promotion? This will delete the active SY <?= htmlspecialchars($hist['school_year']) ?> enrollment and restore the historical SY <?= htmlspecialchars($hist['prev_school_year']) ?> enrollment.');" style="display:inline-block;">
                                                                <?= csrf_input() ?>
                                                                <input type="hidden" name="action" value="revert_promotion">
                                                                <input type="hidden" name="enrollment_id" value="<?= $hist['enrollment_id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                                    <i class="bi bi-arrow-counterclockwise"></i> Revert
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: Not Yet Promoted Learners -->
                    <div class="tab-pane fade" id="unpromoted-pane" role="tabpanel" aria-labelledby="unpromoted-tab">
                        <div class="card rounded-4 border-0 shadow-sm animate-fade-up">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-1">Learners Pending Promotion</h5>
                                <p class="text-muted small mb-4">Active enrollees in SY <?= htmlspecialchars($current_sy) ?> who have not yet been promoted to SY <?= htmlspecialchars($target_sy) ?>.</p>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle border">
                                        <thead class="table-light">
                                            <tr>
                                                <th>LRN</th>
                                                <th>Learner Name</th>
                                                <th>Current School</th>
                                                <th>Placement & Grade / Program</th>
                                                <th>Current SY</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($unpromoted_learners)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">All active enrollees have been promoted for the next school year!</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($unpromoted_learners as $l): ?>
                                                    <tr>
                                                        <td><code><?= htmlspecialchars($l['lrn']) ?></code></td>
                                                        <td class="fw-bold text-dark"><?= htmlspecialchars($l['last_name'] . ', ' . $l['first_name']) ?></td>
                                                        <td><small><?= htmlspecialchars($l['school_name'] ?? '—') ?></small></td>
                                                        <td>
                                                            <span class="small">
                                                                <?= htmlspecialchars($l['placement_type']) ?> &middot; 
                                                                <?= htmlspecialchars($l['placement_type'] === 'Non-Graded' ? ($l['self_contained_level'] ?? '—') : ($l['grade_level'] ?? '—')) ?>
                                                            </span>
                                                        </td>
                                                        <td><span class="badge bg-light text-secondary border">SY <?= htmlspecialchars($l['school_year']) ?></span></td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 btn-select-to-promote" data-lrn="<?= htmlspecialchars($l['lrn']) ?>" data-name="<?= htmlspecialchars($l['last_name'] . ', ' . $l['first_name']) ?>">
                                                                <i class="bi bi-arrow-right-short"></i> Promote
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        const currentSy = '<?= $current_sy ?>';
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }

        // --- Single Promote Javascript ---
        const promotionGradeLevelGroups = <?= json_encode(sned_grade_level_groups(false), JSON_UNESCAPED_SLASHES) ?>;
        let selectedLearnerGradeLevels = [];

        function getNextLogicalLevel(currentVal, placement) {
            if (placement === 'Non-Graded') {
                const order = ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'];
                const idx = order.indexOf(currentVal);
                return (idx !== -1 && idx < order.length - 1) ? order[idx + 1] : currentVal;
            } else {
                const order = ['Kindergarten', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
                const idx = order.indexOf(currentVal);
                return (idx !== -1 && idx < order.length - 1) ? order[idx + 1] : currentVal;
            }
        }

        function renderPromotionGradeOptions(allowedValues, selectedValue) {
            const select = document.getElementById('nextGradeLevel');
            if (!select) return;
            const allowed = new Set(Array.isArray(allowedValues) ? allowedValues : []);
            const selected = selectedValue || '';
            select.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = allowed.size > 0 ? '-- Select Grade Level --' : 'Select a learner with configured school grade levels';
            placeholder.disabled = allowed.size === 0;
            select.appendChild(placeholder);

            Object.entries(promotionGradeLevelGroups).forEach(([label, options]) => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = label;
                let hasOptions = false;

                Object.entries(options).forEach(([value, text]) => {
                    if (!allowed.has(value)) return;
                    hasOptions = true;
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = text;
                    if (selected === value) {
                        option.selected = true;
                    }
                    optgroup.appendChild(option);
                });

                if (hasOptions) {
                    select.appendChild(optgroup);
                }
            });
        }

        const indPlacement = document.getElementById('indPlacement');
        const indSelfContainedWrap = document.getElementById('indSelfContainedLevelWrap');
        const nextGradeWrap = document.getElementById('nextGradeWrap');
        const nextGradeLevel = document.getElementById('nextGradeLevel');
        if (indPlacement && indSelfContainedWrap) {
            indPlacement.addEventListener('change', function() {
                const isNonGraded = this.value === 'Non-Graded';
                indSelfContainedWrap.style.display = isNonGraded ? '' : 'none';
                if (nextGradeWrap) nextGradeWrap.style.display = isNonGraded ? 'none' : '';
                if (nextGradeLevel) nextGradeLevel.required = !isNonGraded;
            });
            indPlacement.dispatchEvent(new Event('change'));
        }
        renderPromotionGradeOptions([]);

        const promoteSearchInput = document.getElementById('promoteSearchInput');
        const promoteSearchResults = document.getElementById('promoteSearchResults');
        const promoteSelectedLrn = document.getElementById('promoteSelectedLrn');
        const promoteLearnerInfo = document.getElementById('promoteLearnerInfo');
        let promoteSearchTimeout = null;

        promoteSearchInput.addEventListener('input', function() {
            clearTimeout(promoteSearchTimeout);
            const q = this.value.trim();
            if (q.length < 2) {
                promoteSearchResults.style.display = 'none';
                promoteSelectedLrn.value = '';
                selectedLearnerGradeLevels = [];
                renderPromotionGradeOptions([]);
                promoteLearnerInfo.classList.add('d-none');
                document.getElementById('selectedLearnerPlaceholder').classList.remove('d-none');
                const sySelect = document.querySelector('select[name="school_year"]');
                if (sySelect) {
                    sySelect.innerHTML = '<option value="" disabled selected>-- Select Learner First --</option>';
                }
                return;
            }
            promoteSearchTimeout = setTimeout(function() {
                fetch('ajax_learner_search.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        promoteSearchResults.innerHTML = '';
                        if (!data.success || data.results.length === 0) {
                            promoteSearchResults.innerHTML = '<div class="list-group-item text-muted small">No learners found.</div>';
                            promoteSearchResults.style.display = 'block';
                            return;
                        }
                        data.results.forEach(l => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'list-group-item list-group-item-action py-2';
                            item.innerHTML = '<div class="fw-semibold small">' + l.name + '</div><div class="small text-muted"><code>' + l.lrn + '</code> &middot; ' + l.school_name + ' &middot; ' + l.grade_level + '</div>';
                            item.addEventListener('click', function() {
                                promoteSelectedLrn.value = l.lrn;
                                promoteSearchInput.value = l.name + ' (' + l.lrn + ')';
                                promoteSearchResults.style.display = 'none';
                                selectedLearnerGradeLevels = l.school_grade_levels || [];
                                renderPromotionGradeOptions(selectedLearnerGradeLevels);
                                
                                document.getElementById('infoName').textContent = l.name;
                                document.getElementById('infoLrn').textContent = l.lrn;
                                document.getElementById('infoAge').textContent = l.age;
                                document.getElementById('infoSchool').textContent = l.school_name;
                                document.getElementById('infoGrade').textContent = l.grade_level;
                                
                                let placementText = l.placement;
                                if (l.self_contained_level) {
                                    placementText += ' (' + l.self_contained_level + ')';
                                }
                                document.getElementById('infoPlacement').textContent = placementText;
                                document.getElementById('infoDiagnosis').textContent = l.primary_diagnosis;
                                document.getElementById('infoSy').textContent = l.school_year;
                                
                                // Automatically restrict options and select the next school year based on current enrollment SY
                                if (l.school_year) {
                                    const parts = l.school_year.split('-');
                                    if (parts.length === 2) {
                                        const nextSy = (parseInt(parts[0]) + 1) + '-' + (parseInt(parts[1]) + 1);
                                        const sySelect = document.querySelector('select[name="school_year"]');
                                        if (sySelect) {
                                            sySelect.innerHTML = '';
                                            
                                            // Placeholder option at index 0
                                            const placeholder = document.createElement('option');
                                            placeholder.value = '';
                                            placeholder.textContent = '-- Select School Year --';
                                            placeholder.disabled = true;
                                            sySelect.appendChild(placeholder);
                                            
                                            // Next school year option at index 1
                                            const opt = document.createElement('option');
                                            opt.value = nextSy;
                                            opt.textContent = 'SY ' + nextSy;
                                            opt.selected = true;
                                            sySelect.appendChild(opt);
                                        }
                                    }
                                }
                                
                                // Automatically select the correct Placement Type and logical next Grade/Program
                                const indPlacement = document.getElementById('indPlacement');
                                if (indPlacement) {
                                    indPlacement.value = l.placement;
                                    indPlacement.dispatchEvent(new Event('change'));
                                    
                                    if (l.placement === 'Non-Graded') {
                                        const selfContainedSelect = document.querySelector('select[name="self_contained_level"]');
                                        if (selfContainedSelect) {
                                            const nextLogical = getNextLogicalLevel(l.self_contained_level, 'Non-Graded');
                                            selfContainedSelect.value = nextLogical;
                                        }
                                    } else {
                                        const gradeSelect = document.getElementById('nextGradeLevel');
                                        if (gradeSelect) {
                                            const nextLogical = getNextLogicalLevel(l.grade_level, 'Graded');
                                            gradeSelect.value = nextLogical;
                                        }
                                    }
                                }
                                
                                const statusSpan = document.getElementById('infoEnrollmentStatus');
                                statusSpan.className = 'badge py-2 px-3 fs-7';
                                if (l.school_year === currentSy) {
                                    statusSpan.textContent = 'Already Enrolled for SY ' + currentSy;
                                    statusSpan.classList.add('bg-success-subtle', 'text-success', 'border', 'border-success-subtle');
                                } else {
                                    statusSpan.textContent = 'Not Yet Enrolled for SY ' + currentSy;
                                    statusSpan.classList.add('bg-danger-subtle', 'text-danger', 'border', 'border-danger-subtle');
                                }
                                
                                document.getElementById('selectedLearnerPlaceholder').classList.add('d-none');
                                promoteLearnerInfo.classList.remove('d-none');
                            });
                            promoteSearchResults.appendChild(item);
                        });
                        promoteSearchResults.style.display = 'block';
                    })
                    .catch(() => {
                        promoteSearchResults.innerHTML = '<div class="list-group-item text-danger small">Server error.</div>';
                        promoteSearchResults.style.display = 'block';
                    });
            }, 300);
        });

        // Global click handler to close dropdown
        document.addEventListener('click', function(e) {
            if (!promoteSearchInput.contains(e.target) && !promoteSearchResults.contains(e.target)) {
                promoteSearchResults.style.display = 'none';
            }
        });

        // --- Bulk Promote Javascript ---
        const bulkSchoolSelect = document.getElementById('bulkSchoolSelect');
        const btnLoadRoster = document.getElementById('btnLoadRoster');
        const bulkRosterPlaceholder = document.getElementById('bulkRosterPlaceholder');
        const bulkPromotionForm = document.getElementById('bulkPromotionForm');
        const bulkRosterTbody = document.getElementById('bulkRosterTbody');
        const chkSelectAll = document.getElementById('chkSelectAll');
        const lblBulkTargetSy = document.getElementById('lblBulkTargetSy');
        const hiddenBulkSchoolYear = document.getElementById('hiddenBulkSchoolYear');
        const bulkGlobalGradeSelect = document.getElementById('bulkGlobalGradeSelect');
        const btnApplyBulkGlobal = document.getElementById('btnApplyBulkGlobal');



        // Populates target select option list options
        function buildGradeOptionsList(placement, currentVal) {
            const nextVal = getNextLogicalLevel(currentVal, placement);
            let html = '';
            
            if (placement === 'Non-Graded') {
                const programs = ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'];
                programs.forEach(p => {
                    const sel = (p === nextVal) ? 'selected' : '';
                    html += `<option value="${p}" ${sel}>${p}</option>`;
                });
            } else {
                Object.entries(promotionGradeLevelGroups).forEach(([label, options]) => {
                    html += `<optgroup label="${label}">`;
                    Object.entries(options).forEach(([val, txt]) => {
                        const sel = (val === nextVal) ? 'selected' : '';
                        html += `<option value="${val}" ${sel}>${txt}</option>`;
                    });
                    html += `</optgroup>`;
                });
            }
            return html;
        }

        if (btnLoadRoster) {
            btnLoadRoster.addEventListener('click', function() {
                const schoolId = bulkSchoolSelect.value;

                if (!schoolId) {
                    alert('Please select a school.');
                    return;
                }

                // Compute target SY based on currentSy
                const parts = currentSy.split('-');
                const targetSy = (parseInt(parts[0]) + 1) + '-' + (parseInt(parts[1]) + 1);

                lblBulkTargetSy.textContent = targetSy;
                hiddenBulkSchoolYear.value = targetSy;

                // Load roster via AJAX
                let url = `ajax_promotion_roster.php?school_id=${schoolId}&current_sy=${currentSy}&target_sy=${targetSy}`;

                btnLoadRoster.disabled = true;
                btnLoadRoster.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Loading...';

                fetch(url)
                    .then(r => r.json())
                    .then(data => {
                        btnLoadRoster.disabled = false;
                        btnLoadRoster.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Load Roster';

                        if (!data.success) {
                            alert(data.message || 'Failed to load roster.');
                            return;
                        }

                        bulkRosterTbody.innerHTML = '';
                        if (data.results.length === 0) {
                            bulkRosterPlaceholder.innerHTML = `
                                <i class="bi bi-info-circle fs-1 mb-2 text-warning"></i>
                                <p class="mb-0 fw-bold small text-uppercase">No Learners Found</p>
                                <span class="small text-muted">All active learners in this school are already promoted to SY ${targetSy}.</span>
                            `;
                            bulkRosterPlaceholder.classList.remove('d-none');
                            bulkPromotionForm.classList.add('d-none');
                            return;
                        }

                        // Success: Populate Table
                        bulkRosterPlaceholder.classList.add('d-none');
                        bulkPromotionForm.classList.remove('d-none');

                        // Populate Global Adjuster options with both Graded and Non-Graded options
                        bulkGlobalGradeSelect.innerHTML = '<option value="">— Select Level / Program —</option>';
                        
                        const ngGroup = document.createElement('optgroup');
                        ngGroup.label = 'Non-Graded Programs';
                        ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'].forEach(p => {
                            const opt = document.createElement('option');
                            opt.value = p;
                            opt.textContent = p;
                            ngGroup.appendChild(opt);
                        });
                        bulkGlobalGradeSelect.appendChild(ngGroup);

                        Object.entries(promotionGradeLevelGroups).forEach(([label, options]) => {
                            const optgroup = document.createElement('optgroup');
                            optgroup.label = label;
                            Object.entries(options).forEach(([val, txt]) => {
                                const opt = document.createElement('option');
                                opt.value = val;
                                opt.textContent = txt;
                                optgroup.appendChild(opt);
                            });
                            bulkGlobalGradeSelect.appendChild(optgroup);
                        });

                        // Render each row
                        data.results.forEach(l => {
                            const isNonGraded = l.placement === 'Non-Graded';
                            const curLevel = isNonGraded ? l.self_contained_level : l.grade_level;
                            const nextLogical = getNextLogicalLevel(curLevel, l.placement);
                            const optionsListHtml = buildGradeOptionsList(l.placement, curLevel);

                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>
                                    <input type="checkbox" name="checked_lrns[]" value="${l.lrn}" class="chk-learner form-check-input" checked>
                                </td>
                                <td><code>${l.lrn}</code></td>
                                <td class="fw-bold text-dark">${l.name}</td>
                                <td>
                                    <span class="small text-muted">${l.placement} &middot; ${curLevel}</span>
                                </td>
                                <td>
                                    <input type="hidden" name="promotions[${l.lrn}][placement_type]" value="${l.placement}">
                                    <input type="hidden" name="promotions[${l.lrn}][self_contained_level]" class="self-contained-hidden" value="${isNonGraded ? nextLogical : ''}">
                                    <select name="promotions[${l.lrn}][next_grade_level]" class="form-select form-select-sm select-row-grade" required style="max-width: 220px;">
                                        ${optionsListHtml}
                                    </select>
                                </td>
                            `;

                            // Sync hidden self-contained level if selection changes
                            const selectGrade = tr.querySelector('.select-row-grade');
                            const hiddenSelfContained = tr.querySelector('.self-contained-hidden');
                            selectGrade.addEventListener('change', function() {
                                if (isNonGraded && hiddenSelfContained) {
                                    hiddenSelfContained.value = this.value;
                                }
                            });

                            bulkRosterTbody.appendChild(tr);
                        });

                        // Set up select all checkbox behavior
                        chkSelectAll.checked = true;
                    })
                    .catch(err => {
                        btnLoadRoster.disabled = false;
                        btnLoadRoster.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Load Roster';
                        alert('An error occurred while loading roster: ' + err.message);
                    });
            });
        }

        // Bulk apply global adjusters to checked rows
        if (btnApplyBulkGlobal) {
            btnApplyBulkGlobal.addEventListener('click', function() {
                const globalGrade = bulkGlobalGradeSelect.value;

                if (!globalGrade) {
                    alert('Please select a grade to apply.');
                    return;
                }

                const checkedRows = document.querySelectorAll('.chk-learner:checked');
                checkedRows.forEach(chk => {
                    const row = chk.closest('tr');
                    const gradeSelect = row.querySelector('.select-row-grade');
                    
                    if (globalGrade && gradeSelect) {
                        // Check if the selected option exists in this learner's select dropdown (since Graded and Non-Graded options vary)
                        const optionExists = Array.from(gradeSelect.options).some(opt => opt.value === globalGrade);
                        if (optionExists) {
                            gradeSelect.value = globalGrade;
                            gradeSelect.dispatchEvent(new Event('change'));
                        }
                    }
                });
            });
        }

        // Master Select All / Unselect All checkbox handler
        if (chkSelectAll) {
            chkSelectAll.addEventListener('change', function() {
                const checked = this.checked;
                const checkboxes = document.querySelectorAll('.chk-learner');
                checkboxes.forEach(chk => {
                    chk.checked = checked;
                });
            });
        }

        // Intercept Single Promotion form submit to show Before vs After screen
        let isSingleConfirmed = false;
        const singleForm = document.getElementById('singlePromotionForm');
        if (singleForm) {
            singleForm.addEventListener('submit', function(e) {
                if (!isSingleConfirmed) {
                    e.preventDefault();
                    
                    // Before/Current data
                    document.getElementById('modalLrnName').textContent = document.getElementById('infoName').textContent;
                    document.getElementById('modalLrnCode').textContent = document.getElementById('infoLrn').textContent;
                    document.getElementById('confirmBeforeSy').textContent = 'SY ' + document.getElementById('infoSy').textContent;
                    document.getElementById('confirmBeforePlacement').textContent = document.getElementById('infoPlacement').textContent;
                    document.getElementById('confirmBeforeLevel').textContent = document.getElementById('infoGrade').textContent;

                    // After/Proposed data
                    const sySelect = document.querySelector('select[name="school_year"]');
                    document.getElementById('confirmAfterSy').textContent = 'SY ' + sySelect.value;
                    
                    const placementVal = document.getElementById('indPlacement').value;
                    document.getElementById('confirmAfterPlacement').textContent = placementVal;
                    
                    if (placementVal === 'Non-Graded') {
                        const progSelect = document.querySelector('select[name="self_contained_level"]');
                        document.getElementById('confirmAfterLevel').textContent = progSelect.value ? progSelect.value : '—';
                    } else {
                        const gradeSelect = document.getElementById('nextGradeLevel');
                        document.getElementById('confirmAfterLevel').textContent = gradeSelect.options[gradeSelect.selectedIndex].text;
                    }

                    const modalEl = document.getElementById('singleConfirmModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            });
        }

        // Intercept Bulk Promotion form submit to show count confirmation
        let isBulkConfirmed = false;
        const bulkForm = document.getElementById('bulkPromotionForm');
        if (bulkForm) {
            bulkForm.addEventListener('submit', function(e) {
                if (!isBulkConfirmed) {
                    e.preventDefault();
                    
                    const checkedCount = document.querySelectorAll('.chk-learner:checked').length;
                    if (checkedCount === 0) {
                        alert('Please select at least one student to promote.');
                        return;
                    }

                    document.getElementById('confirmBulkCount').textContent = checkedCount;
                    document.getElementById('confirmBulkSy').textContent = document.getElementById('hiddenBulkSchoolYear').value;

                    const modalEl = document.getElementById('bulkConfirmModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            });
        }

        // Event delegation for confirm buttons click handlers (since modal buttons are defined at the end of body and may not be in DOM yet)
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'btnConfirmSinglePromote') {
                isSingleConfirmed = true;
                if (typeof singleForm.requestSubmit === 'function') {
                    singleForm.requestSubmit();
                } else {
                    singleForm.submit();
                }
            }
            if (e.target && e.target.id === 'btnConfirmBulkPromote') {
                isBulkConfirmed = true;
                if (typeof bulkForm.requestSubmit === 'function') {
                    bulkForm.requestSubmit();
                } else {
                    bulkForm.submit();
                }
            }
        });

        // Click handler to select and load learner for single promotion
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('btn-select-to-promote')) {
                const lrn = e.target.getAttribute('data-lrn');
                
                // 1. Switch to Single Promotion tab
                const singleTabEl = document.getElementById('single-tab');
                if (singleTabEl) {
                    const tab = bootstrap.Tab.getOrCreateInstance(singleTabEl);
                    tab.show();
                }
                
                // 2. Populate Single Promotion search input and trigger search
                const promoteSearchInput = document.getElementById('promoteSearchInput');
                if (promoteSearchInput) {
                    promoteSearchInput.value = lrn;
                    promoteSearchInput.dispatchEvent(new Event('input'));
                    
                    // Automatically click the first matching student from results
                    setTimeout(() => {
                        const firstResult = document.querySelector('#promoteSearchResults .list-group-item');
                        if (firstResult) {
                            firstResult.click();
                        }
                    }, 400);
                }
            }
        });
    </script>
    <!-- Single Promotion Before vs After Confirmation Modal -->
    <div class="modal fade" id="singleConfirmModal" tabindex="-1" aria-labelledby="singleConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="singleConfirmModalLabel"><i class="bi bi-shield-fill-check text-success me-2"></i> Confirm Learner Promotion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Please verify the enrollment updates below before submitting the promotion.</p>
                    
                    <div class="card bg-light rounded-4 border p-3 mb-4">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-circle text-secondary fs-4"></i>
                            <div>
                                <h6 class="fw-bold text-dark mb-0" id="modalLrnName">John Doe</h6>
                                <small class="text-muted">LRN: <code id="modalLrnCode">000000000000</code></small>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Before (Current) Column -->
                        <div class="col-md-6">
                            <div class="p-3 border rounded-4 bg-white h-100">
                                <div class="d-flex align-items-center gap-2 mb-3 text-secondary">
                                    <i class="bi bi-arrow-left-circle-fill"></i>
                                    <span class="fw-bold text-uppercase small">Before (Current)</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">School Year</label>
                                    <span class="text-dark fw-semibold" id="confirmBeforeSy">SY 2025-2026</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Placement Type</label>
                                    <span class="text-dark fw-semibold" id="confirmBeforePlacement">Graded</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Grade Level / Program</label>
                                    <span class="text-dark fw-semibold" id="confirmBeforeLevel">Grade 1</span>
                                </div>
                            </div>
                        </div>

                        <!-- After (Proposed) Column -->
                        <div class="col-md-6">
                            <div class="p-3 border border-success-subtle rounded-4 bg-success-subtle bg-opacity-10 h-100">
                                <div class="d-flex align-items-center gap-2 mb-3 text-success">
                                    <i class="bi bi-arrow-right-circle-fill"></i>
                                    <span class="fw-bold text-uppercase small">After (New Promotion)</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">School Year</label>
                                    <span class="text-success fw-bold" id="confirmAfterSy">SY 2026-2027</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Placement Type</label>
                                    <span class="text-success fw-bold" id="confirmAfterPlacement">Graded</span>
                                </div>
                                <div class="mb-3">
                                    <label class="text-uppercase text-muted x-small d-block mb-1 fw-bold">Grade Level / Program</label>
                                    <span class="text-success fw-bold" id="confirmAfterLevel">Grade 2</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="btnConfirmSinglePromote" class="btn btn-success rounded-pill px-4 fw-bold">Confirm & Promote</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Promotion Confirmation Modal -->
    <div class="modal fade" id="bulkConfirmModal" tabindex="-1" aria-labelledby="bulkConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="bulkConfirmModalLabel"><i class="bi bi-people-fill text-success me-2"></i> Confirm Batch Promotion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert bg-success-subtle border-success-subtle text-success p-3 rounded-4 mb-3">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Target Promotion Year: <strong id="confirmBulkSy"></strong>
                    </div>
                    <p class="text-dark mb-0">You are about to promote <strong class="text-success" id="confirmBulkCount">0</strong> checked enrollees in batch.</p>
                    <p class="text-muted small mt-1">Their active enrollments will be archived as historical and new active enrollees will be created under their next grades/programs.</p>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="btnConfirmBulkPromote" class="btn btn-success rounded-pill px-4 fw-bold">Promote Group</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
