<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Blank SNED Intake Form</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #525659;
            font-size: 9pt;
            padding: 20px 0;
        }

        .page {
            width: 210mm;
            height: 297mm;
            padding: 15mm;
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        .header-title {
            text-align: center;
            font-size: 15pt;
            font-weight: 800;
            text-transform: uppercase;
            margin: 0;
            color: #1e3a8a;
        }

        .header-subtitle {
            text-align: center;
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 3px;
            font-weight: 600;
        }

        .section-title {
            background-color: #f1f5f9;
            font-weight: 800;
            text-transform: uppercase;
            padding: 6px;
            border: 1px solid #000;
            border-bottom: none;
            font-size: 9pt;
            margin-top: 15px;
            color: #1e3a8a;
        }

        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0px;
        }

        .info-grid td {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: top;
            height: 40px;
        }

        .label {
            font-size: 7.5pt;
            font-weight: 700;
            color: #475569;
            display: block;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .value-placeholder {
            border-bottom: 1px dashed #cbd5e1;
            height: 16px;
            width: 100%;
            margin-top: 2px;
        }

        .signature-area {
            margin-top: auto;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 50px;
            text-align: center;
            padding-top: 30px;
        }

        .sig-line {
            border-top: 1.5px solid #000;
            margin-top: 40px;
            font-weight: 700;
            font-size: 9pt;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        @media print {
            body {
                background: none;
                padding: 0;
                margin: 0;
            }
            .page {
                margin: 0;
                box-shadow: none;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Intake Template'); ?>

    <!-- PAGE 1: PHYSICAL DATA INTAKE SHEET -->
    <div class="page a4-fixed">
        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <img src="img/deped-logo.webp" style="height: 50px; object-fit: contain;" alt="DepEd Logo">
            <div class="text-center flex-grow-1 mx-3" style="line-height: 1.25;">
                <div style="font-size: 8.5pt; text-transform: uppercase;">Republic of the Philippines</div>
                <div style="font-size: 9.5pt; font-weight: 700; text-transform: uppercase;">Department of Education</div>
                <div style="font-size: 8.5pt;">Schools Division Office of Valenzuela City</div>
                <h1 class="header-title mt-1 mb-0" style="font-size: 13pt; font-weight: bold;">SNED Profile Registration Sheet</h1>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 50px; object-fit: contain;" alt="Valenzuela Logo">
        </div>

        <!-- Section 1: Learner Credentials -->
        <div class="section-title">1. Learner Credentials & Demographics</div>
        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <span class="label">Learner Reference Number (LRN)</span>
                    <div class="value-placeholder"></div>
                    <small class="text-muted" style="font-size: 6pt;">(Must be exactly 12 numeric digits)</small>
                </td>
                <td style="width: 50%;">
                    <span class="label">PWD ID Card Number</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Last Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">First Name</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 70%;">
                    <span class="label">Middle Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 30%;">
                    <span class="label">Extension Name (Jr., III, etc.)</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Date of Birth</span>
                    <div class="value-placeholder"></div>
                    <small class="text-muted" style="font-size: 6pt;">(YYYY - MM - DD)</small>
                </td>
                <td style="width: 50%;">
                    <span class="label">Sex (Check one)</span>
                    <div class="mt-2">
                        <span class="me-4"><i class="bi bi-square"></i> Male</span>
                        <span><i class="bi bi-square"></i> Female</span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Section 2: Educational Placement -->
        <div class="section-title">2. Current Educational Placement & Medical Data</div>
        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <span class="label">Assigned School Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">School Year (SY)</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Placement Type (Check one)</span>
                    <div class="mt-2" style="font-size: 7.5pt;">
                        <div class="mb-1"><i class="bi bi-square"></i> Graded</div>
                        <div class="mb-1"><i class="bi bi-square"></i> Non-Graded</div>
                        <div><i class="bi bi-square"></i> Non-Graded</div>
                    </div>
                </td>
                <td style="width: 50%;">
                    <span class="label">Grade Level / Transition Level</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <span class="label">Primary Diagnosis (Check one)</span>
                    <div class="mt-2 d-flex justify-content-between" style="font-size: 8pt;">
                        <span><i class="bi bi-square"></i> Autism Spectrum Disorder (ASD)</span>
                        <span><i class="bi bi-square"></i> Intellectual Disability (ID)</span>
                        <span><i class="bi bi-square"></i> Global Developmental Delay (GDD)</span>
                        <span><i class="bi bi-square"></i> Hearing Impairment</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="height: 60px;">
                    <span class="label">Assessment Remarks / Special Recommendations</span>
                    <div class="value-placeholder" style="margin-top: 10px;"></div>
                    <div class="value-placeholder" style="margin-top: 10px;"></div>
                </td>
            </tr>
        </table>

        <!-- Section 3: Family / Guardian Details -->
        <div class="section-title">3. Family Details & Address</div>
        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <span class="label">Guardian Last Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">Guardian First Name & Middle Name</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Contact / Telephone Number</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">Guardian Sex (Check one)</span>
                    <div class="mt-2">
                        <span class="me-4"><i class="bi bi-square"></i> Male</span>
                        <span><i class="bi bi-square"></i> Female</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td style="width: 70%;">
                    <span class="label">House No. & Street Address</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 30%;">
                    <span class="label">Barangay (Check one)</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Mother's Full Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">Mother's Contact No.</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <span class="label">Father's Full Name</span>
                    <div class="value-placeholder"></div>
                </td>
                <td style="width: 50%;">
                    <span class="label">Father's Contact No.</span>
                    <div class="value-placeholder"></div>
                </td>
            </tr>
        </table>

        <!-- Signatures and Verification -->
        <div class="signature-area">
            <div>
                <div class="sig-line">Signature of Parent / Guardian</div>
                <div class="small text-muted mt-1">Conveys validation of credentials and privacy consent</div>
            </div>
            <div>
                <div class="sig-line">Signature of Interviewing Coordinator</div>
                <div class="small text-muted mt-1">SNED Evaluator / Case Officer</div>
            </div>
        </div>
    </div>
</body>
</html>
