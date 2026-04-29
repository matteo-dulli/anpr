<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $plateId = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
  if ($plateId <= 0) throw new Exception('plate_id non valido');

  $db = getDatabaseConnection();
  $stmt = $db->prepare("SELECT autor FROM plates WHERE id=? LIMIT 1");
  $stmt->execute([$plateId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Targa non trovata');

  $response['success'] = true;
  $response['data'] = ['autor' => $row['autor'] ?? ''];

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);