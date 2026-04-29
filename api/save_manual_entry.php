<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $plateId     = isset($body['plate_id']) ? (int)$body['plate_id'] : 0;
    $plateNumber = isset($body['plate_number']) ? trim((string)$body['plate_number']) : '';
    $entryDate   = isset($body['entry_date']) ? trim((string)$body['entry_date']) : '';
    $entryTime   = isset($body['entry_time']) ? trim((string)$body['entry_time']) : '';

    if ($plateId <= 0) throw new Exception("plate_id non valido");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) throw new Exception("entry_date non valido");
    if (!preg_match('/^\d{2}:\d{2}$/', $entryTime)) throw new Exception("entry_time non valido (atteso HH:MM)");

    // Costruisco datetime completo
    $entryDateTime = $entryDate . ' ' . $entryTime . ':00';

    // 1) Verifica targa e che sia MANUALE
    $stmt = $db->prepare("SELECT id, is_manual FROM plates WHERE id = ? LIMIT 1");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plate) throw new Exception("Targa non trovata");
    if ((int)$plate['is_manual'] !== 1) {
        // ✅ IMPORTANTISSIMO: non permettere patch su rilevate
        throw new Exception("Operazione consentita solo su targhe manuali");
    }

    // 2) Trova ultimo ticket_printed per quella targa
    $stmt = $db->prepare("
        SELECT id, ticket_code, entry_datetime
        FROM tickets_printed
        WHERE plate_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$plateId]);
    $tp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tp || empty($tp['ticket_code'])) {
        // Qui decidi tu la policy:
        // - o errore (consigliato): non puoi impostare ingresso senza ticket emesso
        // - o permetti solo tickets table e stop
        throw new Exception("Nessun ticket associato alla targa: emetti prima un ticket");
    }

    $tpId = (int)$tp['id'];
    $ticketCode = (string)$tp['ticket_code'];

    // 3) Se esiste già ricevuta -> blocca modifica ingresso
    $stmt = $db->prepare("
        SELECT invoice_code
        FROM cassa
        WHERE Tplate_id = ? AND Tticket_code = ?
        LIMIT 1
    ");
    $stmt->execute([$plateId, $ticketCode]);
    $cassa = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cassa && !empty($cassa['invoice_code'])) {
        throw new Exception("Ricevuta già emessa: ingresso non modificabile");
    }

    // 4) Salva su tickets (1 riga per plate_id)
    // Nota: la tua tabella tickets è unica per plate_id (OK)
    $db->beginTransaction();

    // Se la riga tickets non esiste ancora, la creiamo minimal
    // (in modo conservativo: NON tocchiamo altri campi)
    $stmt = $db->prepare("
        INSERT INTO tickets (plate_id, entry_date, entry_time)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            entry_date = VALUES(entry_date),
            entry_time = VALUES(entry_time)
    ");
    $stmt->execute([$plateId, $entryDate, $entryTime]);

    // 5) Allinea tickets_printed.entry_datetime dell'ultima riga (coerenza stampa)
    $stmt = $db->prepare("
        UPDATE tickets_printed
        SET entry_datetime = ?
        WHERE id = ?
    ");
    $stmt->execute([$entryDateTime, $tpId]);

    // 6) (Opzionale) Se esiste una riga cassa senza invoice_code, aggiorna Tentry_*
    // NON facciamo INSERT qui per non creare righe cassa “fantasma”
    if ($cassa) {
        $stmt = $db->prepare("
            UPDATE cassa
            SET Tentry_date = ?, Tentry_time = ?
            WHERE Tplate_id = ? AND Tticket_code = ?
            LIMIT 1
        ");
        $stmt->execute([$entryDate, $entryTime, $plateId, $ticketCode]);
    }

    $db->commit();

    $response['success'] = true;
    $response['message'] = '✅ Ingresso salvato (solo targa manuale)';
    $response['data'] = [
        'plate_id' => $plateId,
        'ticket_code' => $ticketCode,
        'entry_date' => $entryDate,
        'entry_time' => $entryTime,
        'entry_datetime' => $entryDateTime
    ];

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>