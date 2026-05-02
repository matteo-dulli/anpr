<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/escpos.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
error_log("=== EMIT_TICKET START ===");

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];
    $plateId = isset($body['plate_id']) ? (int)$body['plate_id'] : 0;
$fascia = isset($body['fascia']) ? trim((string)$body['fascia']) : null;
$entry_date = isset($body['entry_date']) ? trim((string)$body['entry_date']) : '';
$entry_time = isset($body['entry_time']) ? trim((string)$body['entry_time']) : '';

    error_log("EMIT_TICKET CALLED plate_id={$plateId} from_ip=" . ($_SERVER['REMOTE_ADDR'] ?? '') . " ua=" . ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    // ===== 1. DATI GARAGE =====
    $stmt = $db->query("SELECT * FROM garage_info ORDER BY id ASC LIMIT 1");
    $garage = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$garage) {
        throw new Exception('Dati garage (garage_info) non configurati');
    }

    // ===== 2. DATA/ORA ATTUALE =====
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));

    $plateNumber = null;
    $plateDateDetected = null;
    $entryDateTime = null;

    if ($plateId > 0) {
        // ===== 3. RECUPERA TARGA SELEZIONATA =====
$stmt = $db->prepare("SELECT plate_number, plate_corrected, date_detected, is_manual FROM plates WHERE id = ?");
$stmt->execute([$plateId]);
$plate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plate) {
    throw new Exception('Targa selezionata non trovata');
}

$plateNumber = $plate['plate_corrected'] ?: $plate['plate_number'];
$plateDateDetected = $plate['date_detected'];

// ✅ NEW: se targa manuale e l'utente ha inserito ingresso in UI, usa quello
// NON cancelliamo nulla: lasciamo la logica vecchia come fallback.
$isManualPlate = (int)($plate['is_manual'] ?? 0) === 1;

// Il frontend deve inviare entry_date / entry_time (YYYY-MM-DD e HH:MM)
$bodyEntryDate = isset($body['entry_date']) ? trim((string)$body['entry_date']) : '';
$bodyEntryTime = isset($body['entry_time']) ? trim((string)$body['entry_time']) : '';

if ($isManualPlate && $bodyEntryDate !== '' && $bodyEntryTime !== '') {
    // normalizza HH:MM -> HH:MM:SS
    $tt = (strlen($bodyEntryTime) === 5) ? ($bodyEntryTime . ':00') : $bodyEntryTime;

    // ✅ usa l'ingresso inserito dall'utente
    $entryDateTime = $bodyEntryDate . ' ' . $tt;
} else {
    // ✅ PER TARGA: USA L'ORARIO RILEVATO (date_detected) (fallback storico)
    $entryDateTime = $plateDateDetected;
}
} else {
    // ✅ PER PASSAGGIO: USA L'ORARIO ADESSO
    $entryDateTime = $now->format('Y-m-d H:i:s');
}
    // ===== 4. GENERA ID UNIVOCO TICKET =====
    $codeDate  = $now->format('Ymd-His');
    $randPart  = substr(strtoupper(bin2hex(random_bytes(4))), 0, 5);
    $ticketCode = "T{$codeDate}-{$randPart}";

    // Barcode secondario = ultima parte dopo l'ultimo trattino (es. "D27BE")
    $ticketTail = $randPart;

    error_log("DEBUG: About to INSERT into tickets_printed with: ticket_code={$ticketCode}, plate_id={$plateId}, entry_datetime={$entryDateTime}");

    // ===== 5. SALVA IN tickets_printed =====
   // ===== 5. SALVA IN tickets_printed =====
