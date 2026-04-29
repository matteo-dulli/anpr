<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'plate_id' => null];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['plate_number'])) {
        throw new Exception('Numero targa mancante');
    }
    
    $plateNumber = strtoupper(trim($data['plate_number']));
    
    // ===== INSERISCI NUOVA TARGA =====
    $stmt = $db->prepare("
        INSERT INTO plates 
        (plate_number, plate_corrected, date_detected, is_manual, created_at, updated_at)
        VALUES (?, ?, NOW(), 1, NOW(), NOW())
    ");
    
    $stmt->execute([$plateNumber, $plateNumber]);
    $plateId = $db->lastInsertId();
    
    // ===== REGISTRA SUBITO COME MANUALE =====
    $manualStmt = $db->prepare("
        INSERT INTO manual_plates (plate_id, plate_number, created_at)
        VALUES (?, ?, NOW())
    ");
    
    $manualStmt->execute([$plateId, $plateNumber]);
    
    // ===== CREA TICKET VUOTO =====
    $ticketStmt = $db->prepare("
        INSERT INTO tickets 
        (plate_id, created_at, updated_at)
        VALUES (?, NOW(), NOW())
    ");
    
    $ticketStmt->execute([$plateId]);
    
    $response['success'] = true;
    $response['message'] = '✅ Targa manuale creata';
    $response['plate_id'] = (int)$plateId;
    
    logEvent('plate', "Targa manuale creata: ID=$plateId, Targa=$plateNumber");
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    logEvent('error', 'CREATE_MANUAL_TICKET_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>