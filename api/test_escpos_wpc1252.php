<?php
// api/test_escpos_wpc1252.php
// Test stampa ESC/POS: WPC1252 + € + taglio

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php'; // se qui dentro includi escpos.php ok
require_once __DIR__ . '/escpos.php';

header('Content-Type: application/json; charset=utf-8');

$n = isset($_GET['n']) ? (int)$_GET['n'] : 16;      // prova: 16, 17, 18, 19...
$hri = isset($_GET['hri']) ? (int)$_GET['hri'] : 1; // mostra testo sotto barcode

$txtUtf8 =
"================================\n" .
"TEST WPC1252\n" .
"--------------------------------\n" .
"Euro: € 12,34\n" .
"Accenti: àèìòù ÀÈÌÒÙ\n" .
"Simboli: ° ½ µ ñ ç\n" .
"--------------------------------\n" .
"TAGLIO A FINE PAGINA\n" .
"BARCODE:\n" .
"================================\n";

// Costruisci RAW a mano per forzare ESC t N e conversione
$raw = "";
$raw .= "\x1B\x40";            // init
$raw .= "\x1B\x74" . chr($n);  // ESC t n  (WPC1252 se n è quello giusto per la tua stampante)

// Converti testo in Windows-1252 (NOTA: fondamentale)
$txt1252 = iconv('UTF-8', 'Windows-1252//TRANSLIT', $txtUtf8);
if ($txt1252 === false) $txt1252 = $txtUtf8;

// Aggiungi il testo
$raw .= str_replace("\n", "\x0A", $txt1252);

// Barcode di test (CODE128)
$barcode = "TEST123456";
$raw .= "\x0A";
$raw .= "\x1B\x61\x01";                 // center
$raw .= ($hri ? "\x1D\x48\x02" : "\x1D\x48\x00"); // HRI
$raw .= "\x1D\x66\x00";                 // HRI font A
$raw .= "\x1D\x77" . chr(2);            // module width
$raw .= "\x1D\x68" . chr(80);           // height
$raw .= "\x1D\x6B\x49" . chr(strlen($barcode)) . $barcode;
$raw .= "\x0A\x0A";
$raw .= "\x1B\x61\x00";                 // left

// Taglio
$raw .= "\x0A\x0A";
$raw .= "\x1D\x56\x01"; // partial cut

// Stampa: usa la tua pipeline esistente
$cfg = escpos_get_printer_config();
if (($cfg['method'] ?? 'none') === 'none') {
  echo json_encode(['success'=>false,'message'=>'Stampante non configurata'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($cfg['method'] === 'tcp') {
  $res = escpos_print_tcp($cfg['ip'], $cfg['port'], $raw);
} else {
  $res = escpos_print_windows($cfg['printer'], $raw);
}

echo json_encode([
  'success' => $res['success'] ?? false,
  'message' => $res['message'] ?? '',
  'n_used'  => $n,
  'method'  => $cfg['method'],
], JSON_UNESCAPED_UNICODE);