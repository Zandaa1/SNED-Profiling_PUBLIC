<?php
function sned_grade_level_groups(bool $include_non_graded = true): array {
    $groups = [
        'Kindergarten' => [
            'Kindergarten' => 'Kindergarten',
        ],
        'Elementary School (Grade 1 - Grade 6)' => [
            'Grade 1' => 'Grade 1',
            'Grade 2' => 'Grade 2',
            'Grade 3' => 'Grade 3',
            'Grade 4' => 'Grade 4',
            'Grade 5' => 'Grade 5',
            'Grade 6' => 'Grade 6',
        ],
        'Junior High School (Grade 7 - Grade 10)' => [
            'Grade 7' => 'Grade 7',
            'Grade 8' => 'Grade 8',
            'Grade 9' => 'Grade 9',
            'Grade 10' => 'Grade 10',
        ],
        'Senior High School - Grade 11' => [
            'Grade 11 - Academic Track (STEM)' => 'Grade 11 - Academic Track (STEM)',
            'Grade 11 - Academic Track (ABM)' => 'Grade 11 - Academic Track (ABM)',
            'Grade 11 - Academic Track (HUMSS)' => 'Grade 11 - Academic Track (HUMSS)',
            'Grade 11 - Academic Track (GAS)' => 'Grade 11 - Academic Track (GAS)',
            'Grade 11 - Technical-Vocational-Livelihood (TVL) Track' => 'Grade 11 - Technical-Vocational-Livelihood (TVL) Track',
            'Grade 11 - Sports Track' => 'Grade 11 - Sports Track',
            'Grade 11 - Arts and Design Track' => 'Grade 11 - Arts and Design Track',
        ],
        'Senior High School - Grade 12' => [
            'Grade 12 - Academic Track (STEM)' => 'Grade 12 - Academic Track (STEM)',
            'Grade 12 - Academic Track (ABM)' => 'Grade 12 - Academic Track (ABM)',
            'Grade 12 - Academic Track (HUMSS)' => 'Grade 12 - Academic Track (HUMSS)',
            'Grade 12 - Academic Track (GAS)' => 'Grade 12 - Academic Track (GAS)',
            'Grade 12 - Technical-Vocational-Livelihood (TVL) Track' => 'Grade 12 - Technical-Vocational-Livelihood (TVL) Track',
            'Grade 12 - Sports Track' => 'Grade 12 - Sports Track',
            'Grade 12 - Arts and Design Track' => 'Grade 12 - Arts and Design Track',
        ],
        'Non-Graded' => [
            'Non-Graded' => 'Non-Graded',
        ],
    ];

    if (!$include_non_graded) {
        unset($groups['Non-Graded']);
    }

    return $groups;
}

function sned_all_grade_level_values(bool $include_non_graded = true): array {
    $values = [];
    foreach (sned_grade_level_groups($include_non_graded) as $options) {
        foreach (array_keys($options) as $value) {
            $values[] = $value;
        }
    }
    return $values;
}

function sned_grade_levels_from_school_offerings(?string $offerings): array {
    $groups = sned_grade_level_groups(false);
    $map = [
        'Kinder' => ['Kindergarten'],
        'Kindergarten' => ['Kindergarten'],
        'Grade 1-6' => array_keys($groups['Elementary School (Grade 1 - Grade 6)']),
        'Grade 7-10' => array_keys($groups['Junior High School (Grade 7 - Grade 10)']),
        'Grade 11-12' => array_merge(
            array_keys($groups['Senior High School - Grade 11']),
            array_keys($groups['Senior High School - Grade 12'])
        ),
        'Kinder - Grade School' => array_merge(
            ['Kindergarten'],
            array_keys($groups['Elementary School (Grade 1 - Grade 6)'])
        ),
        'Junior High - Senior High' => array_merge(
            array_keys($groups['Junior High School (Grade 7 - Grade 10)']),
            array_keys($groups['Senior High School - Grade 11']),
            array_keys($groups['Senior High School - Grade 12'])
        ),
    ];

    $allowed = [];
    $tokens = array_filter(array_map('trim', explode(',', (string)$offerings)));
    foreach ($tokens as $token) {
        if (isset($map[$token])) {
            $allowed = array_merge($allowed, $map[$token]);
            continue;
        }
        if (in_array($token, sned_all_grade_level_values(false), true)) {
            $allowed[] = $token;
        }
    }

    return array_values(array_unique($allowed));
}

function sned_school_allows_grade_level(mysqli $conn, int $school_id, string $grade_level): bool {
    if ($school_id <= 0 || $grade_level === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT grade_levels_offered FROM schools WHERE id = ? AND is_deleted = 0 LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }

    return in_array($grade_level, sned_grade_levels_from_school_offerings($row['grade_levels_offered'] ?? ''), true);
}

