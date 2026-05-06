<?php
/**
 * modulo1_emit_receipt_recharge.php
 * Emette ricevuta per RICARICA
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
    if (empty($data['id_ricarica'])) {
        throw new Exception('id_ricarica mancante');
    }

    $id_ricarica = (int)$data['id_ricarica'];
    $id_turno = isset($data['id_turno']) ? (int)$data['id_turno'] : null;

    // ===== CARICA DATI RICARICA =====
    $stmt = $db->prepare("SELECT * FROM ricariche WHERE id = ? LIMIT 1");
    $stmt->execute([$id_ricarica]);
    $ricarica = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ricarica) {
        throw new Exception('Ricarica non trovata (ID: ' . $id_ricarica . ')');
    }

    // Controlla se ricevuta già emessa
    if (!empty($ricarica['receipt_emitted']) && (int)$ricarica['receipt_emitted'] === 1) {
        throw new Exception('Ricevuta già emessa per questa ricarica');
    }

    // ===== ESTRAI DATI RICARICA =====
    $plate_number = trim((string)($ricarica['plate_number'] ?? ''));
    $tipo_ricarica = trim((string)($ricarica['tipo_ricarica'] ?? ''));
    $quantita_ore = (float)($ricarica['quantita_ore'] ?? 0);
    $totale_ricarica = (float)($ricarica['totale_ricarica'] ?? 0);

    // ===== GENERA CODICE RICEVUTA =====
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $receiptCode = 'R_' . $now->format('Ymd_His');
    $nowSql = $now->format('Y-m-d H:i:s');

    // ===== GENERA TESTO RICEVUTA =====
    $receiptText = generate_receipt_recharge($receiptCode, $plate_number, $totale_ricarica, $tipo_ricarica, $quantita_ore);

    // ===== SALVA IN CASSA =====
    $stmt = $db->prepare("
        INSERT INTO cassa (
            id_ricarica,
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
        $id_ricarica,
        'ricarica',
        $plate_number,
        $receiptCode,
        $totale_ricarica,
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
    $stmt->execute([$receiptCode, $totale_ricarica, $id_turno, $nowSql, $nowSql]);

    $idInvoice = $db->lastInsertId();

    // ===== SALVA IN INVOICES_PRINTED =====
    $stmt = $db->prepare("
        INSERT INTO invoices_printed (
            receipt_code,
            id_ricarica,
            tipo_servizio,
            price,
            id_turno,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $receiptCode,
        $id_ricarica,
        'ricarica',
        $totale_ricarica,
        $id_turno,
        $nowSql
    ]);

    $idInvoicePrinted = $db->lastInsertId();

    // ===== AGGIORNA RICARICA: MARCA COME RICEVUTA EMESSA =====
    $stmt = $db->prepare("
        UPDATE ricariche
        SET receipt_emitted = 1, updated_at = ?
        WHERE id = ?
    ");
    $stmt->execute([$nowSql, $id_ricarica]);

    // ===== STAMPA SU STAMPANTE TERMICA =====
    $printResult = escpos_print_txt_with_barcode($receiptText, $receiptCode, true);

    if (!$printResult['success']) {
        error_log('[modulo1_emit_receipt_recharge] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
    }

    // ===== RISPOSTA =====
    $response['success'] = true;
    $response['message'] = '✅ Ricevuta ricarica emessa e stampata';
    $response['data'] = [
        'receipt_code' => $receiptCode,
        'id_cassa' => $idCassa,
        'id_invoices' => $idInvoice,
        'id_invoices_printed' => $idInvoicePrinted,
        'total_price' => $totale_ricarica,
        'print_success' => (bool)($printResult['success'] ?? false),
        'print_message' => (string)($printResult['message'] ?? '')
    ];

} catch (Throwable $e) {
    http_response_code(400);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('[modulo1_emit_receipt_recharge] ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
