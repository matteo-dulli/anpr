<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $plateId = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
  if ($plateId <= 0) throw new Exception('plate_id mancante');

  $ticketCode = trim((string)($_GET['ticket_code'] ?? ''));

  $db = getDatabaseConnection();

  // 1) se c'è ticket_code provo tickets_printed.note
  if ($ticketCode !== '') {
    $s = $db->prepare("SELECT note FROM tickets_printed WHERE plate_id = :pid AND ticket_code = :tc LIMIT 1");
    $s->execute(['pid'=>$plateId, 'tc'=>$ticketCode]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $response['success'] = true;
      $response['data'] = ['note' => (string)($r['note'] ?? '')];
      echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    // se non trovato, fallback sotto (non errore)
  }

  // 2) fallback senza ticket: plates.note_io
  $s2 = $db->prepare("SELECT note_io FROM plates WHERE id = :id LIMIT 1");
  $s2->execute(['id'=>$plateId]);
  $r2 = $s2->fetch(PDO::FETCH_ASSOC);

  $response['success'] = true;
  $response['data'] = ['note_io' => (string)($r2['note_io'] ?? '')];

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;