function grade_level_options($selected = null, ?array $allowed_values = null, bool $include_non_graded = true) {
    $groups = sned_grade_level_groups($include_non_graded);
    $allowed_lookup = $allowed_values === null ? null : array_fill_keys($allowed_values, true);

    $html = '<option value="">-- Select Grade Level --</option>';
    if ($allowed_values !== null && empty($allowed_values)) {
        $html .= '<option value="" disabled>No grade levels configured for this school</option>';
        return $html;
    }

    $is_custom = (
        $allowed_values === null
        && !empty($selected)
        && !in_array($selected, sned_all_grade_level_values($include_non_graded), true)
    );
    if ($is_custom) {
        $html .= '<option value="' . htmlspecialchars($selected) . '" selected>' . htmlspecialchars($selected) . ' (Current)</option>';
    }
    foreach ($groups as $label => $options) {
        $group_html = '';
        $has_options = false;
        foreach ($options as $value => $text) {
            if ($allowed_lookup !== null && !isset($allowed_lookup[$value])) {
                continue;
            }
            $has_options = true;
            $sel = ($selected !== null && (string)$selected === (string)$value) ? ' selected' : '';
            $group_html .= '<option value="' . htmlspecialchars($value) . '"' . $sel . '>' . htmlspecialchars($text) . '</option>';
        }
        if (!$has_options) {
            continue;
        }
        $html .= '<optgroup label="' . htmlspecialchars($label) . '">';
        $html .= $group_html;
        $html .= '</optgroup>';
    }
    return $html;
}

function program_options($selected = null) {
    $programs = ['Kinder', 'Primary Level 1', 'Primary Level 2', 'Primary Level 3', 'Transition'];
    $html = '<option value="">-- Select Program --</option>';
    foreach ($programs as $prog) {
        $sel = ($selected !== null && (string)$selected === $prog) ? ' selected' : '';
        $html .= '<option value="' . $prog . '"' . $sel . '>' . $prog . '</option>';
    }
    return $html;
}

function self_contained_level_options($selected = null) {
    return program_options($selected);
}

function guardian_relationship_options($selected = null) {
    $options = ['Parent', 'Grandparent', 'Sibling', 'Relative', 'Legal Guardian', 'Other'];
    $html = '<option value="">-- Select --</option>';
    foreach ($options as $opt) {
        $sel = ($selected !== null && (string)$selected === $opt) ? ' selected' : '';
        $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    return $html;
}

function placement_type_select($name = 'placement_type', $selected = 'Graded', $id = null) {
    $id_attr = $id ? ' id="' . htmlspecialchars($id) . '"' : '';
    $html = '<select name="' . htmlspecialchars($name) . '"' . $id_attr . ' class="form-select rounded-3" required>';
    foreach (['Graded', 'Non-Graded'] as $opt) {
        $sel = ($selected === $opt) ? ' selected' : '';
        $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function clean_diagnosis($diag) {
    $diag_upper = trim(strtoupper((string)$diag));
    if (in_array($diag_upper, ['ASD', 'AUTISM SPECTRUM DISORDER', 'LWASD', 'AUTISM'])) {
        return 'Autism Spectrum Disorder (ASD)';
    }
    if (in_array($diag_upper, ['ID', 'INTELLECTUAL DISABILITY', 'MILD INTELLECTUAL DISORDER', 'INTELLECTUAL'])) {
        return 'Intellectual Disability (ID)';
    }
    if (in_array($diag_upper, ['GDD', 'GLOBAL DEVELOPMENTAL DELAY'])) {
        return 'Global Developmental Delay (GDD)';
    }
    if (in_array($diag_upper, ['HI', 'HEARING IMPAIRMENT', 'DEAF'])) {
        return 'Hearing Impairment';
    }

    return trim((string)$diag);
}

function sned_table_exists(mysqli $conn, string $table): bool {
    static $cache = [];
    $key = $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    if (!$stmt) {
        return $cache[$key] = false;
    }
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    return $cache[$key] = $count > 0;
}

function sned_lis_diagnosed_disabilities(): array {
    return [
        'Visual Impairment',
        'Hearing Impairment',
        'Learning Disability',
        'Intellectual Disability',
        'Autism Spectrum Disorder',
        'Emotional-Behavioral Disorder',
        'Orthopedic/Physical Handicap',
        'Speech/Language Disorder',
        'Cerebral Palsy',
        'Special Health Problem/Chronic Disease',
        'Multiple Disabilities',
        'Other',
    ];
}

function sned_lis_difficulty_categories(): array {
    return [
        'Seeing',
        'Hearing',
        'Basic Learning/Applying Knowledge',
        'Remembering/Concentrating/Attention/Understanding',
        'Adaptive Skills',
        'Interpersonal Behavior',
        'Mobility',
        'Communicating',
        'Other',
    ];
}

function sned_lis_disability_options(): array {
    return [
        'diagnosed' => sned_lis_diagnosed_disabilities(),
        'difficulty' => sned_lis_difficulty_categories(),
    ];
}

function sned_clean_other_text(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return strtoupper($value);
}

function sned_collect_disability_rows_from_post(array $post): array {
    $rows = [];
    $options = sned_lis_disability_options();
    $posted = $post['disabilities'] ?? [];
    $other = $post['disability_other'] ?? [];
    $primary_val = $post['primary_disability'] ?? '';

    foreach ($options as $category => $allowed) {
        $values = $posted[$category] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }
        $seen = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '' || !in_array($value, $allowed, true) || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            
            $is_primary = 0;
            if ($primary_val !== '') {
                $parts = explode('|', $primary_val, 2);
                if (count($parts) === 2 && $parts[0] === $category && $parts[1] === $value) {
                    $is_primary = 1;
                }
            }
            
            $rows[] = [
                'category' => $category,
                'disability' => $value,
                'other_text' => $value === 'Other' ? sned_clean_other_text($other[$category] ?? '') : null,
                'is_primary' => $is_primary,
            ];
        }
    }

    // Failsafe: if none is primary but we have rows, mark the first one as primary
    $has_primary = false;
    foreach ($rows as $row) {
        if (!empty($row['is_primary'])) {
            $has_primary = true;
            break;
        }
    }
    if (!$has_primary && !empty($rows)) {
        $rows[0]['is_primary'] = 1;
    }

    return $rows;
}

function sned_disability_summary(array $rows, string $fallback = 'Unassigned'): string {
    // Sort rows so the primary disability comes first
    usort($rows, function ($a, $b) {
        return ($b['is_primary'] ?? 0) - ($a['is_primary'] ?? 0);
    });

    $labels = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['disability'] ?? ''));
        if ($label === '') {
            continue;
        }
        if ($label === 'Other' && !empty($row['other_text'])) {
            $label .= ': ' . trim((string)$row['other_text']);
        }
        if (!empty($row['is_primary'])) {
            $label .= ' (Primary)';
        }
        $labels[] = $label;
    }
    $labels = array_values(array_unique($labels));
    return empty($labels) ? $fallback : implode('; ', $labels);
}

