<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['plate_id']) || !isset($data['plate_number'])) {
        throw new Exception('Dati mancanti');
    }
    
    $plateId = (int)$data['plate_id'];
    $plateNumber = $data['plate_number'];
    
    // ===== VERIFICA SE ESISTE GIÀ =====
    $checkStmt = $db->prepare("SELECT id FROM manual_plates WHERE plate_id = ?");
    $checkStmt->execute([$plateId]);
    
    if ($checkStmt->rowCount() === 0) {
        // ===== INSERISCI COME MANUALE =====
        $stmt = $db->prepare("
            INSERT INTO manual_plates (plate_id, plate_number, created_at)
            VALUES (?, ?, NOW())
        ");
        
        $stmt->execute([$plateId, $plateNumber]);
        logEvent('plate', "Targa segnata come manuale: ID=$plateId, Targa=$plateNumber");
    }
    
    $response['success'] = true;
    $response['message'] = '✅ Targa segnata come manuale';
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    logEvent('error', 'MARK_MANUAL_PLATE_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>