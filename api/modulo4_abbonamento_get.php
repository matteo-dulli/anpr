<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $plate = trim((string)($_GET['plate_number'] ?? ''));
  if ($plate === '') throw new Exception('plate_number mancante');

  $db = getDatabaseConnection();
  $stmt = $db->prepare("SELECT * FROM abbonamenti WHERE plate_number=? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $response['success'] = true;
    $response['data'] = null;
  } else {
    $response['success'] = true;
    $response['data'] = $row;
  }

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);