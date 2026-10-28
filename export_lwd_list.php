<?php
session_start();
require 'config.php';
require_once __DIR__ . '/includes/export_report_helpers.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access. Please log in.');
}

$user_role = $_SESSION['role'] ?? 'user';
if (!user_is_coordinator($user_role)) {
    die('Access denied. Insufficient permissions.');
}

$scope = strtolower(trim((string)($_GET['scope'] ?? '')));
$requested_school_id = isset($_GET['school_id']) && $_GET['school_id'] !== '' ? (int)$_GET['school_id'] : null;
$require_school = $scope === 'school';

[$school_id, $school] = sned_export_resolve_school_scope($conn, $user_role, $requested_school_id, $require_school);
if ($school_id === null && user_is_scoped($user_role)) {
    [$school_id, $school] = sned_export_resolve_school_scope($conn, $user_role, (int)($_SESSION['school_id'] ?? 0), true);
}

$has_school_year_param = isset($_GET['school_year']) && trim((string)$_GET['school_year']) !== '';
$school_year = ($school_id !== null || $has_school_year_param)
    ? sned_export_normalize_school_year($_GET['school_year'] ?? null)
    : null;

$learners = sned_export_fetch_lwd_rows($conn, $school_id, $school_year);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('LWD List');
$sheet->setShowGridlines(true);

$sheet->setCellValue('A1', '');
$sheet->setCellValue('B1', 'NAME OF GUARDIANS');
$sheet->mergeCells('B1:D1');
$sheet->setCellValue('E1', '');
$sheet->mergeCells('E1:G1');
$sheet->setCellValue('H1', 'NAME OF SNED LEARNER');
$sheet->mergeCells('H1:J1');
$sheet->setCellValue('K1', '');
$sheet->setCellValue('L1', '');

$headers = [
    'A2' => 'NO.',
    'B2' => 'SURNAME',
    'C2' => 'FIRST NAME',
    'D2' => 'MIDDLE NAME',
    'E2' => 'CONTACT NO.',
    'F2' => 'ADDRESS',
    'G2' => 'BARANGAY',
    'H2' => 'SURNAME',
    'I2' => 'FIRST NAME',
    'J2' => 'MIDDLE NAME',
    'K2' => 'Diagnosis',
    'L2' => 'Placement',
];
foreach ($headers as $cell => $label) {
    $sheet->setCellValue($cell, $label);
}

$headerStyle = [
    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
];
$sheet->getStyle('A1:L2')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(24);
$sheet->getRowDimension(2)->setRowHeight(28);

$rowIdx = 3;
$num = 1;
foreach ($learners as $row) {
    $sheet->setCellValue('A' . $rowIdx, $num);
    $sheet->setCellValue('B' . $rowIdx, $row['g_last_name'] ?? '');
    $sheet->setCellValue('C' . $rowIdx, $row['g_first_name'] ?? '');
    $sheet->setCellValue('D' . $rowIdx, $row['g_middle_name'] ?? '');
    $sheet->setCellValueExplicit('E' . $rowIdx, (string)($row['contact_number'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('F' . $rowIdx, $row['g_house_no_street'] ?? '');
    $sheet->setCellValue('G' . $rowIdx, $row['g_barangay'] ?? '');
    $sheet->setCellValue('H' . $rowIdx, $row['l_last_name'] ?? '');
    $sheet->setCellValue('I' . $rowIdx, $row['l_first_name'] ?? '');
    $sheet->setCellValue('J' . $rowIdx, $row['l_middle_name'] ?? '');
    $sheet->setCellValue('K' . $rowIdx, $row['disability_summary'] ?? 'Unassigned');
    $sheet->setCellValue('L' . $rowIdx, sned_export_placement_label($row));

    $sheet->getStyle('A' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('E' . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('A' . $rowIdx . ':L' . $rowIdx)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);

    $rowIdx++;
    $num++;
}

foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A3');

$target_label = $school ? $school['school_name'] : 'Master';
$year_label = $school_year ? ' (' . $school_year . ')' : '';
log_transaction($conn, $_SESSION['user_id'], 'print', 'learners', 'lwd_list', 'Exported LWD Excel List for ' . $target_label . $year_label);

$filename_parts = ['LIST-OF-LWDs'];
if ($school) {
    $filename_parts[] = sned_export_safe_filename_part($school['school_name'], 'School');
}
if ($school_year) {
    $filename_parts[] = sned_export_safe_filename_part($school_year, 'SY');
}
$filename_parts[] = date('Ymd');
$filename = implode('-', $filename_parts) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
