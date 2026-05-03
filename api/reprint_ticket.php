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
        SELECT id, ticket_code, entry_datetime, exit_datetime,
               plate_id, plate_number, fascia,
               COALESCE(barcode_secondary, SUBSTRING_INDEX(ticket_code, '-', -1)) AS barcode_secondary
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

    $barcodeSecondary = $ticket['barcode_secondary'] ?: ticketCodeSuffix($ticketCode);
    $fasciaTicket = $ticket['fascia'] ?? '';
    $plateNumberTicket = $ticket['plate_number'] ?? '';

    // Carica dati garage
    $stmtG = $db->query("SELECT * FROM garage_info ORDER BY id ASC LIMIT 1");
    $garage = $stmtG ? $stmtG->fetch(PDO::FETCH_ASSOC) : null;
    if (!$garage) {
        throw new Exception('Dati garage (garage_info) non configurati');
    }

    // Formatta data/ora ingresso
    $entryDT = $ticket['entry_datetime'] ?: date('Y-m-d H:i:s');
    try {
        $dtEntry = new DateTime($entryDT, new DateTimeZone('Europe/Rome'));
        $entryDateFmt = $dtEntry->format('d/m/Y');
        $entryTimeFmt = $dtEntry->format('H:i');
    } catch (Exception $eDt) {
        $entryDateFmt = date('d/m/Y');
        $entryTimeFmt = date('H:i');
    }

    // Costruisci plateLine con CLASSE
    $plateLine = $plateNumberTicket
        ? ('TARGA: ' . $plateNumberTicket . '   CLASSE: ' . $fasciaTicket)
        : 'TARGA: __________   CLASSE: ' . $fasciaTicket;

    // Linee anagrafiche garage
    $garageLines = [
        $garage['ragione_sociale'],
        $garage['nome_cognome'] ?? '',
        trim(($garage['indirizzo'] ?? '') . ' ' . ($garage['cap'] ?? '') . ' ' . ($garage['citta'] ?? '') . ' (' . ($garage['provincia'] ?? '') . ')'),
        'P.IVA: ' . ($garage['piva'] ?? '') . '   CF: ' . ($garage['cf'] ?? ''),
        'Tel: ' . ($garage['tel'] ?? '') . '   Cell: ' . ($garage['cell'] ?? ''),
        'Email: ' . ($garage['email'] ?? ''),
    ];

    $footer = "Presentare questo biglietto al ritiro del veicolo.\nPresent this ticket when collecting the vehicle.";

    // ===== PRIMO TICKET (copia originale, barcode secondario) =====
    $lines = [];
    $lines[] = str_repeat('=', 32);
    foreach ($garageLines as $gl) {
        if (trim($gl) !== '') $lines[] = $gl;
    }
    $lines[] = str_repeat('-', 32);
    $lines[] = 'TICKET: ' . $barcodeSecondary;
    $lines[] = $plateLine;
    $lines[] = 'INGRESSO: ' . $entryDateFmt . '  ' . $entryTimeFmt;
    $lines[] = str_repeat('-', 32);
    $lines[] = $footer;
    $lines[] = '';
    $lines[] = 'BARCODE:'; // solo immagine barcode, nessun testo
    $lines[] = str_repeat('=', 32);
    $lines[] = '';
    $firstTicketText = implode(PHP_EOL, $lines);

    // ===== SECONDO TICKET (piccolo, barcode secondario) =====
    $smallLines = [];
    $smallLines[] = str_repeat('=', 32);
    $smallLines[] = $garage['ragione_sociale'];
    $smallLines[] = str_repeat('-', 32);
    $smallLines[] = $plateLine;
    $smallLines[] = 'INGRESSO: ' . $entryDateFmt . '  ' . $entryTimeFmt;
    $smallLines[] = 'BARCODE:'; // barcode secondario, solo immagine
    $smallLines[] = str_repeat('=', 32);
    $smallLines[] = '';
    $secondTicketText = implode(PHP_EOL, $smallLines);

    // Stampa primo ticket con barcode secondario
    $printResult = escpos_print_txt_with_barcode($firstTicketText, $barcodeSecondary);
    if (!$printResult['success']) {
        error_log('[reprint_ticket] PRINT WARNING (primo ticket): ' . ($printResult['message'] ?? 'unknown'));
    }

    // Stampa secondo ticket con barcode secondario
    $printResult2 = escpos_print_txt_with_barcode($secondTicketText, $barcodeSecondary);
    if (!$printResult2['success']) {
        error_log('[reprint_ticket] PRINT WARNING (secondo ticket): ' . ($printResult2['message'] ?? 'unknown'));
    }

    // Log DB ristampa (best-effort, senza numerazione file)
    try {
        $stmt2 = $db->prepare("
            INSERT INTO tickets_printed_reprint 
            (ticket_id, ticket_code, plate_id, passage_id, reprinted_by, reprint_number, reprint_filename)
            VALUES (?, ?, ?, ?, 'web', 1, ?)
        ");
        if ($stmt2) {
            $stmt2->execute([
                $ticket['id'],
                $ticketCode,
                $plateId,
                $passageId,
                'REPRINT_' . $ticketCode
            ]);
        }
    } catch (Throwable $dbError) {
        error_log('[reprint_ticket] DB warning (non-fatal): ' . $dbError->getMessage());
    }

    $response['success'] = true;
    $response['message'] = '✅ Ticket ristampato correttamente (barcode secondario: ' . $barcodeSecondary . ')';
    $response['data'] = [
        'ticket_code' => $ticketCode,
        'barcode_secondary' => $barcodeSecondary,
        'entry_datetime' => $ticket['entry_datetime'],
        'exit_datetime' => $ticket['exit_datetime'],
        'printer' => $printResult,
        'printer2' => $printResult2 ?? null
    ];

    // opzionale: info in debug
    $debug = !empty($_GET['debug']);
    if ($debug) {
        $response['data']['first_ticket_text'] = $firstTicketText;
    }

    error_log('[reprint_ticket] SUCCESS - ' . json_encode($response['data']));

    if (function_exists('logEvent')) {
        logEvent('ticket', "Ticket ristampato: CODE=$ticketCode BARCODE_SEC=$barcodeSecondary");
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