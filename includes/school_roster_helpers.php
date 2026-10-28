<?php
require_once __DIR__ . '/helpers.php';

if (!function_exists('school_roster_current_school_year')) {
    function school_roster_current_school_year(): string {
        $cy = (int)date('Y');
        $cm = (int)date('n');
        return ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
    }
}

if (!function_exists('school_roster_filters_from_request')) {
    function school_roster_filters_from_request(array $source, string $default_school_year): array {
        $filters = [
            'school_year' => trim((string)($source['school_year'] ?? $default_school_year)),
            'q' => trim((string)($source['q'] ?? '')),
            'sex' => trim((string)($source['sex'] ?? '')),
            'grade_level' => trim((string)($source['grade_level'] ?? '')),
            'placement_type' => trim((string)($source['placement_type'] ?? '')),
            'self_contained_level' => trim((string)($source['self_contained_level'] ?? '')),
            'disability' => trim((string)($source['disability'] ?? '')),
            'barangay' => trim((string)($source['barangay'] ?? '')),
            'city' => trim((string)($source['city'] ?? '')),
            'age_min' => trim((string)($source['age_min'] ?? '')),
            'age_max' => trim((string)($source['age_max'] ?? '')),
            'assessment_mode' => trim((string)($source['assessment_mode'] ?? '')),
            'interpreter_needed' => trim((string)($source['interpreter_needed'] ?? '')),
        ];

        if (!preg_match('/^\d{4}-\d{4}$/', $filters['school_year'])) {
            $filters['school_year'] = $default_school_year;
        }
        foreach (['age_min', 'age_max'] as $key) {
            if ($filters[$key] !== '') {
                $age = max(0, min(120, (int)$filters[$key]));
                $filters[$key] = (string)$age;
            }
        }

        return $filters;
    }
}

