<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';

$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $raw = file_get_contents('php://input');
  $b = $raw ? json_decode($raw, true) : null;
  if (!is_array($b)) throw new Exception('JSON non valido');

  $plateId = (int)($b['plate_id'] ?? 0);
  if ($plateId <= 0) throw new Exception('plate_id mancante');

  $ticketCode = trim((string)($b['ticket_code'] ?? ''));
  $note = trim((string)($b['note'] ?? ''));

  $db = getDatabaseConnection();

  if ($ticketCode !== '') {
    // salva su tickets_printed se ticket esiste
    $u = $db->prepare("
      UPDATE tickets_printed
      SET note = :note
      WHERE plate_id = :plate_id AND ticket_code = :ticket_code
      LIMIT 1
    ");
    $u->execute([
      'note' => $note,
      'plate_id' => $plateId,
      'ticket_code' => $ticketCode
    ]);

    if ($u->rowCount() === 0) {
      throw new Exception('Ticket non trovato per plate_id + ticket_code');
    }

    $response['success'] = true;
    $response['message'] = '✅ Nota salvata su ticket';
    $response['data'] = ['where' => 'tickets_printed', 'rows' => $u->rowCount()];
  } else {
    // ✅ fallback: salva senza ticket sulla riga plates (serve colonna note_io)
    $u2 = $db->prepare("
      UPDATE plates
      SET note_io = :note, updated_at = NOW()
      WHERE id = :id
      LIMIT 1
    ");
    $u2->execute([
      'note' => $note,
      'id' => $plateId
    ]);

    $response['success'] = true;
    $response['message'] = '✅ Nota salvata sulla sessione (senza ticket)';
    $response['data'] = ['where' => 'plates.note_io', 'rows' => $u2->rowCount()];
  }
} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;