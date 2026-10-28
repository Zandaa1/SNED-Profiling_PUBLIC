<?php
require_once __DIR__ . '/helpers.php';

if (!function_exists('sned_export_current_school_year')) {
    function sned_export_current_school_year(): string {
        $cy = (int)date('Y');
        $cm = (int)date('n');
        return ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
    }
}

if (!function_exists('sned_export_normalize_school_year')) {
    function sned_export_normalize_school_year(?string $school_year, ?string $fallback = null): string {
        $school_year = trim((string)$school_year);
        if (preg_match('/^\d{4}-\d{4}$/', $school_year)) {
            return $school_year;
        }
        return $fallback ?: sned_export_current_school_year();
    }
}

if (!function_exists('sned_export_safe_filename_part')) {
    function sned_export_safe_filename_part(string $value, string $fallback = 'Report'): string {
        $safe = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $value), '_');
        return $safe !== '' ? $safe : $fallback;
    }
}

if (!function_exists('sned_export_bind_params')) {
    function sned_export_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
        if ($types === '') {
            return;
        }
        $refs = [];
        foreach ($params as $idx => $value) {
            $refs[$idx] = &$params[$idx];
        }
        $stmt->bind_param($types, ...$refs);
    }
}

if (!function_exists('sned_export_get_school')) {
    function sned_export_get_school(mysqli $conn, int $school_id): ?array {
        if ($school_id <= 0) {
            return null;
        }
        $stmt = $conn->prepare('SELECT id, school_name, district, unit FROM schools WHERE id = ? AND is_deleted = 0 LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $school_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }
}

if (!function_exists('sned_export_resolve_school_scope')) {
    function sned_export_resolve_school_scope(mysqli $conn, string $user_role, ?int $requested_school_id, bool $require_school = false): array {
        $session_school_id = (int)($_SESSION['school_id'] ?? 0);
        $school_id = (int)($requested_school_id ?? 0);

        if (user_is_scoped($user_role)) {
            if ($session_school_id <= 0) {
                http_response_code(403);
                exit('Access denied. No school is assigned to your account.');
            }
            if ($school_id > 0 && $school_id !== $session_school_id) {
                http_response_code(403);
                exit('Access denied. You can only export data for your own school.');
            }
            $school_id = $session_school_id;
        }

        if ($require_school && $school_id <= 0) {
            http_response_code(400);
            exit('Please choose a school for this report.');
        }

        $school = $school_id > 0 ? sned_export_get_school($conn, $school_id) : null;
        if ($school_id > 0 && !$school) {
            http_response_code(404);
            exit('School not found.');
        }

        return [$school_id > 0 ? $school_id : null, $school];
    }
}

if (!function_exists('sned_export_full_name')) {
    function sned_export_full_name(array $row, string $prefix = ''): string {
        return trim(implode(' ', array_filter([
            $row[$prefix . 'first_name'] ?? '',
            $row[$prefix . 'middle_name'] ?? '',
            $row[$prefix . 'last_name'] ?? '',
            $row[$prefix . 'extension_name'] ?? '',
        ], static fn($value) => trim((string)$value) !== '')));
    }
}

if (!function_exists('sned_export_guardian_address')) {
    function sned_export_guardian_address(array $row): string {
        return implode(', ', array_filter([
            $row['g_house_no_street'] ?? '',
            $row['g_barangay'] ?? '',
            $row['g_city'] ?? '',
            $row['g_province'] ?? '',
        ], static fn($value) => trim((string)$value) !== ''));
    }
}

if (!function_exists('sned_export_placement_label')) {
    function sned_export_placement_label(array $row): string {
        $placement = trim((string)($row['placement_type'] ?? ''));
        $grade = trim((string)($row['grade_level'] ?? ''));
        $level = trim((string)($row['self_contained_level'] ?? ''));
        if ($placement === '') {
            return 'Unassigned';
        }
        $details = implode(' - ', array_filter([$grade, $level], static fn($value) => $value !== ''));
        return $details !== '' ? $placement . ' (' . $details . ')' : $placement;
    }
}

if (!function_exists('sned_export_fetch_lwd_rows')) {
    function sned_export_fetch_lwd_rows(mysqli $conn, ?int $school_id = null, ?string $school_year = null, ?int $min_age = null): array {
        $sub_conditions = ['e1.lrn = l.lrn', 'e1.is_deleted = 0', 'e1.is_historical = 0'];
        $types = '';
        $params = [];

        if ($school_id !== null && $school_id > 0) {
            $sub_conditions[] = 'e1.school_id = ?';
            $types .= 'i';
            $params[] = $school_id;
        }

        if ($school_year !== null && trim($school_year) !== '') {
            $sub_conditions[] = 'e1.school_year = ?';
            $types .= 's';
            $params[] = $school_year;
        }

        $where = ['l.is_deleted = 0', 'l.lrn_is_temporary = 0', 'e.id IS NOT NULL'];
        if ($min_age !== null) {
            $where[] = 'TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) >= ?';
        }

        $sql = "
            SELECT
                l.lrn,
                l.last_name AS l_last_name,
                l.first_name AS l_first_name,
                l.middle_name AS l_middle_name,
                l.extension_name AS l_extension_name,
                l.birthday,
                TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) AS age,
                l.sex,
                g.last_name AS g_last_name,
                g.first_name AS g_first_name,
                g.middle_name AS g_middle_name,
                g.extension_name AS g_extension_name,
                g.contact_number,
                g.relationship,
                g.house_no_street AS g_house_no_street,
                g.barangay AS g_barangay,
                g.city AS g_city,
                g.province AS g_province,
                e.id AS enrollment_id,
                e.school_year,
                e.placement_type,
                e.self_contained_level,
                e.grade_level,
                e.primary_diagnosis,
                s.school_name,
                s.district,
                COALESCE(NULLIF(eds.disability_summary, ''), NULLIF(e.primary_diagnosis, ''), 'Unassigned') AS disability_summary
            FROM learners l
            LEFT JOIN guardians g ON l.guardian_id = g.id
            LEFT JOIN enrollments e ON e.id = (
                SELECT e1.id
                FROM enrollments e1
                WHERE " . implode(' AND ', $sub_conditions) . "
                ORDER BY e1.school_year DESC, e1.id DESC
                LIMIT 1
            )
            LEFT JOIN schools s ON e.school_id = s.id
            LEFT JOIN (
                SELECT
                    enrollment_id,
                    GROUP_CONCAT(
                        DISTINCT CASE
                            WHEN disability = 'Other' AND other_text IS NOT NULL AND other_text <> '' THEN CONCAT(disability, ': ', other_text)
                            ELSE disability
                        END
                        ORDER BY sort_order ASC, id ASC
                        SEPARATOR '; '
                    ) AS disability_summary
                FROM enrollment_disabilities
                GROUP BY enrollment_id
            ) eds ON eds.enrollment_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.school_name ASC, l.last_name ASC, l.first_name ASC, l.middle_name ASC
        ";

        if ($min_age !== null) {
            $types .= 'i';
            $params[] = $min_age;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        sned_export_bind_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
