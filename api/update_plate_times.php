<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['plate_id'])) {
        throw new Exception('ID targa mancante');
    }
    
    $plateId = (int)$data['plate_id'];
    $entryDate = isset($data['entry_date']) && $data['entry_date'] !== '' ? $data['entry_date'] : null;
    $entryTime = isset($data['entry_time']) && $data['entry_time'] !== '' ? $data['entry_time'] : null;
    $exitDate = isset($data['exit_date']) && $data['exit_date'] !== '' ? $data['exit_date'] : null;
    $exitTime = isset($data['exit_time']) && $data['exit_time'] !== '' ? $data['exit_time'] : null;
    $price = isset($data['price']) ? floatval($data['price']) : 0;

    // ===== INIZIA TRANSAZIONE =====
    $db->beginTransaction();

    // ===== 1. AGGIORNA TICKET CON INGRESSO/USCITA =====
    $stmt = $db->prepare("
        INSERT INTO tickets (
            plate_id,
            entry_date,
            entry_time,
            exit_date,
            exit_time
        ) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            entry_date = VALUES(entry_date),
            entry_time = VALUES(entry_time),
            exit_date = VALUES(exit_date),
            exit_time = VALUES(exit_time)
    ");

    $stmt->execute([
        $plateId,
        $entryDate,
        $entryTime,
        $exitDate,
        $exitTime
    ]);

    // ===== 2. AGGIORNA CASSA (se esiste record) =====
    $stmt = $db->prepare("
        UPDATE cassa 
        SET 
            Tentry_date = ?,
            Tentry_time = ?,
            Texit_date = ?,
            Texit_time = ?,
            prezzo = ?
        WHERE Tplate_id = ?
    ");

    $stmt->execute([
        $entryDate,
        $entryTime,
        $exitDate,
        $exitTime,
        $price,
        $plateId
    ]);

    // ===== COMMIT =====
    $db->commit();

    $response['success'] = true;
    $response['message'] = '✅ Dati ingresso/uscita targa salvati correttamente';

    if (function_exists('logEvent')) {
        logEvent('plate', "Ingresso/uscita targa aggiornato: ID=$plateId");
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'UPDATE_PLATE_TIMES_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
