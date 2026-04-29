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

  $raw = file_get_contents('php://input');
  $b = $raw ? json_decode($raw, true) : null;
  if (!is_array($b)) throw new Exception('JSON non valido');

  $plateId = (int)($b['plate_id'] ?? 0);
  if ($plateId <= 0) throw new Exception('plate_id mancante');

  // ticket opzionale
  $ticketCode = trim((string)($b['ticket_code'] ?? ''));
  // nota obbligatoria (può anche essere stringa vuota se vuoi "cancellare")
  $note = (string)($b['note'] ?? '');

  $db = getDatabaseConnection();

  if ($ticketCode !== '') {
    // ✅ caso ticket presente: salva su tickets_printed.note
    $u = $db->prepare("
      UPDATE tickets_printed
      SET note = :note
      WHERE plate_id = :plate_id
        AND ticket_code = :ticket_code
      LIMIT 1
    ");
    $u->execute([
      'note' => $note,
      'plate_id' => $plateId,
      'ticket_code' => $ticketCode
    ]);

    if ($u->rowCount() === 0) {
      // ticket dichiarato ma non trovato: fallback su plates.note_io invece di errore
      $u2 = $db->prepare("
        UPDATE plates
        SET note_io = :note, updated_at = NOW()
        WHERE id = :id
        LIMIT 1
      ");
      $u2->execute(['note' => $note, 'id' => $plateId]);

      $response['success'] = true;
      $response['message'] = '✅ Nota salvata (fallback senza ticket)';
      $response['data'] = ['where' => 'plates.note_io', 'rows' => $u2->rowCount()];
    } else {
      $response['success'] = true;
      $response['message'] = '✅ Nota salvata su ticket';
      $response['data'] = ['where' => 'tickets_printed.note', 'rows' => $u->rowCount()];
    }
  } else {
    // ✅ caso ticket ASSENTE: salva su plates.note_io
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
    $response['message'] = '✅ Nota salvata (senza ticket)';
    $response['data'] = ['where' => 'plates.note_io', 'rows' => $u2->rowCount()];
  }

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;