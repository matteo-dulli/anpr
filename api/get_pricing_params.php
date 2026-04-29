<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'data' => [], 'message' => ''];

try {
    $fascia = isset($_GET['fascia']) ? trim($_GET['fascia']) : 'F1';
    
    if (!in_array($fascia, ['F1', 'F2', 'F3', 'F4', 'F5'])) {
        throw new Exception('Fascia non valida: ' . $fascia);
    }

    // ===== CARICA COSTANTI =====
    $costanti = $GLOBALS['COSTANTI'];

    // ===== ESTRAI NUMERO FASCIA (F1 -> 1) =====
    preg_match('/F(\d)/', $fascia, $matches);
    $num = $matches[1] ?? 1;

    // ===== LEGGI PARAMETRI DELLA FASCIA =====
    $nore = (int)($costanti['Nore'] ?? 5);
    $tolleranza = (int)($costanti["TolleranzaF$num"] ?? 5);

    error_log('✅ GET_PRICING_PARAMS: fascia=' . $fascia . ', nore=' . $nore . ', tolleranza=' . $tolleranza);

    $response['success'] = true;
    $response['data'] = [
        'fascia' => $fascia,
        'nore' => $nore,
        'tolleranza' => $tolleranza
    ];
    $response['message'] = '✅ Parametri caricati da costanti.txt';

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('GET_PRICING_PARAMS ERROR: ' . $e->getMessage());
    
    // ===== FALLBACK =====
    $response['data'] = [
        'fascia' => 'F1',
        'nore' => 5,
        'tolleranza' => 5
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>