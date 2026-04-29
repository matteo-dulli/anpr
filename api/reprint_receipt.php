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
        // evita output parziale non-JSON
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

    $originalFileName = 'RECEIPT_' . $receiptCode . '.txt';
    $originalFilePath = rtrim(INVOICE_DIR, '/\\') . DIRECTORY_SEPARATOR . $originalFileName;

    if (!file_exists($originalFilePath)) {
        throw new Exception('File ricevuta originale non trovato: ' . $originalFilePath);
    }

    $fileContent = @file_get_contents($originalFilePath);
    if ($fileContent === false) {
        throw new Exception('Errore lettura file ricevuta: ' . $originalFileName);
    }

    // directory invoice
    if (!is_dir(INVOICE_DIR)) {
        if (!@mkdir(INVOICE_DIR, 0777, true)) {
            throw new Exception('Impossibile creare cartella: ' . INVOICE_DIR);
        }
    }
    if (!is_writable(INVOICE_DIR)) {
        throw new Exception('Cartella INVOICE_DIR non scrivibile: ' . INVOICE_DIR);
    }

    // trova primo indice libero
    $reprintCount = 0;
    for ($i = 1; $i <= 100; $i++) {
        $candidate = 'RECEIPT_' . $receiptCode . '-' . $i . '.txt';
        $candidatePath = rtrim(INVOICE_DIR, '/\\') . DIRECTORY_SEPARATOR . $candidate;

        if (!file_exists($candidatePath)) {
            $reprintCount = $i;
            break;
        }
    }
    if ($reprintCount === 0) {
        throw new Exception('Limite di ristampe raggiunto (100)');
    }

    $reprintFileName = 'RECEIPT_' . $receiptCode . '-' . $reprintCount . '.txt';
    $reprintFilePath = rtrim(INVOICE_DIR, '/\\') . DIRECTORY_SEPARATOR . $reprintFileName;

    $bytesWritten = @file_put_contents($reprintFilePath, $fileContent);
    if ($bytesWritten === false) {
        throw new Exception('Errore creazione file ristampa ricevuta');
    }

    // log DB (best-effort)
    try {
        $stmt = $db->prepare("
            INSERT INTO invoices_printed_reprint
            (receipt_code, passage_id, reprint_number, reprint_filename, reprinted_by)
            VALUES (?, ?, ?, ?, 'web')
        ");
        $stmt->execute([$receiptCode, $passageId, $reprintCount, $reprintFileName]);
    } catch (Throwable $dbError) {
        error_log('Receipt reprint DB warning: ' . $dbError->getMessage());
    }

    // stampa ESC/POS: barcode = ticket_code (letto dal TXT)
    $ticketCode = '';
    if (preg_match('/^\s*TICKET:\s*(.+)\s*$/mi', $fileContent, $m)) {
        $ticketCode = trim($m[1]);
    }

    if ($ticketCode !== '') {
        $printResult = escpos_print_txt_with_barcode_from_file($reprintFilePath, $ticketCode);
        if (!$printResult['success']) {
            error_log('[reprint_receipt] PRINT WARNING: ' . $printResult['message']);
        }
    } else {
        $printResult = ['success' => false, 'message' => 'Ticket_code non trovato nella ricevuta: barcode non stampato'];
        error_log('[reprint_receipt] PRINT WARNING: ' . $printResult['message']);
    }

    $response['success'] = true;
    $response['message'] = '✅ Ricevuta ristampata: ' . $reprintFileName;
    $response['data'] = [
        'reprint_filename' => $reprintFileName,
        'reprint_count' => $reprintCount,
        'receipt_code' => $receiptCode,
       //// 'download_url' => (defined('API_BASE') ? API_BASE : '/anpr/api') . '/download_invoice.php?file=' . rawurlencode($reprintFileName),
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