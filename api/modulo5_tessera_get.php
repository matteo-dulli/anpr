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
  $stmt = $db->prepare("SELECT * FROM tesserapre WHERE plate_number=? AND canc=0 ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  // normalizza (mai undefined lato JS)
  if ($row) {
    $row['id'] = (int)$row['id'];
    $row['res1'] = (float)($row['res1'] ?? 0);
    $row['attivo'] = (int)($row['attivo'] ?? 0);
    $row['Apay'] = (int)($row['Apay'] ?? 0);
    $row['SpayC'] = (int)($row['SpayC'] ?? 0);
    $row['SpayE'] = (int)($row['SpayE'] ?? 0);
    $row['canc'] = (int)($row['canc'] ?? 0);
  }

  $response['success'] = true;
  $response['data'] = $row ?: null;

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;