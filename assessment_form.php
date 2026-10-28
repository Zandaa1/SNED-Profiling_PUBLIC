<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

// 1. Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

// 2. Role check — coordinator or above
if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

// 3. Learner validation
$lrn = $_GET['id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid LRN request.");
}

// Scope check for scoped roles
if (!user_is_admin($user_role) && user_is_scoped($user_role)) {
    if (!check_teacher_learner_access($conn, $lrn, true)) {
        header("Location: 403.php");
        exit();
    }
}

// Fetch assessment data using helper
$assessment_data = assessment_load_data($conn, $lrn);
$learner = $assessment_data['learner'];
if (!$learner) {
    die("Learner not found.");
}
$grouped_items = $assessment_data['grouped_items'];
$responses = $assessment_data['responses'];
$dates = $assessment_data['dates'];
$area_totals = $assessment_data['area_totals'];
$area_rated = $assessment_data['area_rated'];

// Compute overall metrics
$total_items_count = array_sum($area_totals);
$total_rated_count = array_sum($area_rated);
$total_pct = $total_items_count > 0 ? (int)round(($total_rated_count / $total_items_count) * 100) : 0;

// Include sidebar compact styling flag
$currentPage = basename($_SERVER['PHP_SELF']);
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Assessment Checklist — <?= htmlspecialchars(sned_learner_name($learner)) ?></title>
    <link class="styles" rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        .checklist-table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: var(--sned-cloud) !important;
            color: var(--sned-neutral-dark);
            border-bottom: 2px solid var(--color-rule) !important;
            padding: 12px 14px;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .checklist-table td {
            padding: 10px 14px;
            vertical-align: middle;
            border-bottom: 1px solid var(--color-rule);
        }
        .checklist-table tbody tr:hover:not(.group-divider-row):not(.subsection-divider-row) {
            background-color: rgba(var(--color-primary-rgb), 0.015);
        }
        .subsection-divider-row {
            background-color: var(--sned-cloud) !important;
            border-left: 4px solid var(--sned-neutral);
        }
        .subsection-divider-row td {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--sned-neutral-dark);
            letter-spacing: 0.03em;
            padding: 10px 14px !important;
        }
        .group-divider-row {
            background-color: rgba(var(--color-primary-rgb), 0.02) !important;
            border-left: 4px solid var(--color-primary);
        }
        .group-divider-row td {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--color-primary);
            padding: 8px 14px !important;
        }
        .rating-btn-group input[type="radio"] {
            display: none;
        }
        .rating-btn-group label {
            padding: 4px 10px;
            font-size: 0.725rem;
            font-weight: 700;
            border: 1px solid var(--color-rule);
            background: #fff;
            color: var(--sned-neutral);
            cursor: pointer;
            transition: all 0.15s ease;
            margin-bottom: 0;
            min-width: 38px;
            text-align: center;
        }
        .rating-btn-group label:focus {
            outline: 2px solid var(--color-primary);
            outline-offset: 1px;
            z-index: 1;
        }
        .rating-btn-group label:first-of-type {
            border-radius: 6px 0 0 6px;
        }
        .rating-btn-group label:last-of-type {
            border-radius: 0 6px 6px 0;
            border-left: 0;
        }
        .rating-btn-group label:not(:first-of-type):not(:last-of-type) {
            border-left: 0;
        }
        .rating-btn-group input[value="E"]:checked + label {
            background-color: var(--color-success);
            color: #fff;
            border-color: var(--color-success);
        }
        .rating-btn-group input[value="NYE"]:checked + label {
            background-color: var(--color-warning);
            color: #fff;
            border-color: var(--color-warning);
        }
        .rating-btn-group input[value="NO"]:checked + label {
            background-color: var(--color-primary);
            color: #fff;
            border-color: var(--color-primary);
        }
        .remarks-input {
            font-size: 0.85rem;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid var(--color-rule);
            width: 100%;
            transition: border-color 0.15s ease;
        }
        .remarks-input:focus {
            border-color: var(--color-primary);
            outline: none;
        }
        .save-indicator {
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }
        .legend-card {
            background: #fff;
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        /* Left Navigation Sidebar Design */
        .nav-area-item {
            border: 1px solid var(--color-rule);
            background: #fff;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .nav-area-item:hover {
            border-color: var(--color-primary-light);
            background: rgba(var(--color-primary-rgb), 0.01);
            transform: translateY(-1px);
        }
        .nav-area-item.active {
            border-color: var(--color-primary);
            background: var(--color-primary-light);
            box-shadow: 0 4px 12px rgba(var(--color-primary-rgb), 0.08);
        }
        .nav-area-item.active .area-title {
            color: var(--color-primary) !important;
            font-weight: 700;
        }
        .nav-area-item.active .progress-bar {
            background-color: var(--color-primary) !important;
        }
    </style>
</head>
<body class="bg-light">

    <div class="container-fluid py-4" style="max-width: 1400px;">
        <!-- Header Section -->
        <div class="card p-3 border-0 bg-white shadow-sm mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center gap-3">
                        <a href="viewLearner.php?id=<?= urlencode($lrn) ?>&tab=assessment" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">
                            <i class="bi bi-arrow-left"></i> Back to Profile
                        </a>
                        <h4 class="fw-bold mb-0 text-primary">Assessment Checklist Data Entry</h4>
                    </div>
                    <div class="mt-2 text-secondary small">
                        <strong>Student:</strong> <?= htmlspecialchars(sned_learner_name($learner)) ?> | 
                        <strong>LRN:</strong> <?= htmlspecialchars($learner['lrn']) ?> <?= !empty($learner['lrn_is_temporary']) ? '<span class="badge bg-warning text-dark">Temporary</span>' : '' ?> | 
                        <strong>Grade:</strong> <?= htmlspecialchars($learner['grade_level'] ?: 'N/A') ?> | 
                        <strong>School Year:</strong> SY <?= htmlspecialchars($learner['school_year'] ?: 'N/A') ?>
                    </div>
                </div>
                <div class="col-md-4 mt-3 mt-md-0">
                    <div class="d-flex flex-column align-items-md-end align-items-start gap-2">
                        <span class="badge bg-primary rounded-pill px-3 py-2 fs-7 shadow-sm">
                            <i class="bi bi-info-circle me-1"></i> Changes auto-save in real-time.
                        </span>
                        <div class="w-100" style="max-width: 280px;">
                            <div class="d-flex justify-content-between mb-1 small fw-bold text-secondary">
                                <span>OVERALL PROGRESS</span>
                                <span id="overall-progress-text"><?= $total_rated_count ?> / <?= $total_items_count ?> (<?= $total_pct ?>%)</span>
                            </div>
                            <div class="progress" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-success progress-bar-striped" id="overall-progress-bar" role="progressbar" style="width: <?= $total_pct ?>%" aria-valuenow="<?= $total_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legend and Dates Top Card -->
        <div class="card legend-card p-3 mb-4">
            <div class="row align-items-center g-3">
                <!-- Legend -->
                <div class="col-lg-6">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <span class="fw-bold text-secondary small text-uppercase"><i class="bi bi-info-circle-fill text-primary me-1"></i> Rating Legend:</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success rounded-pill px-3 py-1">E</span>
                            <span class="small fw-semibold text-secondary">Evident</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark rounded-pill px-3 py-1">NYE</span>
                            <span class="small fw-semibold text-secondary">Not Yet Evident</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary rounded-pill px-3 py-1">NO</span>
                            <span class="small fw-semibold text-secondary">Not Observed</span>
                        </div>
                    </div>
                </div>
                <!-- Dates -->
                <div class="col-lg-6 border-start-lg border-start">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="pre_date" class="form-label fw-bold small text-secondary text-uppercase mb-0">Pre-Assessment Date</label>
                                <span class="save-indicator text-muted" id="pre-date-status" style="font-size: 0.8rem; height: 14px; width: 14px;"></span>
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" id="pre_date" class="form-control" value="<?= htmlspecialchars($dates['pre_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="post_date" class="form-label fw-bold small text-secondary text-uppercase mb-0">Post-Assessment Date</label>
                                <span class="save-indicator text-muted" id="post-date-status" style="font-size: 0.8rem; height: 14px; width: 14px;"></span>
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" id="post_date" class="form-control" value="<?= htmlspecialchars($dates['post_date'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sidebar Navigation -->
            <div class="col-md-4 col-lg-3 mb-4">
                <div class="sticky-top" style="top: 20px; z-index: 10;">
                    <div class="card p-3 border-0 shadow-sm bg-white rounded-3 mb-3">
                        <h6 class="fw-bold text-uppercase text-secondary mb-3 small"><i class="bi bi-list-task text-primary me-2"></i>Learning Areas</h6>
                        <div class="nav flex-column" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                            <?php 
                            $area_idx = 1;
                            foreach ($grouped_items as $area => $subsections):
                                $area_id = "area_" . $area_idx;
                                $area_label = ucwords(strtolower(str_replace('_', ' ', $area)));
                                $rated = $area_rated[$area];
                                $total = $area_totals[$area];
                                $pct = $total > 0 ? (int)round(($rated / $total) * 100) : 0;
                                $active_class = ($area_idx === 1) ? 'active' : '';
                            ?>
                                <button class="nav-area-item text-start w-100 <?= $active_class ?>" 
                                        id="nav-tab-<?= $area_id ?>" 
                                        data-area-id="<?= $area_id ?>" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="pane-<?= $area_id ?>" 
                                        aria-selected="<?= ($area_idx === 1) ? 'true' : 'false' ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="area-title fw-semibold text-secondary small text-truncate" title="<?= htmlspecialchars($area_label) ?>">
                                            <?= htmlspecialchars($area_label) ?>
                                        </span>
                                        <span class="badge bg-light text-dark font-monospace border small" id="sidebar-badge-count-<?= $area ?>" style="font-size: 0.7rem;">
                                            <?= $rated ?>/<?= $total ?>
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 4px; border-radius: 2px;">
                                        <div class="progress-bar bg-secondary" id="sidebar-progress-bar-<?= $area ?>" role="progressbar" style="width: <?= $pct ?>%" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </button>
                            <?php 
                                $area_idx++;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="col-md-8 col-lg-9">
                <div class="tab-content" id="v-pills-tabContent">
                    <?php 
                    $area_idx = 1;
                    foreach ($grouped_items as $area => $subsections):
                        $area_id = "area_" . $area_idx;
                        $area_label = ucwords(strtolower(str_replace('_', ' ', $area)));
                        $active_class = ($area_idx === 1) ? 'd-block' : 'd-none';
                    ?>
                        <div class="tab-pane-custom <?= $active_class ?>" id="pane-<?= $area_id ?>" role="tabpanel" aria-labelledby="nav-tab-<?= $area_id ?>">
                            <div class="card border-0 bg-white shadow-sm rounded-3 mb-4 overflow-hidden">
                                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                                    <h5 class="fw-bold text-primary mb-0 text-uppercase">
                                        <i class="bi bi-folder-fill text-primary me-2"></i><?= htmlspecialchars($area_label) ?>
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table checklist-table align-middle w-100 mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 45%;">Skills</th>
                                                    <th class="text-center" style="width: 15%; border-right: 1px solid var(--color-rule);">Pre-Assessment</th>
                                                    <th class="text-center" style="width: 15%;">Post-Assessment</th>
                                                    <th style="width: 25%;">Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                foreach ($subsections as $sub => $items_list): 
                                                ?>
                                                    <tr class="subsection-divider-row">
                                                        <td colspan="4"><i class="bi bi-folder2-open me-2 text-secondary"></i> <?= htmlspecialchars($sub) ?></td>
                                                    </tr>
                                                    <?php
                                                    $prev_group = null;
                                                    foreach ($items_list as $item):
                                                        $item_id = $item['id'];
                                                        $resp = $responses[$item_id] ?? null;
                                                        $curr_pre_rating = $resp['pre_rating'] ?? '';
                                                        $curr_post_rating = $resp['post_rating'] ?? '';
                                                        $curr_remarks = $resp['remarks'] ?? '';
                                                        
                                                        // Render Group Header if changed
                                                        if ($item['group_header'] !== null && $item['group_header'] !== $prev_group):
                                                            $prev_group = $item['group_header'];
                                                    ?>
                                                            <tr class="group-divider-row">
                                                                <td colspan="4"><i class="bi bi-tag-fill me-1 text-primary"></i> <?= htmlspecialchars($prev_group) ?></td>
                                                            </tr>
                                                    <?php 
                                                        endif;
                                                        if ($item['group_header'] === null) {
                                                            $prev_group = null;
                                                        }
                                                    ?>
                                                        <tr data-item-id="<?= $item_id ?>" data-area="<?= htmlspecialchars($area) ?>">
                                                            <td class="small fw-semibold text-dark">
                                                                <?php if ($item['item_number']): ?>
                                                                    <span class="text-primary font-monospace me-1"><?= htmlspecialchars($item['item_number']) ?>.</span>
                                                                <?php endif; ?>
                                                                <?= htmlspecialchars($item['item_text']) ?>
                                                            </td>
                                                            <!-- Pre-Assessment Rating Group -->
                                                            <td class="text-center" style="border-right: 1px solid var(--color-rule);">
                                                                <div class="rating-btn-group d-inline-flex" role="radiogroup" aria-label="Pre-assessment rating for: <?= htmlspecialchars($item['item_text']) ?>">
                                                                    <input type="radio" name="pre-rating-<?= $item_id ?>" id="pre-r-e-<?= $item_id ?>" value="E" tabindex="-1" <?= $curr_pre_rating === 'E' ? 'checked' : '' ?>>
                                                                    <label for="pre-r-e-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_pre_rating === 'E' ? 'true' : 'false' ?>">E</label>
                                                                    
                                                                    <input type="radio" name="pre-rating-<?= $item_id ?>" id="pre-r-nye-<?= $item_id ?>" value="NYE" tabindex="-1" <?= $curr_pre_rating === 'NYE' ? 'checked' : '' ?>>
                                                                    <label for="pre-r-nye-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_pre_rating === 'NYE' ? 'true' : 'false' ?>">NYE</label>
                                                                    
                                                                    <input type="radio" name="pre-rating-<?= $item_id ?>" id="pre-r-no-<?= $item_id ?>" value="NO" tabindex="-1" <?= $curr_pre_rating === 'NO' ? 'checked' : '' ?>>
                                                                    <label for="pre-r-no-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_pre_rating === 'NO' ? 'true' : 'false' ?>">NO</label>
                                                                </div>
                                                            </td>
                                                            <!-- Post-Assessment Rating Group -->
                                                            <td class="text-center">
                                                                <div class="rating-btn-group d-inline-flex" role="radiogroup" aria-label="Post-assessment rating for: <?= htmlspecialchars($item['item_text']) ?>">
                                                                    <input type="radio" name="post-rating-<?= $item_id ?>" id="post-r-e-<?= $item_id ?>" value="E" tabindex="-1" <?= $curr_post_rating === 'E' ? 'checked' : '' ?>>
                                                                    <label for="post-r-e-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_post_rating === 'E' ? 'true' : 'false' ?>">E</label>
                                                                    
                                                                    <input type="radio" name="post-rating-<?= $item_id ?>" id="post-r-nye-<?= $item_id ?>" value="NYE" tabindex="-1" <?= $curr_post_rating === 'NYE' ? 'checked' : '' ?>>
                                                                    <label for="post-r-nye-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_post_rating === 'NYE' ? 'true' : 'false' ?>">NYE</label>
                                                                    
                                                                    <input type="radio" name="post-rating-<?= $item_id ?>" id="post-r-no-<?= $item_id ?>" value="NO" tabindex="-1" <?= $curr_post_rating === 'NO' ? 'checked' : '' ?>>
                                                                    <label for="post-r-no-<?= $item_id ?>" tabindex="0" role="radio" aria-checked="<?= $curr_post_rating === 'NO' ? 'true' : 'false' ?>">NO</label>
                                                                </div>
                                                            </td>
                                                            <!-- Remarks & Save status icon -->
                                                            <td>
                                                                <div class="d-flex align-items-center gap-2">
                                                                    <input type="text" class="remarks-input text-uppercase" name="remarks-<?= $item_id ?>" value="<?= htmlspecialchars($curr_remarks) ?>" placeholder="Optional remarks...">
                                                                    <span class="save-indicator text-muted" id="status-<?= $item_id ?>"></span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php 
                        $area_idx++;
                    endforeach; 
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const learnerId = <?= json_encode($lrn) ?>;
            const csrfToken = <?= json_encode(csrf_token()) ?>;

            // Debounce timers helper
            const debounceTimers = {};

            // Keep track of counts dynamically
            const areaTotals = <?= json_encode($area_totals) ?>;

            // State variable to handle deselection race conditions
            let radioBeforeState = false;

            const updateAriaChecked = (groupName) => {
                document.querySelectorAll(`input[name="${groupName}"]`).forEach(r => {
                    const label = document.querySelector(`label[for="${r.id}"]`);
                    if (label) {
                        label.setAttribute('aria-checked', r.checked ? 'true' : 'false');
                    }
                });
            };

            const updateOverallProgress = () => {
                let overallRated = 0;
                let overallTotal = 0;
                for (const area in areaTotals) {
                    overallTotal += areaTotals[area];
                }
                const rows = document.querySelectorAll('tr[data-item-id]');
                rows.forEach(row => {
                    const itemId = row.getAttribute('data-item-id');
                    const preRadio = row.querySelector(`input[name="pre-rating-${itemId}"]:checked`);
                    const postRadio = row.querySelector(`input[name="post-rating-${itemId}"]:checked`);
                    const hasPre = preRadio && preRadio.value !== '';
                    const hasPost = postRadio && postRadio.value !== '';
                    if (hasPre || hasPost) {
                        overallRated++;
                    }
                });
                const pct = overallTotal > 0 ? Math.round((overallRated / overallTotal) * 100) : 0;
                const textEl = document.getElementById('overall-progress-text');
                const barEl = document.getElementById('overall-progress-bar');
                if (textEl && barEl) {
                    textEl.textContent = `${overallRated} / ${overallTotal} (${pct}%)`;
                    barEl.style.width = `${pct}%`;
                    barEl.setAttribute('aria-valuenow', pct);
                }
            };

            const updateAreaCounters = (area) => {
                const rows = document.querySelectorAll(`tr[data-area="${area}"]`);
                let ratedCount = 0;
                rows.forEach(row => {
                    const itemId = row.getAttribute('data-item-id');
                    const preRadio = row.querySelector(`input[name="pre-rating-${itemId}"]:checked`);
                    const postRadio = row.querySelector(`input[name="post-rating-${itemId}"]:checked`);
                    const hasPre = preRadio && preRadio.value !== '';
                    const hasPost = postRadio && postRadio.value !== '';
                    if (hasPre || hasPost) {
                        ratedCount++;
                    }
                });

                const badge = document.getElementById(`sidebar-badge-count-${area}`);
                const bar = document.getElementById(`sidebar-progress-bar-${area}`);
                if (badge && bar) {
                    const total = areaTotals[area] || 0;
                    badge.textContent = `${ratedCount}/${total}`;
                    const pct = total > 0 ? Math.round((ratedCount / total) * 100) : 0;
                    bar.style.width = `${pct}%`;
                    bar.setAttribute('aria-valuenow', pct);
                }

                // Update overall progress bar in real-time
                updateOverallProgress();
            };

            // Event delegator to listen for inputs and deselection clicks
            const container = document.getElementById('v-pills-tabContent');
            
            // Capture state before value changes on mousedown to prevent race conditions
            container.addEventListener('mousedown', (e) => {
                const label = e.target.closest('label');
                if (label) {
                    const radioId = label.getAttribute('for');
                    const radio = document.getElementById(radioId);
                    if (radio) {
                        radioBeforeState = radio.checked;
                    }
                }
            });

            // Handle keyboard navigation for labels (role="radio")
            container.addEventListener('keydown', (e) => {
                if (e.target.tagName === 'LABEL' && (e.key === ' ' || e.key === 'Enter')) {
                    e.preventDefault();
                    const radioId = e.target.getAttribute('for');
                    const radio = document.getElementById(radioId);
                    if (radio) {
                        radioBeforeState = radio.checked;
                        radio.click();
                    }
                }
            });

            // Listen for clicks on radios to allow deselection
            container.addEventListener('click', (e) => {
                const target = e.target;
                if (target.type === 'radio') {
                    const groupName = target.name;
                    if (radioBeforeState) {
                        // User clicked/toggled the already-checked radio button -> deselect it!
                        target.checked = false;
                        radioBeforeState = false;
                    }
                    updateAriaChecked(groupName);
                    
                    const row = target.closest('tr');
                    const itemId = row.getAttribute('data-item-id');
                    const area = row.getAttribute('data-area');
                    saveRowItem(itemId, row, area);
                }
            });

            container.addEventListener('input', (e) => {
                const target = e.target;
                const row = target.closest('tr');
                if (!row) return;

                const itemId = row.getAttribute('data-item-id');
                const area = row.getAttribute('data-area');

                if (target.classList.contains('remarks-input')) {
                    // Debounce text save to prevent db spamming on every letter typed
                    if (debounceTimers[itemId]) clearTimeout(debounceTimers[itemId]);
                    
                    const indicator = document.getElementById(`status-${itemId}`);
                    if (indicator) {
                        indicator.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary" style="width: 12px; height: 12px;" role="status"></span>';
                    }

                    debounceTimers[itemId] = setTimeout(() => {
                        saveRowItem(itemId, row, area);
                    }, 800);
                }
            });

            // Fallback: save remarks on blur immediately
            container.addEventListener('focusout', (e) => {
                const target = e.target;
                const row = target.closest('tr');
                if (!row) return;

                const itemId = row.getAttribute('data-item-id');
                const area = row.getAttribute('data-area');

                if (target.classList.contains('remarks-input')) {
                    if (debounceTimers[itemId]) {
                        clearTimeout(debounceTimers[itemId]);
                        delete debounceTimers[itemId];
                        saveRowItem(itemId, row, area);
                    }
                }
            });

            // Perform AJAX POST call to save row checklist item
            const saveRowItem = (itemId, row, area) => {
                const preRadio = row.querySelector(`input[name="pre-rating-${itemId}"]:checked`);
                const postRadio = row.querySelector(`input[name="post-rating-${itemId}"]:checked`);
                const preRating = preRadio ? preRadio.value : '';
                const postRating = postRadio ? postRadio.value : '';
                const remarks = row.querySelector('.remarks-input').value;

                const indicator = document.getElementById(`status-${itemId}`);
                if (indicator) {
                    indicator.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" style="width: 12px; height: 12px;" role="status"></span>';
                }

                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('learner_id', learnerId);
                formData.append('item_id', itemId);
                formData.append('pre_rating', preRating);
                formData.append('post_rating', postRating);
                formData.append('remarks', remarks);

                fetch('save_assessment_checklist.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (indicator) {
                            indicator.innerHTML = '<i class="bi bi-check-circle-fill text-success" title="Saved successfully"></i>';
                        }
                        updateAreaCounters(area);
                    } else {
                        if (indicator) {
                            indicator.innerHTML = `<i class="bi bi-x-circle-fill text-danger" title="${data.error || 'Unknown error'}"></i>`;
                        }
                    }
                })
                .catch(error => {
                    if (indicator) {
                        indicator.innerHTML = '<i class="bi bi-x-circle-fill text-danger" title="Network or server error"></i>';
                    }
                });
            };

            // Save overall assessment dates
            const saveOverallDates = (inputEl, statusElId) => {
                const preDate = document.getElementById('pre_date').value;
                const postDate = document.getElementById('post_date').value;

                const statusEl = document.getElementById(statusElId);
                if (statusEl) {
                    statusEl.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" style="width: 12px; height: 12px;" role="status"></span>';
                }

                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('learner_id', learnerId);
                formData.append('pre_date', preDate);
                formData.append('post_date', postDate);

                fetch('save_assessment_dates.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (statusEl) {
                            statusEl.innerHTML = '<i class="bi bi-check-circle-fill text-success" title="Saved successfully"></i>';
                            setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
                        }
                    } else {
                        if (statusEl) {
                            statusEl.innerHTML = `<i class="bi bi-x-circle-fill text-danger" title="${data.error || 'Unknown error'}"></i>`;
                        }
                    }
                })
                .catch(error => {
                    if (statusEl) {
                        statusEl.innerHTML = '<i class="bi bi-x-circle-fill text-danger" title="Network or server error"></i>';
                    }
                });
            };

            const preDateEl = document.getElementById('pre_date');
            const postDateEl = document.getElementById('post_date');
            if (preDateEl) preDateEl.addEventListener('change', () => saveOverallDates(preDateEl, 'pre-date-status'));
            if (postDateEl) postDateEl.addEventListener('change', () => saveOverallDates(postDateEl, 'post-date-status'));

            // Tab switcher logic
            const tabButtons = document.querySelectorAll('.nav-area-item');
            tabButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    // Remove active from all buttons
                    tabButtons.forEach(b => {
                        b.classList.remove('active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    // Add active to current button
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');

                    // Get target pane ID
                    const areaId = btn.getAttribute('data-area-id');
                    
                    // Hide all panes
                    document.querySelectorAll('.tab-pane-custom').forEach(pane => {
                        pane.classList.remove('d-block');
                        pane.classList.add('d-none');
                    });
                    
                    // Show target pane
                    const targetPane = document.getElementById('pane-' + areaId);
                    if (targetPane) {
                        targetPane.classList.remove('d-none');
                        targetPane.classList.add('d-block');
                    }
                });
            });
        });
    </script>
</body>
</html>
