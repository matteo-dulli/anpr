<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $db = getDatabaseConnection();

  // prossimo id "preview": MAX(id)+1, se tabella vuota => 1
  $r = $db->query("SELECT COALESCE(MAX(id),0)+1 AS next_id FROM tesserapre");
  $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
  $nextId = (int)($row['next_id'] ?? 1);

  $response['success'] = true;
  $response['data'] = ['next_id' => $nextId];

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;