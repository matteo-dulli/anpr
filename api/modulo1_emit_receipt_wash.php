<?php
/**
 * modulo1_emit_receipt_wash.php
 * Emette ricevuta per LAVAGGIO
 * - Salva in cassa, invoices, invoices_printed
 * - Stampa su stampante termica via ESCPOS
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';
require_once __DIR__ . '/modulo1_receipt_format.php';

$response = ['success' => false, 'message' => ''];

try {
    $db = getDatabaseConnection();
    $data = json_decode(file_get_contents('php://input'), true);

    // ===== VALIDAZIONI =====
    if (empty($data['id_lavaggio'])) {
        throw new Exception('id_lavaggio mancante');
    }

    $id_lavaggio = (int)$data['id_lavaggio'];
    $id_turno = isset($data['id_turno']) ? (int)$data['id_turno'] : null;

    // ===== CARICA DATI LAVAGGIO =====
    $stmt = $db->prepare("SELECT * FROM lavaggi WHERE id = ? LIMIT 1");
    $stmt->execute([$id_lavaggio]);
    $lavaggio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lavaggio) {
        throw new Exception('Lavaggio non trovato (ID: ' . $id_lavaggio . ')');
    }

    // Controlla se ricevuta già emessa
    if (!empty($lavaggio['receipt_emitted']) && (int)$lavaggio['receipt_emitted'] === 1) {
        throw new Exception('Ricevuta già emessa per questo lavaggio');
    }

    // Controlla se ticket già abbinato
    if (!empty($lavaggio['secondary_barcode'])) {
        throw new Exception('⚠️ Ticket già abbinato! Usa ricevuta della sosta per stampare.');
    }

    // ===== ESTRAI DATI LAVAGGIO =====
    $plate_number = trim((string)($lavaggio['plate_number'] ?? ''));
    $tipo_lavaggio = trim((string)($lavaggio['tipo_lavaggio'] ?? ''));
    $totale_lavaggio = (float)($lavaggio['totale_lavaggio'] ?? 0);
    $accessories = !empty($lavaggio['accessori_json']) ? json_decode($lavaggio['accessori_json'], true) : [];
    $products = !empty($lavaggio['prodotti_json']) ? json_decode($lavaggio['prodotti_json'], true) : [];

    // ===== GENERA CODICE RICEVUTA =====
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $receiptCode = 'R_' . $now->format('Ymd_His');
    $nowSql = $now->format('Y-m-d H:i:s');

    // ===== GENERA TESTO RICEVUTA =====
    $receiptText = generate_receipt_wash($receiptCode, $plate_number, $totale_lavaggio, $tipo_lavaggio, $accessories, $products);

    // ===== SALVA IN CASSA =====
    $stmt = $db->prepare("
        INSERT INTO cassa (
            id_lavaggio,
            tipo_servizio,
            plate_number,
            invoice_code,
            invoice_price,
            id_turno,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $id_lavaggio,
        'lavaggio',
        $plate_number,
        $receiptCode,
        $totale_lavaggio,
        $id_turno,
        $nowSql,
        $nowSql
    ]);

    $idCassa = $db->lastInsertId();

    // ===== SALVA IN INVOICES =====
    $stmt = $db->prepare("
        INSERT INTO invoices (receipt_code, price, id_turno, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$receiptCode, $totale_lavaggio, $id_turno, $nowSql, $nowSql]);

    $idInvoice = $db->lastInsertId();

    // ===== SALVA IN INVOICES_PRINTED =====
    $stmt = $db->prepare("
        INSERT INTO invoices_printed (
            receipt_code,
            id_lavaggio,
            tipo_servizio,
            price,
            id_turno,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $receiptCode,
        $id_lavaggio,
        'lavaggio',
        $totale_lavaggio,
        $id_turno,
        $nowSql
    ]);

    $idInvoicePrinted = $db->lastInsertId();

    // ===== AGGIORNA LAVAGGIO: MARCA COME RICEVUTA EMESSA =====
    $stmt = $db->prepare("
        UPDATE lavaggi
        SET receipt_emitted = 1, updated_at = ?
        WHERE id = ?
    ");
    $stmt->execute([$nowSql, $id_lavaggio]);

    // ===== STAMPA SU STAMPANTE TERMICA =====
    $printResult = escpos_print_txt_with_barcode($receiptText, $receiptCode, true);

    if (!$printResult['success']) {
        error_log('[modulo1_emit_receipt_wash] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
    }

    // ===== RISPOSTA =====
    $response['success'] = true;
    $response['message'] = '✅ Ricevuta lavaggio emessa e stampata';
    $response['data'] = [
        'receipt_code' => $receiptCode,
        'id_cassa' => $idCassa,
        'id_invoices' => $idInvoice,
        'id_invoices_printed' => $idInvoicePrinted,
        'total_price' => $totale_lavaggio,
        'print_success' => (bool)($printResult['success'] ?? false),
        'print_message' => (string)($printResult['message'] ?? '')
    ];

} catch (Throwable $e) {
    http_response_code(400);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('[modulo1_emit_receipt_wash] ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
