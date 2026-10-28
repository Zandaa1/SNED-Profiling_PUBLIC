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

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access. Please log in.");
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    die("Access denied. Insufficient permissions.");
}

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$school_year = trim($_GET['school_year'] ?? '');
if ($school_id <= 0 || $school_year === '') {
    die("Please provide school_id and school_year parameters.");
}

if (user_is_scoped($user_role) && $school_id !== (int)($_SESSION['school_id'] ?? 0)) {
    die("Access denied. You can only export data for your own school.");
}

$stmt = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$res = $stmt->get_result();
$school = $res ? $res->fetch_assoc() : null;
if (!$school) {
    die("School not found.");
}

$school_name = $school['school_name'];

$stmt = $conn->prepare("
    SELECT l.lrn, l.last_name, l.first_name, l.middle_name, l.birthday,
           g.first_name as g_first, g.last_name as g_last, g.contact_number, g.relationship,
           g.house_no_street, g.barangay,
           g.mother_first_name, g.mother_last_name, g.mother_contact_number, g.no_mother,
           g.father_first_name, g.father_last_name, g.father_contact_number, g.no_father
    FROM learners l
    LEFT JOIN guardians g ON l.guardian_id = g.id
    JOIN enrollments e ON l.lrn = e.lrn AND e.is_deleted = 0 AND e.is_historical = 0
    WHERE e.school_id = ? AND e.school_year = ?
      AND l.is_deleted = 0 AND l.lrn_is_temporary = 0
    ORDER BY l.last_name ASC, l.first_name ASC
");
$stmt->bind_param("is", $school_id, $school_year);
$stmt->execute();
$result = $stmt->get_result();
$learners = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $learners[] = $row;
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("SNED Data");

$sheet->setShowGridlines(true);

$sheet->setCellValue('A1', strtoupper($school_name) . ' — SNED DATA COLLECTION');
$sheet->mergeCells('A1:M1');

$sheet->setCellValue('A2', 'NO.');
$sheet->setCellValue('B2', 'LRN');
$sheet->setCellValue('C2', 'LEARNER SURNAME');
$sheet->setCellValue('D2', 'LEARNER FIRST NAME');
$sheet->setCellValue('E2', 'LEARNER MIDDLE NAME');
$sheet->setCellValue('F2', 'BIRTHDAY mm/dd/yyyy');
$sheet->setCellValue('G2', 'GUARDIAN SURNAME');
$sheet->setCellValue('H2', 'GUARDIAN FIRST NAME');
$sheet->setCellValue('I2', 'CONTACT NO.');
$sheet->setCellValue('J2', 'RELATIONSHIP');
$sheet->setCellValue('K2', 'ADDRESS');
$sheet->setCellValue('L2', 'MOTHER\'S NAME');
$sheet->setCellValue('M2', 'FATHER\'S NAME');

$headerStyle = [
    'font' => [
        'bold' => true,
        'size' => 10,
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

$sheet->getStyle('A1:M2')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(28);
$sheet->getRowDimension(2)->setRowHeight(24);

$rowIdx = 3;
$num = 1;
foreach ($learners as $row) {
    $address = trim($row['house_no_street'] . ', ' . $row['barangay']);
    $mother_name = !empty($row['no_mother']) ? '(No Mother)' : trim(($row['mother_last_name'] ?? '') . ', ' . ($row['mother_first_name'] ?? ''));
    $father_name = !empty($row['no_father']) ? '(No Father)' : trim(($row['father_last_name'] ?? '') . ', ' . ($row['father_first_name'] ?? ''));
    $sheet->setCellValue('A' . $rowIdx, $num);
    $sheet->setCellValueExplicit('B' . $rowIdx, $row['lrn'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $rowIdx, $row['last_name']);
    $sheet->setCellValue('D' . $rowIdx, $row['first_name']);
    $sheet->setCellValue('E' . $rowIdx, $row['middle_name'] ?: '');
    $sheet->setCellValue('F' . $rowIdx, date('m/d/Y', strtotime($row['birthday'])));
    $sheet->setCellValue('G' . $rowIdx, $row['g_last']);
    $sheet->setCellValue('H' . $rowIdx, $row['g_first']);
    $sheet->setCellValueExplicit('I' . $rowIdx, $row['contact_number'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('J' . $rowIdx, $row['relationship'] ?? '');
    $sheet->setCellValue('K' . $rowIdx, $address);
    $sheet->setCellValue('L' . $rowIdx, $mother_name);
    $sheet->setCellValue('M' . $rowIdx, $father_name);

    $sheet->getStyle('A' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('I' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $sheet->getStyle('A' . $rowIdx . ':M' . $rowIdx)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CBD5E1']
            ]
        ]
    ]);

    $rowIdx++;
    $num++;
}

foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

log_transaction($conn, $_SESSION['user_id'], 'print', 'learners', 'sned_school', "Exported SNED Data Collection for $school_name ($school_year)");

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $school_name);
header('Content-Disposition: attachment;filename="SNED-Data-Collection-' . $safe_name . '-' . $school_year . '-' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
