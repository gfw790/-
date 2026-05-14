<?php
declare(strict_types=1);

require_once __DIR__ . '/../tbm/phpqrcode/qrlib.php';

$data = trim((string)($_GET['data'] ?? ''));
if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'QR data is required.';
    exit;
}

header('Content-Type: image/png');
QRcode::png($data, null, QR_ECLEVEL_M, 4, 1);
