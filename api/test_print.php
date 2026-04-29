<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';

$txt = "==== TEST BARCODE ====\nBARCODE: X\n======================\n";
$barcode = "T12345";

$r = escpos_print_txt_with_barcode($txt, $barcode);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);