<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'db_connected' => false];

try {
    // ===== TENTA CONNESSIONE CON RETRY ✅ =====
    $maxRetries = 3;
    $retry = 0;
    $connected = false;
    
    while ($retry < $maxRetries && !$connected) {
        try {
            $db = getDatabaseConnection();
            $stmt = $db->query("SELECT 1");
            $connected = true;
        } catch (Exception $e) {
            $retry++;
            if ($retry < $maxRetries) {
                sleep(1);
            }
        }
    }
    
    if ($connected) {
        $response['success'] = true;
        $response['db_connected'] = true;
        $response['message'] = '✅ Database connesso';
    } else {
        throw new Exception('DB non raggiungibile dopo ' . $maxRetries . ' tentativi');
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['db_connected'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    logEvent('error', 'CHECK_DB_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>