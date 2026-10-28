<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;

$lrn = $_GET['lrn'] ?? '';
if (!preg_match('/^\d{12}$/', $lrn)) {
    http_response_code(400);
    exit('Invalid LRN');
}

header('Content-Type: image/svg+xml');

$options = new QROptions([
    'eccLevel' => EccLevel::M,
    'addQuietzone' => false,
    'outputType' => QRCode::OUTPUT_MARKUP_SVG,
]);

$qrcode = new QRCode($options);
echo $qrcode->render($lrn);
