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
$user_school_id = $_SESSION['school_id'] ?? null;

// Handle Soft Delete / Archiving
$message = '';
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (user_is_admin($user_role)) {
        $lrn_to_delete = $_GET['id'];
        
        // Soft delete learner
        $stmt = $conn->prepare("UPDATE learners SET is_deleted = 1 WHERE lrn = ?");
        $stmt->bind_param("s", $lrn_to_delete);
        if ($stmt->execute()) {
            // Also soft delete enrollments for this learner
            $stmt_env = $conn->prepare("UPDATE enrollments SET is_deleted = 1 WHERE lrn = ?");
            $stmt_env->bind_param("s", $lrn_to_delete);
            $stmt_env->execute();
            
            // Log transaction
            log_transaction($conn, $_SESSION['user_id'], 'delete', 'learners', $lrn_to_delete, "Archived learner LRN: $lrn_to_delete");
            
            $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                <i class='bi bi-check-circle-fill me-2'></i> Learner record archived successfully.
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
            </div>";
        } else {
            $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-exclamation-octagon-fill me-2'></i> Failed to archive learner.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger shadow-sm'><i class='bi bi-shield-slash-fill me-2'></i> Unauthorized to delete records.</div>";
    }
}

// Age Helper Function
function calculate_age($birthday) {
    $birthDate = new DateTime($birthday);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}

if (!function_exists('learners_fetch_distinct_enrollment_values')) {
    function learners_fetch_distinct_enrollment_values(mysqli $conn, string $column, ?int $school_id = null, string $order = 'ASC'): array {
        $allowed_columns = ['school_year', 'grade_level', 'self_contained_level', 'primary_diagnosis'];
        if (!in_array($column, $allowed_columns, true)) {
            return [];
        }

        $sql = "SELECT DISTINCT {$column} AS value FROM enrollments WHERE is_deleted = 0 AND is_historical = 0";
        $params = [];
        $types = '';

        if ($school_id && $school_id > 0) {
            $sql .= " AND school_id = ?";
            $params[] = $school_id;
            $types .= 'i';
        }

        $sql .= " AND {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} {$order}";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];

        while ($result && $row = $result->fetch_assoc()) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}

