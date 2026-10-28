<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';
include 'includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$lrn = $_GET['learner_id'] ?? '';
$school_year = $_GET['sy'] ?? '';
$quarter = $_GET['q'] ?? '';

if (empty($lrn)) {
    die("Invalid LRN request.");
}

// Fetch learner with current enrollment
$stmt = $conn->prepare("
    SELECT l.*, e.placement_type, e.grade_level, e.primary_diagnosis, e.school_year, e.self_contained_level,
           s.school_name, s.id as school_id, s.district, s.address as school_address,
           g.first_name as g_first, g.last_name as g_last, g.contact_number as g_contact,
           g.house_no_street, g.barangay
    FROM learners l
    LEFT JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    LEFT JOIN schools s ON e.school_id = s.id
    LEFT JOIN guardians g ON l.guardian_id = g.id
    WHERE l.lrn = ? AND l.is_deleted = 0
    ORDER BY e.school_year DESC
    LIMIT 1
");
$stmt->bind_param("s", $lrn);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();

if (!$learner) {
    die("Learner not found.");
}

if (empty($school_year)) {
    $school_year = $learner['school_year'] ?: date('Y') . '-' . (date('Y') + 1);
}

// Fetch Behavior Intervention Report
if (!empty($quarter)) {
    $stmt_bir = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? AND quarter = ?");
    $stmt_bir->bind_param("sss", $lrn, $school_year, $quarter);
} else {
    $stmt_bir = $conn->prepare("SELECT * FROM behavior_intervention_reports WHERE lrn = ? AND school_year = ? ORDER BY id DESC LIMIT 1");
    $stmt_bir->bind_param("ss", $lrn, $school_year);
}
$stmt_bir->execute();
$bir = $stmt_bir->get_result()->fetch_assoc();

if (!$bir) {
    die("Behavior Intervention Report record not found. Please create and save the report first before viewing the print layout.");
}

// Fetch principal
$principal_name = null;
if (!empty($learner['school_id'])) {
    $principal_name = get_current_principal($conn, (int)$learner['school_id']);
}

$age = (new DateTime($learner['birthday']))->diff(new DateTime())->y;
$bir_quarter = $bir['quarter'] ?? $quarter;
$bir_quarter_label = sned_bir_quarter_label($bir_quarter);

// Log print transaction
log_transaction($conn, $_SESSION['user_id'], 'print', 'behavior_intervention_reports', $lrn, "Print BIR for LRN $lrn, SY $school_year, Quarter $bir_quarter");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annex 3: Behavior Intervention Report - LRN <?= htmlspecialchars($lrn) ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: Arial, sans-serif; background: #6b7280; font-size: 10pt; padding: 20px 0; color: #000; }
        .page { width: 210mm; min-height: 297mm; padding: 15mm; margin: 0 auto 12px; background: white; box-shadow: 0 6px 18px rgba(0,0,0,.22); box-sizing: border-box; position: relative; }
        .header-title { font-size: 12pt; font-weight: 800; text-transform: uppercase; color: #000; margin: 0; }
        .section-title { font-size: 9.5pt; font-weight: 700; color: #000; border: 1px solid #000; background: #d9d9d9; padding: 3px 5px; margin-top: 6px; margin-bottom: 3px; text-transform: uppercase; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-size: 7.5pt; text-transform: uppercase; color: #222; font-weight: 700; }
        .info-value { font-weight: 600; border-bottom: 1px solid #111; padding-bottom: 2px; color: #111; min-height: 15px; }
        .table-abc th { background-color: #d9d9d9 !important; font-size: 8.5pt; font-weight: 700; color: #000; text-transform: uppercase; text-align: center; }
        .table-abc td { font-size: 9pt; color: #000; vertical-align: top; width: 33.33%; }
        .signature-area { margin-top: 25px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; text-align: center; }
        .sig-line { border-top: 1.5px solid #000; margin-top: 30px; font-weight: 700; font-size: 8.5pt; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        @media print {
            body { background: none; padding: 0; margin: 0; }
            .page { margin: 0; box-shadow: none; page-break-after: always; }
            .page:last-child { page-break-after: avoid; }
            .no-print { display: none !important; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Report'); ?>

    <!-- Page 1: Instructions (How to use the BIR) -->
    <div class="page a4-fixed">
        <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-4">
            <div class="fw-bold" style="font-size: 11pt; color: #000;">Annex 3: Behavior Intervention Report</div>
            <div></div>
        </div>

        <div class="text-center" style="margin: 20px 0 30px;">
            <h4 class="fw-bold text-uppercase" style="color: #111; letter-spacing: 0; font-size: 13pt;">How to Use the Behavior Intervention Report (BIR)</h4>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2" style="font-size: 10.5pt;">Description</h6>
            <p style="text-align: justify; line-height: 1.5; text-indent: 30px; margin-bottom: 12px;">
                Behavior Intervention Report (BIR) is a narrative report for learners manifesting behavior that interferes instruction and affects academic performance, as reflected in the anecdotal records in the present grade level of the learner. Specifically, it includes the personal information about the learner, antecedent/prior behavior, observable behavior, result/consequence of behavior, interventions, target behavior and specific objectives.
            </p>
            <p style="text-align: justify; line-height: 1.5; text-indent: 30px; margin-bottom: 12px;">
                SPED teachers shall use the Behavior Intervention Report quarterly as a supplementary record to the K to 12 School Form 9 (SF9). Likewise, the general education teachers shall use the form. This report should be treated with confidentiality and limited for the consumption of teachers / advisers, therapists and parents.
            </p>
            <p style="text-align: justify; line-height: 1.5; text-indent: 30px; margin-bottom: 12px;">
                At the end of the school year, the unclaimed BIR must be turned over to the Guidance Counselor/Coordinator or office of the school principal for safe keeping and future reference.
            </p>
            <p style="text-align: justify; line-height: 1.5; text-indent: 30px; margin-bottom: 12px;">
                The results of the behavior report include the description of the target behavior to be addressed. The intervention strategies shall be discussed quarterly by the Behavior Intervention Planning (BIP) team or as the need arises.
            </p>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2" style="font-size: 10.5pt;">Members of the Behavior Intervention Team (BIR)</h6>
            <p style="text-align: justify; line-height: 1.5; text-indent: 30px;">
                School Heads, teachers/advisers, parent's/family members, guidance counselors, learner concerned, and other multi-disciplinary team.
            </p>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2" style="font-size: 10.5pt;">Steps in Accomplishing the Behavior Intervention Report (BIR)</h6>
            <ol style="line-height: 1.5; padding-left: 20px;">
                <li class="mb-2">Identify prior behavior, refer to the baseline data of the learner if available</li>
                <li class="mb-2">Describe exactly what occurred in the environment that triggered the behavior was exhibited. Identify prior behavior of the learners at home, school, and other settings.</li>
                <li class="mb-2">List different types of behavior displayed by the learners during the incident to identify the notable behavior manifested.</li>
                <li class="mb-2">Prepare measurable goals to address and improve the behavior of the learners.</li>
                <li class="mb-2">Prepare behavioral intervention plan.</li>
                <li class="mb-2">Enhance and master target behavior goal.</li>
            </ol>
        </div>

        <div class="text-center text-muted" style="position: absolute; bottom: 8mm; left: 0; right: 0; font-size: 9pt;">1</div>
    </div>

    <!-- Page 2: Demographics, Observation, and Background -->
    <div class="page a4-fixed">
        <div class="letterhead-container" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px;">
            <img src="img/deped-logo.webp" style="height: 55px; object-fit: contain;" alt="DepEd Logo">
            <div class="text-center" style="flex-grow: 1; line-height: 1.2;">
                <div style="font-size: 9.5pt; font-weight: bold; text-transform: uppercase;">Republic of the Philippines</div>
                <div class="header-title" style="font-size: 11pt; font-weight: bold; text-transform: uppercase; margin: 2px 0;">Department of Education</div>
                <div style="font-size: 9pt; font-weight: 600; text-transform: uppercase; color: #000;">National Capital Region</div>
                <div style="font-size: 9pt; font-weight: 600; text-transform: uppercase; color: #000;">Schools Division Office of Valenzuela City</div>
                <div style="font-size: 10pt; font-weight: 700; color: #000; margin-top: 3px;"><?= htmlspecialchars($learner['school_name'] ?: 'Valenzuela SPED Center') ?></div>
                <?php if (!empty($learner['school_address'])): ?>
                    <div style="font-size: 8pt; color: #000; font-style: italic;"><?= htmlspecialchars($learner['school_address']) ?></div>
                <?php endif; ?>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 55px; object-fit: contain;" alt="Valenzuela Logo">
        </div>
        <hr style="border: 1px solid #111; opacity: 1; margin: 10px 0 5px 0;">
        <div style="font-size: 8pt; text-align: right; font-weight: bold; color: #000; margin-bottom: 15px;">ANNEX 3: BEHAVIOR INTERVENTION REPORT</div>

        <div class="text-center my-3">
            <h4 class="fw-bold text-uppercase" style="color: #111; letter-spacing: 0;">Behavior Intervention Report</h4>
            <div class="small text-muted fw-semibold">School Year <?= htmlspecialchars($school_year) ?> | <?= htmlspecialchars($bir_quarter_label) ?></div>
        </div>

        <div class="section-title">I. Learner Identification</div>
        <div class="info-grid mb-3">
            <div class="info-item"><span class="info-label">Learner Name</span><span class="info-value"><?= htmlspecialchars(sned_learner_name($learner)) ?></span></div>
            <div class="info-item"><span class="info-label">LRN</span><span class="info-value"><code><?= htmlspecialchars($learner['lrn']) ?></code></span></div>
            <div class="info-item"><span class="info-label">Age / Sex</span><span class="info-value"><?= $age ?> years / <?= htmlspecialchars($learner['sex']) ?></span></div>
            <div class="info-item"><span class="info-label">Primary Diagnosis</span><span class="info-value"><?= htmlspecialchars($learner['primary_diagnosis'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-label">Educational Placement</span><span class="info-value"><?= htmlspecialchars($bir['educational_placement'] ?: $learner['placement_type'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-label">Date of Observation</span><span class="info-value"><?= date('F d, Y', strtotime($bir['date_of_observation'])) ?></span></div>
            <div class="info-item"><span class="info-label">Observer / Teacher Name</span><span class="info-value"><?= htmlspecialchars($bir['teacher_name'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-label">School / District</span><span class="info-value"><?= htmlspecialchars($learner['school_name'] ?: '—') ?> / <?= htmlspecialchars($learner['district'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-label">School ID</span><span class="info-value"><?= htmlspecialchars((string)($learner['school_id'] ?? '—')) ?></span></div>
            <div class="info-item"><span class="info-label">Parents / Guardians</span><span class="info-value"><?= htmlspecialchars(sned_guardian_name($learner) ?: '—') ?></span></div>
            <div class="info-item"><span class="info-label">Contact Nos.</span><span class="info-value"><?= htmlspecialchars(sned_guardian_contact($learner) ?: '—') ?></span></div>
        </div>

        <div class="section-title">II. Background and Context</div>
        <div class="mb-3">
            <strong>Difficulty / Disability Description:</strong>
            <p class="mt-1 text-secondary" style="line-height: 1.4;"><?= nl2br(htmlspecialchars($bir['difficulty_disability'] ?: '—')) ?></p>
        </div>
        <div class="mb-3">
            <strong>Baseline Behavior Data:</strong>
            <p class="mt-1 text-secondary" style="line-height: 1.4;"><?= nl2br(htmlspecialchars($bir['baseline_data'] ?: '—')) ?></p>
        </div>
        <div class="mb-3">
            <strong>Medical / Psychological Assessment:</strong>
            <p class="mt-1 text-secondary" style="line-height: 1.4;">
                <?php if (!empty($bir['with_medical_assessment'])): ?>
                    <span class="badge bg-success text-white px-2 py-1 mb-2">Yes (Documented)</span><br>
                    <?= nl2br(htmlspecialchars($bir['medical_results'] ?: '—')) ?>
                <?php else: ?>
                    <span class="badge bg-secondary text-white px-2 py-1">No assessment details recorded</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="text-center text-muted" style="position: absolute; bottom: 8mm; left: 0; right: 0; font-size: 9pt;">2</div>
    </div>

    <!-- Page 3: ABC Analysis & Intervention Plan -->
    <div class="page a4-fixed">
        <div class="section-title" style="margin-top: 0;">III. Antecedent-Behavior-Consequence (A-B-C) Analysis</div>
        <table class="table table-bordered table-abc mb-3">
            <thead>
                <tr>
                    <th>Antecedents (what triggered the behavior)</th>
                    <th>Observable Behavior (what the student did)</th>
                    <th>Consequence (what occurred immediately after)</th>
                </tr>
            </thead>
            <tbody>
                <tr class="table-light"><td colspan="3" class="fw-bold small text-uppercase">A. Home Environment</td></tr>
                <tr>
                    <td><?= nl2br(htmlspecialchars($bir['antecedent_home'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['observable_home'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['consequence_home'] ?: '—')) ?></td>
                </tr>
                <tr class="table-light"><td colspan="3" class="fw-bold small text-uppercase">B. School Environment</td></tr>
                <tr>
                    <td><?= nl2br(htmlspecialchars($bir['antecedent_school'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['observable_school'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['consequence_school'] ?: '—')) ?></td>
                </tr>
                <tr class="table-light"><td colspan="3" class="fw-bold small text-uppercase">C. Other Environment / Community</td></tr>
                <tr>
                    <td><?= nl2br(htmlspecialchars($bir['antecedent_other'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['observable_other'] ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars($bir['consequence_other'] ?: '—')) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="section-title">IV. Behavior Intervention Plan</div>
        <div class="row g-2 mb-3">
            <div class="col-md-6 mb-2">
                <strong>Targeted Behavior for Modification:</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['targeted_behavior'] ?: '—')) ?></p>
            </div>
            <div class="col-md-6 mb-2">
                <strong>Specific Objectives:</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['specific_objectives'] ?? '—')) ?></p>
            </div>
            <div class="col-md-6 mb-2">
                <strong>Proactive Strategies (Prevention):</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['proactive_strategies'] ?: '—')) ?></p>
            </div>
            <div class="col-md-6 mb-2">
                <strong>Reactive Strategies (Response):</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['reactive_strategies'] ?: '—')) ?></p>
            </div>
            <div class="col-md-6">
                <strong>Basic Interventions Currently Handled:</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['basic_intervention'] ?: '—')) ?></p>
            </div>
            <div class="col-md-6">
                <strong>Past Interventions & Efficacy:</strong>
                <p class="small text-secondary mt-1"><?= nl2br(htmlspecialchars($bir['interventions_done'] ?: '—')) ?></p>
            </div>
        </div>

        <div class="signature-area">
            <div>
                <div class="sig-line">Prepared by: Observer / Class Teacher</div>
                <div class="small fw-semibold mt-1"><?= htmlspecialchars($bir['teacher_name']) ?></div>
                <div class="small text-muted">(Signature over Printed Name)</div>
            </div>
            <div>
                <div class="sig-line">Conforme: Parent / Guardian</div>
                <div class="small fw-semibold mt-1"><?= htmlspecialchars($bir['parent_guardian_conforme'] ?: '—') ?></div>
                <div class="small text-muted">(Signature over Printed Name)</div>
            </div>
            <div>
                <div class="sig-line">Noted by: School Principal</div>
                <div class="small fw-semibold mt-1"><?= htmlspecialchars($principal_name ?: '') ?></div>
                <div class="small text-muted">(Signature over Printed Name)</div>
            </div>
        </div>
        <div class="text-center text-muted" style="position: absolute; bottom: 8mm; left: 0; right: 0; font-size: 9pt;">3</div>
    </div>
</body>
</html>
