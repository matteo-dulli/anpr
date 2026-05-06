<?php
/**
 * modulo1_recharge_get.php
 * Recupera dati di ricarica per un ticket (secondary_barcode)
 * 
 * GET: ?secondary_barcode=XXX
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $secondaryBarcode = isset($_GET['secondary_barcode']) ? trim((string)$_GET['secondary_barcode']) : '';
    if (empty($secondaryBarcode)) throw new Exception('secondary_barcode mancante');

    $db = getDatabaseConnection();

    $stmt = $db->prepare("
        SELECT
            id,
            tipo_ricarica,
            prezzo_ricarica,
            quantita_ore,
            totale_ricarica
        FROM ricariche
        WHERE secondary_barcode = ?
        LIMIT 1
    ");
    $stmt->execute([$secondaryBarcode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $response['success'] = true;
        $response['data'] = $row;
    } else {
        $response['success'] = true;
        $response['data'] = null; // No recharge record yet
    }

} catch (Throwable $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>