function sned_disability_rows_from_legacy_diagnosis(string $diagnosis): array {
    $diagnosis = clean_diagnosis($diagnosis);
    $map = [
        'Autism Spectrum Disorder (ASD)' => 'Autism Spectrum Disorder',
        'Intellectual Disability (ID)' => 'Intellectual Disability',
        'Hearing Impairment' => 'Hearing Impairment',
        'Global Developmental Delay (GDD)' => 'Other',
    ];
    if ($diagnosis === '') {
        return [];
    }
    $disability = $map[$diagnosis] ?? (in_array($diagnosis, sned_lis_diagnosed_disabilities(), true) ? $diagnosis : 'Other');
    return [[
        'category' => 'diagnosed',
        'disability' => $disability,
        'other_text' => $disability === 'Other' ? strtoupper($diagnosis) : null,
    ]];
}

function sned_get_enrollment_disabilities(mysqli $conn, ?int $enrollment_id): array {
    if (!$enrollment_id || !sned_table_exists($conn, 'enrollment_disabilities')) {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT category, disability, other_text, is_primary
        FROM enrollment_disabilities
        WHERE enrollment_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function sned_save_enrollment_disabilities(mysqli $conn, int $enrollment_id, array $rows): void {
    if (!sned_table_exists($conn, 'enrollment_disabilities')) {
        return;
    }
    $stmt_del = $conn->prepare("DELETE FROM enrollment_disabilities WHERE enrollment_id = ?");
    $stmt_del->bind_param("i", $enrollment_id);
    $stmt_del->execute();

    if (empty($rows)) {
        return;
    }

    // Failsafe: make sure exactly one row is marked as primary
    $has_primary = false;
    foreach ($rows as $row) {
        if (!empty($row['is_primary'])) {
            $has_primary = true;
            break;
        }
    }
    if (!$has_primary) {
        $rows[0]['is_primary'] = 1;
    }

    $stmt_ins = $conn->prepare("
        INSERT INTO enrollment_disabilities (enrollment_id, category, disability, other_text, sort_order, is_primary)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($rows as $idx => $row) {
        $category = $row['category'];
        $disability = $row['disability'];
        $other_text = $row['other_text'] ?? null;
        $is_primary = !empty($row['is_primary']) ? 1 : 0;
        $stmt_ins->bind_param("isssii", $enrollment_id, $category, $disability, $other_text, $idx, $is_primary);
        $stmt_ins->execute();
    }
}

function sned_render_disability_checklist(array $selected_rows = []): string {
    $selected = [];
    $primary_lookup = [];
    $other_text = ['diagnosed' => '', 'difficulty' => ''];
    foreach ($selected_rows as $row) {
        $category = $row['category'] ?? '';
        $disability = $row['disability'] ?? '';
        if ($category === '' || $disability === '') {
            continue;
        }
        $selected[$category][$disability] = true;
        if (!empty($row['is_primary'])) {
            $primary_lookup[$category][$disability] = true;
        }
        if ($disability === 'Other') {
            $other_text[$category] = (string)($row['other_text'] ?? '');
        }
    }

    $groups = [
        'diagnosed' => 'With Medical Diagnosis',
        'difficulty' => 'Functional Difficulty / Without Medical Diagnosis but with Manifestation',
    ];

    $pretty_label = static function (string $label): string {
        $formatted = preg_replace('/\s*\/\s*/', ' / ', trim($label));
        return $formatted !== null ? $formatted : trim($label);
    };

    $html = '<div class="row g-4">';
    foreach ($groups as $category => $title) {
        $html .= '<div class="col-xl-6 col-12">';
        $html .= '<div class="border rounded-4 p-4 bg-white h-100">';
        $html .= '<div class="fw-bold small text-uppercase text-muted mb-3 pb-2 border-bottom">' . htmlspecialchars($title) . '</div>';
        $html .= '<div class="d-flex flex-column gap-2">';
        foreach (sned_lis_disability_options()[$category] as $option) {
            $id = 'disability_' . $category . '_' . substr(md5($option), 0, 10);
            $checked = !empty($selected[$category][$option]) ? ' checked' : '';
            $display_option = $pretty_label($option);
            
            $radio_id = 'primary_' . $category . '_' . substr(md5($option), 0, 10);
            $radio_checked = !empty($primary_lookup[$category][$option]) ? ' checked' : '';
            $radio_disabled = empty($selected[$category][$option]) ? ' disabled' : '';

            $html .= '<div class="disability-row-container d-flex align-items-center justify-content-between gap-2 px-3 py-2 border rounded-3 bg-white shadow-sm" style="min-height: 52px; transition: all 0.15s ease-in-out;">';
            
            // Left Checkbox
            $html .= '<div class="d-flex align-items-center flex-grow-1" style="min-width: 0;">';
            $html .= '<input type="checkbox" name="disabilities[' . htmlspecialchars($category) . '][]" value="' . htmlspecialchars($option) . '" id="' . $id . '" class="js-disability-checkbox me-2" style="width: 18px; height: 18px; cursor: pointer; flex-shrink: 0;"' . $checked . '>';
            $html .= '<label for="' . $id . '" class="fw-semibold text-dark mb-0 cursor-pointer text-wrap" style="font-size: 14px; line-height: 1.25; user-select: none;">' . htmlspecialchars($display_option) . '</label>';
            $html .= '</div>';
            
            // Right Primary Radio (PRIMARY DISABILITY)
            $html .= '<div class="primary-radio-container d-flex align-items-center ps-2 border-start" style="border-color: #dee2e6 !important; flex-shrink: 0;">';
            $html .= '<input type="radio" name="primary_disability" value="' . htmlspecialchars($category) . '|' . htmlspecialchars($option) . '" id="' . $radio_id . '" class="form-check-input js-primary-radio" style="width: 16px; height: 16px; cursor: pointer; margin-top: 0; flex-shrink: 0;"' . $radio_checked . $radio_disabled . '>';
            $html .= '<label for="' . $radio_id . '" class="small text-muted ms-1 cursor-pointer fw-bold mb-0" style="font-size: 11px; cursor: pointer; user-select: none;">Primary</label>';
            $html .= '</div>';
            
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<div class="mt-3 other-specify-container">';
        $html .= '<label class="form-label fw-bold">Specify Other (' . htmlspecialchars($title) . ')</label>';
        $html .= '<input type="text" name="disability_other[' . htmlspecialchars($category) . ']" class="form-control rounded-3" placeholder="Specify Other..." value="' . htmlspecialchars($other_text[$category] ?? '') . '">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function sned_store_uploaded_document(mysqli $conn, array $file, string $lrn, string $document_type, string $notes, int $uploaded_by): ?int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed_extensions = ['docx', 'pdf', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'xlsx', 'xls', 'csv'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions, true)) {
        throw new Exception("Unsupported file type. Allowed: " . implode(', ', array_map(fn($e) => '.' . $e, $allowed_extensions)));
    }

    $upload_dir = __DIR__ . '/../uploads/documents';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $extracted = null;
    if ($ext === 'txt' || $ext === 'csv') {
        $extracted = file_get_contents($file['tmp_name']);
    } elseif ($ext === 'rtf') {
        $rtf = file_get_contents($file['tmp_name']);
        if ($rtf !== false) {
            $text = preg_replace('/\\\\[a-z]{1,32}?(?:-?\d+)? ?/', '', $rtf);
            $text = preg_replace('/\{[^{}]*\}/', '', $text);
            $text = str_replace(['\\\\', '\{', '\}', '~', '\nonumber\par', '\par'], '', $text);
            $extracted = trim($text);
        }
    } elseif ($ext === 'docx' && extension_loaded('zip')) {
        $za = new ZipArchive();
        if ($za->open($file['tmp_name']) === true) {
            $xmlContent = $za->getFromName('word/document.xml');
            $za->close();
            if ($xmlContent) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlContent);
                if ($xml) {
                    $namespaces = $xml->getNamespaces(true);
                    $w = $namespaces['w'] ?? 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                    $paragraphs = [];
                    foreach ($xml->xpath('//w:p') as $p) {
                        $texts = [];
                        foreach ($p->children($w)->r as $r) {
                            $t = (string)$r->children($w)->t;
                            if ($t !== '') {
                                $texts[] = $t;
                            }
                        }
                        $line = implode('', $texts);
                        if (trim($line) !== '') {
                            $paragraphs[] = trim($line);
                        }
                    }
                    $extracted = implode("\n", $paragraphs);
                }
            }
        } else {
            $fallback_text = file_get_contents($file['tmp_name']);
            $extracted = $fallback_text !== false ? trim($fallback_text) : null;
        }
    }

    $stored_name = uniqid('doc_') . '.' . $ext;
    $dest = $upload_dir . '/' . $stored_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception("Failed to move uploaded file to destination.");
    }

    $mime = mime_content_type($dest);
    $stmt = $conn->prepare("
        INSERT INTO documents (lrn, document_type, original_filename, stored_filename, file_size, mime_type, extracted_text, notes, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssisssi", $lrn, $document_type, $file['name'], $stored_name, $file['size'], $mime, $extracted, $notes, $uploaded_by);
    $stmt->execute();
    return (int)$stmt->insert_id;
}

function sned_h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sned_person_name(array $row, string $first_key = 'first_name', string $middle_key = 'middle_name', string $last_key = 'last_name', string $ext_key = 'extension_name', string $format = 'legal'): string {
    $first = trim((string)($row[$first_key] ?? ''));
    $middle = trim((string)($row[$middle_key] ?? ''));
    $last = trim((string)($row[$last_key] ?? ''));
    $ext = trim((string)($row[$ext_key] ?? ''));
    $middle_text = $middle !== '' ? ' ' . $middle : '';
    $ext_text = $ext !== '' ? ' ' . $ext : '';

    if ($format === 'display') {
        return trim($first . $middle_text . ' ' . $last . $ext_text);
    }

    if ($last !== '' && $first !== '') {
        return trim($last . ', ' . $first . $middle_text . $ext_text);
    }

    return trim($first . $middle_text . ' ' . $last . $ext_text);
}

function sned_learner_name(array $learner, string $format = 'legal'): string {
    return sned_person_name($learner, 'first_name', 'middle_name', 'last_name', 'extension_name', $format);
}

function sned_guardian_name(array $row, string $format = 'legal'): string {
    if (!empty($row['primary_contact_name'])) {
        return trim((string)$row['primary_contact_name']);
    }

    $first_key = array_key_exists('guardian_first_name', $row) ? 'guardian_first_name' : 'g_first';
    $last_key = array_key_exists('guardian_last_name', $row) ? 'guardian_last_name' : 'g_last';
    $middle_key = array_key_exists('guardian_middle_name', $row) ? 'guardian_middle_name' : 'g_middle';
    $ext_key = array_key_exists('guardian_extension_name', $row) ? 'guardian_extension_name' : 'g_ext';

    return sned_person_name($row, $first_key, $middle_key, $last_key, $ext_key, $format);
}

function sned_guardian_contact(array $row): string {
    return trim((string)($row['primary_contact_number'] ?? $row['guardian_contact_number'] ?? $row['g_contact'] ?? ''));
}

function sned_guardian_address(array $row): string {
    if (!empty($row['primary_contact_address'])) {
        return trim((string)$row['primary_contact_address']);
    }

    return trim(implode(', ', array_filter([
        trim((string)($row['guardian_house_no_street'] ?? $row['g_house'] ?? $row['house_no_street'] ?? '')),
        trim((string)($row['guardian_barangay'] ?? $row['g_barangay'] ?? $row['barangay'] ?? '')),
    ])));
}

function sned_get_primary_guardian(mysqli $conn, string $lrn): array {
    if (!preg_match('/^\d{12}$/', $lrn)) {
        return [];
    }

    $stmt = $conn->prepare("SELECT lg.* FROM learner_guardians lg WHERE lg.lrn = ? AND lg.is_primary_contact = 1 ORDER BY lg.updated_at DESC, lg.id DESC LIMIT 1");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return $row;
    }

    $stmt = $conn->prepare("\n        SELECT g.*\n        FROM learners l\n        JOIN guardians g ON g.id = l.guardian_id\n        WHERE l.lrn = ?\n        LIMIT 1\n    ");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function sned_first_non_empty(...$values): string {
    foreach ($values as $value) {
        $value = trim((string)($value ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function sned_prefill_value($posted_value, ...$fallbacks): string {
    return sned_first_non_empty($posted_value, ...$fallbacks);
}

function sned_bir_quarter_options(): array {
    return [
        'Q1' => '1st Quarter',
        'Q2' => '2nd Quarter',
        'Q3' => '3rd Quarter',
        'Q4' => '4th Quarter',
    ];
}

function sned_bir_quarter_label(?string $quarter): string {
    $quarter = trim((string)$quarter);
    $options = sned_bir_quarter_options();

    return $options[$quarter] ?? $quarter;
}

function sned_bir_quarter_from_date(?string $date): string {
    if (empty($date)) {
        return '';
    }

    $month = (int)date('n', strtotime($date));
    if ($month >= 6 && $month <= 8) {
        return 'Q1';
    }
    if ($month >= 9 && $month <= 11) {
        return 'Q2';
    }
    if ($month === 12 || $month <= 2) {
        return 'Q3';
    }

    return 'Q4';
}

function sned_latest_assessment_prefill(mysqli $conn, string $lrn): array {
    $stmt = $conn->prepare("
        SELECT a.assessment_type, a.date_administered AS assessment_date, a.administrator, a.results
        FROM learner_ilp_assessments a
        INNER JOIN learner_ilp ilp ON ilp.id = a.ilp_id
        WHERE ilp.learner_id = ?
        ORDER BY a.date_administered DESC, a.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $lrn);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc() ?: [];
}

function sned_is_non_graded(array $learner): bool {
    return ($learner['placement_type'] ?? '') === 'Non-Graded';
}

function sned_block_unless_non_graded(array $learner, string $document_name): void {
    if (sned_is_non_graded($learner)) {
        return;
    }

    http_response_code(403);
    die($document_name . ' is only available for learners with Non-Graded placement.');
}

function school_year_select($name = 'school_year', $selected = null, $id = null) {
    $id_attr = $id ? ' id="' . htmlspecialchars($id) . '"' : '';
    $html = '<select name="' . htmlspecialchars($name) . '"' . $id_attr . ' class="form-select rounded-3" required>' . school_year_options($selected) . '</select>';
    return $html;
}

function sf9_rating_options(): array {
    return [
        '' => '',
        'P' => 'P',
        'AP' => 'AP',
        'D' => 'D',
        'B' => 'B',
        'NO' => 'NO',
        'NA' => 'NA',
    ];
}

function sf9_months(): array {
    return [
        1 => 'June',
        2 => 'July',
        3 => 'Aug',
        4 => 'Sept',
        5 => 'Oct',
        6 => 'Nov',
        7 => 'Dec',
        8 => 'Jan',
        9 => 'Feb',
        10 => 'Mar',
        11 => 'Apr',
        12 => 'Total',
    ];
}

function sf9_domain_order(): array {
    return [
        'Daily Living Skills',
        'Socio-Emotional',
        'Language',
        'Psychomotor',
        'Cognitive',
        'Aesthetic & Creative',
        'Behavior',
        'Orientation & Mobility',
    ];
}

function sf9_domain_aliases(): array {
    return [
        'daily living skills' => 'Daily Living Skills',
        'daily living skills domain' => 'Daily Living Skills',
        'socio-emotional' => 'Socio-Emotional',
        'socio emotional' => 'Socio-Emotional',
        'socio-emotional domain' => 'Socio-Emotional',
        'language' => 'Language',
        'language development' => 'Language',
        'language development domain' => 'Language',
        'psychomotor' => 'Psychomotor',
        'psychomotor domain' => 'Psychomotor',
        'cognitive' => 'Cognitive',
        'cognitive domain' => 'Cognitive',
        'aesthetic & creative' => 'Aesthetic & Creative',
        'aesthetic and creative' => 'Aesthetic & Creative',
        'aesthetic and creative domain' => 'Aesthetic & Creative',
        'behavior' => 'Behavior',
        'behavioral development' => 'Behavior',
        'behavioral development domain' => 'Behavior',
        'orientation & mobility' => 'Orientation & Mobility',
        'orientation and mobility' => 'Orientation & Mobility',
        'orientation and mobility domain' => 'Orientation & Mobility',
    ];
}

function sf9_normalize_domain(?string $domain): string {
    $normalized = strtolower(trim((string)$domain));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $aliases = sf9_domain_aliases();
    return $aliases[$normalized] ?? trim((string)$domain);
}

function sf9_master_indicators(): array {
    return [
        'Daily Living Skills' => [
            'SELF FEEDING' => [
                'Expresses need to eat or drink through non-verbal and/or verbal means',
                'Chews and swallows different kinds of foods',
                'Swallows liquid like soup',
                'Picks up food with fingers or scoops with spoon',
                'Picks up and eats finger food',
                'Sips and drinks liquid',
                'Eats with spoon and fork',
                'Uses the table knife for spreading',
                'Cuts food using the table knife',
                'Distinguishes edible and non-edible foods and substances',
                'Peels/unwraps food',
            ],
            'TOILETING' => [
                'Uses comfort room/toilet bowl to urinate or defecate',
                'Uses the toilet paper to clean up self and disposes it properly',
                'Uses diaper correctly',
                'Cleans self with soap and water after toileting',
            ],
            'DRESSING' => [
                'Removes / puts on shoes or slippers',
                'Removes / puts on socks',
                'Removes / puts on clothes',
                'Opens and closes dressing implements (zippers, buttons, snaps, buttonholes)',
            ],
            'GROOMING AND HYGIENE' => [
                'Washes and dries hands properly',
                'Cleans own face',
                'Brushes teeth',
                'Combs/brushes hair',
            ],
        ],
        'Socio-Emotional' => [
            '' => [
                'Uses courteous expressions appropriately',
                'Asks an apology when necessary',
                'Pays attention to someone talking',
                'Engages in communication with others',
                'Plays with peers',
                'Makes friends easy',
                'Follows rules and regulations',
                'Seeks/accepts help',
                'Expresses/shows appropriate emotions',
                'Waits for one\'s turn',
                'Asks permission to use things owned by others',
                'Seeks help from older friends',
                'Imitates adult activities',
                'Displays sense of humor',
                'Identifies self as a member of a family/cultural group',
                'Identifies personal belongings',
                'Displays sensitivity to the feelings of others',
                'Shows sportsmanship',
                'Shows initiatives to work on tasks',
                'Works independently',
                'Shows self-control',
            ],
        ],
        'Language' => [
            'LISTENING' => [
                'Follows simple directions',
                'Distinguishes different types of sounds',
                'Comprehends simple and familiar stories',
                'Listens attentively to stories, poems/rhymes',
            ],
            'SPEAKING' => [
                'Increases vocabulary to describe things',
                'Increases vocabulary to express one\'s feelings',
                'Increases vocabulary to share information',
                'Answers and responds to questions accordingly',
                'Narrates simple and familiar stories',
            ],
            'READING' => [
                'Discriminates similarities and differences between pictures and objects',
                'Classifies objects according to function',
                'Notes details in pictures',
                'Visualizes objects and pictures from memory',
                'Comprehends picture stories',
                'Performs relevant study skills',
            ],
            'WRITING' => [
                'Holds/grips pencil properly',
                'Traces lines and shapes',
                'Traces letters, numbers, and one\'s name properly',
                'Copies lines, shapes, letters, numbers, and one\'s name properly',
                'Draws basic figures',
                'Uses basic strokes correctly',
            ],
        ],
        'Psychomotor' => [
            'BASIC MOVEMENT' => [
                'Sits, stands and walks with good posture',
                'Runs and jogs gradually in increasing distance',
                'Jumps and performs other exercises with or without music',
                'Lifts increasing heavy weights',
                'Balances with one foot for gradually increasing period of time',
                'Imitates motor movements of people and animals',
                'Bends and straightens knees properly while feet flat on the floor',
                'Goes up and down the stairs',
            ],
            'PERCEPTUAL MOTOR SKILLS' => [
                'Uses clay to make simple but increasingly meaningful shapes and objects',
                'Uses crayon to color',
            ],
            'GROSS MOTOR COMPETENCIES' => [
                'Walks while carrying objects',
                'Jumps toward aim without falling',
                'Throws and catches objects',
                'Kicks ball without losing balance',
                'Hops alternately without falling',
            ],
            'FINE MOTOR SKILLS' => [
                'Makes an object out of clay',
                'Squeezes soft rubber ball of convenient size',
                'Squeezes water from wet rag',
                'Folds, divides and tears paper into halves/pieces',
                'Cuts out shapes, outline and objects',
                'Pastes paper properly',
                'Strings and threads beads',
                'Turns doorknob with forearm rotation',
                'Removes bottle cap',
            ],
        ],
        'Cognitive' => [
            '' => [
                'Identifies colors',
                'Identifies shapes',
                'Identifies letters of the alphabet',
                'Identifies the sounds of the letters of the alphabet',
                'Identifies sizes (long, short, big, small, tall, short)',
                'Sorts objects according to color',
                'Sorts objects according to size',
                'Sorts objects according to shape',
                'Tells the size of an object',
                'Identifies numbers up to ___',
                'Counts numbers up to ___',
                'Follows one to three steps direction',
                'Identifies the body parts',
                'Identifies the five senses and their functions',
                'Sequences/Arranges pictures in the story',
                'Identifies/reads simple words with pictures',
                'Identifies/reads simple phrases with pictures',
                'Identifies/reads simple sentences with pictures',
                'Identifies the days of the week',
                'Identifies the months of the year',
                'Identifies one self',
                'Identifies members of the family',
                'Identifies different community helpers in school and/or in the community',
                'Identifies objects/pictures being presented',
            ],
        ],
        'Aesthetic & Creative' => [
            '' => [
                'Uses the five senses to observe things in the classroom',
                'Enumerates and classifies things in terms of color, shape, texture and number',
                'Points to drawings or paintings that they like or appreciate',
                'Identifies basic drawing materials',
                'Draws and paints simple objects based on a visual model',
                'Displays his drawings and paintings on display board',
                'Listens to musical pieces familiar to the child',
                'Sings, with/without prompts, the musical pieces listened to',
                'Sees and touches musical instruments found in school',
                'Names musical instruments found in school',
                'Watches how these musical instruments are used',
                'Plays with the musical instruments with teacher\'s prompts',
                'Expresses appreciation for the dance that they prefer',
                'Expresses appreciation for what was heard through body movements',
                'Moves one\'s body with the variety of rhythms heard',
                'Demonstrates feelings through facial expressions',
                'Dances with/without music with the teacher',
                'Watches a dramatic play from media sources (television, internet)',
            ],
        ],
        'Behavior' => [
            '' => [
                'Uses appropriate verbal communication for social interaction',
                'Learns how to speak at a lower tone',
                'Familiarizes with and takes a rerouted direction',
                'Follows "Quieting Down" instruction',
                'Performs simple tasks (e.g. throwing trash in the garbage, etc.)',
                'Puts back materials used like pencil in its proper place',
                'Accepts consequences of behavior',
                'Follows teacher\'s command/instruction',
                'Participates well in the lesson executed by the teacher',
                'Responds to questions, activities given to him/her',
                'Attends to task without getting out from the chair',
                'Watches/listens to videos/music for 5 minutes or more',
                'Responds positively to behavior management procedures',
                'Reduces/eliminates inappropriate and aggressive behaviors during the session',
                'Reduces/eliminates tantrums during the session',
                'Plays with other children',
                'Takes turn in game activity',
                'Takes turn in games/knows how to wait when playing time',
                'Shares things/object without teachers\' prompt',
                'Sits for 30 minutes to one hour',
                'Develops longer attention span to complete the task',
                'Completes task on time',
            ],
        ],
        'Orientation & Mobility' => [
            '' => [
                'Tells the different body parts',
                'Points/names body parts on the right/left side',
                'Tells the spatial location of an object/person/place',
                'Follows directions given to find objects',
                'Uses the position of common objects in reference to one\'s self',
                'Performs bilateral arm and leg movements simultaneously with coordination',
                'Shows the body with balance and rhythm',
                'Identifies landmarks as clues',
                'Protects one\'s self from vertical and shoulder height obstacles using the upper hand and forearm technique',
                'Uses parallel walls as guide',
                'Contacts with shorelines in the environment',
                'Locates dropped objects in the environment using different search patterns',
                'Familiarizes with cardinal direction as North, South, East, West in indoor travels',
                'Utilizes appropriate travel techniques in travelling in different locations',
                'Acquaints oneself with sighted guide',
                'Follows signals when to go up and down the stairs',
                'Follows directly behind sighted guide without changing the position of the hands',
                'Enters and exits safely when the door and the sighted guide are on the opposite side of the pupil',
                'Acquaints oneself with a cane',
                'Uses different cane techniques in travelling',
                'Travels in areas without curbs/sidewalks',
            ],
        ],
    ];
}

function sf9_group_ratings(array $ratings): array {
    $grouped = [];
    foreach ($ratings as $rating) {
        $domain = sf9_normalize_domain($rating['domain'] ?? '');
        $subdomain = trim((string)($rating['subdomain'] ?? ''));
        $grouped[$domain][$subdomain][] = $rating;
    }

    $ordered = [];
    foreach (sf9_domain_order() as $domain) {
        if (isset($grouped[$domain])) {
            $ordered[$domain] = $grouped[$domain];
            unset($grouped[$domain]);
        }
    }

    foreach ($grouped as $domain => $subdomains) {
        $ordered[$domain] = $subdomains;
    }

    return $ordered;
}

function ensure_sf9_indicator_ratings(mysqli $conn, string $lrn, ?int $enrollment_id, string $school_year): void {
    $existing = [];
    $stmt_existing = $conn->prepare("
        SELECT domain, subdomain, indicator
        FROM learner_indicator_ratings
        WHERE learner_id = ? AND school_year = ?
    ");
    $stmt_existing->bind_param("ss", $lrn, $school_year);
    $stmt_existing->execute();
    $res_existing = $stmt_existing->get_result();
    while ($row = $res_existing->fetch_assoc()) {
        $key = sf9_rating_key($row['domain'] ?? '', $row['subdomain'] ?? '', $row['indicator'] ?? '');
        $existing[$key] = true;
    }

    $stmt_insert = $conn->prepare("
        INSERT INTO learner_indicator_ratings (learner_id, enrollment_id, school_year, domain, subdomain, indicator, q1, q2, q3, q4)
        VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)
    ");

    foreach (sf9_master_indicators() as $domain => $subdomains) {
        foreach ($subdomains as $subdomain => $indicators) {
            foreach ($indicators as $indicator) {
                $key = sf9_rating_key($domain, $subdomain, $indicator);
                if (isset($existing[$key])) {
                    continue;
                }
                $sub_val = ($subdomain === '') ? null : $subdomain;
                $stmt_insert->bind_param("sissss", $lrn, $enrollment_id, $school_year, $domain, $sub_val, $indicator);
                $stmt_insert->execute();
                $existing[$key] = true;
            }
        }
    }
}

function sf9_rating_key(?string $domain, ?string $subdomain, ?string $indicator): string {
    return strtolower(trim((string)$domain)) . '|' . strtolower(trim((string)$subdomain)) . '|' . strtolower(trim((string)$indicator));
}

function sned_previous_school_year(?string $current_sy): ?string {
    if ($current_sy === null || $current_sy === '') return null;
    if (!preg_match('/^(\d{4})-(\d{4})$/', $current_sy, $m)) return null;
    $prev_start = (int)$m[1] - 1;
    $prev_end = (int)$m[2] - 1;
    if ($prev_start < 1900) return null;
    return $prev_start . '-' . $prev_end;
}

/**
 * Loads all assessment-related data for a learner.
 * Used by assessment_form.php and assessmentPrint.php
 *
 * @param mysqli $conn
 * @param string $lrn
 * @return array
 */
function assessment_load_data(mysqli $conn, string $lrn): array {
    // 1. Fetch Learner info
    $stmt_learner = $conn->prepare("
        SELECT l.*, s.school_name, e.school_year, e.grade_level
        FROM learners l
        LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
        LEFT JOIN schools s ON e.school_id = s.id
        WHERE l.lrn = ? AND l.is_deleted = 0
        ORDER BY e.school_year DESC LIMIT 1
    ");
    $stmt_learner->bind_param("s", $lrn);
    $stmt_learner->execute();
    $learner = $stmt_learner->get_result()->fetch_assoc();
    $stmt_learner->close();

    if (!$learner) {
        return [
            'learner' => null,
            'grouped_items' => [],
            'responses' => [],
            'dates' => null,
            'area_totals' => [],
            'area_rated' => []
        ];
    }

    // Fetch all assessment items
    $res_items = $conn->query("SELECT * FROM `assessment_checklist_items` ORDER BY sort_order ASC");
    $items = [];
    while ($row = $res_items->fetch_assoc()) {
        $items[] = $row;
    }

    // Group items by learning area, then subsection_label
    $grouped_items = [];
    $area_totals = [];
    foreach ($items as $item) {
        $area = $item['learning_area'];
        $sub = $item['subsection_label'];
        if (!isset($grouped_items[$area])) {
            $grouped_items[$area] = [];
            $area_totals[$area] = 0;
        }
        if (!isset($grouped_items[$area][$sub])) {
            $grouped_items[$area][$sub] = [];
        }
        $grouped_items[$area][$sub][] = $item;
        $area_totals[$area]++;
    }

    // Fetch existing responses
    $stmt_resp = $conn->prepare("SELECT * FROM `learner_assessment_checklist` WHERE `learner_id` = ?");
    $stmt_resp->bind_param("s", $lrn);
    $stmt_resp->execute();
    $res_resp = $stmt_resp->get_result();
    $responses = [];
    $area_rated = [];
    foreach ($grouped_items as $area => $subs) {
        $area_rated[$area] = 0;
    }
    while ($row = $res_resp->fetch_assoc()) {
        $responses[$row['item_id']] = $row;
    }
    $stmt_resp->close();

    // Fetch overall assessment dates
    $stmt_dates = $conn->prepare("SELECT * FROM `learner_assessment_dates` WHERE `learner_id` = ?");
    $stmt_dates->bind_param("s", $lrn);
    $stmt_dates->execute();
    $dates = $stmt_dates->get_result()->fetch_assoc();
    $stmt_dates->close();

    // Count rated items per area
    foreach ($items as $item) {
        if (isset($responses[$item['id']])) {
            $has_pre = $responses[$item['id']]['pre_rating'] !== null;
            $has_post = $responses[$item['id']]['post_rating'] !== null;
            if ($has_pre || $has_post) {
                $area_rated[$item['learning_area']]++;
            }
        }
    }

    return [
        'learner' => $learner,
        'grouped_items' => $grouped_items,
        'responses' => $responses,
        'dates' => $dates,
        'area_totals' => $area_totals,
        'area_rated' => $area_rated
    ];
}

