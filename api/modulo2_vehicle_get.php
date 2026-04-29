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

  $plateId = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
  if ($plateId <= 0) throw new Exception('plate_id mancante o non valido');

  $ticketCode = trim((string)($_GET['ticket_code'] ?? ''));
  $entryDatetime = trim((string)($_GET['entry_datetime'] ?? ''));

  $db = getDatabaseConnection();

  // 1) Recupero plate_number e date_detected dalla riga corrente
  $stmt = $db->prepare("SELECT id, plate_number, date_detected, tipo, marca, colore, notev, posizione FROM plates WHERE id = :id LIMIT 1");
  $stmt->execute(['id' => $plateId]);
  $cur = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$cur) throw new Exception('Targa non trovata per plate_id');

  $plateNumber = (string)$cur['plate_number'];
  $curDetected = (string)($cur['date_detected'] ?? '');

  if ($entryDatetime === '') $entryDatetime = $curDetected;

  // 2) Dati globali per targa: prendo l’ultima riga aggiornata per quella targa che abbia valori
  $stmtG = $db->prepare("
    SELECT tipo, marca, colore, notev
    FROM plates
    WHERE plate_number = :pn
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
  ");
  $stmtG->execute(['pn' => $plateNumber]);
  $g = $stmtG->fetch(PDO::FETCH_ASSOC) ?: [];

  // 3) Posizione di sessione:
  // - se c’è ticket_code e c’è tickets_printed che matcha plate_id+ticket_code => posizione dalla riga plates corrente (id)
  // - altrimenti uso direttamente plates.id (sessione corrente), perché la scheda è quella.
  //   (Questo è il comportamento più robusto lato UI: posizione è per quella scheda/ingresso)
  $posizioneSessione = (string)($cur['posizione'] ?? '');

  $response['success'] = true;
  $response['data'] = [
    'plate_id' => $plateId,
    'plate_number' => $plateNumber,
    'entry_datetime' => $entryDatetime,
    'ticket_code' => $ticketCode,

    // globali per targa
    'tipo' => (string)($g['tipo'] ?? ''),
    'marca' => (string)($g['marca'] ?? ''),
    'colore' => (string)($g['colore'] ?? ''),
    'notev' => (string)($g['notev'] ?? ''),

    // sessione (solo questa scheda)
    'posizione' => $posizioneSessione
  ];
} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;