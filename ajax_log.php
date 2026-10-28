<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$details = $_POST['details'] ?? '';

$user_role = $_SESSION['role'] ?? 'user';
$user_school_id = $_SESSION['school_id'] ?? null;

if ($action === 'PRINT_LIST') {
    log_transaction($conn, $_SESSION['user_id'], 'PRINT', 'learners', null, "Printed learner directory list" . ($details ? ": $details" : ""));
    echo json_encode(['status' => 'success']);
} elseif ($action === 'lookup_guardian') {
    $lrn = $_GET['lrn'] ?? '';
    if (!preg_match('/^\d{12}$/', $lrn)) {
        echo json_encode(['success' => false, 'message' => 'Invalid LRN format.']);
        exit();
    }
    if (!user_is_coordinator($user_role)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
    if (user_is_scoped($user_role)) {
        $has_school_access = false;
        if ($user_school_id) {
            $stmt_g = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE lrn = ? AND school_id = ? AND is_deleted = 0 AND is_historical = 0");
            $stmt_g->bind_param("si", $lrn, $user_school_id);
            $stmt_g->execute();
            $gate_res = $stmt_g->get_result()->fetch_assoc();
            $has_school_access = ($gate_res['count'] ?? 0) > 0;
        }
        if (!$has_school_access && !check_teacher_learner_access($conn, $lrn)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
            exit();
        }
    }

    $stmt = $conn->prepare("SELECT g.house_no_street, g.barangay, g.first_name, g.last_name
        FROM learners l
        LEFT JOIN guardians g ON l.guardian_id = g.id
        WHERE l.lrn = ? AND l.is_deleted = 0");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'guardian_name' => $row['last_name'] . ', ' . $row['first_name'],
            'house_no_street' => $row['house_no_street'],
            'barangay' => $row['barangay'],
            'address' => $row['house_no_street'] . ', ' . $row['barangay']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Learner not found or has no guardian.']);
    }
} elseif ($action === 'learner_quick_view') {
    $lrn = $_GET['lrn'] ?? '';
    if (!preg_match('/^\d{12}$/', $lrn)) {
        echo json_encode(['success' => false, 'message' => 'Invalid LRN format.']);
        exit();
    }
    if (!user_is_coordinator($user_role)) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
    if (user_is_scoped($user_role)) {
        $has_school_access = false;
        if ($user_school_id) {
            $stmt_g = $conn->prepare("SELECT COUNT(*) as count FROM enrollments WHERE lrn = ? AND school_id = ? AND is_deleted = 0 AND is_historical = 0");
            $stmt_g->bind_param("si", $lrn, $user_school_id);
            $stmt_g->execute();
            $gate_res = $stmt_g->get_result()->fetch_assoc();
            $has_school_access = ($gate_res['count'] ?? 0) > 0;
        }
        if (!$has_school_access && !check_teacher_learner_access($conn, $lrn)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
            exit();
        }
    }

    $stmt = $conn->prepare("
        SELECT l.lrn, l.first_name, l.last_name, l.middle_name, l.extension_name,
               l.birthday, l.sex, l.pwd_id_number, l.profile_id,
               e.grade_level, e.placement_type, e.self_contained_level, e.school_year, e.primary_diagnosis,
               s.school_name, s.district,
               g.first_name AS gfname, g.last_name AS glname, g.house_no_street, g.barangay, g.contact_number AS mobile_number
        FROM learners l
        LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
        LEFT JOIN schools s ON e.school_id = s.id
        LEFT JOIN guardians g ON l.guardian_id = g.id
        WHERE l.lrn = ? AND l.is_deleted = 0
        LIMIT 1");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $age = '—';
        if (!empty($row['birthday'])) {
            try {
                $bday  = new DateTime($row['birthday']);
                $today = new DateTime();
                $age   = $today->diff($bday)->y;
            } catch (Exception $e) {
                $age = '—';
            }
        }

        echo json_encode([
            'success' => true,
            'learner' => [
                'lrn' => $row['lrn'],
                'name' => $row['last_name'] . ', ' . $row['first_name'] . ($row['middle_name'] ? ' ' . $row['middle_name'] : ''),
                'birthday' => $row['birthday'],
                'age' => $age,
                'sex' => $row['sex'],
                'pwd_id' => $row['pwd_id_number'],
                'profile_id' => $row['profile_id'],
            ],
            'enrollment' => $row['school_name'] ? [
                'school' => $row['school_name'],
                'district' => $row['district'],
                'grade_level' => $row['grade_level'],
                'placement' => $row['placement_type'],
                'self_contained_level' => $row['self_contained_level'],
                'school_year' => $row['school_year'],
                'diagnosis' => $row['primary_diagnosis'],
            ] : null,
            'guardian' => $row['glname'] ? [
                'name'    => $row['glname'] . ', ' . $row['gfname'],
                'address' => ($row['house_no_street'] ? $row['house_no_street'] . ', ' : '') . ($row['barangay'] ?? ''),
                'mobile'  => $row['mobile_number'],
            ] : null
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Learner not found.']);
    }
} else {
    http_response_code(400);
}
?>