// ===== 5. SALVA IN tickets_printed =====
$stmt = $db->prepare("
    INSERT INTO tickets_printed (
        ticket_code,
        plate_id,
        plate_number,
        entry_datetime,
        fascia,
        secondary_barcode
    ) VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $ticketCode,
    $plateId ?: null,
    $plateNumber,
    $entryDateTime,
    $fascia,
    $ticketTail
]);

    $printedId = (int)$db->lastInsertId();
    $passageId = null;

    // ===== 5b. SE NON C'E' TARGA, CREA UN PASSAGGIO ANONIMO =====
    if ($plateId === 0) {
        $stmt = $db->prepare("
            INSERT INTO passages (
                ticket_printed_id,
                ticket_code,
                entry_datetime,
                note
            ) VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $printedId,
            $ticketCode,
            $entryDateTime,
            null
        ]);

        $passageId = (int)$db->lastInsertId();

        // ✅ SALVA passage_id in tickets_printed (OBBLIGATORIO)
        $upd = $db->prepare("UPDATE tickets_printed SET passage_id = ? WHERE id = ?");
        $upd->execute([$passageId, $printedId]);
    }

    // ===== 6. PREPARA DATI FORMATTATI =====
    $entryDate = (new DateTime($entryDateTime))->format('d/m/Y');
    $entryTime = (new DateTime($entryDateTime))->format('H:i');

    $footer = "Presentare questo biglietto al ritiro del veicolo.\nPresent this ticket when collecting the vehicle.";

    // linee anagrafiche garage
    $garageLines = [
        $garage['ragione_sociale'],
        $garage['nome_cognome'],
        trim($garage['indirizzo'] . ' ' . $garage['cap'] . ' ' . $garage['citta'] . ' (' . $garage['provincia'] . ')'),
        'P.IVA: ' . $garage['piva'] . '   CF: ' . $garage['cf'],
        'Tel: ' . $garage['tel'] . '   Cell: ' . $garage['cell'],
        'Email: ' . $garage['email'],
    ];

    $plateLine = $plateNumber ? ('TARGA: ' . $plateNumber) : 'TARGA: __________';

    // ===== 7. COSTRUISCI TESTO BIGLIETTO PRIMARIO (PER FILE DI STAMPA) =====
    $lines = [];
    $lines[] = str_repeat('=', 32);
    foreach ($garageLines as $gl) {
        if (trim($gl) !== '') {
            $lines[] = $gl;
        }
    }
    $lines[] = str_repeat('-', 32);
    // A1: mostra solo il tail (es. D27BE) invece del codice completo
    $lines[] = 'TICKET: ' . $ticketTail;
    $lines[] = $plateLine;
    // A3: per passaggio (plateId === 0), aggiungi CLASSE con la fascia
    if ($plateId === 0 && !empty($fascia)) {
        $lines[] = 'CLASSE: ' . $fascia;
    }
    $lines[] = 'INGRESSO: ' . $entryDate . '  ' . $entryTime;
    $lines[] = str_repeat('-', 32);
    $lines[] = $footer;
    $lines[] = '';
    // A2: usa BARCODE_SILENT per stampare il barcode senza testo visibile
    $lines[] = 'BARCODE_SILENT: ' . $ticketTail;
    $lines[] = str_repeat('=', 32);
    $lines[] = ''; // riga vuota finale

    $ticketText = implode(PHP_EOL, $lines);

    // ===== 7b. COSTRUISCI TESTO MINI-TICKET (secondo biglietto) =====
    $miniLines = [];
    $miniLines[] = str_repeat('=', 32);
    $miniLines[] = $garage['ragione_sociale'];
    $miniLines[] = str_repeat('-', 32);
    $miniLines[] = $plateLine;
    // B: CLASSE nel mini-ticket (sia targa che passaggio, se fascia disponibile)
    if (!empty($fascia)) {
        $miniLines[] = 'CLASSE: ' . $fascia;
    }
    $miniLines[] = 'INGRESSO: ' . $entryDate . '  ' . $entryTime;
    $miniLines[] = '';
    // B1: barcode secondario = tail del primario, senza testo
    $miniLines[] = 'BARCODE_SILENT: ' . $ticketTail;
    $miniLines[] = str_repeat('=', 32);
    $miniLines[] = '';

    $miniTicketText = implode(PHP_EOL, $miniLines);

    // ===== 8. SCRIVI FILE DI TESTO IN PRINT_DIR =====
    // ❌ VECCHIO (DISATTIVATO): scriveva nella cartella locale ../print e non in PRINT_DIR
    // $printDir = realpath(__DIR__ . '/../print');

    // ✅ NUOVO: usa sempre PRINT_DIR da config.php
    if (!defined('PRINT_DIR')) {
        throw new Exception('Costante PRINT_DIR non definita');
    }
    $printDir = rtrim(PRINT_DIR, DIRECTORY_SEPARATOR);
    if (!is_dir($printDir)) {
        if (!@mkdir($printDir, 0777, true)) {
            throw new Exception('Impossibile creare cartella di stampa: ' . $printDir);
        }
    }
    if (!is_writable($printDir)) {
        throw new Exception('Cartella di stampa non scrivibile: ' . $printDir);
    }

    $filename = 'TICKET_' . $ticketCode . '.txt';
    $fullPath = $printDir . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($fullPath, $ticketText) === false) {
        throw new Exception('Impossibile scrivere il file di stampa: ' . $fullPath);
    }

    // Scrivi il file del mini-ticket
    $miniFilename = 'MINI_' . $ticketCode . '.txt';
    $miniFullPath = $printDir . DIRECTORY_SEPARATOR . $miniFilename;
    if (file_put_contents($miniFullPath, $miniTicketText) === false) {
        error_log('[emit_ticket] WARN: impossibile scrivere mini-ticket: ' . $miniFullPath);
    }

    // ✅ Stampa ticket primario (CODE128 senza testo)
    $printResult = escpos_print_txt_with_barcode_from_file($fullPath, $ticketTail);
    if (!$printResult['success']) {
        error_log('[emit_ticket] PRINT WARNING (primario): ' . $printResult['message']);
    }

    // ✅ Stampa mini-ticket subito dopo (con taglio implicito alla fine della stampa)
    $printMiniResult = escpos_print_txt_with_barcode_from_file($miniFullPath, $ticketTail);
    if (!$printMiniResult['success']) {
        error_log('[emit_ticket] PRINT WARNING (mini): ' . $printMiniResult['message']);
    }

    // ===== 9. PREPARA RISPOSTA JSON =====
    $ticketData = [
        'ticket_id'          => $printedId,
        'ticket_code'        => $ticketCode,
        'ticket_tail'        => $ticketTail,
        'secondary_barcode'  => $ticketTail,
        'plate_id'           => $plateId ?: null,
        'plate_number'       => $plateNumber,
        'entry_datetime'     => $entryDateTime,
        'entry_date_it'      => $entryDate,
        'entry_time_it'      => $entryTime,
        'passage_id'         => $passageId,
        'garage'             => [
            'ragione_sociale' => $garage['ragione_sociale'],
            'nome_cognome'    => $garage['nome_cognome'],
            'indirizzo'       => $garage['indirizzo'],
            'cap'             => $garage['cap'],
            'citta'           => $garage['citta'],
            'provincia'       => $garage['provincia'],
            'piva'            => $garage['piva'],
            'cf'              => $garage['cf'],
            'tel'             => $garage['tel'],
            'cell'            => $garage['cell'],
            'email'           => $garage['email'],
        ],
        'footer'             => $footer,
        'barcode_value'      => $ticketTail,
        'print_file'         => $fullPath,
        'mini_ticket_file'   => $miniFullPath,
        // ✅ NEW: feedback stampa
        'printer'            => $printResult,
        'printer_mini'       => $printMiniResult
    ];

    $response['success'] = true;
    $response['data'] = $ticketData;
    $response['message'] = 'Ticket generato correttamente (file di stampa creato)';

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('EMIT_TICKET_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
