<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) throw new Exception('JSON non valido');

  $plateId = isset($body['plate_id']) ? (int)$body['plate_id'] : 0;
  if ($plateId <= 0) throw new Exception('plate_id mancante');

  $tipo   = trim((string)($body['tipo'] ?? ''));
  $marca  = trim((string)($body['marca'] ?? ''));
  $colore = trim((string)($body['colore'] ?? ''));

  // ✅ Campo DB è "notev" (non "noteve")
  // ✅ Per compatibilità, accetto sia "notev" che "noteve" dal frontend
  $notev = '';
  if (array_key_exists('notev', $body)) {
    $notev = trim((string)$body['notev']);
  } else if (array_key_exists('noteve', $body)) {
    $notev = trim((string)$body['noteve']);
  }

  $db = getDatabaseConnection();

  // ricavo plate_number dalla riga corrente
  $stmt = $db->prepare("SELECT plate_number FROM plates WHERE id = :id LIMIT 1");
  $stmt->execute(['id' => $plateId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || empty($row['plate_number'])) throw new Exception('Targa non trovata per plate_id');

  $plateNumber = $row['plate_number'];

  // aggiorno tutti i record di quella targa
  $upd = $db->prepare("
    UPDATE plates
    SET tipo = :tipo,
        marca = :marca,
        colore = :colore,
        notev = :notev,
        updated_at = NOW()
    WHERE plate_number = :pn
  ");

  $upd->execute([
    'tipo' => $tipo,
    'marca' => $marca,
    'colore' => $colore,
    'notev' => $notev,
    'pn' => $plateNumber
  ]);

  $response['success'] = true;
  $response['message'] = "OK (aggiornate " . $upd->rowCount() . " righe per $plateNumber)";
  $response['data'] = ['plate_number' => $plateNumber, 'rows' => $upd->rowCount()];
} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;