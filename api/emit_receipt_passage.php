<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

// ✅ evita warning: variabili non definite al primo log
error_log(
    '[PRINT DEBUG] ticket_code=' . var_export($ticket_code ?? null, true)
    . ' barcodeValue=' . var_export($barcodeValue ?? null, true)
    . ' file=' . var_export($fileCreato ?? null, true)
);

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/emit_receipt_passage_fatal.log');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(
            __DIR__ . '/emit_receipt_passage_fatal.log',
            "\n[" . date('Y-m-d H:i:s') . "] FATAL: " . print_r($e, true) . "\n",
            FILE_APPEND
        );
    }
});

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/escpos.php'; // ✅ NECESSARIO per la stampa ESC/POS

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $passageId = isset($body['passage_id']) ? (int)$body['passage_id'] : 0;
    $price     = isset($body['price']) ? (float)$body['price'] : 0.0;

    $Tpaid      = isset($body['Tpaid']) ? (int)$body['Tpaid'] : 0;
    $TpayC      = isset($body['TpayC']) ? (int)$body['TpayC'] : 0;
    $TpayE      = isset($body['TpayE']) ? (int)$body['TpayE'] : 0;
    $Tannullato = isset($body['Tannullato']) ? (int)$body['Tannullato'] : 0;
    $Tannultxt  = isset($body['Tannultxt']) ? trim((string)$body['Tannultxt']) : '';
    $umFlag     = isset($body['um']) ? (int)$body['um'] : 0;

    $fascia = isset($body['fascia']) ? trim((string)$body['fascia']) : 'F1';
    if ($fascia === '') $fascia = 'F1';

    if ($passageId <= 0) {
        throw new Exception('ID passaggio non valido: ' . $passageId);
    }

    $stmtAnn = $db->prepare("SELECT COALESCE(Pannullato,0) AS Pannullato FROM cassa WHERE idpassages=? LIMIT 1");
    $stmtAnn->execute([$passageId]);
    $annRow = $stmtAnn->fetch(PDO::FETCH_ASSOC);
    if ($annRow && (int)$annRow['Pannullato'] === 1) {
        throw new Exception("Passaggio annullato: non puoi emettere la ricevuta");
    }

    $stmt = $db->prepare("
        SELECT entry_datetime, exit_datetime, ticket_code
        FROM passages
        WHERE id=?
    ");
    $stmt->execute([$passageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Passaggio non trovato');
    }

    $entry_datetime = !empty($row['entry_datetime']) ? $row['entry_datetime'] : '';
    $exit_datetime  = !empty($row['exit_datetime']) ? $row['exit_datetime'] : date('Y-m-d H:i:s');
    $ticket_code    = isset($row['ticket_code']) ? (string)$row['ticket_code'] : '';

    // Aggiorna flag UM in tickets_printed (best-effort)
    if ($umFlag && $ticket_code !== '') {
        try {
            $stmtUm = $db->prepare("UPDATE tickets_printed SET um = 1 WHERE ticket_code = ? LIMIT 1");
            $stmtUm->execute([$ticket_code]);
        } catch (Throwable $eUm) {
            error_log('[emit_receipt_passage] UM update warning: ' . $eUm->getMessage());
        }
    }
////////////////////////////////////////
    if (empty($entry_datetime) || empty($exit_datetime)) {
    throw new Exception("Data/ora ingresso o uscita non valorizzata!");
}

    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $receiptCode = 'R_' . $now->format('Ymd_His');
    $nowSql = $now->format('Y-m-d H:i:s');

    $stmtCheck = $db->prepare("SELECT idcassa FROM cassa WHERE idpassages = ? LIMIT 1");
    $stmtCheck->execute([$passageId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmtCassa = $db->prepare("
            UPDATE cassa
            SET
                Pticket_code   = ?,
                Ppaid          = ?,
                PpayC          = ?,
                PpayE          = ?,
                Pannullato     = ?,
                Pannultxt      = ?,
                fascia         = ?,
                invoice_price  = ?,
                invoice_code   = ?,
                Pexit_datetime = ?,
                updated_at     = ?
            WHERE idcassa = ?
            LIMIT 1
        ");
        $stmtCassa->execute([
            $ticket_code,
            $Tpaid,
            $TpayC,
            $TpayE,
            $Tannullato,
            $Tannultxt,
            $fascia,
            $price,
            $receiptCode,
            $exit_datetime,
            $nowSql,
            (int)$existing['idcassa']
        ]);
    } else {
        $stmtCassa = $db->prepare("
            INSERT INTO cassa (
                idpassages,
                Pticket_code,
                Ppaid,
                PpayC,
                PpayE,
                Pannullato,
                Pannultxt,
                fascia,
                invoice_price,
                invoice_code,
                Pexit_datetime,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtCassa->execute([
            $passageId,
            $ticket_code,
            $Tpaid,
            $TpayC,
            $TpayE,
            $Tannullato,
            $Tannultxt,
            $fascia,
            $price,
            $receiptCode,
            $exit_datetime,
            $nowSql,
            $nowSql
        ]);
    }

    $stmt = $db->prepare("
        INSERT INTO invoices_printed
          (passage_id, receipt_code, entry_datetime, exit_datetime, price, created_at, Tannullato, Tannultxt)
        VALUES
          (?, ?, ?, ?, ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $passageId,
        $receiptCode,
        $entry_datetime,
        $exit_datetime,
        $price,
        $Tannullato,
        $Tannultxt
    ]);

    $invoiceDir = rtrim(INVOICE_DIR, DIRECTORY_SEPARATOR);

    if (!is_dir($invoiceDir)) {
        @mkdir($invoiceDir, 0777, true);
    }

    if (!is_dir($invoiceDir) || !is_writable($invoiceDir)) {
        error_log("[ERRORE] INVOICE_DIR non accessibile: {$invoiceDir} exists=" . (is_dir($invoiceDir) ? '1' : '0') . " writable=" . (is_writable($invoiceDir) ? '1' : '0'));
        throw new Exception("Cartella INVOICE_DIR non scrivibile o inesistente: " . $invoiceDir);
    }

    $fileCreato = writeReceiptTxt($receiptCode, $invoiceDir . DIRECTORY_SEPARATOR, false, $db, (bool)$umFlag);

    if (is_string($fileCreato) && $fileCreato !== '' && strpos($fileCreato, DIRECTORY_SEPARATOR) === false) {
        $fileCreato = $invoiceDir . DIRECTORY_SEPARATOR . $fileCreato;
    }

    if (!$fileCreato || !file_exists($fileCreato)) {
        $candidate1 = $invoiceDir . DIRECTORY_SEPARATOR . $receiptCode . '.txt';
        $candidate2 = $invoiceDir . DIRECTORY_SEPARATOR . 'RECEIPT_' . $receiptCode . '.txt';

        if (file_exists($candidate1)) {
            $fileCreato = $candidate1;
        } elseif (file_exists($candidate2)) {
            $fileCreato = $candidate2;
        }
    }

    if (!$fileCreato || !file_exists($fileCreato)) {
        $err = error_get_last();
        error_log("[ERRORE] Ricevuta NON scritta o path errato. fileCreato=" . var_export($fileCreato, true) . " invoiceDir={$invoiceDir} phpError=" . var_export($err, true));
        throw new Exception('Errore fisico nella scrittura della ricevuta TXT!');
    }

    // ✅ Ricevuta: barcode = receiptCode, HRI visibile (true)
    $barcodeValue = trim((string)$receiptCode);
    if ($barcodeValue !== '') {
        $printResult = escpos_print_txt_with_barcode_from_file($fileCreato, $barcodeValue, true);
        if (!$printResult['success']) {
            error_log('[emit_receipt_passage] PRINT WARNING: ' . ($printResult['message'] ?? 'unknown'));
        }
    } else {
        error_log('[emit_receipt_passage] PRINT WARNING: receiptCode vuoto, barcode non stampato');
    }

    $response['success'] = true;
    $response['message'] = "Ricevuta passaggio emessa e dati aggiornati";
    $response['data'] = [
        'receipt_code' => $receiptCode,
        'INVOICE_URL' => defined('INVOICE_URL') ? INVOICE_URL : '',
        'RECEIPT_FILENAME' => basename($fileCreato)
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>