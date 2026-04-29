<?php
// api/check_plates_count.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM plates");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt2 = $db->prepare("SELECT * FROM plates LIMIT 5");
    $stmt2->execute();
    $samples = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_plates' => $result['total'],
        'samples' => $samples
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>