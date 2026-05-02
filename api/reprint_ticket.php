<?php
// ✅ GESTIONE ERRORI MIGLIORATA
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ✅ CATTURA ERRORI FATALI
register_shutdown_function(function() {
    $error = error_get_last();
    if (!$error) return;

    // SOLO errori fatali veri
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return; // ignora warning/notice
    }

    if (headers_sent()) return;

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '❌ Errore fatale: ' . $error['message'],
        'error_type' => $error['type'],
        'error_file' => $error['file'],
        'error_line' => $error['line'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
});

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => ''];

try {
    error_log('[reprint_ticket] __DIR__: ' . __DIR__);
    error_log('[reprint_ticket] dirname(__DIR__): ' . dirname(__DIR__));

    $configPath = dirname(__DIR__) . '/config/config.php';

    error_log('[reprint_ticket] Config path: ' . $configPath);
    error_log('[reprint_ticket] Config exists: ' . (file_exists($configPath) ? 'YES' : 'NO'));

    if (!file_exists($configPath)) {
        $configPath = __DIR__ . '/../config/config.php';
        error_log('[reprint_ticket] Trying alternative path: ' . $configPath);
        error_log('[reprint_ticket] Alternative exists: ' . (file_exists($configPath) ? 'YES' : 'NO'));

        if (!file_exists($configPath)) {
            throw new Exception('File config.php non trovato. Percorsi provati: 
            1. ' . dirname(__DIR__) . '/config/config.php
            2. ' . __DIR__ . '/../config/config.php');
        }
    }

    require_once $configPath;
require_once __DIR__ . '/escpos.php';
    // ✅ NEW: stampa ESC/POS
    require_once __DIR__ . '/functions.php';

    error_log('[reprint_ticket] Config loaded successfully');
    error_log('[reprint_ticket] PRINT_DIR defined: ' . (defined('PRINT_DIR') ? 'YES' : 'NO'));
    error_log('[reprint_ticket] PRINT_DIR value: ' . (defined('PRINT_DIR') ? PRINT_DIR : 'NOT DEFINED'));

    $db = getDatabaseConnection();
    if (!$db) {
        throw new Exception('Connessione DB fallita');
    }

    $raw = file_get_contents('php://input');
    error_log('[reprint_ticket] Raw input length: ' . strlen($raw));

    $body = $raw ? json_decode($raw, true) : [];

    $ticketCode = isset($body['ticket_code']) ? trim($body['ticket_code']) : '';
    $plateId = isset($body['plate_id']) ? (int)$body['plate_id'] : null;
    $passageId = isset($body['passage_id']) ? (int)$body['passage_id'] : null;

    error_log('[reprint_ticket] Input: ticketCode=' . $ticketCode . ', plateId=' . $plateId . ', passageId=' . $passageId);

    if (!$ticketCode) {
        throw new Exception('Codice ticket non valido');
    }

    if (!defined('PRINT_DIR')) {
        throw new Exception('Costante PRINT_DIR non definita in config.php. Percorso config: ' . $configPath);
    }

    error_log('[reprint_ticket] PRINT_DIR check passed: ' . PRINT_DIR);

    $stmt = $db->prepare("
        SELECT id, ticket_code, plate_number, entry_datetime, exit_datetime,
               fascia, secondary_barcode, plate_id, passage_id
        FROM tickets_printed 
        WHERE ticket_code = ? 
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Errore prepare query DB');
    }

    $stmt->execute([$ticketCode]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log('[reprint_ticket] Ticket found in DB: ' . ($ticket ? 'YES' : 'NO'));

    if (!$ticket) {
        throw new Exception('Ticket non trovato nel database con codice: ' . $ticketCode);
    }

    $originalFileName = 'TICKET_' . $ticketCode . '.txt';
    $originalFilePath = PRINT_DIR . '/' . $originalFileName;

    error_log('[reprint_ticket] Original file path: ' . $originalFilePath);
    error_log('[reprint_ticket] Original file exists: ' . (file_exists($originalFilePath) ? 'YES' : 'NO'));

    if (!file_exists($originalFilePath)) {
        throw new Exception('File ticket originale non trovato: ' . $originalFilePath);
    }

    // Barcode secondario = secondary_barcode dal DB, fallback alla coda del ticket_code
    $secondaryBarcode = !empty($ticket['secondary_barcode'])
        ? $ticket['secondary_barcode']
        : substr($ticketCode, strrpos($ticketCode, '-') + 1);

    // F: NON creare più file con suffisso -1/-2/-3
    // Ristampare direttamente il file originale (già contiene BARCODE_SILENT: tail)

    error_log('[reprint_ticket] Reprinting original: ' . $originalFilePath . ' with secondary_barcode=' . $secondaryBarcode);

    // ✅ Stampa ticket primario (ristampa del file originale)
    $printResult = escpos_print_txt_with_barcode_from_file($originalFilePath, $secondaryBarcode);

    if (!$printResult['success']) {
        error_log('[reprint_ticket] PRINT WARNING (primario): ' . ($printResult['message'] ?? 'unknown'));
    }

    // ✅ Genera e stampa il mini-ticket
    $entryDT = $ticket['entry_datetime'] ?? '';
    $entryDateFmt = '';
    $entryTimeFmt = '';
    if ($entryDT) {
        $dtObj = DateTime::createFromFormat('Y-m-d H:i:s', $entryDT);
        if (!$dtObj) $dtObj = new DateTime($entryDT);
        if ($dtObj) {
            $entryDateFmt = $dtObj->format('d/m/Y');
            $entryTimeFmt = $dtObj->format('H:i');
        }
    }

    $plateLine = !empty($ticket['plate_number']) ? ('TARGA: ' . $ticket['plate_number']) : 'TARGA: __________';

    $miniLines = [];
    $miniLines[] = str_repeat('=', 32);

    // Intestazione ridotta: solo ragione sociale dal DB
    try {
        $stmtGarage = $db->query("SELECT ragione_sociale FROM garage_info ORDER BY id ASC LIMIT 1");
        $garage = $stmtGarage->fetch(PDO::FETCH_ASSOC);
        if (!empty($garage['ragione_sociale'])) {
            $miniLines[] = $garage['ragione_sociale'];
        }
    } catch (Throwable $eGarage) {
        // ignora
    }

    $miniLines[] = str_repeat('-', 32);
    $miniLines[] = $plateLine;
    if (!empty($ticket['fascia'])) {
        $miniLines[] = 'CLASSE: ' . $ticket['fascia'];
    }
    if ($entryDateFmt !== '') {
        $miniLines[] = 'INGRESSO: ' . $entryDateFmt . '  ' . $entryTimeFmt;
    }
    $miniLines[] = '';
    $miniLines[] = 'BARCODE_SILENT: ' . $secondaryBarcode;
    $miniLines[] = str_repeat('=', 32);
    $miniLines[] = '';

    $miniTicketText = implode(PHP_EOL, $miniLines);
    $miniFilePath = PRINT_DIR . '/MINI_REPRINT_' . $ticketCode . '.txt';
    @file_put_contents($miniFilePath, $miniTicketText);

    $printMiniResult = escpos_print_txt_with_barcode_from_file($miniFilePath, $secondaryBarcode);
    if (!$printMiniResult['success']) {
        error_log('[reprint_ticket] PRINT WARNING (mini): ' . ($printMiniResult['message'] ?? 'unknown'));
    }

    // ✅ URL pubblico del file (NON filesystem)
    $printUrlBase = defined('PRINT_URL')
        ? rtrim(PRINT_URL, "/\\")
        : (defined('BASE_PATH') ? rtrim(BASE_PATH, "/\\") . '/print' : '/anpr/print');

    $response['success'] = true;
    $response['message'] = '✅ Ticket ristampato correttamente';
$response['data'] = [
    'ticket_code'       => $ticketCode,
    'secondary_barcode' => $secondaryBarcode,
    'entry_datetime'    => $ticket['entry_datetime'],
    'exit_datetime'     => $ticket['exit_datetime'],
    'printer'           => $printResult,
    'printer_mini'      => $printMiniResult
];
    error_log('[reprint_ticket] SUCCESS - ' . json_encode($response['data']));

    if (function_exists('logEvent')) {
        logEvent('ticket', "Ticket ristampato: CODE=$ticketCode REPRINT_NUM=$reprintCount FILE=$reprintFileName");
    }

} catch (Throwable $e) {
    error_log('[reprint_ticket] EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();

    if (function_exists('logEvent')) {
        logEvent('error', 'REPRINT_TICKET_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 500);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;