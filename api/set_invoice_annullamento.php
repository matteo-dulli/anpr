<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $receiptCode = isset($body['receipt_code']) ? trim((string)$body['receipt_code']) : '';
    $Tannullato  = isset($body['Tannullato']) ? (int)$body['Tannullato'] : 0;
    $Tannultxt   = isset($body['Tannultxt']) ? trim((string)$body['Tannultxt']) : '';

    if ($receiptCode === '') throw new Exception("receipt_code mancante");

    // ✅ PATCH: aggiorna invoices_printed (campi richiesti da te)
    // NB: serve che la tabella abbia colonne Tannullato/Tannultxt.
    $stmt = $db->prepare("
        UPDATE invoices_printed
        SET Tannullato = ?, Tannultxt = ?
        WHERE receipt_code = ?
        LIMIT 1
    ");
    $stmt->execute([$Tannullato, $Tannultxt, $receiptCode]);

    // ✅ (opzionale ma consigliato) allinea anche cassa sulla stessa ricevuta
    $stmt2 = $db->prepare("
        UPDATE cassa
        SET Tannullato = ?, Tannultxt = ?, updated_at = NOW()
        WHERE invoice_code = ?
    ");
    $stmt2->execute([$Tannullato, $Tannultxt, $receiptCode]);

    $response['success'] = true;
    $response['message'] = '✅ Annullamento aggiornato su ricevuta';
    $response['data'] = ['receipt_code' => $receiptCode];

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);