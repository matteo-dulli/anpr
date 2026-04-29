<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    
    $db = Database::getInstance()->getConnection();
    
    $response = [
        'connected' => true,
        'database' => DB_NAME,
        'host' => DB_HOST,
        'version' => $db->getAttribute(PDO::ATTR_SERVER_VERSION)
    ];
    
} catch (Exception $e) {
    $response = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>