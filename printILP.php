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

$document_type = $_GET['document_type'] ?? 'ILP';
if (!in_array($document_type, ['IEP', 'ILP'])) {
    $document_type = 'ILP';
}

$user_role = $_SESSION['role'] ?? 'user';
$session_school_id = $_SESSION['school_id'] ?? 0;

// Fetch Learner & Current Enrollment
$stmt = $conn->prepare("
    SELECT l.*, e.school_id, e.grade_level, e.placement_type, e.id as enrollment_id, s.school_name, e.school_year, e.primary_diagnosis
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

$learner_disabilities = sned_get_enrollment_disabilities($conn, isset($learner['enrollment_id']) ? (int)$learner['enrollment_id'] : null);
if (empty($learner_disabilities) && !empty($learner['primary_diagnosis'])) {
    $learner_disabilities = sned_disability_rows_from_legacy_diagnosis($learner['primary_diagnosis']);
}
$learner_disability_summary = sned_disability_summary($learner_disabilities, $learner['primary_diagnosis'] ?: 'Unassigned');

// Enforce school scope for coordinators
if (user_is_scoped($user_role) && $learner['school_id'] != $session_school_id) {
    header("Location: 403.php");
    exit();
}

// Fetch ILP/IEP Record
$school_year = $_GET['sy'] ?? $learner['school_year'] ?? '';
$stmt_ilp = $conn->prepare("SELECT * FROM learner_ilp WHERE learner_id = ? AND school_year = ? AND document_type = ? LIMIT 1");
$stmt_ilp->bind_param("sss", $lrn, $school_year, $document_type);
$stmt_ilp->execute();
$ilp = $stmt_ilp->get_result()->fetch_assoc();

if (!$ilp) {
    die("No " . htmlspecialchars($document_type) . " on file for LRN: " . htmlspecialchars($lrn) . " in SY: " . htmlspecialchars($school_year));
}

// Decode transition package for display
$transition_package_labels = [
    'care_skills' => 'Care Skills',
    'life_skills' => 'Life Skills',
    'functional_academics' => 'Functional Academics',
    'pre_vocational_skills' => 'Pre-Vocational Skills',
    'livelihood_skills' => 'Livelihood Skills',
    'career_skills' => 'Career Skills',
    'enrichment_skills' => 'Enrichment Skills',
];
$transition_package_display = '—';
$tp_raw = $ilp['transition_work_opportunities'] ?? '';
if (!empty($tp_raw)) {
    $decoded = json_decode($tp_raw, true);
    if (is_array($decoded)) {
        $mapped = [];
        foreach ($decoded as $key) {
            $mapped[] = $transition_package_labels[$key] ?? $key;
        }
        $transition_package_display = implode(', ', $mapped);
    } else {
        $transition_package_display = $tp_raw;
    }
}

// Log Print Transaction
log_transaction($conn, $_SESSION['user_id'], 'print', 'learner_ilp', $lrn, "Printed " . $document_type . " form for LRN: $lrn (SY: $school_year)");

// Fetch Adviser name
$adviser_name = '_________________________';
if (!empty($ilp['adviser_id'])) {
    $stmt_adv = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt_adv->bind_param("i", $ilp['adviser_id']);
    $stmt_adv->execute();
    $adv_res = $stmt_adv->get_result()->fetch_assoc();
    if ($adv_res) $adviser_name = $adv_res['full_name'];
}

// Fetch Principal name
$principal_name = get_current_principal($conn, (int)$learner['school_id']) ?: '_________________________';

// Fetch Goals, Team Members, and Barriers
$goals = [];
$team_members = [];
$barriers = [];
$assessments = [];

$stmt_goals = $conn->prepare("SELECT * FROM ilp_goals WHERE ilp_id = ? ORDER BY id ASC");
$stmt_goals->bind_param("i", $ilp['id']);
$stmt_goals->execute();
$goals = $stmt_goals->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt_team = $conn->prepare("SELECT * FROM ilp_team_members WHERE ilp_id = ? ORDER BY id ASC");
$stmt_team->bind_param("i", $ilp['id']);
$stmt_team->execute();
$team_members = $stmt_team->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt_assessments = $conn->prepare("SELECT * FROM learner_ilp_assessments WHERE ilp_id = ? ORDER BY date_administered ASC, id ASC");
$stmt_assessments->bind_param("i", $ilp['id']);
$stmt_assessments->execute();
$assessments = $stmt_assessments->get_result()->fetch_all(MYSQLI_ASSOC);

if (!empty($ilp['barriers_json'])) {
    $barriers = json_decode($ilp['barriers_json'], true) ?? [];
}

$sf_json = [];
if (!empty($ilp['special_factors'])) {
    $sf_json = json_decode($ilp['special_factors'], true) ?? [];
}

// Age Helper
$birthDate = new DateTime($learner['birthday']);
$today = new DateTime();
$age = $today->diff($birthDate)->y;

$special_factor_labels = [
    'sf_behavior' => 'Learner has difficulty relating with people',
    'sf_mobility' => 'Difficulty in moving/walking',
    'sf_vision' => 'Difficulty seeing or with blindness/visual impairment',
    'sf_communication' => 'Difficulty in communicating',
    'sf_attention' => 'Difficulty in concentrating/paying attention',
    'sf_memory' => 'Difficulty in remembering/understanding',
    'sf_hearing' => 'Difficulty in hearing or deaf/hard of hearing',
    'sf_assistive_tech' => 'Needs assistive technology devices or services',
    'sf_alt_format' => 'Requires alternative format for instructional materials',
];

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

$is_transition_eligible = in_array($learner['grade_level'] ?? '', ['Transition Level 1', 'Transition Level 2', 'Transition Level 3', 'Non-Graded']) || (($learner['placement_type'] ?? '') === 'Non-Graded');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $document_type ?> Print Report — LRN <?= htmlspecialchars($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            background-color: #525659;
            font-size: 10pt;
            padding: 20px 0;
            color: #000;
        }
        .page {
            width: 210mm;
            margin: 0px auto;
            background: white;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
            padding: 15mm;
            box-sizing: border-box;
            position: relative;
        }
        .header-title {
            text-align: center;
            font-size: 12pt;
            font-weight: 800;
            text-transform: uppercase;
            color: #000;
            margin-top: 10px;
        }
        .header-subtitle {
            text-align: center;
            font-size: 8.5pt;
            color: #000;
            font-weight: 600;
            text-transform: uppercase;
        }
        .section-header {
            font-size: 9.5pt;
            font-weight: 700;
            color: #000;
            border: 1px solid #000;
            background: #d9d9d9;
            padding: 3px 5px;
            margin-top: 6px;
            margin-bottom: 3px;
            text-transform: uppercase;
            page-break-after: avoid;
        }
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 8.5pt;
        }
        .table-custom th, .table-custom td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: top;
        }
        .table-custom th {
            background-color: #d9d9d9;
            font-weight: 700;
            color: #000;
        }
        .rights-notice {
            border: 1px solid #000;
            padding: 8px;
            border-radius: 6px;
            background-color: #f5f5f5;
            font-size: 8pt;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .signature-block {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            page-break-inside: avoid;
        }
        .sig-col {
            width: 30%;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #111827;
            margin-top: 40px;
            font-weight: 700;
            padding-top: 3px;
        }
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .text-primary {
            color: #000 !important;
        }
        @media print {
            body {
                background: none;
                padding: 0;
                margin: 0;
            }
            .page {
                box-shadow: none;
                padding: 12mm 14mm;
                margin: 0;
                width: 210mm;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
    <style>
        @media print {
            @page { size: A4 portrait; margin: 12mm 14mm; }

            html, body {
                width: auto !important;
            }

            .page {
                width: auto !important;
                max-width: none !important;
                min-height: auto !important;
                padding: 0 !important;
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php print_a4_toolbar('Print Document'); ?>

    <div class="page a4-flow">
        <!-- DepEd Header -->
        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
            <img src="img/deped-logo.webp" style="height: 50px; object-fit: contain;" alt="DepEd Logo">
            <div class="text-center flex-grow-1 mx-3" style="line-height: 1.25;">
                <div class="header-subtitle" style="font-size: 9pt; font-weight: bold; text-transform: uppercase;">Republic of the Philippines</div>
                <div class="header-subtitle" style="font-size: 9.5pt; font-weight: 700;">Department of Education - Division of City Schools - Valenzuela</div>
                <div class="header-title mt-1" style="font-size: 10pt; font-weight: 700; color: #000;"><?= htmlspecialchars($learner['school_name'] ?: 'Valenzuela SPED Center') ?></div>
                <h1 class="header-title mt-2" style="font-size: 13pt; font-weight: 800; text-transform: uppercase;"><?= $document_type === 'IEP' ? 'Individualized Education Plan (IEP)' : 'Individual Learner Profile (ILP)' ?></h1>
                <p class="text-muted small mx-auto mt-1 mb-0" style="max-width: 600px; font-size: 7.5pt; line-height: 1.2;">
                    Official student record compliant with DepEd Order No. 044, s. 2021. Formulated to provide educational pathways, interventions, and welfare support.
                </p>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 50px; object-fit: contain;" alt="Valenzuela Logo">
        </div>

        <!-- Section A: Student Details -->
        <div class="section-header">Section A: Profile &amp; Metadata</div>
        <table class="table-custom">
            <tbody>
                <tr>
                    <th style="width: 50%;">Student Profile</th>
                    <th style="width: 50%;">Meeting &amp; Collaborative Details</th>
                </tr>
                <tr>
                    <td>
                        <div class="mb-1"><strong>Learner Name:</strong> <?= htmlspecialchars($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name'] . ' ' . $learner['extension_name']) ?></div>
                        <div class="mb-1"><strong>LRN:</strong> <code><?= htmlspecialchars($learner['lrn']) ?></code></div>
                        <div class="mb-1"><strong>Sex:</strong> <?= htmlspecialchars($learner['sex']) ?> · <strong>Age:</strong> <?= $age ?> Years Old</div>
                        <div class="mb-1"><strong>Date of Birth:</strong> <?= date('F d, Y', strtotime($learner['birthday'])) ?></div>
                        <div class="mb-1"><strong>Placement:</strong> <?= htmlspecialchars($learner['placement_type'] . ' (' . ($learner['grade_level'] ?: 'Not Enrolled') . ')') ?></div>
                        <div><strong>Disabilities:</strong> <?= htmlspecialchars($learner_disability_summary) ?></div>
                    </td>
                    <td>
                        <div class="mb-1"><strong>School Year:</strong> <?= htmlspecialchars($school_year) ?></div>
                        <div class="mb-1"><strong>Meeting Date:</strong> <?= !empty($ilp['meeting_date']) ? date('F d, Y', strtotime($ilp['meeting_date'])) : '—' ?></div>
                        <div class="mb-1"><strong>Review Date:</strong> <?= !empty($ilp['review_date']) ? date('F d, Y', strtotime($ilp['review_date'])) : '—' ?></div>
                        <div class="mb-1"><strong>Purpose of Meeting:</strong> <?= htmlspecialchars($ilp['meeting_purpose'] ?: '—') ?></div>
                        <div class="mb-1"><strong>Interview Date:</strong> <?= !empty($ilp['interview_date']) ? date('F d, Y', strtotime($ilp['interview_date'])) : '—' ?></div>
                        <div class="mb-1"><strong>Guardian Interviewed:</strong> <?= htmlspecialchars($ilp['interview_guardian_name'] ?: '—') ?></div>
                        <div class="mb-1"><strong>Guardian Contact:</strong> <?= htmlspecialchars($ilp['interview_guardian_contact'] ?: '—') ?></div>
                        <div class="mb-1"><strong>Adviser/Teacher:</strong> <?= htmlspecialchars($adviser_name) ?></div>
                        <div><strong>Interests &amp; Hobbies:</strong> <span class="text-muted"><?= htmlspecialchars($ilp['interests_hobbies'] ?: '—') ?></span></div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Section B: Present Levels -->
        <div class="section-header">Section B: Present Levels &amp; Special Factors</div>
        <table class="table-custom">
            <tbody>
                <tr>
                    <th colspan="2">Present Levels of Performance</th>
                </tr>
                <tr>
                    <td colspan="2">
                        <div class="mb-2"><strong>Evaluation &amp; Assessment Results:</strong></div>
                        <p class="text-muted mb-3"><?= nl2br(htmlspecialchars($ilp['present_level_results'] ?: 'No evaluation results documented.')) ?></p>
                        
                        <div class="row">
                            <div class="col-6 border-end">
                                <div class="mb-1"><strong>Parental Concerns:</strong></div>
                                <p class="text-muted small"><?= nl2br(htmlspecialchars($ilp['parental_concerns'] ?: 'None documented.')) ?></p>
                            </div>
                            <div class="col-6">
                                <div class="mb-1"><strong>Impact of Disability:</strong></div>
                                <p class="text-muted small"><?= nl2br(htmlspecialchars($ilp['disability_impact'] ?: 'None documented.')) ?></p>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Record of Assessments -->
        <div class="fw-bold mb-2 text-primary" style="font-size: 8.5pt;">Record of Assessments</div>
        <?php if (empty($assessments)): ?>
            <p class="text-muted small">No assessments documented.</p>
        <?php else: ?>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width: 20%;">Type of Assessment</th>
                        <th style="width: 14%;">Date Administered</th>
                        <th style="width: 14%;">Chronological Age</th>
                        <th style="width: 18%;">Administrator</th>
                        <th style="width: 34%;">Results / Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assessments as $assessment): ?>
                        <tr>
                            <td><?= htmlspecialchars($assessment['assessment_type'] ?? '') ?></td>
                            <td><?= htmlspecialchars($assessment['date_administered'] ?? '') ?></td>
                            <td><?= htmlspecialchars($assessment['chronological_age'] ?? '') ?></td>
                            <td><?= htmlspecialchars($assessment['administrator'] ?? '') ?></td>
                            <td><?= nl2br(htmlspecialchars($assessment['results'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Narrative Domains -->
        <div class="fw-bold mb-2 text-primary" style="font-size: 8.5pt;">Functional Narrative Profile (7 Domains)</div>
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 25%;">Domain</th>
                    <th style="width: 38%;">Strengths</th>
                    <th style="width: 37%;">Needs</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $domains_print = [
                    'daily_living' => 'Daily Living Skills',
                    'language'     => 'Language / Comm',
                    'psychomotor'  => 'Psychomotor',
                    'cognitive'    => 'Cognitive Domain',
                    'aesthetic'    => 'Aesthetic & Creative',
                    'behavioral'   => 'Socio-Behavioral',
                    'orientation'  => 'Orientation/Mobility'
                ];
                foreach ($domains_print as $key => $name): ?>
                <tr>
                    <td><strong><?= $name ?></strong></td>
                    <td><?= nl2br(htmlspecialchars($ilp[$key . '_strengths'] ?? '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($ilp[$key . '_needs'] ?? '—')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Special Factors -->
        <div class="fw-bold mb-2 text-primary" style="font-size: 8.5pt;">Special Factors Assessment</div>
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 45%;">Special Factor Description</th>
                    <th style="width: 10%;" class="text-center">Status</th>
                    <th style="width: 45%;">Description / Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($special_factor_labels as $sfk => $sfl): ?>
                <tr>
                    <td><?= $sfl ?></td>
                    <td class="text-center font-monospace"><strong><?= (($sf_json[$sfk]['yesno'] ?? '0') == '1') ? 'YES' : 'NO' ?></strong></td>
                    <td class="text-muted small"><?= htmlspecialchars($sf_json[$sfk]['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Section C: Barriers & Accommodations -->
        <div class="section-header">Section C: Barriers &amp; Enabling Supports</div>
        <?php if (empty($barriers)): ?>
            <p class="text-muted small">No barriers or accommodations documented.</p>
        <?php else: ?>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width: 20%;">Difficulty Area</th>
                        <th style="width: 20%;">Environmental Barriers</th>
                        <th style="width: 12%;">Barrier Qualifier</th>
                        <th style="width: 20%;">Environmental Facilitators</th>
                        <th style="width: 12%;">Facilitator Qualifier</th>
                        <th style="width: 16%;">Accommodations Required</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barriers as $b): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($difficulty_labels[$b['difficulty']] ?? $b['difficulty']) ?></strong></td>
                        <td><?= htmlspecialchars($b['barrier_text'] ?? '') ?></td>
                        <td class="text-center font-monospace"><?= htmlspecialchars($b['barrier_qualifier'] ?? '') ?></td>
                        <td><?= htmlspecialchars($b['facilitator'] ?? '') ?></td>
                        <td class="text-center font-monospace"><?= htmlspecialchars($b['facilitator_qualifier'] ?? '') ?></td>
                        <td><?= htmlspecialchars($b['accommodations'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- SMART Goals -->
        <div class="section-header">Section D: Goals &amp; Progress Roadmap</div>

        <?php if (!empty(trim($ilp['priority_needs'] ?? ''))): ?>
        <div class="mb-2">
            <strong class="small text-muted">Priority Learning Needs / Interventions:</strong>
            <div class="small"><?= nl2br(htmlspecialchars($ilp['priority_needs'])) ?></div>
        </div>
        <?php endif; ?>

        <?php if (empty($goals)): ?>
            <p class="text-muted small">No goals documented.</p>
        <?php else: ?>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width: 15%;">Domain/Interest</th>
                        <th style="width: 30%;">SMART Goal Target</th>
                        <th style="width: 25%;">Interventions</th>
                        <th style="width: 10%;">Timeline</th>
                        <th style="width: 12%;">Responsible Persons</th>
                        <th style="width: 8%;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($goals as $g): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($g['interest'] ?? '') ?></strong></td>
                        <td><?= htmlspecialchars($g['goal'] ?? '') ?></td>
                        <td><?= htmlspecialchars($g['interventions'] ?? '') ?></td>
                        <td class="text-center"><?= htmlspecialchars($g['timeline'] ?? '') ?></td>
                        <td><?= htmlspecialchars($g['persons_responsible'] ?? '') ?></td>
                        <td><?= htmlspecialchars($g['remarks'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Section E: Transition (Conditional) -->
        <?php if ($is_transition_eligible): ?>
            <div class="section-header">Section E: Transition Planning</div>
            <table class="table-custom">
                <tbody>
                    <tr>
                        <th style="width: 33%;">Transition Interests</th>
                        <th style="width: 34%;">Transition Package</th>
                        <th style="width: 33%;">Enabling Skills &amp; Interventions</th>
                    </tr>
                    <tr>
                        <td><?= nl2br(htmlspecialchars($ilp['transition_interests'] ?: '—')) ?></td>
                        <td><?= nl2br(htmlspecialchars($transition_package_display)) ?></td>
                        <td><?= nl2br(htmlspecialchars($ilp['transition_skills'] ?: '—')) ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Rights transfer notification -->
        <div class="rights-notice mt-3">
            <strong>Rights Transfer Notification:</strong> 
            <?php if (($ilp['rights_transfer_notified'] ?? 0) == 1): ?>
                <i class="bi bi-check-square-fill text-primary"></i> Informed of rights transfer at least 1 year before age 18.
            <?php elseif (($ilp['rights_not_applicable'] ?? 0) == 1): ?>
                <i class="bi bi-check-square-fill text-primary"></i> Not Applicable (Will not reach age 18 within the next year).
            <?php else: ?>
                <i class="bi bi-square"></i> Not documented.
            <?php endif; ?>
        </div>

        <!-- Collaborative Attendance Block -->
        <div class="fw-bold mt-4 mb-2 text-primary" style="font-size: 8.5pt;">Collaborative IEP / ILP Team in Attendance</div>
        <?php if (empty($team_members)): ?>
            <p class="text-muted small">No team members listed.</p>
        <?php else: ?>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width: 45%;">Role</th>
                        <th style="width: 55%;">Full Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($team_members as $tm): ?>
                        <tr>
                            <td><?= htmlspecialchars($tm['role'] ?? '') ?></td>
                            <td><?= htmlspecialchars($tm['full_name'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="fw-bold mt-4 mb-2 text-primary" style="font-size: 8.5pt;">Collaborative IEP / ILP Team Signatures</div>
        <div class="signature-block">
            <?php if (empty($team_members)): ?>
                <div class="sig-col">
                    <div class="sig-line"><?= htmlspecialchars($adviser_name) ?></div>
                    <div class="small text-muted">Special Education Teacher</div>
                </div>
                <div class="sig-col">
                    <div class="sig-line">_________________________</div>
                    <div class="small text-muted">Parent / Guardian</div>
                </div>
                <div class="sig-col">
                    <div class="sig-line"><?= htmlspecialchars($principal_name) ?></div>
                    <div class="small text-muted">School Head / Principal</div>
                </div>
            <?php else: ?>
                <?php 
                $count_t = 0;
                foreach ($team_members as $tm): 
                    if ($count_t >= 3) break;
                    $count_t++;
                ?>
                    <div class="sig-col">
                        <div class="sig-line"><?= htmlspecialchars($tm['full_name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($tm['role']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if ($count_t < 3): ?>
                    <?php for ($i = $count_t; $i < 3; $i++): ?>
                        <div class="sig-col">
                            <div class="sig-line">_________________________</div>
                            <div class="small text-muted">Collaborator</div>
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
