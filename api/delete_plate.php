<?php
header('Content-Type: application/json');
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
    $reason = $data['reason'] ?? 'Cancellazione manuale';
    $deletedBy = $data['deleted_by'] ?? 'web';

    // Inizio transazione
    $db->beginTransaction();

    // ===== 1. RECUPERA INFO TARGA =====
    $stmt = $db->prepare("SELECT plate_number, date_detected FROM plates WHERE id = ? LIMIT 1");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plate) {
        throw new Exception('Targa non trovata');
    }

    $plateNumber = $plate['plate_number'];

    // ===== 2. SALVA IN deletion_logs =====
    $logStmt = $db->prepare("
        INSERT INTO deletion_logs (plate_id, plate_number, date_detected, reason, deleted_by, deleted_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $logStmt->execute([$plateId, $plateNumber, $plate['date_detected'], $reason, $deletedBy]);

    // ===== 3. ELIMINA TICKET ASSOCIATO =====
    $delTicket = $db->prepare("DELETE FROM tickets WHERE plate_id = ? LIMIT 1");
    $delTicket->execute([$plateId]);

    // ===== 4. ELIMINA TICKET_PRINTED ASSOCIATO =====
    $delPrinted = $db->prepare("DELETE FROM tickets_printed WHERE plate_id = ? LIMIT 1");
    $delPrinted->execute([$plateId]);

    // ===== 5. ELIMINA DA manual_plates SE MANUALE =====
    $delManual = $db->prepare("DELETE FROM manual_plates WHERE plate_id = ? LIMIT 1");
    $delManual->execute([$plateId]);

    // ===== 6. ELIMINA LA TARGA =====
    $delStmt = $db->prepare("DELETE FROM plates WHERE id = ? LIMIT 1");
    $delStmt->execute([$plateId]);

    // Commit
    $db->commit();

    $response['success'] = true;
    $response['message'] = "✅ Targa $plateNumber eliminata correttamente";

    if (function_exists('logEvent')) {
        logEvent('deletion', "Targa eliminata: ID=$plateId NUMERO=$plateNumber MOTIVO=$reason");
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'DELETE_PLATE_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>