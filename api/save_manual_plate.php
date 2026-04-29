<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['plate_id']) || !isset($data['plate_number'])) {
        throw new Exception('Dati mancanti');
    }

    $plateId = (int)$data['plate_id'];
    $plateNumber = trim((string)$data['plate_number']);

    // ✅ NEW: permetti salvataggio ingresso anche senza ticket (scheda manuale pre-ticket)
    $entryDate = isset($data['entry_date']) ? trim((string)$data['entry_date']) : null;
    $entryTime = isset($data['entry_time']) ? trim((string)$data['entry_time']) : null;

    if (is_string($entryDate) && $entryDate === '') $entryDate = null;
    if (is_string($entryTime) && $entryTime === '') $entryTime = null;

    // Validazione solo se presenti
    if ($entryDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        throw new Exception('entry_date non valida');
    }
    // input type="time" di solito manda HH:MM
    if ($entryTime !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $entryTime)) {
        throw new Exception('entry_time non valida');
    }

    // ✅ VERIFICA SE ESISTE GIÀ
    $checkStmt = $db->prepare("SELECT id FROM manual_plates_log WHERE plate_id = ? LIMIT 1");
    $checkStmt->execute([$plateId]);

    if ($checkStmt->rowCount() === 0) {
        // ==========================
        // OLD: inseriva solo plate_id/plate_number
        // ==========================
        // $stmt = $db->prepare("
        //     INSERT INTO manual_plates_log (plate_id, plate_number, created_at, updated_at)
        //     VALUES (?, ?, NOW(), NOW())
        // ");
        // $stmt->execute([$plateId, $plateNumber]);

        // ✅ NEW: inserisce anche entry_date/entry_time (se la tabella ha queste colonne)
        $stmt = $db->prepare("
            INSERT INTO manual_plates_log (plate_id, plate_number, entry_date, entry_time, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$plateId, $plateNumber, $entryDate, $entryTime]);

        error_log("✅ TARGA MANUALE SALVATA NEL DB: ID=$plateId, Targa=$plateNumber");
        $response['success'] = true;
        $response['message'] = '✅ Targa manuale registrata nel database';
    } else {
        // ==========================
        // OLD: non aggiorna niente
        // ==========================
        // $response['success'] = true;
        // $response['message'] = '✅ Targa manuale già registrata';

        // ✅ NEW: aggiorna anche ingresso (se presente) + updated_at
        $stmtUpd = $db->prepare("
            UPDATE manual_plates_log
            SET
                plate_number = ?,
                entry_date = COALESCE(?, entry_date),
                entry_time = COALESCE(?, entry_time),
                updated_at = NOW()
            WHERE plate_id = ?
            LIMIT 1
        ");
        $stmtUpd->execute([$plateNumber, $entryDate, $entryTime, $plateId]);

        $response['success'] = true;
        $response['message'] = '✅ Targa manuale aggiornata';
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('SAVE_MANUAL_PLATE_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>