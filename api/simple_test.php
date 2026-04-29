<?php
require_once __DIR__ . '/escpos.php';

$GLOBALS['COSTANTI'] = [
  'PRINTER_NAME' => 'POS-80-Series',
  'PRINTER_SHARE' => '\\\\PC-TICKET\\POS-80',
  'BARCODE' => 'CODE128',
  'BARCODE_FONT' => 'Libre Barcode 128',
];

$txt = "TEST STAMPA\n====================\nBARCODE:\nFINE\n";
$res = escpos_print_txt_with_barcode($txt, '12345678901234');

header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);