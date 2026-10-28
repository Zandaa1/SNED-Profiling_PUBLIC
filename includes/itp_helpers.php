<?php

if (!function_exists('itp_entry_point_options')) {
    function itp_entry_point_options(): array {
        return [
            'transition_home_to_school' => 'Transition from home to school',
            'transition_school_to_life' => 'Transition from school to functional life',
            'transition_sped_to_inclusion' => 'Transition from SPED Center/SPED Classes to Inclusion Classes',
            'transition_grade_to_grade' => 'Transition from one grade level to the next grade level',
            'transition_school_to_work' => 'Transition from school to employment or entrepreneurship',
            'transition_class_to_class' => 'Transition from one class to another in the same grade level',
        ];
    }
}

if (!function_exists('itp_fixed_team_roles')) {
    function itp_fixed_team_roles(): array {
        return [
            'ITP Coordinator',
            'School Head',
            'SPED Teacher',
            'Parent/Guardian',
            'Learner',
            'Regular/Guidance Teacher',
            'Linkages',
        ];
    }
}

if (!function_exists('itp_map_legacy_role')) {
    function itp_map_legacy_role(string $role): string {
        $normalized = strtolower(trim($role));
        return match (true) {
            $normalized === 'itp coordinator' => 'ITP Coordinator',
            in_array($normalized, ['school head', 'principal', 'school principal'], true) => 'School Head',
            in_array($normalized, ['sped teacher', 'special education teacher'], true) => 'SPED Teacher',
            in_array($normalized, ['parent/guardian', 'guardian', 'parent'], true) => 'Parent/Guardian',
            $normalized === 'learner' => 'Learner',
            in_array($normalized, ['regular/receiving teacher', 'regular/guidance teacher', 'guidance counselor', 'regular teacher'], true) => 'Regular/Guidance Teacher',
            default => 'Linkages',
        };
    }
}

if (!function_exists('itp_instruction_sections')) {
    function itp_instruction_sections(): array {
        return [
            'Description' => 'Transition planning provides guidance for family and educators, and for the learners to be ready for the real world. This will ensure that the learner will be placed in possible point of entry according to his/her capabilities for them to live independently with a full participating life in the community. It is a living document and can be modified at any time based on the progress/development/performance of the learner.',
            'Purpose' => 'This is designed to enable and empower learners with special educational needs (LSENs) to successfully move forward from home to school, from class to class, from school to school, and from school to technical-vocational field, or employment, entrepreneurship or life beyond school.',
            'Who is it for?' => 'This form is for learners with disabilities in elementary and high school who need transition planning based on their assessed entry and exit points.',
            'When to use?' => 'This form will be accomplished after assessment for the appropriate entry point of transition program of LSENs and will be reviewed or revisited regularly to track progress.',
            'How to use?' => 'The SPED teacher shall fill-out the required data in the form (see attached ITP Form) and work with the Transition Team in formulating the transition goal and plan.',
        ];
    }
}

if (!function_exists('itp_transition_packages')) {
    function itp_transition_packages(): array {
        return [
            'Care',
            'Life Skills',
            'Functional Academics',
            'Pre-Vocational',
            'Enrichment',
            'Livelihood',
            'Career Skills',
        ];
    }
}

if (!function_exists('itp_life_pathways')) {
    function itp_life_pathways(): array {
        return [
            'Higher Education',
            'Entrepreneurship',
            'Employment',
            'Middle Level Skills',
            'Functional Life Path',
        ];
    }
}

if (!function_exists('itp_transition_matrix')) {
    function itp_transition_matrix(): array {
        return [
            [
                'label' => 'Transition from school to functional life',
                'cells' => ['', '', 'check', '', '', 'check', ''],
            ],
            [
                'label' => 'Transition from home to school',
                'cells' => ['check', 'check', 'check', 'check', 'check', 'check', 'check'],
            ],
            [
                'label' => 'Transition from one class to another in the same grade level/program option',
                'cells' => ['', '', '', '', '', '', ''],
            ],
            [
                'label' => 'Transition from SPED center to inclusion classes',
                'cells' => [
                    'check',
                    'This will not to be taken as it is already in the K to 12 BEC.',
                    'check',
                    'check',
                    'This will not to be taken as it is already in the K to 12 BEC.',
                    'check',
                    'check',
                ],
            ],
            [
                'label' => 'Transition from one grade level to the next grade level',
                'cells' => ['check', '', '', '', '', '', ''],
            ],
            [
                'label' => 'Transition from school to employment and entrepreneurship',
                'cells' => ['check', 'check', 'check', 'check', 'check', 'check', 'check'],
            ],
        ];
    }
}

if (!function_exists('itp_find_photo_src')) {
    function itp_find_photo_src(string $lrn): string {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $test = "uploads/{$lrn}.{$ext}";
            if (file_exists($test)) {
                return $test;
            }
        }
        return 'img/student_placeholder.webp';
    }
}

if (!function_exists('itp_table_exists')) {
    function itp_table_exists(mysqli $conn, string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $conn->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ');
        if (!$stmt) {
            return $cache[$table] = false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        return $cache[$table] = ($count > 0);
    }
}

if (!function_exists('itp_column_exists')) {
    function itp_column_exists(mysqli $conn, string $table, string $column): bool {
        static $cache = [];
        $cache_key = $table . '.' . $column;
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $stmt = $conn->prepare('
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ');
        if (!$stmt) {
            return $cache[$cache_key] = false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        return $cache[$cache_key] = ($count > 0);
    }
}

if (!function_exists('itp_record_value')) {
    function itp_record_value(array $row, array $preferred_keys, $fallback = '') {
        foreach ($preferred_keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $fallback;
    }
}

if (!function_exists('itp_matrix_row_matches_entry_point')) {
    function itp_matrix_row_matches_entry_point(string $matrix_label, string $entry_point_label): bool {
        $m = strtolower(trim($matrix_label));
        $e = strtolower(trim($entry_point_label));
        if ($m === $e) {
            return true;
        }
        if (str_contains($m, 'functional life') && str_contains($e, 'functional life')) return true;
        if (str_contains($m, 'home to school') && str_contains($e, 'home to school')) return true;
        if (str_contains($m, 'class to another') && str_contains($e, 'class to another')) return true;
        if (str_contains($m, 'sped center') && str_contains($e, 'sped center')) return true;
        if (str_contains($m, 'grade level to the next') && str_contains($e, 'grade level to the next')) return true;
        if (str_contains($m, 'employment') && str_contains($e, 'employment')) return true;
        return false;
    }
}
