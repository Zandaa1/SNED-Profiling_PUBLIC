<?php
session_start();
require_once 'config.php';
require_once 'includes/helpers.php';
require_once 'includes/itp_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!function_exists('stmt_bind_params')) {
    function stmt_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
        if (strlen($types) !== count($params)) {
            throw new InvalidArgumentException('Bind parameter count does not match type definition.');
        }
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('null_if_empty')) {
    function null_if_empty($value): ?string {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: learners.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
    die("Invalid CSRF token.");
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    header("Location: 403.php");
    exit();
}

$lrn = $_POST['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner ID.");
}

$stmt = $conn->prepare("
    SELECT e.school_id, e.id AS enrollment_id, e.placement_type
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();

if (!$current) {
    die("Learner has no active enrollment or does not exist.");
}

if (!user_is_admin($user_role) && user_is_scoped($user_role)) {
    $session_school_id = $_SESSION['school_id'] ?? 0;
    if (!check_teacher_learner_access($conn, $lrn, true)) {
        header("Location: 403.php");
        exit();
    }
}

$school_year = trim($_POST['school_year'] ?? '');
if ($school_year === '') {
    die("School year is required.");
}

$enrollment_id = !empty($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : null;
$plan_date = null_if_empty($_POST['plan_date'] ?? '');
$review_date = null_if_empty($_POST['review_date'] ?? '');
$target_exit_date = null_if_empty($_POST['target_exit_date'] ?? '');

$parent_guardian_name = null_if_empty($_POST['parent_guardian_name'] ?? '');
$current_level_of_education = null_if_empty($_POST['current_level_of_education'] ?? '');
$previous_school_name = null_if_empty($_POST['previous_school_name'] ?? '');
$years_in_school = null_if_empty($_POST['years_in_school'] ?? '');
$religion = null_if_empty($_POST['religion'] ?? '');
$current_address = null_if_empty($_POST['current_address'] ?? '');
$contact_number = null_if_empty($_POST['contact_number'] ?? '');
$assessment_mode = null_if_empty($_POST['assessment_mode'] ?? '');
$assessment_institution = null_if_empty($_POST['assessment_institution'] ?? '');
$strengths = null_if_empty($_POST['strengths'] ?? '');
$interests = null_if_empty($_POST['interests'] ?? '');
$talents = null_if_empty($_POST['talents'] ?? '');
$skills = null_if_empty($_POST['skills'] ?? '');
$needs = null_if_empty($_POST['needs'] ?? '');
$recommendations = null_if_empty($_POST['recommendations'] ?? '');
$transition_goal = null_if_empty($_POST['transition_goal'] ?? '');
$entry_point_label = null_if_empty($_POST['entry_point_label'] ?? '');
$learning_packages = null_if_empty($_POST['learning_packages'] ?? '');
$prepared_by_name = null_if_empty($_POST['prepared_by_name'] ?? '');
$prepared_by_title = null_if_empty($_POST['prepared_by_title'] ?? '');
$noted_by_name = null_if_empty($_POST['noted_by_name'] ?? '');
$noted_by_title = null_if_empty($_POST['noted_by_title'] ?? '');

$entry_options = itp_entry_point_options();
$label_to_field = array_flip($entry_options);
if ($entry_point_label !== null && isset($entry_options[$entry_point_label])) {
    $entry_point_label = $entry_options[$entry_point_label];
}

$transition_home_to_school = 0;
$transition_school_to_life = 0;
$transition_sped_to_inclusion = 0;
$transition_grade_to_grade = 0;
$transition_school_to_work = 0;
$transition_class_to_class = 0;

if ($entry_point_label !== null && isset($label_to_field[$entry_point_label])) {
    ${$label_to_field[$entry_point_label]} = 1;
} else {
    $legacy_points = $_POST['transition_points'] ?? [];
    foreach ($legacy_points as $legacy_point) {
        if (array_key_exists($legacy_point, $entry_options)) {
            ${$legacy_point} = 1;
            $entry_point_label = $entry_options[$legacy_point];
            break;
        }
    }
}

$team_rows_input = $_POST['team_rows'] ?? [];
$team_rows = [];
$team_json_rows = [];
foreach (itp_fixed_team_roles() as $index => $fixed_role) {
    $row = $team_rows_input[$index] ?? [];
    $role = trim((string)($row['role'] ?? $fixed_role));
    $name = null_if_empty($row['name'] ?? '');
    $details = null_if_empty($row['contact_details'] ?? '');
    $date_started = null_if_empty($row['date_started_with_team'] ?? '');
    $team_rows[] = [
        'role' => $role !== '' ? $role : $fixed_role,
        'name' => $name,
        'contact_details' => $details,
        'date_started_with_team' => $date_started,
        'sort_order' => $index,
    ];
    if ($name !== null) {
        $team_json_rows[] = ['role' => $role !== '' ? $role : $fixed_role, 'name' => $name];
    }
}
$team_json = json_encode($team_json_rows, JSON_UNESCAPED_UNICODE);

$goal_rows_input = $_POST['goal_rows'] ?? [];
$goal_rows = [];
foreach ($goal_rows_input as $index => $row) {
    $competency = null_if_empty($row['competency'] ?? '');
    $activities = null_if_empty($row['activities'] ?? '');
    $time_frame = null_if_empty($row['time_frame'] ?? '');
    $persons_responsible = null_if_empty($row['persons_responsible'] ?? '');
    $remarks = null_if_empty($row['remarks'] ?? '');
    if ($competency === null && $activities === null && $time_frame === null && $persons_responsible === null && $remarks === null) {
        continue;
    }
    $goal_rows[] = [
        'competency' => $competency,
        'activities' => $activities,
        'time_frame' => $time_frame,
        'persons_responsible' => $persons_responsible,
        'remarks' => $remarks,
        'sort_order' => count($goal_rows),
    ];
}

$has_complete_goal_row = false;
foreach ($goal_rows as $goal_row) {
    if (
        trim((string)($goal_row['competency'] ?? '')) !== ''
        && trim((string)($goal_row['activities'] ?? '')) !== ''
        && trim((string)($goal_row['time_frame'] ?? '')) !== ''
        && trim((string)($goal_row['persons_responsible'] ?? '')) !== ''
    ) {
        $has_complete_goal_row = true;
        break;
    }
}

if (!$has_complete_goal_row) {
    header("Location: itp_form.php?learner_id=" . urlencode($lrn) . "&school_year=" . urlencode($school_year) . "&error=goal_required");
    exit();
}

$has_annex5_parent_fields = itp_column_exists($conn, 'learner_itp', 'parent_guardian_name')
    && itp_column_exists($conn, 'learner_itp', 'strengths')
    && itp_column_exists($conn, 'learner_itp', 'entry_point_label');
$has_itp_team_table = itp_table_exists($conn, 'learner_itp_team_members');
$has_itp_goal_table = itp_table_exists($conn, 'learner_itp_goal_rows');

$conn->begin_transaction();

try {
    $stmt_check = $conn->prepare("SELECT id FROM learner_itp WHERE learner_id = ? AND school_year = ? LIMIT 1");
    $stmt_check->bind_param("ss", $lrn, $school_year);
    $stmt_check->execute();
    $existing = $stmt_check->get_result()->fetch_assoc();

    $parent_sql_values = [
        $enrollment_id,
        $plan_date,
        $review_date,
        $target_exit_date,
        $parent_guardian_name,
        $current_level_of_education,
        $previous_school_name,
        $years_in_school,
        $religion,
        $current_address,
        $contact_number,
        $assessment_mode,
        $assessment_institution,
        $strengths,
        $interests,
        $talents,
        $skills,
        $needs,
        $recommendations,
        $transition_goal,
        $entry_point_label,
        $learning_packages,
        $prepared_by_name,
        $prepared_by_title,
        $noted_by_name,
        $noted_by_title,
        $strengths,
        $interests,
        $transition_goal,
        $needs,
        $recommendations,
        $team_json,
        $transition_home_to_school,
        $transition_school_to_life,
        $transition_sped_to_inclusion,
        $transition_grade_to_grade,
        $transition_school_to_work,
        $transition_class_to_class,
    ];

    $legacy_follow_up_notes = null_if_empty(trim(implode("\n", array_filter([
        $recommendations ?? '',
        $learning_packages ?? '',
    ]))));

    if ($existing && $has_annex5_parent_fields) {
        $itp_id = (int)$existing['id'];
        $stmt_upd = $conn->prepare("
            UPDATE learner_itp SET
                enrollment_id = ?, plan_date = ?, review_date = ?, target_exit_date = ?,
                parent_guardian_name = ?, current_level_of_education = ?, previous_school_name = ?, years_in_school = ?,
                religion = ?, current_address = ?, contact_number = ?, assessment_mode = ?, assessment_institution = ?,
                strengths = ?, interests = ?, talents = ?, skills = ?, needs = ?, recommendations = ?,
                transition_goal = ?, entry_point_label = ?, learning_packages = ?, prepared_by_name = ?,
                prepared_by_title = ?, noted_by_name = ?, noted_by_title = ?, learner_strengths = ?,
                learner_preferences = ?, post_school_goal = ?, transition_needs = ?, follow_up_notes = ?, team_json = ?,
                transition_home_to_school = ?, transition_school_to_life = ?, transition_sped_to_inclusion = ?,
                transition_grade_to_grade = ?, transition_school_to_work = ?, transition_class_to_class = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $params = $parent_sql_values;
        $params[] = $_SESSION['user_id'];
        $params[] = $itp_id;
        $types = 'i' . str_repeat('s', 31) . str_repeat('i', 8);
        stmt_bind_params($stmt_upd, $types, $params);
        $stmt_upd->execute();
        $action = 'update';
    } elseif (!$existing && $has_annex5_parent_fields) {
        $stmt_ins = $conn->prepare("
            INSERT INTO learner_itp (
                learner_id, enrollment_id, school_year, plan_date, review_date, target_exit_date,
                parent_guardian_name, current_level_of_education, previous_school_name, years_in_school,
                religion, current_address, contact_number, assessment_mode, assessment_institution,
                strengths, interests, talents, skills, needs, recommendations, transition_goal,
                entry_point_label, learning_packages, prepared_by_name, prepared_by_title, noted_by_name,
                noted_by_title, learner_strengths, learner_preferences, post_school_goal, transition_needs,
                follow_up_notes, team_json, transition_home_to_school, transition_school_to_life,
                transition_sped_to_inclusion, transition_grade_to_grade, transition_school_to_work,
                transition_class_to_class, created_by, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        $params = array_merge(
            [$lrn, $parent_sql_values[0], $school_year],
            array_slice($parent_sql_values, 1),
            [$_SESSION['user_id'], $_SESSION['user_id']]
        );
        $types = 'sis' . str_repeat('s', 31) . str_repeat('i', 8);
        stmt_bind_params($stmt_ins, $types, $params);
        $stmt_ins->execute();
        $itp_id = (int)$conn->insert_id;
        $action = 'create';
    } elseif ($existing) {
        $itp_id = (int)$existing['id'];
        $stmt_upd = $conn->prepare("
            UPDATE learner_itp SET
                enrollment_id = ?, plan_date = ?, review_date = ?, target_exit_date = ?,
                learner_strengths = ?, learner_preferences = ?, post_school_goal = ?, transition_needs = ?,
                family_supports = ?, community_linkages = ?, follow_up_notes = ?, team_json = ?,
                transition_home_to_school = ?, transition_school_to_life = ?, transition_sped_to_inclusion = ?,
                transition_grade_to_grade = ?, transition_school_to_work = ?, transition_class_to_class = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $params = [
            $enrollment_id,
            $plan_date,
            $review_date,
            $target_exit_date,
            $strengths,
            $interests,
            $transition_goal,
            $needs,
            null,
            null,
            $legacy_follow_up_notes,
            $team_json,
            $transition_home_to_school,
            $transition_school_to_life,
            $transition_sped_to_inclusion,
            $transition_grade_to_grade,
            $transition_school_to_work,
            $transition_class_to_class,
            $_SESSION['user_id'],
            $itp_id,
        ];
        $types = 'i' . str_repeat('s', 11) . str_repeat('i', 8);
        stmt_bind_params($stmt_upd, $types, $params);
        $stmt_upd->execute();
        $action = 'update';
    } else {
        $stmt_ins = $conn->prepare("
            INSERT INTO learner_itp (
                learner_id, enrollment_id, school_year, plan_date, review_date, target_exit_date,
                learner_strengths, learner_preferences, post_school_goal, transition_needs,
                family_supports, community_linkages, follow_up_notes, team_json,
                transition_home_to_school, transition_school_to_life, transition_sped_to_inclusion,
                transition_grade_to_grade, transition_school_to_work, transition_class_to_class,
                created_by, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        $params = [
            $lrn,
            $enrollment_id,
            $school_year,
            $plan_date,
            $review_date,
            $target_exit_date,
            $strengths,
            $interests,
            $transition_goal,
            $needs,
            null,
            null,
            $legacy_follow_up_notes,
            $team_json,
            $transition_home_to_school,
            $transition_school_to_life,
            $transition_sped_to_inclusion,
            $transition_grade_to_grade,
            $transition_school_to_work,
            $transition_class_to_class,
            $_SESSION['user_id'],
            $_SESSION['user_id'],
        ];
        $types = 'sis' . str_repeat('s', 11) . str_repeat('i', 8);
        stmt_bind_params($stmt_ins, $types, $params);
        $stmt_ins->execute();
        $itp_id = (int)$conn->insert_id;
        $action = 'create';
    }

    if ($has_itp_team_table) {
        $stmt_clear_team = $conn->prepare("DELETE FROM learner_itp_team_members WHERE itp_id = ?");
        $stmt_clear_team->bind_param("i", $itp_id);
        $stmt_clear_team->execute();

        $stmt_team = $conn->prepare("
            INSERT INTO learner_itp_team_members
                (itp_id, role, name, contact_details, date_started_with_team, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($team_rows as $team_row) {
            $params = [
                $itp_id,
                $team_row['role'],
                $team_row['name'],
                $team_row['contact_details'],
                $team_row['date_started_with_team'],
                $team_row['sort_order'],
            ];
            $types = 'issssi';
            stmt_bind_params($stmt_team, $types, $params);
            $stmt_team->execute();
        }
    }

    if ($has_itp_goal_table) {
        $stmt_clear_goal = $conn->prepare("DELETE FROM learner_itp_goal_rows WHERE itp_id = ?");
        $stmt_clear_goal->bind_param("i", $itp_id);
        $stmt_clear_goal->execute();

        $stmt_goal = $conn->prepare("
            INSERT INTO learner_itp_goal_rows
                (itp_id, competency, activities, time_frame, persons_responsible, remarks, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($goal_rows as $goal_row) {
            $params = [
                $itp_id,
                $goal_row['competency'],
                $goal_row['activities'],
                $goal_row['time_frame'],
                $goal_row['persons_responsible'],
                $goal_row['remarks'],
                $goal_row['sort_order'],
            ];
            $types = 'isssssi';
            stmt_bind_params($stmt_goal, $types, $params);
            $stmt_goal->execute();
        }
    }

    $stmt_clear_legacy = $conn->prepare("DELETE FROM learner_itp_plans WHERE itp_id = ?");
    $stmt_clear_legacy->bind_param("i", $itp_id);
    $stmt_clear_legacy->execute();

    $stmt_legacy = $conn->prepare("
        INSERT INTO learner_itp_plans
            (itp_id, sort_order, interest, work_opportunities, interventions, timeline, responsible, status, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($goal_rows as $goal_row) {
        $params = [
            $itp_id,
            $goal_row['sort_order'],
            $goal_row['competency'],
            $goal_row['activities'],
            null,
            $goal_row['time_frame'],
            $goal_row['persons_responsible'],
            null,
            $goal_row['remarks'],
        ];
        $types = 'iisssssss';
        stmt_bind_params($stmt_legacy, $types, $params);
        $stmt_legacy->execute();
    }

    $conn->commit();
    log_transaction(
        $conn,
        $_SESSION['user_id'],
        $action === 'create' ? 'create_itp' : 'update_itp',
        'itp',
        $lrn,
        "Saved Annex 5 Individual Transition Plan for LRN: $lrn (SY: $school_year)"
    );

    header(
        "Location: viewLearner.php?id=" . urlencode($lrn)
        . "&tab=itp&msg=" . urlencode("Individual Transition Plan saved successfully.")
        . "&msg_type=success&msg_title=" . urlencode("ITP Saved")
    );
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    die("Database error: " . $e->getMessage());
}
?>
