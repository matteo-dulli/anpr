<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'ticket_code' => null];

try {
    $plateId = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
    
    if ($plateId <= 0) {
        throw new Exception('plate_id non valido');
    }

    $db = getDatabaseConnection();

    // Leggi DIRETTAMENTE da tickets_printed (non fare JOIN)
    $stmt = $db->prepare("
        SELECT ticket_code 
        FROM tickets_printed 
        WHERE plate_id = ? 
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$plateId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && !empty($result['ticket_code'])) {
        $response['ticket_code'] = $result['ticket_code'];
    }

    $response['success'] = true;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>