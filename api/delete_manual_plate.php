<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['plate_id'])) {
        throw new Exception('ID targa mancante');
    }
    
    $plateId = $data['plate_id'];
    
    // ✅ ELIMINA DAL DB
    $stmt = $db->prepare("DELETE FROM manual_plates_log WHERE plate_id = ?");
    $stmt->execute([$plateId]);
    
    error_log("✅ TARGA MANUALE ELIMINATA DAL DB: ID=$plateId");
    
    $response['success'] = true;
    $response['message'] = '✅ Targa manuale rimossa dal database';
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('DELETE_MANUAL_PLATE_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>