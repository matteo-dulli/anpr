<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
    $invoiceCode = isset($data['invoice_code']) ? $data['invoice_code'] : '';
    $entryDate = isset($data['entry_date']) && $data['entry_date'] !== '' ? $data['entry_date'] : null;
    $entryTime = isset($data['entry_time']) && $data['entry_time'] !== '' ? $data['entry_time'] : null;
    $exitDate = isset($data['exit_date']) && $data['exit_date'] !== '' ? $data['exit_date'] : null;
    $exitTime = isset($data['exit_time']) && $data['exit_time'] !== '' ? $data['exit_time'] : null;
    $price = isset($data['prezzo']) ? floatval($data['prezzo']) : (isset($data['price']) ? floatval($data['price']) : 0);
    $fascia = isset($data['fascia']) ? $data['fascia'] : 'F1';

    error_log('💾 update_plate_receipt: plate_id=' . $plateId . ', invoice_code=' . $invoiceCode . ', entry_date=' . $entryDate . ', entry_time=' . $entryTime . ', exit_date=' . $exitDate . ', exit_time=' . $exitTime . ', price=' . $price . ', fascia=' . $fascia);

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

    error_log('✅ Tickets aggiornato/inserito: plate_id=' . $plateId);

    // ===== 2. AGGIORNA O CREA RECORD IN CASSA =====
    // Controlla se esiste già un record per questa targa
    $checkStmt = $db->prepare("SELECT idcassa FROM cassa WHERE Tplate_id = ? LIMIT 1");
    $checkStmt->execute([$plateId]);
    $existingCassa = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingCassa) {
        // Aggiorna record esistente
        $stmt = $db->prepare("
            UPDATE cassa 
            SET 
                invoice_code = ?,
                Tentry_date = ?,
                Tentry_time = ?,
                Texit_date = ?,
                Texit_time = ?,
                prezzo = ?,
                fascia = ?
            WHERE Tplate_id = ?
        ");

        $stmt->execute([
            $invoiceCode,
            $entryDate,
            $entryTime,
            $exitDate,
            $exitTime,
            $price,
            $fascia,
            $plateId
        ]);
        
        error_log('✅ Cassa aggiornato (UPDATE): plate_id=' . $plateId);
    } else {
        // Crea nuovo record
        $stmt = $db->prepare("
            INSERT INTO cassa (
                Tplate_id,
                invoice_code,
                Tentry_date,
                Tentry_time,
                Texit_date,
                Texit_time,
                prezzo,
                fascia,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $plateId,
            $invoiceCode,
            $entryDate,
            $entryTime,
            $exitDate,
            $exitTime,
            $price,
            $fascia
        ]);
        
        error_log('✅ Cassa inserito (INSERT): plate_id=' . $plateId);
    }

    // ===== COMMIT =====
    $db->commit();

    $response['success'] = true;
    $response['message'] = '✅ Ricevuta targa salvata correttamente';

    error_log('✅ UPDATE_PLATE_RECEIPT completato: plate_id=' . $plateId . ', invoice_code=' . $invoiceCode);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('❌ UPDATE_PLATE_RECEIPT ERROR: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

http_response_code($response['success'] ? 200 : 500);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>