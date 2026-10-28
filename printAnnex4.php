<?php
session_start();
require_once 'config.php';
require_once __DIR__ . '/includes/print_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$competencies = [
    ['Self-Care/Daily Living Skills', "bathing, dressing, grooming, and feeding one's self."],
    ['Communication Skills', 'understanding and using verbal and nonverbal language.'],
    ['Self-direction', 'problem solving, exercising choice, and initiating and planning activities.'],
    ['Social Skills', 'maintaining interpersonal relationships, understanding emotions and social cues, understanding fairness and honesty, obeying rules and laws.'],
    ['Leisure Skills', "taking responsibility for one's own activities, able to participate in the community."],
    ['Home or School Living', 'housekeeping, cooking, doing laundry, maintaining living space.'],
    ['Functional Academics', 'using reading, writing, and math skills in everyday life.'],
    ['Community Use', 'shopping, using public transportation, using community services.'],
    ['Work', 'ability to maintain part-time or full-time employment, either competitive or sheltered; ability to work under supervision, cooperate with coworkers, be reliable and punctual, and meet work standards.'],
    ['Health and Safety', "ability to protect one's self, responding to health problems."],
];

log_transaction($conn, $_SESSION['user_id'], 'print', 'annex4', 'reference', 'Viewed Annex 4 Essential Adaptive Skills and Competencies reference');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annex 4 Essential Adaptive Skills and Competencies</title>
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body {
            margin: 0;
            padding: 14px 0;
            background: #6b7280;
            color: #111;
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
        }
        .print-toolbar {
            margin: 0 auto 12px;
            text-align: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 16mm 18mm;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0,0,0,.22);
        }
        .annex-label { font-size: 10pt; margin-bottom: 18px; }
        h1 {
            text-align: center;
            font-size: 14pt;
            font-weight: 700;
            text-transform: uppercase;
            margin: 0 0 18px;
        }
        .intro {
            line-height: 1.45;
            margin-bottom: 18px;
            text-align: justify;
        }
        ol {
            padding-left: 24px;
            line-height: 1.45;
        }
        li { margin-bottom: 9px; }
        .skill-name { font-weight: 700; }
        @media print {
            body { background: none; padding: 0; }
            .print-toolbar { display: none !important; }
            .page { box-shadow: none; margin: 0; padding: 16mm 18mm; width: 210mm; min-height: 297mm; }
        }
    </style>
    <?php print_a4_styles('portrait'); ?>
</head>
<body>
    <?php print_a4_toolbar('Print Annex 4'); ?>

    <main class="page a4-fixed">
        <div class="letterhead-container" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; border-bottom: 2px solid #000; padding-bottom: 10px;">
            <img src="img/deped-logo.webp" style="height: 50px; object-fit: contain;" alt="DepEd Logo">
            <div class="letterhead" style="text-align: center; line-height: 1.2; flex-grow: 1;">
                <div style="font-size: 8.5pt; text-transform: uppercase;">Republic of the Philippines</div>
                <div style="font-size: 10pt; font-weight: 700; text-transform: uppercase;">Department of Education</div>
                <div style="font-size: 8.5pt;">Schools Division Office of Valenzuela City</div>
            </div>
            <img src="img/valenzuela_gov_logo.webp" style="height: 50px; object-fit: contain;" alt="Valenzuela Logo">
        </div>
        <div class="annex-label">Annex 4: Essential Adaptive Skills and Competencies</div>
        <h1>Essential Adaptive Skills and Competencies</h1>
        <p class="intro">
            Essential adaptive skills and competencies help LSENs manage schooling and day-to-day activities.
            These are necessary skills for functioning daily, as follows:
        </p>
        <ol>
            <?php foreach ($competencies as [$name, $description]): ?>
                <li><span class="skill-name"><?= htmlspecialchars($name) ?></span> - <?= htmlspecialchars($description) ?></li>
            <?php endforeach; ?>
        </ol>
    </main>
</body>
</html>
