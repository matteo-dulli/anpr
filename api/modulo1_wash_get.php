<?php
/**
 * modulo1_wash_get.php
 * Recupera dati di lavaggio per un ticket (secondary_barcode)
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
            tipo_lavaggio,
            prezzo_lavaggio,
            accessori_json,
            totale_accessori,
            prodotti_json,
            totale_prodotti,
            totale_lavaggio
        FROM lavaggi
        WHERE secondary_barcode = ?
        LIMIT 1
    ");
    $stmt->execute([$secondaryBarcode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Parse JSON arrays
        $row['accessori'] = json_decode($row['accessori_json'], true) ?? [];
        $row['prodotti'] = json_decode($row['prodotti_json'], true) ?? [];
        unset($row['accessori_json']);
        unset($row['prodotti_json']);

        $response['success'] = true;
        $response['data'] = $row;
    } else {
        $response['success'] = true;
        $response['data'] = null; // No wash record yet
    }

} catch (Throwable $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>