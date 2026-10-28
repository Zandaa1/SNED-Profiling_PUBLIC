<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die("Unauthorized");
}

$lrn = $_GET['learner_id'] ?? '';
$sy = $_GET['school_year'] ?? '';

if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    http_response_code(400);
    die("Invalid Learner LRN.");
}

if (empty($sy)) {
    http_response_code(400);
    die("Invalid school year.");
}

// Fetch Learner & Current active enrollment
$stmt = $conn->prepare("
    SELECT l.*, e.school_id, e.grade_level, e.placement_type, e.school_year as active_sy
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    http_response_code(404);
    die("Learner not found.");
}

$user_role = $_SESSION['role'] ?? 'user';
$session_school_id = $_SESSION['school_id'] ?? 0;

$stmt_sy = $conn->prepare("
    SELECT school_id
    FROM enrollments
    WHERE lrn = ? AND school_year = ? AND is_deleted = 0
    ORDER BY is_historical ASC, id DESC
    LIMIT 1
");
$stmt_sy->bind_param("ss", $lrn, $sy);
$stmt_sy->execute();
$selected_enrollment = $stmt_sy->get_result()->fetch_assoc();

// Enforce school scope
if (user_is_scoped($user_role)) {
    $has_access = user_is_teacher($user_role)
        ? check_teacher_learner_access($conn, $lrn)
        : ($selected_enrollment && (int)$selected_enrollment['school_id'] === (int)$session_school_id);
    if (!$has_access) {
        http_response_code(403);
        die("Forbidden");
    }
}

$is_active_sy = ($sy === $learner['active_sy']);
$can_edit = (user_is_coordinator($user_role) && $is_active_sy && check_teacher_learner_access($conn, $lrn, true));

// Fetch ratings for the selected school year
$stmt_r = $conn->prepare("
    SELECT * 
    FROM learner_indicator_ratings 
    WHERE learner_id = ? AND school_year = ? 
    ORDER BY id ASC
");
$stmt_r->bind_param("ss", $lrn, $sy);
$stmt_r->execute();
$ratings = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);

// Group ratings
$grouped_ratings = [];
foreach ($ratings as $r) {
    $domain = $r['domain'];
    $subdomain = $r['subdomain'] ?? '';
    $grouped_ratings[$domain][$subdomain][] = $r;
}

// Order ratings
$domain_order = [
    'Daily Living Skills',
    'Socio-Emotional',
    'Cognitive',
    'Aesthetic & Creative',
    'Language',
    'Psychomotor',
    'Behavior',
    'Orientation & Mobility'
];

$ordered_ratings = [];
foreach ($domain_order as $td) {
    foreach ($grouped_ratings as $ad => $sub) {
        if (strpos(strtolower($ad), strtolower(str_replace(' & ', ' and ', $td))) !== false || strpos(strtolower($ad), strtolower($td)) !== false) {
            $ordered_ratings[$td] = $sub;
            unset($grouped_ratings[$ad]);
        }
    }
}
foreach ($grouped_ratings as $ad => $sub) {
    $ordered_ratings[$ad] = $sub;
}
?>

<?php if (!$is_active_sy): ?>
    <div class="alert alert-info py-2 px-3 mb-3 small d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>Viewing <strong>SY <?= htmlspecialchars($sy) ?></strong> — read only.</span>
    </div>
<?php endif; ?>

<?php if (empty($ordered_ratings)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-card-checklist" style="font-size: 3rem;"></i>
        <p class="mt-2">No indicator ratings found for school year <?= htmlspecialchars($sy) ?>.</p>
    </div>
<?php else: ?>
    <div class="accordion border-0" id="sf9Accordion">
        <?php 
        $dom_idx = 0;
        foreach ($ordered_ratings as $domain => $subdomains): 
            $dom_idx++;
        ?>
            <div class="accordion-item border rounded-3 mb-3 overflow-hidden shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold text-primary bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDom<?= $dom_idx ?>">
                        <i class="bi bi-folder-fill me-2"></i> <?= htmlspecialchars($domain) ?>
                    </button>
                </h2>
                <div id="collapseDom<?= $dom_idx ?>" class="accordion-collapse collapse" data-bs-parent="#sf9Accordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 60%;" class="ps-3">Performance Indicator</th>
                                        <th class="text-center" style="width: 10%;">Q1</th>
                                        <th class="text-center" style="width: 10%;">Q2</th>
                                        <th class="text-center" style="width: 10%;">Q3</th>
                                        <th class="text-center" style="width: 10%;">Q4</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subdomains as $sub => $rows): ?>
                                        <?php if ($sub !== ''): ?>
                                            <tr class="table-light"><td colspan="5" class="fw-bold text-uppercase text-secondary ps-3" style="font-size: 8pt;"><?= htmlspecialchars($sub) ?></td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td class="ps-3"><?= htmlspecialchars($row['indicator']) ?></td>
                                                <?php for ($q = 1; $q <= 4; $q++): 
                                                    $q_field = 'q' . $q;
                                                    $q_val = $row[$q_field] ?? '';
                                                ?>
                                                    <td class="text-center">
                                                        <select class="form-select form-select-sm border rounded" style="width: 75px; margin: 0 auto; font-weight: 600;" onchange="saveRating(<?= $row['id'] ?>, '<?= $q_field ?>', this)" <?= $can_edit ? '' : 'disabled' ?>>
                                                            <option value="" <?= ($q_val === '' || $q_val === null) ? 'selected' : '' ?>></option>
                                                            <option value="P" <?= $q_val === 'P' ? 'selected' : '' ?>>P</option>
                                                            <option value="AP" <?= $q_val === 'AP' ? 'selected' : '' ?>>AP</option>
                                                            <option value="D" <?= $q_val === 'D' ? 'selected' : '' ?>>D</option>
                                                            <option value="B" <?= $q_val === 'B' ? 'selected' : '' ?>>B</option>
                                                            <option value="NO" <?= $q_val === 'NO' ? 'selected' : '' ?>>NO</option>
                                                            <option value="NA" <?= $q_val === 'NA' ? 'selected' : '' ?>>NA</option>
                                                        </select>
                                                    </td>
                                                <?php endfor; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
