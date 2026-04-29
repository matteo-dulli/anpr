<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = ['success' => false, 'message' => '', 'data' => null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $raw = file_get_contents('php://input');
  $b = $raw ? json_decode($raw, true) : null;
  if (!is_array($b)) throw new Exception('JSON non valido');

  $plateId = (int)($b['plate_id'] ?? 0);
  if ($plateId <= 0) throw new Exception('plate_id mancante o non valido');

  $tipo = isset($b['tipo']) ? trim((string)$b['tipo']) : null;
  $marca = isset($b['marca']) ? trim((string)$b['marca']) : null;
  $colore = isset($b['colore']) ? trim((string)$b['colore']) : null;
  $notev = isset($b['notev']) ? trim((string)$b['notev']) : null;

  $posizione = isset($b['posizione']) ? trim((string)$b['posizione']) : null;

  $db = getDatabaseConnection();

  // Recupero la plate_number della scheda corrente
  $stmt = $db->prepare("SELECT plate_number, date_detected FROM plates WHERE id = :id LIMIT 1");
  $stmt->execute(['id' => $plateId]);
  $cur = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Targa non trovata per plate_id');

  $plateNumber = (string)$cur['plate_number'];

  $db->beginTransaction();

  // 1) Update globale per targa (tutte le righe con stessa plate_number)
  $updGlobal = $db->prepare("
    UPDATE plates
    SET tipo = :tipo,
        marca = :marca,
        colore = :colore,
        notev = :notev,
        updated_at = NOW()
    WHERE plate_number = :pn
  ");
  $updGlobal->execute([
    'tipo' => $tipo,
    'marca' => $marca,
    'colore' => $colore,
    'notev' => $notev,
    'pn' => $plateNumber
  ]);

  // 2) Update sessione (solo questa scheda/ingresso)
  $updSession = $db->prepare("
    UPDATE plates
    SET posizione = :posizione,
        updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ");
  $updSession->execute([
    'posizione' => $posizione,
    'id' => $plateId
  ]);

  $db->commit();

  $response['success'] = true;
  $response['message'] = '✅ Modulo2 salvato';
  $response['data'] = [
    'plate_id' => $plateId,
    'plate_number' => $plateNumber,
    'rows_global' => $updGlobal->rowCount(),
    'rows_session' => $updSession->rowCount()
  ];
} catch (Throwable $e) {
  if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;