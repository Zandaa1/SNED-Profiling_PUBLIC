<?php
session_start();
require 'config.php';
require_once __DIR__ . '/includes/school_roster_helpers.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : (int)($_GET['id'] ?? 0);
if ($school_id <= 0) {
    die('Please provide a school_id parameter.');
}

if (user_is_scoped($user_role) && $school_id !== (int)($_SESSION['school_id'] ?? 0)) {
    die('Access denied. You can only export data for your own school.');
}

$stmt = $conn->prepare('SELECT school_name, district, unit FROM schools WHERE id = ? AND is_deleted = 0 LIMIT 1');
$stmt->bind_param('i', $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
if (!$school) {
    die('School not found.');
}

$default_sy = school_roster_current_school_year();
$filters = school_roster_filters_from_request($_GET, $default_sy);
$mode = (($_GET['mode'] ?? 'filtered') === 'all') ? 'all' : 'filtered';
$learners = school_roster_fetch_learners($conn, $school_id, $filters, $mode !== 'all');

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle($mode === 'all' ? 'All Active Learners' : 'Filtered Learners');
$sheet->setShowGridlines(true);

$columns = [
    'No.',
    'LRN',
    'Last Name',
    'First Name',
    'Middle Name',
    'Extension',
    'Sex',
    'Birthdate',
    'Age',
    'Address',
    'Grade Level',
    'Placement',
    'Self-contained Level',
    'Disability / Diagnosis',
    'Assessment Mode',
    'Interpreter Needed',
];

$lastCol = Coordinate::stringFromColumnIndex(count($columns));
$sheet->setCellValue('A1', strtoupper($school['school_name']) . ' — ' . ($mode === 'all' ? 'ALL ACTIVE LEARNERS' : 'FILTERED ACTIVE LEARNERS'));
$sheet->mergeCells('A1:' . $lastCol . '1');
$sheet->setCellValue('A2', 'School Year: ' . $filters['school_year']);
$sheet->mergeCells('A2:' . $lastCol . '2');

$summary = $mode === 'all'
    ? ['Export mode: All active learners for selected school year']
    : school_roster_filter_summary($filters, false);
$sheet->setCellValue('A3', 'Filters: ' . (empty($summary) ? 'None' : implode(' | ', $summary)));
$sheet->mergeCells('A3:' . $lastCol . '3');

foreach ($columns as $idx => $label) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($idx + 1) . '4', $label);
}

$headerStyle = [
    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
];
$sheet->getStyle('A1:' . $lastCol . '4')->applyFromArray($headerStyle);
$sheet->getStyle('A1:' . $lastCol . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getRowDimension(1)->setRowHeight(24);
$sheet->getRowDimension(4)->setRowHeight(28);

$rowIdx = 5;
foreach ($learners as $idx => $learner) {
    $row = [
        $idx + 1,
        $learner['lrn'] ?? '',
        $learner['last_name'] ?? '',
        $learner['first_name'] ?? '',
        $learner['middle_name'] ?? '',
        $learner['extension_name'] ?? '',
        $learner['sex'] ?? '',
        !empty($learner['birthday']) ? date('m/d/Y', strtotime($learner['birthday'])) : '',
        $learner['age'] ?? '',
        school_roster_csv_address($learner),
        $learner['grade_level'] ?? '',
        $learner['placement_type'] ?? '',
        $learner['self_contained_level'] ?? '',
        $learner['disability_summary'] ?? ($learner['primary_diagnosis'] ?? 'Unassigned'),
        $learner['assessment_mode'] ?? '',
        $learner['interpreter_needed'] ?? '',
    ];

    foreach ($row as $colIdx => $value) {
        $cell = Coordinate::stringFromColumnIndex($colIdx + 1) . $rowIdx;
        if ($colIdx === 1) {
            $sheet->setCellValueExplicit($cell, (string)$value, DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($cell, $value);
        }
    }
    $sheet->getStyle('A' . $rowIdx . ':' . $lastCol . $rowIdx)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    $rowIdx++;
}

foreach (range(1, count($columns)) as $colIdx) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIdx))->setAutoSize(true);
}
$sheet->freezePane('A5');

log_transaction($conn, $_SESSION['user_id'], 'print', 'schools', $school_id, 'Exported ' . ($mode === 'all' ? 'all' : 'filtered') . ' school roster for ' . $school['school_name'] . ' (' . $filters['school_year'] . ')');

$safe_school_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $school['school_name']);
$filename = 'School-Roster-' . ($mode === 'all' ? 'All' : 'Filtered') . '-' . $safe_school_name . '-' . preg_replace('/[^0-9-]/', '_', $filters['school_year']) . '-' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
