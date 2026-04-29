<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'data' => []];

try {
    // ✅ CARICA TUTTI GLI ID DELLE TARGHE MANUALI DAL DB
    $stmt = $db->prepare("SELECT plate_id FROM manual_plates_log ORDER BY created_at DESC");
    $stmt->execute();
    
    $manualIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $manualIds[] = (int)$row['plate_id'];
    }
    
    $response['success'] = true;
    $response['data'] = $manualIds;
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('GET_MANUAL_PLATES_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>