// Fetch schools list for filter
$schools_filter = [];
$res_sch = $conn->query("SELECT id, school_name FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
if ($res_sch) {
    while ($row = $res_sch->fetch_assoc()) {
        $schools_filter[] = $row;
    }
}

$school_name_map = [];
foreach ($schools_filter as $school_row) {
    $school_name_map[(int)$school_row['id']] = $school_row['school_name'];
}

$cy = (int)date('Y');
$cm = (int)date('n');
$current_sy = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;

// Build search and filter queries
$search = trim((string)($_GET['search'] ?? ''));
$school_id = null;
if (user_is_scoped($user_role)) {
    $school_id = $user_school_id ? (int)$user_school_id : null;
} else {
    $requested_school_id = (int)($_GET['school_id'] ?? 0);
    if ($requested_school_id > 0 && isset($school_name_map[$requested_school_id])) {
        $school_id = $requested_school_id;
    }
}

$school_year_options = learners_fetch_distinct_enrollment_values($conn, 'school_year', $school_id, 'DESC');
$grade_level_options = learners_fetch_distinct_enrollment_values($conn, 'grade_level', $school_id);
$self_contained_level_options = learners_fetch_distinct_enrollment_values($conn, 'self_contained_level', $school_id);
$diagnosis_options = learners_fetch_distinct_enrollment_values($conn, 'primary_diagnosis', $school_id);

$sex_options = ['Male', 'Female'];
$placement_options = ['Graded', 'Non-Graded'];
$profile_update_options = [
    'needs_update' => 'Needs update',
    'up_to_date' => 'Up to date',
];
$status_options = [
    'current' => 'Enrolled this SY',
    'other' => 'Active other SY',
    'none' => 'No active enrollment',
];

$selected_school_name = $school_id && isset($school_name_map[$school_id]) ? $school_name_map[$school_id] : '';

$filters = [
    'school_year' => trim((string)($_GET['school_year'] ?? '')),
    'sex' => trim((string)($_GET['sex'] ?? '')),
    'grade_level' => trim((string)($_GET['grade_level'] ?? '')),
    'placement_type' => trim((string)($_GET['placement_type'] ?? '')),
    'self_contained_level' => trim((string)($_GET['self_contained_level'] ?? '')),
    'diagnosis' => trim((string)($_GET['diagnosis'] ?? '')),
    'profile_update' => trim((string)($_GET['profile_update'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'age_min' => trim((string)($_GET['age_min'] ?? '')),
    'age_max' => trim((string)($_GET['age_max'] ?? '')),
];

if ($filters['school_year'] !== '' && !in_array($filters['school_year'], $school_year_options, true)) {
    $filters['school_year'] = '';
}
if ($filters['sex'] !== '' && !in_array($filters['sex'], $sex_options, true)) {
    $filters['sex'] = '';
}
if ($filters['grade_level'] !== '' && !in_array($filters['grade_level'], $grade_level_options, true)) {
    $filters['grade_level'] = '';
}
if ($filters['placement_type'] !== '' && !in_array($filters['placement_type'], $placement_options, true)) {
    $filters['placement_type'] = '';
}
if ($filters['self_contained_level'] !== '' && !in_array($filters['self_contained_level'], $self_contained_level_options, true)) {
    $filters['self_contained_level'] = '';
}
if ($filters['diagnosis'] !== '' && !in_array($filters['diagnosis'], $diagnosis_options, true)) {
    $filters['diagnosis'] = '';
}
if ($filters['profile_update'] !== '' && !in_array($filters['profile_update'], array_keys($profile_update_options), true)) {
    $filters['profile_update'] = '';
}
if ($filters['status'] !== '' && !in_array($filters['status'], array_keys($status_options), true)) {
    $filters['status'] = '';
}

foreach (['age_min', 'age_max'] as $age_key) {
    if ($filters[$age_key] !== '') {
        $age_value = max(0, min(120, (int)$filters[$age_key]));
        $filters[$age_key] = (string)$age_value;
    }
}

if ($filters['age_min'] !== '' && $filters['age_max'] !== '' && (int)$filters['age_min'] > (int)$filters['age_max']) {
    [$filters['age_min'], $filters['age_max']] = [$filters['age_max'], $filters['age_min']];
}

$sql = "
    SELECT l.lrn, l.last_name, l.first_name, l.middle_name, l.extension_name, l.birthday, l.sex,
           l.profile_id, l.pwd_id_number, l.needs_profile_update, l.lrn_is_temporary,
           e.id AS enrollment_id, e.school_id, e.school_year, e.placement_type, e.self_contained_level, e.grade_level, e.primary_diagnosis,
           s.school_name,
           CASE
               WHEN e.id IS NULL THEN 'none'
               WHEN e.school_year = '{$current_sy}' THEN 'current'
               ELSE 'other'
           END AS enrollment_status
    FROM learners l
    LEFT JOIN enrollments e ON e.id = (
        SELECT id
        FROM enrollments
        WHERE lrn = l.lrn AND is_deleted = 0 AND is_historical = 0
        ORDER BY school_year DESC, id DESC
        LIMIT 1
    )
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.is_deleted = 0
";

$params = [];
$types = '';
$active_filter_summary = [];

if ($search !== '') {
    $sql .= " AND (l.lrn LIKE ? OR l.first_name LIKE ? OR l.middle_name LIKE ? OR l.last_name LIKE ? OR l.extension_name LIKE ? OR l.profile_id LIKE ? OR l.pwd_id_number LIKE ? OR CONCAT_WS(' ', l.first_name, l.middle_name, l.last_name, l.extension_name) LIKE ? OR CONCAT_WS(' ', l.last_name, l.first_name, l.middle_name, l.extension_name) LIKE ? OR s.school_name LIKE ? OR e.grade_level LIKE ? OR e.primary_diagnosis LIKE ? )";
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term, $search_term);
    $types .= 'ssssssssssss';
    $active_filter_summary[] = 'Search: ' . $search;
}

if ($school_id !== null) {
    $sql .= " AND e.school_id = ?";
    $params[] = $school_id;
    $types .= 'i';
    if (!user_is_scoped($user_role)) {
        $active_filter_summary[] = 'School: ' . ($selected_school_name !== '' ? $selected_school_name : 'Selected school');
    }
}

if ($filters['school_year'] !== '') {
    $sql .= " AND e.school_year = ?";
    $params[] = $filters['school_year'];
    $types .= 's';
    $active_filter_summary[] = 'School Year: SY ' . $filters['school_year'];
}

if ($filters['sex'] !== '') {
    $sql .= " AND l.sex = ?";
    $params[] = $filters['sex'];
    $types .= 's';
    $active_filter_summary[] = 'Sex: ' . $filters['sex'];
}

if ($filters['grade_level'] !== '') {
    $sql .= " AND e.grade_level = ?";
    $params[] = $filters['grade_level'];
    $types .= 's';
    $active_filter_summary[] = 'Grade: ' . $filters['grade_level'];
}

if ($filters['placement_type'] !== '') {
    $sql .= " AND e.placement_type = ?";
    $params[] = $filters['placement_type'];
    $types .= 's';
    $active_filter_summary[] = 'Placement: ' . $filters['placement_type'];
}

if ($filters['self_contained_level'] !== '') {
    $sql .= " AND e.self_contained_level = ?";
    $params[] = $filters['self_contained_level'];
    $types .= 's';
    $active_filter_summary[] = 'Self-contained: ' . $filters['self_contained_level'];
}

if ($filters['diagnosis'] !== '') {
    $sql .= " AND e.primary_diagnosis = ?";
    $params[] = $filters['diagnosis'];
    $types .= 's';
    $active_filter_summary[] = 'Diagnosis: ' . $filters['diagnosis'];
}

if ($filters['profile_update'] === 'needs_update') {
    $sql .= " AND l.needs_profile_update = 1";
    $active_filter_summary[] = 'Profile: Needs update';
} elseif ($filters['profile_update'] === 'up_to_date') {
    $sql .= " AND l.needs_profile_update = 0";
    $active_filter_summary[] = 'Profile: Up to date';
}

if ($filters['status'] === 'current') {
    $sql .= " AND e.id IS NOT NULL AND e.school_year = '{$current_sy}'";
    $active_filter_summary[] = 'Status: Enrolled this SY';
} elseif ($filters['status'] === 'other') {
    $sql .= " AND e.id IS NOT NULL AND e.school_year <> '{$current_sy}'";
    $active_filter_summary[] = 'Status: Active other SY';
} elseif ($filters['status'] === 'none') {
    $sql .= " AND e.id IS NULL";
    $active_filter_summary[] = 'Status: No active enrollment';
}

if ($filters['age_min'] !== '') {
    $sql .= " AND TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) >= ?";
    $params[] = (int)$filters['age_min'];
    $types .= 'i';
    $active_filter_summary[] = 'Age min: ' . $filters['age_min'];
}

if ($filters['age_max'] !== '') {
    $sql .= " AND TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) <= ?";
    $params[] = (int)$filters['age_max'];
    $types .= 'i';
    $active_filter_summary[] = 'Age max: ' . $filters['age_max'];
}

$teacher_filter = get_teacher_scope_filter('l');
if ($teacher_filter) {
    $sql .= $teacher_filter;
}

$featured_order_sql = '';
if (defined('DEBUG_SAMPLE_DATA_ENABLED') && DEBUG_SAMPLE_DATA_ENABLED) {
    $featured_order_sql = "CASE WHEN l.lrn_is_temporary = 1 THEN 0 WHEN l.lrn = '990001000001' THEN 1 WHEN l.lrn = '990001000002' THEN 2 ELSE 3 END ASC, ";
} else {
    $featured_order_sql = "CASE WHEN l.lrn_is_temporary = 1 THEN 0 ELSE 1 END ASC, ";
}

$sql .= " ORDER BY {$featured_order_sql}CASE WHEN e.id IS NOT NULL AND e.school_year = '{$current_sy}' THEN 0 WHEN e.id IS NOT NULL THEN 1 ELSE 2 END ASC, COALESCE(s.school_name, '') ASC, l.last_name ASC, l.first_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$learners = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $learners[] = $row;
    }
}

$active_filter_count = count($active_filter_summary);
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
    <title>Learner Directory - SNED Profiling</title>
    <style>
        :root {
            --badge-danger-bg: var(--sned-danger-light);
            --badge-danger-ink: var(--sned-danger);
            --badge-danger-rule: #E8B4B8;
            
            --badge-success-bg: var(--sned-success-light);
            --badge-success-ink: #1E5C39;
            --badge-success-rule: var(--sned-mint);
            
            --badge-warning-bg: var(--sned-warning-light);
            --badge-warning-ink: #7D5A0A;
            --badge-warning-rule: #F5D98A;
            
            --badge-primary-rule: var(--sned-mint);
            --color-muted-dark: var(--sned-moss);
        }
        
        .text-muted {
            color: var(--color-muted-dark) !important;
        }

        .filter-card {
            background: var(--color-paper);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-rule-light);
            padding: var(--space-lg);
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
            color: var(--color-muted-dark);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-2xs);
        }
        .filter-field--wide {
            grid-column: span 2;
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
            color: var(--color-muted-dark);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-pill);
            padding: 2px 10px;
            font-size: var(--text-xs);
            font-weight: 600;
        }
        
        .search-wrapper {
            position: relative;
        }
        
        .search-wrapper .bi-search {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-muted-dark);
            pointer-events: none;
        }
        
        .search-wrapper .form-control {
            padding-left: 2.35rem !important;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: var(--radius-md);
            border-color: var(--color-rule);
            font-size: var(--text-sm);
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 2px var(--color-primary-light);
        }
        
        .data-table-wrapper {
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-rule-light);
            overflow: visible;
            background: var(--color-paper);
        }
        
        .data-table-wrapper table {
            margin-bottom: 0;
        }
        
        .data-table-wrapper thead th {
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            color: var(--color-muted-dark);
            background: var(--color-paper-2);
            border-bottom: 1px solid var(--color-rule);
            padding: var(--space-md) var(--space-sm);
        }
        
        .data-table-wrapper tbody td {
            padding: var(--space-md) var(--space-sm);
            border-bottom: 1px solid var(--color-rule-light);
            color: var(--color-ink);
            vertical-align: middle;
            font-size: var(--text-sm);
        }
        
        .data-table-wrapper tbody tr:last-child td {
            border-bottom: none;
        }
        
        .student-lrn {
            font-family: var(--font-mono);
            font-size: var(--text-xs);
            background: var(--color-paper-3);
            color: var(--color-ink);
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--color-rule);
            font-weight: 500;
            white-space: nowrap;
        }
        
        .student-avatar {
            width: 38px;
            height: 38px;
            object-fit: cover;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-rule-light);
        }
        
        .badge-tag {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.18rem 0.6rem;
            border-radius: var(--radius-pill);
            line-height: 1.35;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid transparent;
            text-decoration: none;
        }
        
        .badge-tag-danger { background: var(--badge-danger-bg); color: var(--badge-danger-ink); border-color: var(--badge-danger-rule); }
        .badge-tag-success { background: var(--badge-success-bg); color: var(--badge-success-ink); border-color: var(--badge-success-rule); }
        .badge-tag-warning { background: var(--badge-warning-bg); color: var(--badge-warning-ink); border-color: var(--badge-warning-rule); }
        .badge-tag-primary { background: var(--color-primary-light); color: var(--color-primary); border-color: var(--badge-primary-rule); }
        .badge-tag-empty   { background: var(--color-paper-3); color: var(--color-muted-dark); border-color: var(--color-rule); }

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

        .btn-row-view    { color: #1A5276; }
        .btn-row-edit    { color: #1E5C39; }
        .btn-row-archive { color: #7A2028; }
        
        .btn-primary { border-radius: var(--radius-pill); font-weight: 600; }
        .btn-outline-primary { border-radius: var(--radius-pill); font-weight: 550; }
        .btn-outline-secondary { border-radius: var(--radius-pill); }
        
        .dropdown-menu {
            border-color: var(--color-rule-light);
            border-radius: var(--radius-md);
            padding: 0.5rem 0;
        }
        
        .directory-count {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: var(--space-xs) var(--space-sm);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-pill);
            background: var(--color-paper);
            color: var(--color-muted-dark);
            font-size: var(--text-sm);
            font-weight: 600;
        }

        /* Mobile View Styles */
        .learner-mobile-card {
            background: var(--color-paper);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            padding: var(--space-md);
            margin-bottom: var(--space-md);
            transition: border-color var(--dur-short) var(--ease-out), transform var(--dur-short) var(--ease-out);
        }
        
        .learner-mobile-card:hover {
            border-color: var(--color-rule);
        }
        
        .student-avatar-lg {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-rule-light);
        }
        
        .student-mobile-name {
            font-size: var(--text-base);
            font-weight: 700;
            color: var(--color-ink);
            margin: 0;
        }
        
        .student-lrn-badge {
            font-family: var(--font-mono);
            font-size: var(--text-xs);
            background: var(--color-paper-3);
            color: var(--color-ink);
            padding: 0.1rem 0.4rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--color-rule);
            font-weight: 500;
        }
        
        .student-sex-age {
            font-size: var(--text-xs);
            color: var(--color-muted-dark);
        }
        
        .learner-details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--space-sm);
            padding: var(--space-sm) 0;
            border-top: 1px solid var(--color-rule-light);
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: var(--space-3xs);
        }
        
        .detail-label {
            font-size: var(--text-xs);
            font-weight: 700;
            color: var(--color-muted-dark);
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        
        .detail-value {
            font-size: var(--text-sm);
            color: var(--color-ink);
        }
        
        .detail-subvalue {
            font-size: var(--text-xs);
            color: var(--color-muted-dark);
        }

        /* Redesigned Export List (No Nested Cards) */
        .modal-notice-banner {
            background: var(--color-primary-light);
            border: 1px solid color-mix(in srgb, var(--color-primary) 15%, transparent);
            border-radius: var(--radius-md);
            padding: var(--space-sm) var(--space-md);
        }

        .modal-notice-icon-shell {
            color: var(--color-primary);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
        }

        .modal-notice-title {
            font-weight: 700;
            font-size: var(--text-sm);
            color: var(--color-primary-hover);
        }

        .modal-notice-desc {
            font-size: var(--text-xs);
            color: var(--color-muted-dark);
            line-height: 1.4;
            margin-top: 2px;
        }

        .export-list {
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            overflow: hidden;
            background: var(--color-paper);
        }
        
        .export-item {
            display: block;
            padding: var(--space-sm) var(--space-md);
            border-bottom: 1px solid var(--color-rule-light);
            text-decoration: none;
            color: var(--color-ink) !important;
            transition: background var(--dur-short) var(--ease-out);
        }
        
        .export-item:last-child {
            border-bottom: none;
        }
        
        .export-item:hover {
            background: var(--color-paper-2);
        }

        .export-icon-shell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-md);
            background: var(--color-paper-2);
            color: var(--color-muted-dark);
            transition: background var(--dur-short) var(--ease-out), color var(--dur-short) var(--ease-out);
        }

        .export-item:hover .export-icon-shell {
            background: var(--color-primary-light);
            color: var(--color-primary);
        }
        
        .export-item-title {
            font-weight: 600;
            font-size: var(--text-sm);
            color: var(--color-ink);
        }
        
        .export-item-desc {
            font-size: var(--text-xs);
            color: var(--color-muted-dark);
            margin-top: 2px;
        }
        
        .export-item .bi-chevron-right {
            transition: transform var(--dur-short) var(--ease-out), color var(--dur-short) var(--ease-out);
        }
        
        .export-item:hover .bi-chevron-right {
            transform: translateX(2px);
            color: var(--color-primary) !important;
        }
        @media (max-width: 992px) {
            .filter-field--wide {
                grid-column: span 1;
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
                <?php if (user_is_admin($user_role)): ?>
                            <button class="btn btn-outline-secondary rounded-pill px-3" type="button" data-bs-toggle="modal" data-bs-target="#exportOptionsModal"><i class="bi bi-download me-1"></i> Export</button>
                        <?php endif; ?>
            </header>

            <main class="app-content">
                <div class="page-toolbar">
                    <div>
                        <h1 class="page-title">Learner directory</h1>
                        <p class="page-subtitle">Find learner profiles by name, LRN, or school. Results show active enrollment status.</p>
                    </div>
                    <div class="toolbar-actions">
                        <span class="directory-count"><i class="bi bi-people"></i><?= count($learners) ?> result<?= count($learners) === 1 ? '' : 's' ?></span>
                    </div>
                </div>

                <?= $message ?>


                <form method="GET" class="filter-card mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <h2 class="section-head__title mb-1" style="font-size:var(--text-lg);">
                                <i class="bi bi-funnel text-primary"></i> Filter learners
                            </h2>
                            <div class="text-muted small">Search by name, LRN, school, profile ID, or enrollment details. Results default to the latest active enrollment.</div>
                        </div>
                        <div class="directory-count">
                            <i class="bi bi-funnel-fill"></i>
                            <?= $active_filter_count ?> active filter<?= $active_filter_count === 1 ? '' : 's' ?>
                        </div>
                    </div>

                    <?php if (!empty($active_filter_summary)): ?>
                        <div class="filter-summary" aria-label="Active filters">
                            <?php foreach ($active_filter_summary as $summary): ?>
                                <span class="filter-pill"><?= htmlspecialchars($summary) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="filter-grid mt-3">
                        <div class="filter-field filter-field--wide">
                            <label for="searchFilter">Search</label>
                            <div class="search-wrapper">
                                <i class="bi bi-search"></i>
                                <input type="text" id="searchFilter" name="search" class="form-control" placeholder="Search by name, LRN, school, or diagnosis" value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>

                        <div class="filter-field">
                            <label for="schoolFilter">School</label>
                            <?php if (user_is_scoped($user_role)): ?>
                                <div class="form-control bg-body-tertiary d-flex align-items-center" style="min-height: calc(1.5em + .75rem + 2px);">
                                    <i class="bi bi-lock-fill me-2 text-muted"></i>
                                    <span><?= htmlspecialchars($selected_school_name !== '' ? $selected_school_name : 'Assigned school') ?></span>
                                </div>
                                <input type="hidden" name="school_id" value="<?= htmlspecialchars((string)($user_school_id ?? '')) ?>">
                            <?php else: ?>
                                <select id="schoolFilter" name="school_id" class="form-select">
                                    <option value="">All schools</option>
                                    <?php foreach ($schools_filter as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= ($school_id == (int)$s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['school_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="filter-field">
                            <label for="schoolYearFilter">School Year</label>
                            <select id="schoolYearFilter" name="school_year" class="form-select">
                                <option value="">All years</option>
                                <?php foreach ($school_year_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['school_year'] === $opt) ? 'selected' : '' ?>>SY <?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="statusFilter">Enrollment Status</label>
                            <select id="statusFilter" name="status" class="form-select">
                                <option value="">All statuses</option>
                                <?php foreach ($status_options as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= ($filters['status'] === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="sexFilter">Sex</label>
                            <select id="sexFilter" name="sex" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($sex_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['sex'] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="gradeFilter">Grade Level</label>
                            <select id="gradeFilter" name="grade_level" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($grade_level_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['grade_level'] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="placementFilter">Placement</label>
                            <select id="placementFilter" name="placement_type" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($placement_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['placement_type'] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="levelFilter">Self-contained Level</label>
                            <select id="levelFilter" name="self_contained_level" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($self_contained_level_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['self_contained_level'] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="diagnosisFilter">Diagnosis</label>
                            <select id="diagnosisFilter" name="diagnosis" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($diagnosis_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($filters['diagnosis'] === $opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="profileUpdateFilter">Profile Status</label>
                            <select id="profileUpdateFilter" name="profile_update" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($profile_update_options as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>" <?= ($filters['profile_update'] === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="ageMinFilter">Age Min</label>
                            <input type="number" min="0" max="120" id="ageMinFilter" name="age_min" class="form-control" value="<?= htmlspecialchars($filters['age_min']) ?>" placeholder="Any">
                        </div>

                        <div class="filter-field">
                            <label for="ageMaxFilter">Age Max</label>
                            <input type="number" min="0" max="120" id="ageMaxFilter" name="age_max" class="form-control" value="<?= htmlspecialchars($filters['age_max']) ?>" placeholder="Any">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-funnel me-1"></i> Apply filters</button>
                        <a href="learners.php" class="btn btn-outline-secondary rounded-pill px-3 d-inline-flex align-items-center gap-1"><i class="bi bi-x-circle"></i> <span>Reset</span></a>
                    </div>
                </form>

                <div class="data-table-wrapper d-none d-lg-block">
                    <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">Student</th>
                                    <th>LRN</th>
                                    <th>Age & Sex</th>
                                    <th>School, Grade & Placement</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php if (empty($learners)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-5">
                                                <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                                                No learners found matching the search criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($learners as $student): 
                                            $age = calculate_age($student['birthday']);
                                            $is_adult = ($age >= 18);
                                            $placement_display = $student['placement_type'];
                                            if ($student['self_contained_level']) {
                                                $placement_display .= ' (' . $student['self_contained_level'] . ')';
                                            }
                                            $status_badge_class = 'badge-tag-danger';
                                            $status_icon = 'bi-calendar-x';
                                            $status_label = 'No active enrollment';
                                            if (($student['enrollment_status'] ?? '') === 'current') {
                                                $status_badge_class = 'badge-tag-success';
                                                $status_icon = 'bi-check-circle-fill';
                                                $status_label = 'Enrolled this SY';
                                            } elseif (($student['enrollment_status'] ?? '') === 'other') {
                                                $status_badge_class = 'badge-tag-primary';
                                                $status_icon = 'bi-calendar-check';
                                                $status_label = 'Active SY ' . ($student['school_year'] ?? '');
                                            }
                                        ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <?php
                                                        $photo_src = '';
                                                        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                                                            $test = "uploads/{$student['lrn']}.{$ext}";
                                                            if (file_exists(__DIR__ . '/' . $test)) {
                                                                $photo_src = $test;
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <img src="<?= $photo_src ? htmlspecialchars($photo_src) : 'img/student_placeholder.webp' ?>" alt="" class="student-avatar flex-shrink-0">
                                                        <div>
                                                            <div class="fw-bold text-dark mb-1" style="font-size:var(--text-sm);"><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']) ?></div>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <?php if($is_adult): ?>
                                                                    <span class="badge-tag badge-tag-warning" style="font-size:0.65rem;"><i class="bi bi-exclamation-circle-fill me-1"></i> Adult LWD</span>
                                                                <?php endif; ?>
                                                                <?php if ($student['needs_profile_update']): ?>
                                                                    <span class="badge-tag badge-tag-warning" style="font-size:0.65rem;"><i class="bi bi-pencil-square me-1"></i> Needs Update</span>
                                                                <?php endif; ?>
                                                                <span class="badge-tag <?= $status_badge_class ?>" style="font-size:0.65rem;"><i class="bi <?= $status_icon ?> me-1"></i> <?= htmlspecialchars($status_label) ?></span>
                                                            </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="student-lrn"><?= htmlspecialchars($student['lrn']) ?></span>
                                                    <?php if (!empty($student['lrn_is_temporary'])): ?>
                                                        <div class="mt-1"><span class="badge bg-warning text-dark" style="font-size:0.65rem;font-weight:700;">No LRN Yet</span></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark" style="font-size:var(--text-sm);"><?= $age ?></span> <span class="text-muted" style="font-size:var(--text-xs);">yrs</span>
                                                    <div class="text-muted mt-0.5" style="font-size:var(--text-xs);"><?= htmlspecialchars($student['sex']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($student['school_name']): ?>
                                                        <div class="fw-bold text-dark text-truncate" style="max-width:180px;font-size:var(--text-xs);" title="<?= htmlspecialchars($student['school_name']) ?>"><?= htmlspecialchars($student['school_name']) ?></div>
                                                        <div class="text-muted mt-0.5" style="font-size:var(--text-xs);"><?= htmlspecialchars(($student['grade_level'] ?? '') !== '' ? $student['grade_level'] . ' · ' : '') . htmlspecialchars($placement_display) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><?= htmlspecialchars($status_label) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="row-actions-panel">
                                                        <a href="viewLearner.php?id=<?= urlencode($student['lrn']) ?>" class="btn-row-action btn-row-view" title="View profile"><i class="bi bi-eye"></i> View profile</a>
                                                        <?php if (user_is_admin($user_role)): ?>
                                                            <button type="button" class="btn-row-action btn-row-archive" title="Archive" onclick="confirmArchive('<?= htmlspecialchars($student['lrn']) ?>', '<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>')"><i class="bi bi-archive"></i> Archive</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mobile cards list view (< 992px) -->
                <div class="learner-mobile-list d-block d-lg-none">
                    <?php if (empty($learners)): ?>
                        <div class="text-center text-muted py-5 bg-white border border-light rounded-4 shadow-sm">
                            <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                            No learners found matching the search criteria.
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($learners as $student): 
                                $age = calculate_age($student['birthday']);
                                $is_adult = ($age >= 18);
                                $placement_display = $student['placement_type'];
                                if ($student['self_contained_level']) {
                                    $placement_display .= ' (' . $student['self_contained_level'] . ')';
                                }
                                $status_badge_class = 'badge-tag-danger';
                                $status_icon = 'bi-calendar-x';
                                $status_label = 'No active enrollment';
                                if (($student['enrollment_status'] ?? '') === 'current') {
                                    $status_badge_class = 'badge-tag-success';
                                    $status_icon = 'bi-check-circle-fill';
                                    $status_label = 'Enrolled this SY';
                                } elseif (($student['enrollment_status'] ?? '') === 'other') {
                                    $status_badge_class = 'badge-tag-primary';
                                    $status_icon = 'bi-calendar-check';
                                    $status_label = 'Active SY ' . ($student['school_year'] ?? '');
                                }
                                
                                // Get photo
                                $photo_src = '';
                                foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                                    $test = "uploads/{$student['lrn']}.{$ext}";
                                    if (file_exists(__DIR__ . '/' . $test)) {
                                        $photo_src = $test;
                                        break;
                                    }
                                }
                            ?>
                                <div class="learner-mobile-card">
                                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="<?= $photo_src ? htmlspecialchars($photo_src) : 'img/student_placeholder.webp' ?>" alt="" class="student-avatar-lg">
                                            <div>
                                                <h3 class="student-mobile-name">
                                                    <?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']) ?>
                                                </h3>
                                                <div class="d-flex align-items-center gap-2 mt-1">
                                                    <span class="student-lrn-badge <?= !empty($student['lrn_is_temporary']) ? 'bg-warning text-dark border-0 fw-bold' : '' ?>"><?= htmlspecialchars($student['lrn']) ?><?= !empty($student['lrn_is_temporary']) ? ' (No LRN)' : '' ?></span>
                                                    <span class="student-sex-age"><?= $age ?> yrs · <?= htmlspecialchars($student['sex']) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row-actions-panel">
                                            <a href="viewLearner.php?id=<?= urlencode($student['lrn']) ?>" class="btn-row-action btn-row-view" title="View"><i class="bi bi-eye"></i> View profile</a>
                                            <?php if (user_is_admin($user_role)): ?>
                                                <button type="button" class="btn-row-action btn-row-archive" title="Archive" onclick="confirmArchive('<?= htmlspecialchars($student['lrn']) ?>', '<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>')"><i class="bi bi-archive"></i> Archive</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php if($is_adult): ?>
                                            <span class="badge-tag badge-tag-warning"><i class="bi bi-exclamation-circle-fill me-1"></i> Adult LWD</span>
                                        <?php endif; ?>
                                        <?php if ($student['needs_profile_update']): ?>
                                            <span class="badge-tag badge-tag-warning"><i class="bi bi-pencil-square me-1"></i> Needs Update</span>
                                        <?php endif; ?>
                                        <span class="badge-tag <?= $status_badge_class ?>"><i class="bi <?= $status_icon ?> me-1"></i> <?= htmlspecialchars($status_label) ?></span>
                                    </div>
                                    
                                    <div class="learner-details-grid">
                                        <div class="detail-item">
                                            <span class="detail-label">School</span>
                                            <div class="detail-value text-dark fw-semibold">
                                                <?php if ($student['school_name']): ?>
                                                    <?= htmlspecialchars($student['school_name']) ?>
                                                    <div class="detail-subvalue mt-1">
                                                        <?= htmlspecialchars(($student['grade_level'] ?? '') !== '' ? $student['grade_level'] . ' · ' : '') . htmlspecialchars($placement_display) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted fw-normal"><?= htmlspecialchars($status_label) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Export Options Modal -->
    <div class="modal fade" id="exportOptionsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content p-4" style="border-radius: var(--radius-lg);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold"><i class="bi bi-download text-primary me-2"></i> Export System Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-0">
                    <div class="modal-notice-banner d-flex gap-3 mb-4">
                        <div class="modal-notice-icon-shell">
                            <i class="bi bi-shield-fill-exclamation text-primary"></i>
                        </div>
                        <div class="modal-notice-text">
                            <div class="modal-notice-title">Active Database Export</div>
                            <div class="modal-notice-desc">These options generate active spreadsheet reports containing live learner and guardian database records. They are not blank templates.</div>
                        </div>
                    </div>
                    
                    <div class="export-list mb-3">
                        <a href="export_lwd_list.php" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-file-earmark-spreadsheet"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">Master LWD List</div>
                                    <div class="export-item-desc">Roster of registered learners with diagnosis and details.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>

                        <a href="export_lwd_adults.php" target="_blank" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-printer"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">Master LWDs 18+ Printable List</div>
                                    <div class="export-item-desc">Printable citywide list of LWDs 18 years old and above.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>

                        <a href="export_enrollment_macro.php" target="_blank" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-grid-3x3-gap-fill"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">Enrollment Summary</div>
                                    <div class="export-item-desc">Macro statistics and enrollment summaries per school.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>

                        <a href="javascript:void(0);" onclick="triggerSchoolExport()" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">School SNED Data Collection</div>
                                    <div class="export-item-desc">Choose a school and school year for the SNED data spreadsheet.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>

                        <a href="javascript:void(0);" onclick="triggerSchoolReportExport('export_lwd_list.php', 'Export School LWD List', 'Export LWD List', 'Official guardian and learner LWD spreadsheet for one school.')" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-card-checklist"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">School LWD List</div>
                                    <div class="export-item-desc">Choose a school and school year for the official LWD list layout.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>

                        <a href="javascript:void(0);" onclick="triggerSchoolReportExport('export_lwd_adults.php', 'Print School LWDs 18+', 'Open Printable List', 'Printable list of LWDs 18 years old and above for one school.')" class="export-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="export-icon-shell">
                                    <i class="bi bi-printer"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="export-item-title">School LWDs 18+ Printable List</div>
                                    <div class="export-item-desc">Choose a school and school year, then open the printable adult-LWD list.</div>
                                </div>
                                <i class="bi bi-chevron-right text-muted fs-6"></i>
                            </div>
                        </a>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-end pt-0">
                    <button type="button" class="btn btn-light px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SNED School Export Modal -->
    <div class="modal fade" id="snedExportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-4" style="border-radius:var(--radius-lg);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold" id="schoolReportModalTitle"><i class="bi bi-building text-primary me-2"></i> Export School Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3" id="schoolReportModalDesc">Choose a school and school year for this report.</p>
                    <form id="snedExportForm" method="GET" action="export_sned_school.php" target="_blank">
                        <input type="hidden" name="scope" value="school">
                        <div class="mb-3">
                            <label class="form-label">Target School</label>
                            <select name="school_id" class="form-select" required>
                                <option value="">Select School</option>
                                <?php foreach($schools_filter as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['school_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">School Year</label>
                            <select name="school_year" class="form-select" required><?= school_year_options() ?></select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 justify-content-end pt-0 gap-2">
                    <button type="button" class="btn btn-light px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary px-5 rounded-pill" id="schoolReportSubmitBtn" onclick="document.getElementById('snedExportForm').submit();">
                        <i class="bi bi-download me-1"></i> Export Report
                    </button>
                </div>
            </div>
        </div>
    </div>





    <!-- Archive Confirmation Modal -->
    <div class="modal fade" id="confirmArchiveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-4 text-center" style="border-radius:var(--radius-lg);">
                <div class="modal-body">
                    <div class="display-2 text-danger mb-3"><i class="bi bi-archive-fill"></i></div>
                    <h3 class="fw-bold">Archive Profile?</h3>
                    <p class="text-muted mb-4">This will soft-delete the profile of <strong id="archiveName"></strong> (LRN: <code id="archiveLrn"></code>). The student history can be retrieved from Archived Records.</p>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light w-100 py-2 fw-bold" data-bs-dismiss="modal" style="border-radius:var(--radius-md);">Cancel</button>
                        <a id="archiveConfirmBtn" href="#" class="btn btn-danger w-100 py-2 fw-bold" style="border-radius:var(--radius-md);">Yes, Archive</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        function triggerSchoolReportExport(action, title, buttonLabel, description) {
            const form = document.getElementById('snedExportForm');
            const titleEl = document.getElementById('schoolReportModalTitle');
            const descEl = document.getElementById('schoolReportModalDesc');
            const submitBtn = document.getElementById('schoolReportSubmitBtn');

            if (form) {
                form.action = action || 'export_sned_school.php';
            }
            if (titleEl) {
                titleEl.innerHTML = '<i class="bi bi-building text-primary me-2"></i>' + (title || 'Export School Report');
            }
            if (descEl) {
                descEl.innerText = description || 'Choose a school and school year for this report.';
            }
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="bi bi-download me-1"></i> ' + (buttonLabel || 'Export Report');
            }

            const optModalEl = document.getElementById('exportOptionsModal');
            const optModal = bootstrap.Modal.getInstance(optModalEl);
            if (optModal) {
                optModalEl.addEventListener('hidden.bs.modal', function onHidden() {
                    optModalEl.removeEventListener('hidden.bs.modal', onHidden);
                    const snedModal = new bootstrap.Modal(document.getElementById('snedExportModal'));
                    snedModal.show();
                });
                optModal.hide();
            } else {
                const snedModal = new bootstrap.Modal(document.getElementById('snedExportModal'));
                snedModal.show();
            }
        }

        function triggerSchoolExport() {
            triggerSchoolReportExport('export_sned_school.php', 'Export School SNED Data Collection', 'Export SNED', 'Choose a school and school year for the SNED data collection spreadsheet.');
        }

        function promptSchoolExport() {
            triggerSchoolExport();
        }


        function confirmArchive(lrn, name) {
            document.getElementById('archiveName').innerText = name;
            document.getElementById('archiveLrn').innerText = lrn;
            document.getElementById('archiveConfirmBtn').href = 'learners.php?action=delete&id=' + encodeURIComponent(lrn);
            
            const modal = new bootstrap.Modal(document.getElementById('confirmArchiveModal'));
            modal.show();
        }
    </script>
</body>
</html>
