<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : 'B0403';

// testo minimo con BARCODE:
$txt = "===\nTEST BARCODE\n---\nBARCODE:\n===\n";

// prova a stampare
$res = escpos_print_txt_with_barcode($txt, $code);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);