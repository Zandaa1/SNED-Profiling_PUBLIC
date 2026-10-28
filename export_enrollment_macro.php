<?php
session_start();
require 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access. Please log in.");
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    die("Access denied. Insufficient permissions.");
}

$school_year = trim($_GET['school_year'] ?? '');
if ($school_year === '') {
    $cy = (int)date('Y');
    $cm = (int)date('n');
    $school_year = ($cm >= 6) ? $cy . '-' . ($cy + 1) : ($cy - 1) . '-' . $cy;
}

$grade_levels = ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12', 'Transition Level 1', 'Transition Level 2', 'Transition Level 3', 'Non-Graded'];
$placement_types = ['Graded', 'Non-Graded'];

$schools = [];
$res = $conn->query("SELECT id, school_name, district FROM schools WHERE is_deleted = 0 ORDER BY school_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $schools[] = $row;
    }
}

$data = [];
$stmt = $conn->prepare("
    SELECT e.school_id, e.placement_type, e.grade_level, l.sex, COUNT(*) as cnt
    FROM enrollments e
    JOIN learners l ON e.lrn = l.lrn AND l.is_deleted = 0 AND l.lrn_is_temporary = 0
    WHERE e.school_year = ? AND e.is_deleted = 0 AND e.is_historical = 0
    GROUP BY e.school_id, e.placement_type, e.grade_level, l.sex
");
$stmt->bind_param("s", $school_year);
$stmt->execute();
$res = $stmt->get_result();
if ($res) {
        while ($row = $res->fetch_assoc()) {
            $gl_match = $row['grade_level'];
            if (strpos($gl_match, 'Kinder') !== false) {
                $gl_match = 'Kinder';
            } else {
                for ($i = 1; $i <= 12; $i++) {
                    if (strpos($gl_match, "Grade $i") === 0) {
                        $gl_match = "Grade $i";
                        break;
                    }
                }
            }
            $key = $row['school_id'] . '|' . $row['placement_type'] . '|' . $gl_match;
            if (!isset($data[$key])) {
                $data[$key] = ['Male' => 0, 'Female' => 0];
            }
            // Accumulate count instead of overwrite, in case multiple detailed levels map to the same normalized level
            $data[$key][$row['sex']] += (int)$row['cnt'];
        }
    }

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Enrollment SY $school_year");
$sheet->setShowGridlines(true);

$totalGradeCols = count($grade_levels) * count($placement_types) + 1;
$lastCol = Coordinate::stringFromColumnIndex($totalGradeCols + 1);

$sheet->setCellValue('A1', "SPECIAL NEEDS EDUCATION — ENROLMENT SUMMARY SY $school_year");
$sheet->mergeCells('A1:' . $lastCol . '1');

$headerStyle = [
    'font' => [
        'bold' => true,
        'size' => 9,
        'color' => ['rgb' => '1E3A8A']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'EEF2FF']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '94A3B8']
        ]
    ]
];

// Row 2: Placement type headers + Total
$colIdx = 2;
foreach ($placement_types as $pt) {
    $startCol = Coordinate::stringFromColumnIndex($colIdx);
    $endCol = Coordinate::stringFromColumnIndex($colIdx + count($grade_levels) - 1);
    $sheet->setCellValue($startCol . '2', $pt);
    if (count($grade_levels) > 1) {
        $sheet->mergeCells($startCol . '2:' . $endCol . '2');
    }
    foreach ($grade_levels as $gl) {
        $c = Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($c . '3', $gl);
        $colIdx++;
    }
}
$totalStartCol = Coordinate::stringFromColumnIndex($colIdx);
$sheet->setCellValue($totalStartCol . '2', 'TOTAL PER SCHOOL');
$sheet->mergeCells($totalStartCol . '2:' . $totalStartCol . '3');

$sheet->getStyle('A2:' . $lastCol . '3')->applyFromArray($headerStyle);

$rowIdx = 4;
foreach ($schools as $school) {
    $colIdx = 2;
    $schoolCell = 'A' . $rowIdx;
    $sheet->setCellValue($schoolCell, $school['school_name']);
    $sheet->getStyle($schoolCell)->getFont()->setBold(true);

    $schoolTotal = 0;
    foreach ($placement_types as $pt) {
        foreach ($grade_levels as $gl) {
            $key = $school['id'] . '|' . $pt . '|' . $gl;
            $total = ($data[$key]['Male'] ?? 0) + ($data[$key]['Female'] ?? 0);
            $schoolTotal += $total;
            $c = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue($c . $rowIdx, $total);
            $colIdx++;
        }
    }

    $tc = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($tc . $rowIdx, $schoolTotal);
    $sheet->getStyle($tc . $rowIdx)->getFont()->setBold(true);

    $sheet->getStyle('A' . $rowIdx . ':' . $lastCol . $rowIdx)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CBD5E1']
            ]
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
    $sheet->getStyle('A' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $rowIdx++;
}

// Grand total row
$sheet->setCellValue('A' . $rowIdx, 'GRAND TOTAL');
$sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);

$colIdx = 2;
$grandTotal = 0;
foreach ($placement_types as $pt) {
    foreach ($grade_levels as $gl) {
        $ptTotal = 0;
        foreach ($schools as $school) {
            $key = $school['id'] . '|' . $pt . '|' . $gl;
            $ptTotal += ($data[$key]['Male'] ?? 0) + ($data[$key]['Female'] ?? 0);
        }
        $grandTotal += $ptTotal;
        $c = Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($c . $rowIdx, $ptTotal);
        $colIdx++;
    }
}
$tc = Coordinate::stringFromColumnIndex($colIdx);
$sheet->setCellValue($tc . $rowIdx, $grandTotal);

$sheet->getStyle('A' . $rowIdx . ':' . $lastCol . $rowIdx)->applyFromArray([
    'font' => ['bold' => true, 'size' => 10],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FEF3C7']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '94A3B8']
        ]
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
]);
$sheet->getStyle('A' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet->getColumnDimension('A')->setWidth(40);
for ($i = 2; $i <= $colIdx; $i++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(6);
}

log_transaction($conn, $_SESSION['user_id'], 'print', 'learners', 'enrollment_macro', "Exported Enrollment Macro Summary for SY $school_year");

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="SPECIAL-NEEDS-EDUCATION-VALENZUELA-ENROLMENT-' . $school_year . '-' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
