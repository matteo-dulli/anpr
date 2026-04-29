<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success'=>false,'message'=>'','data'=>[]];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $c = $GLOBALS['COSTANTI'] ?? [];
  $types = [];

  // ✅ NEW: supporto "ABBONAMENTI = A|B|C"
  if (!empty($c['ABBONAMENTI'])) {
    $parts = preg_split('/[|,;]/', (string)$c['ABBONAMENTI']);
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p !== '') $types[] = $p;
    }
  }

  // ✅ NEW: supporto chiavi ABBONAMENTO1..N o ABBONAMENTO_*
  foreach ($c as $k => $v) {
    $kk = strtoupper((string)$k);
    if (strpos($kk, 'ABBONAMENTO') === 0 && $kk !== 'ABBONAMENTI') {
      $vv = trim((string)$v);
      if ($vv !== '') $types[] = $vv;
    }
  }

  // fallback se vuoto
  if (empty($types)) {
    $types = ['Mensile', 'Trimestrale', 'Annuale'];
  }

  // unique
  $types = array_values(array_unique($types));

  $response['success'] = true;
  $response['data'] = $types;

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);