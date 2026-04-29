<?php
// api/update_plate.php - Aggiorna numero targa
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['plate_id']) || !isset($input['new_plate'])) {
        throw new Exception('plate_id e new_plate richiesti');
    }
    
    $plateId = (int)$input['plate_id'];
    $newPlate = strtoupper(trim($input['new_plate']));
    
    // Valida formato targa
    if (empty($newPlate) || strlen($newPlate) > 20) {
        throw new Exception('Formato targa non valido');
    }
    
    // Aggiorna database
    $db->prepare(
        "UPDATE plates SET modified_plate = ?, is_modified = TRUE WHERE id = ?"
    )->execute([$newPlate, $plateId]);
    
    $response['success'] = true;
    $response['message'] = 'Targa aggiornata';
    
} catch (Exception $e) {
    logError($e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>