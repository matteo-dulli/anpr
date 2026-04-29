<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $path = dirname(__DIR__) . '/costanti.txt';
  if (!file_exists($path)) throw new Exception('costanti.txt non trovato: ' . $path);

  $txt = file_get_contents($path);
  if ($txt === false) throw new Exception('Impossibile leggere costanti.txt');

  $lines = preg_split("/\r\n|\n|\r/", $txt);

  // parser KEY = VALUE
  $kv = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;

    $parts = preg_split('/\s*=\s*/', $line, 2);
    if (count($parts) !== 2) continue;

    $k = trim($parts[0]);
    $v = trim($parts[1]);
    $kv[$k] = $v;
  }

  $n = isset($kv['N_FASCE_SCALARE']) ? (int)$kv['N_FASCE_SCALARE'] : 5;

  $out = [];
  for ($i = 1; $i <= $n; $i++) {
    $cod = 'F' . $i;

    // ✅ nuove chiavi dedicate alla scalare
    $testoKey = 'TestoF' . $i . 'S';
    $prezzoKey = 'PrezzoF' . $i . 'S';

    $testo = $kv[$testoKey] ?? '';
    $prezzo = isset($kv[$prezzoKey]) ? (float)str_replace(',', '.', $kv[$prezzoKey]) : null;

    $label = $cod;
    if ($testo !== '') $label .= ' - ' . $testo;
    if ($prezzo !== null) $label .= ' - €' . number_format($prezzo, 2, '.', '') . '/h';

    $out[] = [
      'codice' => $cod,
      'testo' => $testo,
      'prezzo' => $prezzo,
      'label' => $label
    ];
  }

  // soglia scalare (opzionale, utile UI)
  $soglia = null;
  if (isset($kv['SOGLIA_SCALARE'])) $soglia = (float)str_replace(',', '.', $kv['SOGLIA_SCALARE']);
  else if (isset($kv['SOGLIA'])) $soglia = (float)str_replace(',', '.', $kv['SOGLIA']);

  $response['success'] = true;
  $response['data'] = $out;
  $response['soglia'] = $soglia;

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;