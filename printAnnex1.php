<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
require_once 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['learner_id'] ?? $_GET['lrn'] ?? '';
$school_year = trim($_GET['school_year'] ?? $_GET['sy'] ?? '');

if ($lrn === '' || !preg_match('/^\d{12}$/', $lrn)) {
    die("Invalid Learner LRN.");
}

$stmt = $conn->prepare("
    SELECT l.*, e.id AS enrollment_id, e.school_id, e.school_year, e.grade_level, e.placement_type,
           e.self_contained_level, e.primary_diagnosis,
           e.previous_school_name, e.years_in_school, e.current_level_of_education, e.interpreter_needed, e.assessment_mode, e.assessment_institution, e.medical_results,
           s.school_name, s.district, s.address AS school_address,
           g.first_name AS g_first, g.last_name AS g_last, g.middle_name AS g_middle,
           g.extension_name AS g_ext, g.contact_number AS g_contact,
           g.workplace AS g_workplace, g.landline AS g_landline, g.mother_tongue AS g_mother_tongue,
           g.house_no_street AS g_house, g.barangay AS g_barangay,
           lg.full_name AS primary_contact_name, lg.contact_number AS primary_contact_number,
           lg.address AS primary_contact_address
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    LEFT JOIN learner_guardians lg ON l.lrn = lg.lrn AND lg.is_primary_contact = 1
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC, e.id DESC
    LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

if ($school_year === '') {
    $school_year = $learner['school_year'] ?? '';
}

$user_role = $_SESSION['role'] ?? 'user';
if (user_is_scoped($user_role)) {
    $has_access = user_is_teacher($user_role)
        ? check_teacher_learner_access($conn, $lrn)
        : ((int)($learner['school_id'] ?? 0) === (int)($_SESSION['school_id'] ?? 0));
    if (!$has_access) {
        header("Location: 403.php");
        exit();
    }
}

$stmt_ilp = $conn->prepare("
    SELECT ilp.*, u.full_name AS adviser_name
    FROM learner_ilp ilp
    LEFT JOIN users u ON ilp.adviser_id = u.id
    WHERE ilp.learner_id = ? AND ilp.school_year = ?
    ORDER BY FIELD(ilp.document_type, 'IEP', 'ILP'), ilp.id DESC
    LIMIT 1
");
$stmt_ilp->bind_param("ss", $lrn, $school_year);
$stmt_ilp->execute();
$ilp = $stmt_ilp->get_result()->fetch_assoc() ?: [];

$assessments = [];
if (!empty($ilp['id'])) {
    $stmt_assessments = $conn->prepare("SELECT * FROM learner_ilp_assessments WHERE ilp_id = ? ORDER BY id ASC");
    $stmt_assessments->bind_param("i", $ilp['id']);
    $stmt_assessments->execute();
    $assessments = $stmt_assessments->get_result()->fetch_all(MYSQLI_ASSOC);
}

$latest_assessment = null;
$stmt_latest = $conn->prepare("SELECT assessment_type, assessment_date FROM assessments WHERE lrn = ? ORDER BY assessment_date DESC LIMIT 1");
if ($stmt_latest) {
    $stmt_latest->bind_param("s", $lrn);
    $stmt_latest->execute();
    $latest_assessment = $stmt_latest->get_result()->fetch_assoc();
}

if (empty($assessments) && $latest_assessment) {
    $assessments[] = [
        'assessment_type' => $latest_assessment['assessment_type'] ?? '',
        'date_administered' => $latest_assessment['assessment_date'] ?? '',
        'chronological_age' => '',
        'administrator' => '',
        'results' => '',
    ];
}

for ($i = count($assessments); $i < 4; $i++) {
    $assessments[] = [
        'assessment_type' => '',
        'date_administered' => '',
        'chronological_age' => '',
        'administrator' => '',
        'results' => '',
    ];
}

$birthdate = !empty($learner['birthday']) ? date('F d, Y', strtotime($learner['birthday'])) : '';
$age = !empty($learner['birthday']) ? (new DateTime($learner['birthday']))->diff(new DateTime())->y : '';
$guardian_name = sned_guardian_name($learner);
$guardian_contact = sned_guardian_contact($learner);
$guardian_address = sned_guardian_address($learner);

function annex1_value(?string $value): string {
    $value = trim((string)$value);
    return $value !== '' ? sned_h($value) : '&nbsp;';
}

function annex1_date(?string $date): string {
    if (!$date) {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('F d, Y', $time) : $date;
}

function annex1_numbered_lines(?string $value, int $count): array {
    $parts = preg_split('/\r\n|\r|\n/', trim((string)$value));
    $parts = array_values(array_filter(array_map('trim', $parts ?: []), fn($line) => $line !== ''));
    for ($i = count($parts); $i < $count; $i++) {
        $parts[] = '';
    }
    return array_slice($parts, 0, $count);
}

$priority_lines = annex1_numbered_lines($ilp['priority_needs'] ?? '', 5);

// Decode transition package (JSON array of keys or legacy text)
$transition_package_labels = [
    'care_skills' => 'Care Skills',
    'life_skills' => 'Life Skills',
    'functional_academics' => 'Functional Academics',
    'pre_vocational_skills' => 'Pre-Vocational Skills',
    'livelihood_skills' => 'Livelihood Skills',
    'career_skills' => 'Career Skills',
    'enrichment_skills' => 'Enrichment Skills',
];
$transition_package_text = '';
$tp_raw = $ilp['transition_work_opportunities'] ?? '';
if (!empty($tp_raw)) {
    $decoded = json_decode($tp_raw, true);
    if (is_array($decoded)) {
        $labels = [];
        foreach ($decoded as $key) {
            $labels[] = $transition_package_labels[$key] ?? $key;
        }
        $transition_package_text = implode("\n", $labels);
    } else {
        $transition_package_text = $tp_raw;
    }
}
$transition_lines = annex1_numbered_lines($transition_package_text, 4);

log_transaction($conn, $_SESSION['user_id'], 'print', 'annex1', $lrn, "Printed Annex 1 Individual Learner's Profile for LRN: $lrn (SY: $school_year)");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annex 1 Individual Learner's Profile - LRN <?= sned_h($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body {
            margin: 0;
            padding: 14px 0;
            background: #6b7280;
            color: #111;
            font-family: "Times New Roman", Times, serif;
            font-size: 10.5pt;
        }
        .print-toolbar {
            margin: 0 auto 12px;
            text-align: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 12px;
            padding: 12mm 14mm;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0,0,0,.22);
            position: relative;
        }
        .annex-kicker {
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            margin-bottom: 10px;
        }
        .letterhead-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .letterhead-logo {
            height: 50px;
            object-fit: contain;
        }
        .letterhead {
            text-align: center;
            line-height: 1.18;
            flex-grow: 1;
            margin-bottom: 0;
        }
        .letterhead .dept {
            font-size: 15pt;
            font-weight: 700;
        }
        .line {
            display: inline-block;
            border-bottom: 1px solid #111;
            min-width: 130px;
            padding: 0 4px 1px;
            text-align: center;
        }
        .title {
            text-align: center;
            font-size: 14pt;
            font-weight: 700;
            margin: 10px 0 12px;
            text-transform: uppercase;
        }
        .part-label {
            text-align: center;
            font-weight: 700;
            margin: 7px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 8px;
        }
        th, td {
            border: 1px solid #111;
            padding: 4px 5px;
            vertical-align: top;
            line-height: 1.18;
        }
        th {
            font-weight: 700;
            text-align: center;
            background: #f5f5f5;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px 10px;
            margin-bottom: 8px;
        }
        .field {
            display: flex;
            align-items: end;
            gap: 5px;
        }
        .field label {
            white-space: nowrap;
            font-weight: 700;
        }
        .field .fill {
            flex: 1;
            min-height: 18px;
            border-bottom: 1px solid #111;
            text-align: center;
            padding: 0 4px 1px;
        }
        .section-title {
            font-weight: 700;
            margin: 10px 0 4px;
        }
        .writing-box {
            border: 1px solid #111;
            min-height: 72px;
            padding: 6px;
            white-space: pre-wrap;
        }
        .domain-table td:first-child {
            width: 23%;
            font-weight: 700;
        }
        .footer-page {
            position: absolute;
            right: 14mm;
            bottom: 8mm;
            color: #444;
            font-size: 9.5pt;
        }
        .annex-page-2 {
            font-size: 10pt;
        }
        .annex-page-2 .annex-kicker {
            margin-bottom: 6px;
        }
        .annex-page-2 .letterhead-container {
            margin-bottom: 7px;
        }
        .annex-page-2 .letterhead-logo {
            height: 42px;
        }
        .annex-page-2 .letterhead {
            line-height: 1.08;
        }
        .annex-page-2 .letterhead .dept {
            font-size: 14pt;
        }
        .annex-page-2 .part-label {
            margin: 4px 0;
        }
        .annex-page-2 .title {
            font-size: 13pt;
            margin: 6px 0 8px;
        }
        .annex-page-2 table {
            margin-bottom: 5px;
        }
        .annex-page-2 th,
        .annex-page-2 td {
            padding: 2px 5px;
            line-height: 1.12;
        }
        .annex-page-2 .section-title {
            margin: 6px 0 3px;
        }
        .annex-page-2 .writing-box {
            min-height: 48px;
            padding: 5px;
        }
        .annex-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28mm;
            margin-top: 12mm;
            text-align: center;
        }
        @media print {
            body { background: none; padding: 0; }
            .print-toolbar { display: none !important; }
            .page { margin: 0; box-shadow: none; page-break-after: always; }
            .page:last-of-type { page-break-after: auto; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Annex 1'); ?>

    <section class="page a4-fixed">
        <div class="annex-kicker"><span>Page 1 of 2</span></div>
        <div class="letterhead-container">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead">
                <div>Republic of the Philippines</div>
                <div class="dept">Department of Education</div>
                <div>Region <span class="line"><?= sned_h(DEPED_REGION) ?></span></div>
                <div>Division <span class="line"><?= sned_h(DEPED_DIVISION) ?></span></div>
                <div>District <span class="line"><?= annex1_value($learner['district'] ?? '') ?></span></div>
                <div>School <span class="line" style="min-width:260px;"><?= annex1_value($learner['school_name'] ?? '') ?></span></div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>

        <div class="part-label">PART 1</div>
        <div class="title">INDIVIDUAL LEARNER'S PROFILE</div>

        <div class="field-grid">
            <div class="field"><label>Name</label><div class="fill"><?= sned_h(sned_learner_name($learner)) ?></div></div>
            <div class="field"><label>Date of Birth</label><div class="fill"><?= sned_h($birthdate) ?></div></div>
            <div class="field"><label>Age</label><div class="fill"><?= sned_h((string)$age) ?></div></div>
            <div class="field"><label>LRN</label><div class="fill"><?= sned_h($lrn) ?></div></div>
            <div class="field"><label>Type of Learner</label><div class="fill"><?= annex1_value(trim(($learner['placement_type'] ?? '') . ' ' . ($learner['self_contained_level'] ?? ''))) ?></div></div>
            <div class="field"><label>School Year</label><div class="fill"><?= sned_h($school_year) ?></div></div>
            <div class="field"><label>Grade / Level</label><div class="fill"><?= annex1_value($learner['grade_level'] ?? '') ?></div></div>
            <div class="field"><label>Adviser</label><div class="fill"><?= annex1_value($ilp['adviser_name'] ?? '') ?></div></div>
        </div>

        <div class="section-title">Record of Assessments</div>
        <table>
            <thead>
                <tr>
                    <th style="width:22%;">Type of Assessments</th>
                    <th style="width:18%;">Date Administered</th>
                    <th style="width:16%;">Chronological Age</th>
                    <th style="width:20%;">Administrator</th>
                    <th>Results / Outcome</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $assessment): ?>
                    <tr>
                        <td><?= annex1_value($assessment['assessment_type'] ?? '') ?></td>
                        <td><?= annex1_value(annex1_date($assessment['date_administered'] ?? '')) ?></td>
                        <td><?= annex1_value($assessment['chronological_age'] ?? '') ?></td>
                        <td><?= annex1_value($assessment['administrator'] ?? '') ?></td>
                        <td><?= nl2br(annex1_value($assessment['results'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Interview with Parents/Guardian</div>
        <div class="field-grid">
            <div class="field"><label>Name of Parent/Guardian</label><div class="fill"><?= annex1_value($ilp['interview_guardian_name'] ?? $guardian_name) ?></div></div>
            <div class="field"><label>Date of Interview</label><div class="fill"><?= annex1_value(annex1_date($ilp['interview_date'] ?? '')) ?></div></div>
            <div class="field"><label>Contact Number/s</label><div class="fill"><?= annex1_value($ilp['interview_guardian_contact'] ?? $guardian_contact) ?></div></div>
            <div class="field"><label>Home Address</label><div class="fill"><?= annex1_value($guardian_address) ?></div></div>
        </div>
        <div class="section-title">Developmental and Educational History:</div>
        <div class="writing-box"><?= nl2br(annex1_value($ilp['present_level_results'] ?? $ilp['disability_impact'] ?? '')) ?></div>

        <div class="section-title">Interview with the Learner</div>
        <div class="field-grid">
            <div class="field"><label>Date of Interview</label><div class="fill"><?= annex1_value(annex1_date($ilp['interview_date'] ?? '')) ?></div></div>
            <div class="field"><label>Interests / Hobbies / Talents</label><div class="fill"><?= annex1_value($ilp['interests_hobbies'] ?? '') ?></div></div>
        </div>
        <div class="footer-page">1 | Page</div>
    </section>

    <section class="page a4-fixed annex-page-2">
        <div class="annex-kicker"><span>Page 2 of 2</span></div>
        <div class="letterhead-container">
            <img src="img/deped-logo.webp" class="letterhead-logo" alt="DepEd Logo">
            <div class="letterhead">
                <div>Republic of the Philippines</div>
                <div class="dept">Department of Education</div>
                <div>School <span class="line" style="min-width:260px;"><?= annex1_value($learner['school_name'] ?? '') ?></span></div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" class="letterhead-logo" alt="Valenzuela Logo">
        </div>
        <div class="part-label">PART 2</div>
        <div class="title">Functional Narrative Profile</div>

        <table class="domain-table">
            <thead>
                <tr>
                    <th>Learning Domain</th>
                    <th>Strengths</th>
                    <th>Needs</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $domains = [
                    'Daily Living Skills Domain' => ['daily_living_strengths', 'daily_living_needs'],
                    'Language Development Domain' => ['language_strengths', 'language_needs'],
                    'Psychomotor Domain' => ['psychomotor_strengths', 'psychomotor_needs'],
                    'Cognitive Domain' => ['cognitive_strengths', 'cognitive_needs'],
                    'Aesthetic and Creative Domain' => ['aesthetic_strengths', 'aesthetic_needs'],
                    'Behavioral Development' => ['behavioral_strengths', 'behavioral_needs'],
                    'Orientation and Mobility' => ['orientation_strengths', 'orientation_needs'],
                ];
                foreach ($domains as $label => [$strength_key, $need_key]):
                ?>
                    <tr>
                        <td><?= sned_h($label) ?></td>
                        <td><?= nl2br(annex1_value($ilp[$strength_key] ?? '')) ?></td>
                        <td><?= nl2br(annex1_value($ilp[$need_key] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Priority Learning Needs/Interventions:</div>
        <table>
            <tbody>
                <?php foreach ($priority_lines as $idx => $line): ?>
                    <tr>
                        <td style="width:8%; text-align:center; font-weight:700;"><?= $idx + 1 ?>.</td>
                        <td><?= annex1_value($line) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Transition Package:</div>
        <table>
            <tbody>
                <?php foreach ($transition_lines as $idx => $line): ?>
                    <tr>
                        <td style="width:8%; text-align:center; font-weight:700;"><?= $idx + 1 ?>.</td>
                        <td><?= annex1_value($line) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($transition_lines)): ?>
                    <tr><td colspan="2" style="color:#999;">—</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">Transition Interests:</div>
        <div class="writing-box"><?= nl2br(annex1_value($ilp['transition_interests'] ?? '')) ?></div>

        <div class="section-title">Enabling Skills &amp; Interventions:</div>
        <div class="writing-box"><?= nl2br(annex1_value($ilp['transition_skills'] ?? '')) ?></div>

        <div class="section-title">Parent Concerns / Additional Notes:</div>
        <div class="writing-box"><?= nl2br(annex1_value($ilp['parental_concerns'] ?? '')) ?></div>

        <div class="annex-signatures">
            <div>
                <div style="border-bottom:1px solid #111; min-height:24px;"><?= annex1_value($ilp['adviser_name'] ?? '') ?></div>
                <div>Adviser / Teacher</div>
            </div>
            <div>
                <div style="border-bottom:1px solid #111; min-height:24px;"><?= annex1_value($ilp['interview_guardian_name'] ?? $guardian_name) ?></div>
                <div>Parent / Guardian</div>
            </div>
        </div>
        <div class="footer-page">2 | Page</div>
    </section>
</body>
</html>
