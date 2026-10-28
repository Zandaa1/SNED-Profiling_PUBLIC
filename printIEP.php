<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['learner_id'] ?? '';
if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner LRN.");
}

$user_role = $_SESSION['role'] ?? 'user';
$session_school_id = $_SESSION['school_id'] ?? 0;

$stmt = $conn->prepare("
    SELECT l.*, e.school_id, e.grade_level, e.placement_type, e.id AS enrollment_id, e.school_year, e.primary_diagnosis,
           s.school_name, s.address AS school_address, s.district
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

$school_year = trim($_GET['sy'] ?? '');
if ($school_year === '') {
    $school_year = trim($_GET['school_year'] ?? '');
}
if ($school_year !== '') {
    $stmt_iep = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? AND school_year = ? ORDER BY id DESC LIMIT 1");
    $stmt_iep->bind_param("ss", $lrn, $school_year);
} else {
    $stmt_iep = $conn->prepare("SELECT * FROM learner_iep_annex6 WHERE learner_id = ? ORDER BY school_year DESC, id DESC LIMIT 1");
    $stmt_iep->bind_param("s", $lrn);
}
$stmt_iep->execute();
$iep = $stmt_iep->get_result()->fetch_assoc();

if (!$iep) {
    die("No IEP on file for LRN: " . htmlspecialchars($lrn));
}

if (!empty($iep['enrollment_id'])) {
    $stmt_iep_enrollment = $conn->prepare("
        SELECT e.id AS enrollment_id, e.school_id, e.grade_level, e.placement_type, e.school_year, e.primary_diagnosis,
               s.school_name, s.address AS school_address, s.district
        FROM enrollments e
        LEFT JOIN schools s ON e.school_id = s.id
        WHERE e.id = ? AND e.lrn = ? AND e.is_deleted = 0
        LIMIT 1
    ");
    $iep_enrollment_id = (int)$iep['enrollment_id'];
    $stmt_iep_enrollment->bind_param("is", $iep_enrollment_id, $lrn);
    $stmt_iep_enrollment->execute();
    $iep_enrollment = $stmt_iep_enrollment->get_result()->fetch_assoc();
    if ($iep_enrollment) {
        foreach ($iep_enrollment as $key => $value) {
            $learner[$key] = $value;
        }
    }
}

if (user_is_scoped($user_role) && $learner['school_id'] != $session_school_id && !check_teacher_learner_access($conn, $lrn)) {
    header("Location: 403.php");
    exit();
}

$learner_disabilities = sned_get_enrollment_disabilities($conn, isset($learner['enrollment_id']) ? (int)$learner['enrollment_id'] : null);
if (empty($learner_disabilities) && !empty($learner['primary_diagnosis'])) {
    $learner_disabilities = sned_disability_rows_from_legacy_diagnosis($learner['primary_diagnosis']);
}
$learner_disability_summary = sned_disability_summary($learner_disabilities, $learner['primary_diagnosis'] ?: 'Unassigned');

$stmt_rows = $conn->prepare("SELECT * FROM learner_iep_annex6_rows WHERE iep_id = ? ORDER BY section_type ASC, sort_order ASC, id ASC");
$stmt_rows->bind_param("i", $iep['id']);
$stmt_rows->execute();
$rows = $stmt_rows->get_result()->fetch_all(MYSQLI_ASSOC);

$barriers = [];
$goals = [];
$transitions = [];
foreach ($rows as $row) {
    if ($row['section_type'] === 'barrier') {
        $barriers[] = $row;
    } elseif ($row['section_type'] === 'goal') {
        $goals[] = $row;
    } elseif ($row['section_type'] === 'transition') {
        $transitions[] = $row;
    }
}

$team_members = !empty($iep['iep_team_json']) ? (json_decode($iep['iep_team_json'], true) ?: []) : [];
$sf_json = !empty($iep['special_factors_json']) ? (json_decode($iep['special_factors_json'], true) ?: []) : [];
$age = !empty($learner['birthday']) ? (new DateTime($learner['birthday']))->diff(new DateTime())->y : '';

$extra_value = static function (array $data, string $key): string {
    return trim((string)($data[$key] ?? ''));
};

$difficulty_labels = [
    'diff_seeing' => 'Difficulty in Seeing',
    'diff_hearing' => 'Difficulty in Hearing',
    'diff_communicating' => 'Difficulty in Communicating',
    'diff_mobility' => 'Difficulty in Mobility/Walking',
    'diff_interpersonal' => 'Difficulty in Displaying Interpersonal Behaviors',
    'diff_adaptive' => 'Difficulty in Performing Adaptive Skills',
    'diff_basic_skills' => 'Difficulty in Basic Skills and Applying Knowledge',
    'diff_remembering' => 'Difficulty in Remembering/Concentrating',
];

$transition_labels = [
    'transition_home_to_school' => 'Home to school',
    'transition_school_to_life' => 'School to functional life',
    'transition_sped_to_inclusion' => 'SPED class to inclusion',
    'transition_grade_to_grade' => 'Grade to grade',
    'transition_school_to_work' => 'School to work',
    'transition_class_to_class' => 'Class to class',
];

$special_factor_labels = [
    'sf_behavior' => 'Learner has difficulty relating with people',
    'sf_mobility' => 'Learner has difficulty in moving/walking',
    'sf_vision' => 'Learner has difficulty in seeing or with blindness/visual impairment',
    'sf_communication' => 'Learner has difficulty in communicating',
    'sf_attention' => 'Learner has difficulty in concentrating/paying attention',
    'sf_memory' => 'Learner has difficulty in remembering/understanding',
    'sf_hearing' => 'Learner has difficulty in hearing or is deaf/hard of hearing',
    'sf_assistive_tech' => 'Learner needs assistive technology devices or services',
    'sf_alt_format' => 'Learner requires alternative format for instructional materials',
];

log_transaction($conn, $_SESSION['user_id'], 'print', 'learner_iep_annex6', $lrn, "Printed IEP form for LRN: $lrn (SY: " . ($iep['school_year'] ?? '') . ")");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IEP Print Report - LRN <?= htmlspecialchars($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page { size: A4 landscape; margin: 0; }
        body { background: #525659; color: #000; font: 9pt Arial, sans-serif; padding: 20px 0; }
        .page { width: 297mm; margin: 0 auto; background: #fff; padding: 10mm 12mm; box-shadow: 0 0 15px rgba(0,0,0,.45); }
        .header-title { color: #000; font-size: 11pt; font-weight: 800; text-align: center; text-transform: uppercase; }
        .section-header { color: #000; border: 1px solid #000; background: #d9d9d9; font-size: 9pt; font-weight: 700; margin: 6px 0 3px; padding: 2px 4px; text-transform: uppercase; page-break-after: avoid; break-after: avoid; }
        .table-custom { width: 100%; border-collapse: collapse; margin-bottom: 6px; page-break-inside: auto; }
        .table-custom th, .table-custom td { border: 1px solid #000; padding: 3px 5px; vertical-align: top; font-size: 8.5pt; }
        .table-custom th { background: #d9d9d9; color: #000; }
        .muted { color: #555; }
        .signature-row { display: flex; gap: 18px; margin-top: 28px; page-break-inside: avoid; break-inside: avoid; }
        .signature-col { flex: 1; text-align: center; }
        .signature-line { border-top: 1px solid #111827; padding-top: 4px; font-weight: 700; }
        
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .text-primary {
            color: #000 !important;
        }

        @media print {
            body { background: #fff; margin: 0; padding: 0; }
            .page { box-shadow: none; margin: 0 auto; padding: 10mm 12mm; width: 297mm; background: #fff; }
            .no-print { display: none !important; }
            
            /* Handle page breaks gracefully */
            tr { page-break-inside: avoid; break-inside: avoid; }
            thead { display: table-header-group; }
            
            .section-header { page-break-after: avoid; break-after: avoid; }
            h1, h2, h3, h4, h5, h6 { page-break-after: avoid; break-after: avoid; }
        }
    </style>
    <?php print_a4_styles('landscape'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Document'); ?>

    <main class="page a4-flow a4-landscape">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
            <img src="img/deped-logo.webp" style="height: 50px; object-fit: contain;" alt="DepEd Logo">
            <div class="text-center flex-grow-1 mx-3" style="line-height: 1.2;">
                <div style="font-size: 8pt; text-transform: uppercase;">Republic of the Philippines</div>
                <div style="font-size: 9.5pt; font-weight: 700; text-transform: uppercase;">Department of Education</div>
                <div style="font-size: 8pt; text-transform: uppercase;">National Capital Region</div>
                <div style="font-size: 8.5pt; font-weight: 600;">Schools Division Office of Valenzuela City</div>
                <div style="font-size: 8pt; text-transform: uppercase; font-style: italic;"><?= htmlspecialchars($learner['district'] ?? 'Central District') ?></div>
                <div style="font-size: 8.5pt; font-weight: 700; text-transform: uppercase;"><?= htmlspecialchars($learner['school_name'] ?? '') ?></div>
                <div class="header-title" style="font-size: 11pt; font-weight: 800; text-transform: uppercase; margin-top: 2px;">Individualized Education Plan (IEP)</div>
                <div class="muted small" style="font-size: 8pt;">School Year <?= htmlspecialchars($iep['school_year'] ?? '') ?></div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 50px; object-fit: contain;" alt="Valenzuela Logo">
        </div>

        <?php
        $render_checkbox = static function(bool $checked, string $label): string {
            return ($checked ? '<strong>[✓]</strong> ' : '[ ] ') . htmlspecialchars($label);
        };
        $purpose = $iep['purpose_of_meeting'] ?? '';
        ?>
        <div class="section-header">SECTION A: PERSONAL INFORMATION</div>
        <table class="table-custom" style="width: 100%; margin-bottom: 8px;">
            <tbody>
                <tr>
                    <!-- Column 1: Learner & Parent Info -->
                    <td style="width: 40%; padding: 6px; line-height: 1.4; font-size: 8pt;">
                        <strong>Learner Name:</strong> <?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . ($learner['middle_name'] ?? '')) ?><br>
                        <strong>LRN:</strong> <?= htmlspecialchars($lrn) ?><br>
                        <strong>Birthdate:</strong> <?= htmlspecialchars($learner['birthday'] ?? '') ?> (Age: <?= htmlspecialchars((string)$age) ?>)<br>
                        <strong>Sex:</strong> <?= htmlspecialchars($learner['sex'] ?? '') ?> &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Grade:</strong> <?= htmlspecialchars($learner['grade_level'] ?? '') ?><br>
                        <strong>Mother Tongue:</strong> <?= htmlspecialchars($extra_value($sf_json, 'mother_tongue') ?: '—') ?><br>
                        <strong>Home Address:</strong> <?= htmlspecialchars($extra_value($sf_json, 'home_address') ?: '—') ?><br>
                        <strong>Learner Phone:</strong> <?= htmlspecialchars($extra_value($sf_json, 'learner_phone') ?: '—') ?><br>
                        <strong>Medical Diagnosis:</strong> <?= !empty($iep['medical_diagnosis']) ? 'Yes' : 'No' ?>
                        <?php if (!empty($iep['medical_diagnosis_specify'])): ?>
                            - <?= htmlspecialchars($iep['medical_diagnosis_specify']) ?>
                        <?php endif; ?><br>
                        <strong>Interpreter/Accommodations:</strong> <?= htmlspecialchars($iep['interpreter_needed'] ?? '—') ?><br>
                        <hr style="margin: 6px 0; border-top: 1px solid #ccc;">
                        <strong>Parent/Guardian:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_name') ?: '—') ?><br>
                        <strong>Mobile:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_mobile') ?: '—') ?> &nbsp;&nbsp;|&nbsp;&nbsp; <strong>Landline:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_landline') ?: '—') ?><br>
                        <strong>Email:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_email') ?: '—') ?><br>
                        <strong>Workplace:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_workplace') ?: '—') ?><br>
                        <strong>Mother Tongue:</strong> <?= htmlspecialchars($extra_value($sf_json, 'guardian_mother_tongue') ?: '—') ?>
                    </td>
                    
                    <!-- Column 2: Areas of Difficulty Checklist -->
                    <td style="width: 30%; padding: 6px; line-height: 1.4; font-size: 8pt;">
                        <div class="fw-bold mb-2" style="border-bottom: 1px solid #000; padding-bottom: 1px; font-size: 8.5pt;">Areas of Difficulty Checklist</div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_seeing']), 'Difficulty in Seeing') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_hearing']), 'Difficulty in Hearing') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_communicating']), 'Difficulty in Communicating') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_mobility']), 'Difficulty in Mobility/Walking') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_interpersonal']), 'Difficulty in Displaying Interpersonal Behaviors') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_adaptive']), 'Difficulty in Performing Adaptive Skills') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_basic_skills']), 'Difficulty in Basic Skills and Applying Knowledge') ?></div>
                        <div class="mb-1"><?= $render_checkbox(!empty($iep['diff_remembering']), 'Difficulty in Remembering/Concentrating') ?></div>
                        <?php if (!empty($iep['diff_others'])): ?>
                            <div class="mt-2" style="border-top: 1px dashed #ccc; padding-top: 4px;"><strong>Others (specify):</strong><br><?= htmlspecialchars($iep['diff_others']) ?></div>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Column 3: Meeting Information -->
                    <td style="width: 30%; padding: 6px; line-height: 1.4; font-size: 8pt;">
                        <div class="fw-bold mb-2" style="border-bottom: 1px solid #000; padding-bottom: 1px; font-size: 8.5pt;">Meeting & Purpose Info</div>
                        <strong>Date of Meeting:</strong> <?= htmlspecialchars($iep['meeting_date'] ?? '—') ?><br>
                        <strong>Date of Last IEP:</strong> <?= htmlspecialchars($iep['date_of_last_iep'] ?? '—') ?><br>
                        <strong>IEP Review Date:</strong> <?= htmlspecialchars($iep['iep_review_date'] ?? '—') ?><br>
                        <div class="fw-bold mt-2" style="font-size: 7.5pt; border-bottom: 1px dashed #ccc; padding-bottom: 2px;">Purpose of Meeting:</div>
                        <div style="font-size: 7.5pt; margin-top: 3px;">
                            <div class="mb-1"><?= $render_checkbox($purpose === 'Initial IEP', 'Initial IEP') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'Interim IEP', 'Interim IEP') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'Term IEP', 'Term IEP') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'IEP Following 3-Year Reevaluation', 'IEP Following 3-Year Reevaluation') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'Revision to IEP', 'Revision to IEP') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'Exit/Graduation', 'Exit/Graduation') ?></div>
                            <div class="mb-1"><?= $render_checkbox($purpose === 'IEP Revision Without a Meeting', 'IEP Revision Without Meeting') ?></div>
                        </div>
                        <?php if (!empty($iep['meeting_comments'])): ?>
                            <div class="mt-2" style="font-size: 7.5pt; line-height: 1.2; border-top: 1px dashed #ccc; padding-top: 4px;"><strong>Comments:</strong> <?= htmlspecialchars($iep['meeting_comments']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="section-header">SECTION I: PRESENT LEVELS OF ACADEMIC ACHIEVEMENT AND FUNCTIONAL PERFORMANCE</div>
        <table class="table-custom">
            <tbody>
                <?php
                $render_narrative = static function(?string $val): string {
                    $trimmed = trim((string)$val);
                    return $trimmed !== '' ? nl2br(htmlspecialchars($trimmed)) : '<em class="text-muted">Not yet assessed</em>';
                };
                ?>
                <tr><th style="width: 30%;">Evaluation Results</th><td><?= $render_narrative($iep['present_eval_results'] ?? null) ?></td></tr>
                <tr><th>Academic Strengths</th><td><?= $render_narrative($iep['academic_strengths'] ?? null) ?></td></tr>
                <tr><th>Academic Needs</th><td><?= $render_narrative($iep['academic_needs'] ?? null) ?></td></tr>
                <tr><th>Parent Concerns</th><td><?= $render_narrative($iep['parental_concerns'] ?? null) ?></td></tr>
                <tr><th>Disability Impact</th><td><?= $render_narrative($iep['disability_impact'] ?? null) ?></td></tr>
            </tbody>
        </table>

        <div class="section-header">SECTION II: CONSIDERATION OF SPECIAL FACTORS</div>
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 45%;">Factor / Special Consideration Question</th>
                    <th style="width: 55%;">Response & Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($special_factor_labels as $key => $label): ?>
                    <?php
                    $factor = is_array($sf_json[$key] ?? null) ? $sf_json[$key] : [];
                    $is_yes = (($factor['yesno'] ?? '0') === '1');
                    $notes = trim((string)($factor['notes'] ?? ''));
                    $details = [];
                    if ($key === 'sf_alt_format') {
                        foreach ([
                            'braille' => 'Braille',
                            'large_type' => 'Large type',
                            'auditory' => 'Auditory',
                            'electronic' => 'Electronic text',
                        ] as $option_key => $option_label) {
                            if (!empty($factor[$option_key])) {
                                $details[] = $option_label;
                            }
                        }
                    }
                    ?>
                    <tr>
                        <th><?= htmlspecialchars($label) ?></th>
                        <td>
                            <strong><?= $is_yes ? 'Yes' : 'No' ?></strong>
                            <?= $notes !== '' ? '<br>' . nl2br(htmlspecialchars($notes)) : '' ?>
                            <?= !empty($details) ? '<br><span class="muted">' . htmlspecialchars(implode(', ', $details)) . '</span>' : '' ?>
                            
                            <?php if ($key === 'sf_behavior' && $is_yes): ?>
                                <div class="mt-2" style="border-top: 1px dashed #ccc; padding-top: 4px; font-size: 8pt;">
                                    Behavior Intervention Plan developed? &nbsp;&nbsp;
                                    <strong>[<?= (isset($factor['bip']) && (string)$factor['bip'] === '1') ? '✓' : ' ' ?>] Yes</strong> &nbsp;&nbsp;
                                    <strong>[<?= (isset($factor['bip']) && (string)$factor['bip'] === '0') ? '✓' : ' ' ?>] No</strong><br>
                                    <span class="text-muted" style="font-size: 7.5pt;">Refer to Behavior Intervention Plan (BIP) for additional information.</span>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-header">SECTION B: DIFFICULTIES, BARRIERS AND ENABLING SUPPORT</div>
        <?php if (empty($barriers)): ?>
            <p class="muted">No barriers listed.</p>
        <?php else: ?>
            <table class="table-custom" style="margin-bottom: 3px;">
                <thead><tr><th>Difficulty / Difficulty Area</th><th>Barrier</th><th>Barrier Qualifier</th><th>Facilitator</th><th>Facilitator Qualifier</th><th>Accommodations</th></tr></thead>
                <tbody>
                    <?php foreach ($barriers as $barrier): ?>
                        <tr>
                            <td><?= htmlspecialchars($difficulty_labels[$barrier['difficulty']] ?? $barrier['difficulty'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($barrier['interest'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($barrier['barrier_qualifier'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($barrier['facilitator'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($barrier['facilitator_qualifier'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($barrier['accommodations'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <table class="table-custom legend-table" style="font-size: 7.5pt; margin-top: 2px; margin-bottom: 8px;">
                <tbody>
                    <tr>
                        <th style="background: #f2f2f2; width: 50%; padding: 2px 4px; font-weight: bold;">Qualifier for Environmental Barriers</th>
                        <th style="background: #f2f2f2; width: 50%; padding: 2px 4px; font-weight: bold;">Qualifier for Environmental Facilitators</th>
                    </tr>
                    <tr>
                        <td style="padding: 2px 4px; font-size: 7.5pt;">0 No barrier / 1 Mild / 2 Moderate / 3 Severe / 4 Complete / 8 Not specified / 9 Not applicable</td>
                        <td style="padding: 2px 4px; font-size: 7.5pt;">+1 Mild / +2 Moderate / +3 Severe / +4 Complete / +8 Not specified / +9 Not applicable</td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-header">SECTION C: LEARNER GOALS</div>
        <p class="text-muted" style="font-size: 7.5pt; margin: 2px 0 4px; font-style: italic; line-height: 1.25;">
            To support identification of learner goals, also confirm: what opportunities are available at the school; what are the student's interest areas; what disability-specific skills does the learner need to develop. Goals should be SMART (Strategic, Measurable, Achievable, Realistic, Time-bound).
        </p>
        <?php if (empty($goals)): ?>
            <p class="muted">No goals listed.</p>
        <?php else: ?>
            <table class="table-custom">
                <thead><tr><th>Interest</th><th>Goal</th><th>Interventions</th><th>Timeline</th><th>Individuals Responsible</th><th>Remarks</th><th>Progress / Next Steps</th></tr></thead>
                <tbody>
                    <?php foreach ($goals as $goal): ?>
                        <tr>
                            <td><?= htmlspecialchars($goal['interest'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($goal['goal'] ?? '')) ?></td>
                            <td><?= nl2br(htmlspecialchars($goal['interventions'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($goal['timeline'] ?? '') ?></td>
                            <td><?= htmlspecialchars($goal['responsible'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($goal['remarks'] ?? '')) ?></td>
                            <td><?= nl2br(htmlspecialchars($goal['progress'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-header">SECTION D: LEARNER TRANSITION</div>
        <p class="text-muted" style="font-size: 7.5pt; margin: 2px 0 4px; font-style: italic; line-height: 1.25;">
            This section is for learners exiting the school environment and transitioning to work.
        </p>
        <?php
        $selected_transitions = [];
        foreach ($transition_labels as $key => $label) {
            if (!empty($iep[$key])) {
                $selected_transitions[] = $label;
            }
        }
        ?>
        <?php if (empty($transitions) && empty($selected_transitions)): ?>
            <p class="text-muted" style="font-size: 8.5pt; font-style: italic; padding: 4px; margin-bottom: 10px;">
                This section applies only to learners exiting the school environment and transitioning to work — not applicable for this learner.
            </p>
        <?php else: ?>
            <p style="font-size: 8.5pt; margin-bottom: 4px;">
                <strong>Selected Transition Points:</strong> 
                <?= htmlspecialchars(empty($selected_transitions) ? 'None selected' : implode(', ', $selected_transitions)) ?>
            </p>
            <?php if (!empty($transitions)): ?>
                <table class="table-custom" style="margin-bottom: 10px;">
                    <thead><tr><th>Interest</th><th>Work Opportunities</th><th>Interventions / Transition Skills</th><th>Individuals Responsible</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach ($transitions as $transition): ?>
                            <tr>
                                <td><?= htmlspecialchars($transition['interest'] ?? '') ?></td>
                                <td><?= nl2br(htmlspecialchars($transition['work_opportunities'] ?? '')) ?></td>
                                <td><?= nl2br(htmlspecialchars($transition['interventions'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($transition['responsible'] ?? '') ?></td>
                                <td><?= nl2br(htmlspecialchars($transition['remarks'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // Map saved team members by role
        $member_names = [];
        foreach ($team_members as $m) {
            $member_names[trim($m['role'])] = $m['name'];
        }

        // Helper to output attendee line/role
        $render_attendee = static function(string $role_label, string $db_role_key) use ($member_names): string {
            $name = $member_names[$db_role_key] ?? '';
            $display_name = $name !== '' ? htmlspecialchars($name) : '';
            return '
            <div style="margin-bottom: 8px; break-inside: avoid; page-break-inside: avoid;">
                <div style="font-weight: bold; font-size: 7.5pt; text-transform: uppercase; color: #333;">' . htmlspecialchars($role_label) . '</div>
                <div style="font-size: 8.5pt; margin-top: 2px;">' . ($display_name !== '' ? $display_name : '<span style="color: #ccc; font-size: 8.5pt;">______________________________________</span>') . '</div>
                <div style="font-size: 7pt; font-style: italic; color: #555; border-top: 1px solid #ddd; width: 85%; margin-top: 2px; padding-top: 1px;">Signature over Printed Name</div>
            </div>';
        };
        ?>

        <div style="page-break-before: auto; break-before: auto; break-inside: avoid; page-break-inside: avoid; margin-top: 10px;">
            <div class="section-header">IEP TEAM MEMBERS AND ATTENDANCE</div>
            <table class="table-custom" style="width: 100%; border: none; margin-bottom: 6px;">
                <tbody>
                    <tr>
                        <!-- Left Column: Core School & Family Members -->
                        <td style="width: 50%; vertical-align: top; border: none; padding-right: 15px;">
                            <?= $render_attendee('Parent / Guardian / Caregiver', 'Parent/Guardian') ?>
                            <?= $render_attendee('Learner', 'Learner') ?>
                            <?= $render_attendee('Principal / School Head', 'Principal/School Head') ?>
                            <?= $render_attendee('Special Education Teacher Adviser', 'Special Education Teacher Adviser') ?>
                            <?= $render_attendee('Regular Education / Receiving Teacher', 'Regular Education/Receiving Teacher') ?>
                            <?= $render_attendee('Other SPED Teacher', 'Other SPED Teachers') ?>
                        </td>
                        
                        <!-- Right Column: Support Staff & Specialists -->
                        <td style="width: 50%; vertical-align: top; border: none; padding-left: 15px;">
                            <?= $render_attendee('School Psychologist', 'School Psychologist') ?>
                            <?= $render_attendee('Guidance Counselor / Designate', 'Guidance Counselor') ?>
                            <?= $render_attendee('School Nurse', 'School Nurse') ?>
                            <?= $render_attendee('Occupational Therapist', 'Occupational Therapist') ?>
                            <?= $render_attendee('Physical Therapist / Speech Pathologist', 'Physical Therapist') ?>
                            <?= $render_attendee('Speech-Language Interpreter', 'Speech-Language Interpreter') ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <table class="table-custom" style="width: 100%; margin-top: 6px; margin-bottom: 6px;">
                <tbody>
                    <!-- Rights Notification Statutory Checklist -->
                    <tr>
                        <td style="width: 60%; padding: 5px; font-size: 7.5pt; line-height: 1.3; vertical-align: top;">
                            <div class="fw-bold" style="font-size: 8pt; margin-bottom: 3px; text-transform: uppercase;">Statutory Notice / Rights Notification:</div>
                            <span style="font-size: 7.5pt; color: #333; display: block; margin-bottom: 3px; font-style: italic;">
                                At least one year prior to reaching age 18, the learner must be informed of their rights under the law and advised that these rights will be enjoyed at age 18.
                            </span>
                            <?php
                            $is_underage = ($age !== '' && (int)$age < 17);
                            $not_applicable_checked = ($is_underage || empty($iep['rights_notified']));
                            $rights_notified_checked = (!empty($iep['rights_notified']) && !$is_underage);
                            ?>
                            <div class="mb-1">
                                <?= $render_checkbox($not_applicable_checked, 'Not Applicable (learner will not be 18 within one year)') ?>
                            </div>
                            <div>
                                <?= $render_checkbox($rights_notified_checked, 'Learner has been informed of their rights under the law') ?>
                            </div>
                        </td>
                        
                        <!-- Distribution List -->
                        <td style="width: 40%; padding: 5px; font-size: 7.5pt; line-height: 1.3; vertical-align: top;">
                            <div class="fw-bold" style="font-size: 8pt; margin-bottom: 3px; text-transform: uppercase;">Distribution List:</div>
                            <div class="mb-1"><?= $render_checkbox(true, "Learner's Folder (SBM / Guidance Office)") ?></div>
                            <div class="mb-1"><?= $render_checkbox(true, 'Parent / Guardian / Caregiver') ?></div>
                            <div class="mb-1"><?= $render_checkbox(true, 'Special Education Teacher') ?></div>
                            <div><?= $render_checkbox(true, 'Receiving Teacher(s)') ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
