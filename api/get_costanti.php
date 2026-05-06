<?php
/**
 * get_costanti.php
 * Espone TUTTE le costanti caricate da costanti.txt
 * Usato da Modulo1 (lavaggi, ricariche, accessori, prodotti)
 * 
 * GET: ?t=timestamp (for cache busting)
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $costanti = $GLOBALS['COSTANTI'] ?? [];
    
    if (empty($costanti)) {
        throw new Exception('Costanti non caricate');
    }

    $response['success'] = true;
    $response['data'] = $costanti;

} catch (Throwable $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>