<?php
// /anpr/api/barcode.php?text=B0403&h=80&scale=2
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// libreria già presente nel repo
require_once __DIR__ . '/../composer/php-barcode-generator-main/src/BarcodeGenerator.php';
require_once __DIR__ . '/../composer/php-barcode-generator-main/src/BarcodeGeneratorPNG.php';

$text = isset($_GET['text']) ? trim((string)$_GET['text']) : '';
if ($text === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Missing text';
  exit;
}

$h = isset($_GET['h']) ? (int)$_GET['h'] : 70;
$h = max(30, min(200, $h));

$scale = isset($_GET['scale']) ? (int)$_GET['scale'] : 2;
$scale = max(1, min(6, $scale));

try {
  $gen = new Picqer\Barcode\BarcodeGeneratorPNG();

  // TYPE_CODE_128
  $png = $gen->getBarcode($text, $gen::TYPE_CODE_128, $scale, $h);

  header('Content-Type: image/png');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo $png;
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Barcode error: ' . $e->getMessage();
}