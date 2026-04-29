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
    
    // ===== RIMUOVI DA MANUALE =====
    $stmt = $db->prepare("DELETE FROM manual_plates WHERE plate_id = ?");
    $stmt->execute([$plateId]);
    
    logEvent('plate', "Targa rimossa da manuale: ID=$plateId");
    
    $response['success'] = true;
    $response['message'] = '✅ Targa rimossa da manuale';
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    logEvent('error', 'UNMARK_MANUAL_PLATE_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>