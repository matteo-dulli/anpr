<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $plateId    = isset($body['plate_id'])   ? (int)$body['plate_id']   : 0;
    $Tannullato = isset($body['Tannullato']) ? (int)$body['Tannullato'] : 0;
    $Tannultxt  = isset($body['Tannultxt'])  ? trim($body['Tannultxt']) : '';
    $Tpaid      = isset($body['Tpaid'])      ? (int)$body['Tpaid']      : 0;

    $TpayC      = isset($body['TpayC'])      ? (int)$body['TpayC']      : 0;
    $TpayE      = isset($body['TpayE'])      ? (int)$body['TpayE']      : 0;

    // ✅ NEW: ingresso (da scheda targa) -> cassa.Tentry_date / cassa.Tentry_time
    $entryDate  = isset($body['entry_date']) ? $body['entry_date'] : null;
    $entryTime  = isset($body['entry_time']) ? $body['entry_time'] : null;

    $exitDate   = isset($body['exit_date'])  ? $body['exit_date']  : null;
    $exitTime   = isset($body['exit_time'])  ? $body['exit_time']  : null;

    if ($plateId <= 0) {
        throw new Exception("plate_id non valido");
    }

    // =========================
    // PATCH: normalizza e valida date/time vuote -> NULL (MySQL non accetta '')
    // =========================
    $normDate = function($d) {
        if (!is_string($d)) return null;
        $d = trim($d);
        if ($d === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        return $d;
    };
    $normTime = function($t) {
        if (!is_string($t)) return null;
        $t = trim($t);
        if ($t === '') return null;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return null;
        // normalizza HH:MM -> HH:MM:SS
        if (strlen($t) === 5) $t .= ':00';
        return $t;
    };

    $entryDate = $normDate($entryDate);
    $entryTime = $normTime($entryTime);
    $exitDate  = $normDate($exitDate);
    $exitTime  = $normTime($exitTime);

    // PATCH: Cerca il ticket_code più recente per plateId (OBBLIGATORIO per salvare su cassa)
    $stmtTicket = $db->prepare("SELECT ticket_code, entry_datetime FROM tickets_printed WHERE plate_id = ? ORDER BY id DESC LIMIT 1");
    $stmtTicket->execute([$plateId]);
    $ticket = $stmtTicket->fetch(PDO::FETCH_ASSOC);

    if (!$ticket || !$ticket['ticket_code']) {
        throw new Exception("Nessun ticket associato a questa targa: emetti prima un ticket");
    }

    $ticketCode = $ticket['ticket_code'];

    // PATCH: Cerca la riga cassa per esatto Tplate_id, Tticket_code!
    $stmtCassa = $db->prepare("SELECT idcassa FROM cassa WHERE Tplate_id = ? AND Tticket_code = ? LIMIT 1");
    $stmtCassa->execute([$plateId, $ticketCode]);
    $row = $stmtCassa->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // UPDATE
        // OLD (senza ingresso):
        // UPDATE cassa SET Tannullato=?, ... Texit_date=?, Texit_time=?, updated_at=NOW() WHERE idcassa=?

        // ✅ NEW: include anche Tentry_date / Tentry_time
        $stmtUpd = $db->prepare("UPDATE cassa SET 
            Tannullato = ?, 
            Tannultxt = ?, 
            Tpaid = ?,
            TpayC = ?,
            TpayE = ?,
            Tentry_date = ?,
            Tentry_time = ?,
            Texit_date = ?,
            Texit_time = ?,
            updated_at = NOW()
            WHERE idcassa = ?");

        $stmtUpd->execute([
            $Tannullato, $Tannultxt, $Tpaid, $TpayC, $TpayE,
            $entryDate, $entryTime,
            $exitDate, $exitTime,
            $row['idcassa']
        ]);

        $response['success'] = true;
        $response['message'] = '✅ Stato aggiornato (cassa UPDATE)';
        $response['data'] = [
            'idcassa' => (int)$row['idcassa'],
            'plate_id' => $plateId,
            'ticket_code' => $ticketCode,
            'action' => 'update'
        ];
    } else {
        // INSERT
        // ✅ NEW: include anche ingresso
        $stmtIns = $db->prepare("
            INSERT INTO cassa (
                Tplate_id, Tticket_code,
                Tannullato, Tannultxt, Tpaid, TpayC, TpayE,
                Tentry_date, Tentry_time,
                Texit_date, Texit_time,
                created_at, updated_at
            ) VALUES (
                ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                NOW(), NOW()
            )
        ");
        $stmtIns->execute([
            $plateId, $ticketCode,
            $Tannullato, $Tannultxt, $Tpaid, $TpayC, $TpayE,
            $entryDate, $entryTime,
            $exitDate, $exitTime
        ]);

        $response['success'] = true;
        $response['message'] = '✅ Stato salvato (cassa INSERT, con ticket_code)';
        $response['data'] = [
            'idcassa' => (int)$db->lastInsertId(),
            'plate_id' => $plateId,
            'ticket_code' => $ticketCode,
            'action' => 'insert'
        ];
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ Errore: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>