if (!function_exists('school_roster_bind_params')) {
    function school_roster_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
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

if (!function_exists('school_roster_build_query')) {
    function school_roster_build_query(int $school_id, array $filters, bool $apply_filters = true): array {
        $sql = "
            SELECT
                l.lrn,
                l.first_name,
                l.middle_name,
                l.last_name,
                l.extension_name,
                l.birthday,
                TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) AS age,
                l.sex,
                l.house_no_street,
                l.barangay,
                l.city,
                l.province,
                e.id AS enrollment_id,
                e.school_year,
                e.placement_type,
                e.self_contained_level,
                e.grade_level,
                e.primary_diagnosis,
                e.assessment_mode,
                e.interpreter_needed,
                COALESCE(NULLIF(eds.disability_summary, ''), NULLIF(e.primary_diagnosis, ''), 'Unassigned') AS disability_summary
            FROM learners l
            JOIN enrollments e ON l.lrn = e.lrn
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
            WHERE l.is_deleted = 0 AND l.lrn_is_temporary = 0
              AND e.school_id = ?
              AND e.school_year = ?
              AND e.is_deleted = 0
              AND e.is_historical = 0
        ";
        $types = 'is';
        $params = [$school_id, $filters['school_year'] ?? school_roster_current_school_year()];

        if ($apply_filters) {
            if (($filters['q'] ?? '') !== '') {
                $like = '%' . $filters['q'] . '%';
                $sql .= " AND (l.lrn LIKE ? OR l.first_name LIKE ? OR l.middle_name LIKE ? OR l.last_name LIKE ? OR CONCAT_WS(' ', l.first_name, l.middle_name, l.last_name) LIKE ? OR CONCAT_WS(' ', l.last_name, l.first_name, l.middle_name) LIKE ?)";
                $types .= 'ssssss';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            foreach ([
                'sex' => 'l.sex',
                'grade_level' => 'e.grade_level',
                'placement_type' => 'e.placement_type',
                'self_contained_level' => 'e.self_contained_level',
                'barangay' => 'l.barangay',
                'city' => 'l.city',
                'assessment_mode' => 'e.assessment_mode',
                'interpreter_needed' => 'e.interpreter_needed',
            ] as $key => $column) {
                if (($filters[$key] ?? '') !== '') {
                    $sql .= " AND {$column} = ?";
                    $types .= 's';
                    $params[] = $filters[$key];
                }
            }
            if (($filters['disability'] ?? '') !== '') {
                $like = '%' . $filters['disability'] . '%';
                $sql .= " AND (e.primary_diagnosis = ? OR eds.disability_summary LIKE ?)";
                $types .= 'ss';
                array_push($params, $filters['disability'], $like);
            }
            if (($filters['age_min'] ?? '') !== '') {
                $sql .= " AND TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) >= ?";
                $types .= 'i';
                $params[] = (int)$filters['age_min'];
            }
            if (($filters['age_max'] ?? '') !== '') {
                $sql .= " AND TIMESTAMPDIFF(YEAR, l.birthday, CURDATE()) <= ?";
                $types .= 'i';
                $params[] = (int)$filters['age_max'];
            }
        }

        $sql .= " ORDER BY l.last_name ASC, l.first_name ASC, l.middle_name ASC";
        return [$sql, $types, $params];
    }
}

if (!function_exists('school_roster_fetch_learners')) {
    function school_roster_fetch_learners(mysqli $conn, int $school_id, array $filters, bool $apply_filters = true): array {
        [$sql, $types, $params] = school_roster_build_query($school_id, $filters, $apply_filters);
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        school_roster_bind_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('school_roster_filter_options')) {
    function school_roster_filter_options(mysqli $conn, int $school_id): array {
        $options = [
            'school_years' => [],
            'grade_levels' => [],
            'self_contained_levels' => [],
            'disabilities' => [],
            'barangays' => [],
            'cities' => [],
            'assessment_modes' => [],
            'interpreter_needed' => [],
        ];

        $queries = [
            'school_years' => "SELECT DISTINCT school_year AS value FROM enrollments WHERE school_id = ? AND is_deleted = 0 ORDER BY school_year DESC",
            'grade_levels' => "SELECT DISTINCT grade_level AS value FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND grade_level <> '' ORDER BY grade_level ASC",
            'self_contained_levels' => "SELECT DISTINCT self_contained_level AS value FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND self_contained_level IS NOT NULL AND self_contained_level <> '' ORDER BY self_contained_level ASC",
            'barangays' => "SELECT DISTINCT l.barangay AS value FROM learners l JOIN enrollments e ON l.lrn = e.lrn WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0 AND l.is_deleted = 0 AND l.barangay IS NOT NULL AND l.barangay <> '' ORDER BY l.barangay ASC",
            'cities' => "SELECT DISTINCT l.city AS value FROM learners l JOIN enrollments e ON l.lrn = e.lrn WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0 AND l.is_deleted = 0 AND l.city IS NOT NULL AND l.city <> '' ORDER BY l.city ASC",
            'assessment_modes' => "SELECT DISTINCT assessment_mode AS value FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND assessment_mode IS NOT NULL AND assessment_mode <> '' ORDER BY assessment_mode ASC",
            'interpreter_needed' => "SELECT DISTINCT interpreter_needed AS value FROM enrollments WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND interpreter_needed IS NOT NULL AND interpreter_needed <> '' ORDER BY interpreter_needed ASC",
        ];

        foreach ($queries as $key => $query) {
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('i', $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                if (trim((string)$row['value']) !== '') {
                    $options[$key][] = $row['value'];
                }
            }
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT value FROM (
                SELECT primary_diagnosis AS value
                FROM enrollments
                WHERE school_id = ? AND is_deleted = 0 AND is_historical = 0 AND primary_diagnosis <> ''
                UNION
                SELECT CASE
                    WHEN ed.disability = 'Other' AND ed.other_text IS NOT NULL AND ed.other_text <> '' THEN CONCAT(ed.disability, ': ', ed.other_text)
                    ELSE ed.disability
                END AS value
                FROM enrollment_disabilities ed
                JOIN enrollments e ON ed.enrollment_id = e.id
                WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0
            ) d
            WHERE value IS NOT NULL AND value <> ''
            ORDER BY value ASC
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $school_id, $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                $options['disabilities'][] = $row['value'];
            }
        }

        return $options;
    }
}

if (!function_exists('school_roster_filter_summary')) {
    function school_roster_filter_summary(array $filters, bool $include_school_year = true): array {
        $labels = [
            'school_year' => 'School Year',
            'q' => 'Search',
            'sex' => 'Sex',
            'grade_level' => 'Grade',
            'placement_type' => 'Placement',
            'self_contained_level' => 'Self-contained Level',
            'disability' => 'Disability/Diagnosis',
            'barangay' => 'Barangay',
            'city' => 'City',
            'assessment_mode' => 'Assessment Mode',
            'interpreter_needed' => 'Interpreter Needed',
        ];
        $summary = [];
        foreach ($labels as $key => $label) {
            if (!$include_school_year && $key === 'school_year') {
                continue;
            }
            if (($filters[$key] ?? '') !== '') {
                $summary[] = $label . ': ' . $filters[$key];
            }
        }
        if (($filters['age_min'] ?? '') !== '' || ($filters['age_max'] ?? '') !== '') {
            $summary[] = 'Age: ' . (($filters['age_min'] ?? '') !== '' ? $filters['age_min'] : '0') . ' to ' . (($filters['age_max'] ?? '') !== '' ? $filters['age_max'] : '120');
        }
        return $summary;
    }
}

if (!function_exists('school_roster_csv_address')) {
    function school_roster_csv_address(array $learner): string {
        return implode(', ', array_filter([
            $learner['house_no_street'] ?? '',
            $learner['barangay'] ?? '',
            $learner['city'] ?? '',
            $learner['province'] ?? '',
        ])) ?: '—';
    }
}
