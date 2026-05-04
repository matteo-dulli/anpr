<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => '', 'data' => null];

// ✅ Shutdown handler: in caso di fatal restituisce JSON (e non stringa vuota)
register_shutdown_function(function () use (&$response) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => '❌ Errore fatale: ' . ($e['message'] ?? 'unknown'),
            'data'    => ['file' => $e['file'] ?? '', 'line' => $e['line'] ?? 0]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    // --- config robusto ---
    $configPath = dirname(__DIR__) . '/config/config.php';
    if (!file_exists($configPath)) {
        $configPath = __DIR__ . '/../config/config.php';
        if (!file_exists($configPath)) {
            throw new Exception('Config.php non trovato');
        }
    }

    require_once $configPath;
    require_once __DIR__ . '/functions.php';

    $db = getDatabaseConnection();
    if (!$db) {
        throw new Exception('Connessione DB fallita');
    }

    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $receiptCode = isset($body['receipt_code']) ? trim((string)$body['receipt_code']) : '';
    $passageId   = isset($body['passage_id']) ? (int)$body['passage_id'] : null;

    if ($receiptCode === '') {
        throw new Exception('Codice ricevuta non valido');
    }

    if (!defined('INVOICE_DIR')) {
        throw new Exception('Costante INVOICE_DIR non definita');
    }

    // ============================================================
    // ✅ RISTAMPA IDENTICA: usa SEMPRE il file originale
    // (niente -1/-2/-3)
    // ============================================================
    $originalFileName = 'RECEIPT_' . $receiptCode . '.txt';
    $originalFilePath = rtrim(INVOICE_DIR, '/\\') . DIRECTORY_SEPARATOR . $originalFileName;

    if (!file_exists($originalFilePath)) {
        throw new Exception('File ricevuta originale non trovato: ' . $originalFilePath);
    }

    $fileContent = @file_get_contents($originalFilePath);
    if ($fileContent === false) {
        throw new Exception('Errore lettura file ricevuta: ' . $originalFileName);
    }

    // log DB (best-effort) — salva filename originale e reprint_number=0
    try {
        $stmt = $db->prepare("
            INSERT INTO invoices_printed_reprint
            (receipt_code, passage_id, reprint_number, reprint_filename, reprinted_by)
            VALUES (?, ?, ?, ?, 'web')
        ");
        $stmt->execute([$receiptCode, $passageId, 0, $originalFileName]);
    } catch (Throwable $dbError) {
        error_log('Receipt reprint DB warning: ' . $dbError->getMessage());
    }

    // ============================================================
    // ✅ Barcode: per ricevuta usa BARCODE: ... dal TXT
    // fallback: ticket/passaggio
    // ============================================================
    $ticketCode = '';

    // 1) formato ticket (alcuni template scrivono "TICKET: xxxx")
    if (preg_match('/^\s*TICKET\s*:\s*(.+)\s*$/mi', $fileContent, $m)) {
        $ticketCode = trim((string)$m[1]);
    }

    // 2) formato ricevuta (writeReceiptTxt scrive "BARCODE: xxxx")
    if ($ticketCode === '' && preg_match('/^\s*BARCODE\s*:\s*(.+)\s*$/mi', $fileContent, $m2)) {
        $ticketCode = trim((string)$m2[1]);
    }

    // 3) fallback DB (soprattutto per ricevute passaggi)
    if ($ticketCode === '' && $passageId) {
        try {
            $stmtTc = $db->prepare("SELECT ticket_code FROM passages WHERE id = ? LIMIT 1");
            $stmtTc->execute([(int)$passageId]);
            $rTc = $stmtTc->fetch(PDO::FETCH_ASSOC);
            if ($rTc && !empty($rTc['ticket_code'])) $ticketCode = trim((string)$rTc['ticket_code']);
        } catch (Throwable $eTc) {
            error_log('[reprint_receipt] ticket_code fallback DB warning: ' . $eTc->getMessage());
        }
    }

    // ============================================================
    // ✅ Stampa ESC/POS: stampa il file originale (identico)
    // ============================================================
    if ($ticketCode !== '') {
        $printResult = escpos_print_txt_with_barcode_from_file($originalFilePath, $ticketCode);
        if (!$printResult['success']) {
            error_log('[reprint_receipt] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
        }
    } else {
        $printResult = ['success' => false, 'message' => 'Codice barcode non trovato: barcode non stampato'];
        error_log('[reprint_receipt] PRINT WARNING: ' . $printResult['message']);
    }

    $response['success'] = true;
    $response['message'] = '✅ Ricevuta ristampata: ' . $originalFileName;
    $response['data'] = [
        'reprint_filename' => $originalFileName,
        'reprint_count' => 0,
        'receipt_code' => $receiptCode,

        // ✅ utile per eventuale download/preview browser (identico)
        'download_url' => (defined('API_BASE') ? API_BASE : '/anpr/api')
            . '/download_invoice.php?file=' . rawurlencode($originalFileName),

        'printer' => $printResult,
    ];

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('REPRINT_RECEIPT_ERROR: ' . $e->getMessage());
}

http_response_code($response['success'] ? 200 : 500);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;