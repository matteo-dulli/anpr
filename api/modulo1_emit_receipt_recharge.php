<?php
/**
 * modulo1_emit_receipt_recharge.php
 * Emette ricevuta per RICARICA
 * - Genera codice ricevuta (R_Ymd_His)
 * - Salva in cassa, invoices, invoices_printed
 * - Stampa su stampante termica ESC/POS
 * - Marca ricarica come receipt_emitted = 1
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';
require_once __DIR__ . '/modulo1_receipt_format.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id_ricarica = isset($data['id_ricarica']) ? (int)$data['id_ricarica'] : 0;
    $id_turno = isset($data['id_turno']) ? (int)$data['id_turno'] : null;
    
    if ($id_ricarica <= 0) {
        throw new Exception('id_ricarica non valido');
    }
    
    $db = getDatabaseConnection();
    
    // ===== 1. RECUPERA DATI RICARICA =====
    $stmt = $db->prepare("
        SELECT id, primary_barcode, plate_number, tipo_ricarica, 
               prezzo_ricarica, quantita_ore, totale_ricarica, 
               receipt_emitted, id_turno
        FROM ricariche
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id_ricarica]);
    $ricarica = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ricarica) {
        throw new Exception('Ricarica non trovata: id=' . $id_ricarica);
    }
    
    // Se già emessa ricevuta, errore
    if ($ricarica['receipt_emitted'] == 1) {
        throw new Exception('Ricevuta già emessa per questa ricarica');
    }
    
    // ===== 2. GENERA CODICE RICEVUTA =====
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $receiptCode = 'R_' . $now->format('Ymd_His');
    $nowSql = $now->format('Y-m-d H:i:s');
    
    // ===== 3. PREPARA DATI RICEVUTA =====
    $plateNumber = trim((string)($ricarica['plate_number'] ?? ''));
    $totalPrice = (float)($ricarica['totale_ricarica'] ?? 0);
    $rechargeType = trim((string)($ricarica['tipo_ricarica'] ?? ''));
    $quantityHours = (float)($ricarica['quantita_ore'] ?? 0);
    
    // ===== 4. GENERA TESTO RICEVUTA =====
    $receiptText = generate_receipt_recharge(
        $receiptCode,
        $plateNumber,
        $totalPrice,
        $rechargeType,
        $quantityHours
    );
    
    // ===== 5. SALVA IN CASSA =====
    $stmtCassa = $db->prepare("
        INSERT INTO cassa (
            id_ricarica,
            tipo_servizio,
            plate_number,
            invoice_code,
            invoice_price,
            id_turno,
            created_at,
            updated_at
        ) VALUES (?, 'ricarica', ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmtCassa->execute([
        $id_ricarica,
        $plateNumber,
        $receiptCode,
        $totalPrice,
        $id_turno
    ]);
    
    $idCassa = $db->lastInsertId();
    
    // ===== 6. SALVA IN INVOICES =====
    $stmtInv = $db->prepare("
        INSERT INTO invoices (
            receipt_code,
            price,
            id_turno,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, NOW(), NOW())
    ");
    
    $stmtInv->execute([
        $receiptCode,
        $totalPrice,
        $id_turno
    ]);
    
    $idInvoice = $db->lastInsertId();
    
    // ===== 7. SALVA IN INVOICES_PRINTED =====
    $stmtInvPrint = $db->prepare("
        INSERT INTO invoices_printed (
            receipt_code,
            id_ricarica,
            tipo_servizio,
            price,
            id_turno,
            created_at
        ) VALUES (?, ?, 'ricarica', ?, ?, NOW())
    ");
    
    $stmtInvPrint->execute([
        $receiptCode,
        $id_ricarica,
        $totalPrice,
        $id_turno
    ]);
    
    // ===== 8. MARCA RICARICA COME RECEIPT_EMITTED =====
    $stmtMark = $db->prepare("
        UPDATE ricariche
        SET receipt_emitted = 1, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    
    $stmtMark->execute([$id_ricarica]);
    
    // ===== 9. STAMPA SU STAMPANTE TERMICA ESC/POS =====
    $printResult = escpos_print_txt_with_barcode($receiptText, $receiptCode, true);
    
    if (!$printResult['success']) {
        error_log('[modulo1_emit_receipt_recharge] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
    }
    
    // ===== 10. RISPOSTA =====
    $response['success'] = true;
    $response['message'] = '✅ Ricevuta ricarica emessa e stampata';
    $response['data'] = [
        'receipt_code' => $receiptCode,
        'id_cassa' => $idCassa,
        'id_invoice' => $idInvoice,
        'id_ricarica' => $id_ricarica,
        'totale' => $totalPrice,
        'quantita_ore' => $quantityHours,
        'print_success' => (bool)($printResult['success'] ?? false),
        'print_message' => (string)($printResult['message'] ?? '')
    ];
    
    if (function_exists('logEvent')) {
        logEvent('ricarica', "Ricevuta emessa: CODE=$receiptCode ID_RICARICA=$id_ricarica TOTALE=$totalPrice QTA=$quantityHours");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    
    if (function_exists('logEvent')) {
        logEvent('error', 'MODULO1_EMIT_RECEIPT_RECHARGE_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
