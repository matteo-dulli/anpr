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
        SELECT id, ticket_code, entry_datetime, exit_datetime 
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

    $fileContent = @file_get_contents($originalFilePath);
    if ($fileContent === false) {
        $lastError = error_get_last();
        throw new Exception('Errore lettura file: ' . $originalFilePath . ' (' . ($lastError ? $lastError['message'] : 'unknown') . ')');
    }

    error_log('[reprint_ticket] File content read, size: ' . strlen($fileContent) . ' bytes');

    if (!is_dir(PRINT_DIR)) {
        error_log('[reprint_ticket] PRINT_DIR does not exist, creating it');
        if (!@mkdir(PRINT_DIR, 0777, true)) {
            throw new Exception('Impossibile creare cartella: ' . PRINT_DIR);
        }
    }

    if (!is_writable(PRINT_DIR)) {
        throw new Exception('Cartella PRINT_DIR non scrivibile: ' . PRINT_DIR);
    }

    error_log('[reprint_ticket] PRINT_DIR is writable');

    $reprintCount = 0;
    for ($i = 1; $i <= 100; $i++) {
        $reprintFileName = 'TICKET_' . $ticketCode . '-' . $i . '.txt';
        $reprintFilePath = PRINT_DIR . '/' . $reprintFileName;

        if (!file_exists($reprintFilePath)) {
            $reprintCount = $i;
            break;
        }
    }

    error_log('[reprint_ticket] Reprint count: ' . $reprintCount);

    if ($reprintCount === 0) {
        throw new Exception('Limite di ristampe raggiunto (100)');
    }

    $reprintFileName = 'TICKET_' . $ticketCode . '-' . $reprintCount . '.txt';
    $reprintFilePath = PRINT_DIR . '/' . $reprintFileName;

    error_log('[reprint_ticket] Creating reprint file: ' . $reprintFilePath);

    $bytesWritten = @file_put_contents($reprintFilePath, $fileContent);
    if ($bytesWritten === false) {
        $lastError = error_get_last();
        throw new Exception('Errore creazione file ristampa: ' . $reprintFilePath . ' (' . ($lastError ? $lastError['message'] : 'unknown') . ')');
    }

    error_log('[reprint_ticket] File created successfully, bytes written: ' . $bytesWritten);

    try {
        $stmt2 = $db->prepare("
            INSERT INTO tickets_printed_reprint 
            (ticket_id, ticket_code, plate_id, passage_id, reprinted_by, reprint_number, reprint_filename)
            VALUES (?, ?, ?, ?, 'web', ?, ?)
        ");

        if ($stmt2) {
            $stmt2->execute([
                $ticket['id'],
                $ticketCode,
                $plateId,
                $passageId,
                $reprintCount,
                $reprintFileName
            ]);
            error_log('[reprint_ticket] DB record inserted successfully');
        }
    } catch (Exception $dbError) {
        error_log('[reprint_ticket] DB warning (non-fatal): ' . $dbError->getMessage());
    }

    // ✅ NEW: stampa ESC/POS "carina A" dal TXT + barcode vero (ticket_code)
    $printResult = escpos_print_txt_with_barcode_from_file($reprintFilePath, $ticketCode);

    if (!$printResult['success']) {
        error_log('[reprint_ticket] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
        // Se vuoi che la ristampa FALLISCA quando la stampa fallisce, scommenta:
        // throw new Exception('Stampa fallita: ' . ($printResult['message'] ?? 'unknown'));
    }

    // ✅ URL pubblico del file (NON filesystem)
    $printUrlBase = defined('PRINT_URL')
        ? rtrim(PRINT_URL, "/\\")
        : (defined('BASE_PATH') ? rtrim(BASE_PATH, "/\\") . '/print' : '/anpr/print');

    $fileUrl = $printUrlBase . '/' . rawurlencode($reprintFileName);

    $response['success'] = true;
    $response['message'] = '✅ Ticket ristampato correttamente';
$response['data'] = [
    'ticket_code' => $ticketCode,
    'reprint_number' => $reprintCount,
    'reprint_filename' => $reprintFileName,
    'entry_datetime' => $ticket['entry_datetime'],
    'exit_datetime' => $ticket['exit_datetime'],
    'printer' => $printResult
];

// opzionale: info file solo in debug
$debug = !empty($_GET['debug']);
if ($debug) {
    $response['data']['file_path'] = $reprintFilePath;
    $response['data']['file_url'] = $fileUrl;
}
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