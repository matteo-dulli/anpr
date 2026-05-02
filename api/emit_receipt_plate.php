<?php

// ================== CONFIG PRIMA DI TUTTO ==================
require_once __DIR__ . '/../config/config.php';

// ================== DEBUG FATAL ==================
error_log('[PRINT DEBUG] ticket_code=' . var_export($ticket_code ?? null, true) . ' barcodeValue=' . var_export($barcodeValue ?? null, true) . ' file=' . var_export($fileCreato ?? null, true));
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOGS_DIR . '/emit_receipt_plate_fatal.log');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(
            LOGS_DIR . '/emit_receipt_plate_fatal.log',
            "\n[" . date('Y-m-d H:i:s') . "] FATAL: " . print_r($e, true) . "\n",
            FILE_APPEND
        );
    }
});
// ================================================================================
header('Content-Type: application/json; charset=utf-8');

if (ob_get_level() > 0) { @ob_clean(); }
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/functions.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => '', 'data' => null];

if (!function_exists('getTesseraScalareAlertThresholdFromCostanti')) {
    function getTesseraScalareAlertThresholdFromCostanti(): ?float {
        if (!defined('COSTANTI_FILE') || !file_exists(COSTANTI_FILE)) return null;

        $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return null;

        $in = false;
        foreach ($lines as $line) {
            $raw = trim((string)$line);
            if ($raw === '') continue;

            if (strpos($raw, '#') === 0) {
                $hdr = mb_strtoupper(preg_replace('/\s+/', ' ', $raw), 'UTF-8');
                if ($hdr === '# ALLARME TESSERESCALARE' || $hdr === '#ALLARME TESSERESCALARE') {
                    $in = true;
                    continue;
                }
                if ($in) break;
                continue;
            }

            if (!$in) continue;

            if (strpos($raw, '=') !== false) {
                [$k, $v] = array_map('trim', explode('=', $raw, 2));
                if (mb_strtoupper($k, 'UTF-8') === 'SOGLIA') {
                    if (is_numeric($v)) return (float)$v;
                }
            } else {
                if (is_numeric($raw)) return (float)$raw;
            }
        }

        return null;
    }
}

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $plateId = isset($body['plate_id']) ? (int)$body['plate_id'] : 0;
    $price   = isset($body['price']) ? floatval($body['price']) : 0;
    $um      = isset($body['um']) ? (int)$body['um'] : 0;

    if ($plateId <= 0) throw new Exception('ID targa non valido: ' . $plateId);

    $Tpaid      = isset($body['Tpaid']) ? (int)$body['Tpaid'] : 0;
    $TpayC      = isset($body['TpayC']) ? (int)$body['TpayC'] : 0;
    $TpayE      = isset($body['TpayE']) ? (int)$body['TpayE'] : 0;
    $Tannullato = isset($body['Tannullato']) ? (int)$body['Tannullato'] : 0;
    $Tannultxt  = isset($body['Tannultxt']) ? trim((string)$body['Tannultxt']) : '';

    $stmt = $db->prepare("
        SELECT 
            t.entry_date AS entry_date,
            t.entry_time AS entry_time,
            t.exit_date  AS exit_date,
            t.exit_time  AS exit_time,
            tp.ticket_code,
            tp.id AS tickets_printed_id,
            tp.entry_datetime AS entry_datetime,
            tp.exit_datetime  AS exit_datetime,
            tp.fascia AS tp_fascia
        FROM tickets_printed tp
        LEFT JOIN tickets t ON t.plate_id = tp.plate_id
        WHERE tp.plate_id = ?
        ORDER BY tp.id DESC
        LIMIT 1
    ");
    $stmt->execute([$plateId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket || empty($ticket['ticket_code'])) throw new Exception("Ticket non trovato");
    $ticket_code = $ticket['ticket_code'];
    $fasciaFromTicket = $ticket['tp_fascia'] ?? null;

    $stmtAnn = $db->prepare("
        SELECT COALESCE(Tannullato,0) AS Tannullato
        FROM cassa
        WHERE Tplate_id = ? AND Tticket_code = ?
        LIMIT 1
    ");
    $stmtAnn->execute([$plateId, $ticket_code]);
    $annRow = $stmtAnn->fetch(PDO::FETCH_ASSOC);
    if ($annRow && (int)$annRow['Tannullato'] === 1) {
        throw new Exception("Ticket annullato: non puoi emettere la ricevuta");
    }

    $entryDate = $ticket['entry_date'] ?? '';
    $entryTime = $ticket['entry_time'] ?? '';
    $exitDate  = $ticket['exit_date'] ?? '';
    $exitTime  = $ticket['exit_time'] ?? '';

    $entryDateTimeTS = $ticket['entry_datetime'] ?? '';
    $exitDateTimeTS  = $ticket['exit_datetime'] ?? '';

    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $nowSql = $now->format('Y-m-d H:i:s');

    if (empty($entryDate)) $entryDate = $now->format('Y-m-d');
    if (empty($entryTime)) $entryTime = $now->format('H:i');
    if (empty($exitDate))  $exitDate  = $now->format('Y-m-d');
    if (empty($exitTime))  $exitTime  = $now->format('H:i');

    if (strlen($entryTime) === 5) $entryTime .= ':00';
    if (strlen($exitTime) === 5)  $exitTime  .= ':00';

    $entryDateTime = "$entryDate " . substr($entryTime, 0, 5);
    $exitDateTime  = "$exitDate "  . substr($exitTime, 0, 5);

    if (!empty($ticket['tickets_printed_id']) && !empty($exitDateTime)) {
        if (empty($ticket['exit_datetime'])) {
            $stmtUpdateExit = $db->prepare("UPDATE tickets_printed SET exit_datetime = ? WHERE id = ?");
            $stmtUpdateExit->execute([$exitDateTime, $ticket['tickets_printed_id']]);
            $exitDateTimeTS = $exitDateTime;
        }
    }

    if (!empty($entryDateTimeTS) && !empty($exitDateTimeTS)) {
        $dtIn  = strtotime($entryDateTimeTS);
        $dtOut = strtotime($exitDateTimeTS);
    } else {
        $dtIn  = strtotime($entryDateTime);
        $dtOut = strtotime($exitDateTime);
    }
    $totMin = max(0, floor(($dtOut - $dtIn) / 60));
    $giorni = floor($totMin / 1440);
    $ore    = floor(($totMin % 1440) / 60);
    $minuti = $totMin % 60;

    $entryForInvoice = !empty($entryDateTimeTS) ? $entryDateTimeTS : $entryDateTime;
    $exitForInvoice  = !empty($exitDateTimeTS)  ? $exitDateTimeTS  : $exitDateTime;

    $receiptCode = 'R_' . $now->format('Ymd_His');

    $stmtCheck = $db->prepare("SELECT idcassa FROM cassa WHERE Tplate_id=? AND Tticket_code=? LIMIT 1");
    $stmtCheck->execute([$plateId, $ticket_code]);
    $rowCassa = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($rowCassa) {
        $query = "UPDATE cassa SET 
            invoice_code = ?,
            datacassa = ?, 
            oraincasso = ?,

            Tentry_date = ?,
            Tentry_time = ?,
            Texit_date  = ?,
            Texit_time  = ?,

            Tpaid = ?, TpayC = ?, TpayE = ?, Tannullato = ?, Tannultxt = ?,
            giorni = ?, ore = ?, minuti = ?,
            invoice_entry_datetime = ?, 
            invoice_exit_datetime = ?,

            prezzo = ?,
            invoice_price = ?,
            fascia = ?
            WHERE idcassa = ?";

        $stmt = $db->prepare($query);
        $stmt->execute([
            $receiptCode,
            $exitDate,
            substr($exitTime, 0, 5),

            $entryDate,
            $entryTime,
            $exitDate,
            $exitTime,

            $Tpaid, $TpayC, $TpayE, $Tannullato, $Tannultxt,
            $giorni, $ore, $minuti,
            $entryForInvoice, $exitForInvoice,

            $price,
            $price,
            $fasciaFromTicket,

            $rowCassa['idcassa']
        ]);
    } else {
        $query = "INSERT INTO cassa
            (
              Tplate_id, Tticket_code,
              invoice_code, datacassa, oraincasso,

              Tentry_date, Tentry_time,
              Texit_date,  Texit_time,

              Tpaid, TpayC, TpayE, Tannullato, Tannultxt,
              giorni, ore, minuti,
              invoice_entry_datetime, invoice_exit_datetime,

              prezzo, invoice_price,
              fascia
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($query);
        $stmt->execute([
            $plateId, $ticket_code,
            $receiptCode, $exitDate, substr($exitTime, 0, 5),

            $entryDate, $entryTime,
            $exitDate,  $exitTime,

            $Tpaid, $TpayC, $TpayE, $Tannullato, $Tannultxt,
            $giorni, $ore, $minuti,
            $entryForInvoice, $exitForInvoice,

            $price,
            $price,
            $fasciaFromTicket
        ]);
    }

    $stmt = $db->prepare("SELECT idcassa FROM cassa WHERE invoice_code = ? LIMIT 1");
    $stmt->execute([$receiptCode]);
    if ($stmt->rowCount() === 0) throw new Exception("Ricevuta NON salvata in cassa!");

    // Salva UM su tickets_printed se richiesto
    $ticketsPrintedId = (int)($ticket['tickets_printed_id'] ?? 0);
    if ($ticketsPrintedId > 0 && $um > 0) {
        try {
            $stmtUm = $db->prepare("UPDATE tickets_printed SET um = 1 WHERE id = ? LIMIT 1");
            $stmtUm->execute([$ticketsPrintedId]);
        } catch (Throwable $eUm) {
            error_log('[emit_receipt_plate] WARN um update: ' . $eUm->getMessage());
        }
    }

    $stmt = $db->prepare("INSERT INTO invoices (receipt_code, price, created_at, updated_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$receiptCode, $price, $nowSql, $nowSql]);

    $stmt = $db->prepare("SELECT id FROM invoices WHERE receipt_code = ? LIMIT 1");
    $stmt->execute([$receiptCode]);
    if ($stmt->rowCount() === 0) throw new Exception("Ricevuta NON salvata in invoices!");

    $stmt = $db->prepare("
        INSERT INTO invoices_printed
          (receipt_code, entry_datetime, exit_datetime, price, created_at, Tannullato, Tannultxt)
        VALUES
          (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $receiptCode,
        $entryForInvoice,
        $exitForInvoice,
        $price,
        $nowSql,
        $Tannullato,
        $Tannultxt
    ]);

    $stmt = $db->prepare("SELECT id FROM invoices_printed WHERE receipt_code = ? LIMIT 1");
    $stmt->execute([$receiptCode]);
    if ($stmt->rowCount() === 0) throw new Exception("Ricevuta NON salvata in invoices_printed!");

    $invoiceDir = rtrim(INVOICE_DIR, DIRECTORY_SEPARATOR);
    if (!is_dir($invoiceDir)) {
        @mkdir($invoiceDir, 0777, true);
    }
    if (!is_dir($invoiceDir) || !is_writable($invoiceDir)) {
        error_log("[ERRORE] INVOICE_DIR non accessibile: {$invoiceDir}");
        throw new Exception("Cartella INVOICE_DIR non scrivibile o inesistente: " . $invoiceDir);
    }

    $fileCreato = writeReceiptTxt($receiptCode, $invoiceDir . DIRECTORY_SEPARATOR, false, $db);
    if (!$fileCreato || !file_exists($fileCreato)) {
        throw new Exception('Errore fisico nella scrittura della ricevuta TXT!');
    }

    try {
        $stmtPN = $db->prepare("SELECT plate_corrected, plate_number FROM plates WHERE id=? LIMIT 1");
        $stmtPN->execute([$plateId]);
        $pRow = $stmtPN->fetch(PDO::FETCH_ASSOC);
        $plateNumber = trim((string)(($pRow['plate_corrected'] ?? '') ?: ($pRow['plate_number'] ?? '')));

        if ($plateNumber !== '') {
            $stmtT = $db->prepare("SELECT * FROM tesserapre WHERE plate_number=? AND canc=0 ORDER BY id DESC LIMIT 1");
            $stmtT->execute([$plateNumber]);
            $tess = $stmtT->fetch(PDO::FETCH_ASSOC);

            if ($tess && (int)($tess['attivo'] ?? 0) === 1) {
                $tesseraId = (int)$tess['id'];
                $resBefore = (float)($tess['res1'] ?? 0);
                $amountTotal = (float)$price;
                if ($amountTotal < 0) $amountTotal = 0;

                if ($amountTotal > 0 && $resBefore > 0) {
                    $amountScaled = min($resBefore, $amountTotal);
                    $amountRemaining = max(0, $amountTotal - $amountScaled);
                    $resAfter = $resBefore - $amountScaled;

                    if ($amountScaled > 0) {
                        $stmtUpd = $db->prepare("UPDATE tesserapre SET res1=? WHERE id=? LIMIT 1");
                        $stmtUpd->execute([$resAfter, $tesseraId]);

                        $stmtInv = $db->prepare("
                            UPDATE invoices_printed
                            SET tessera_id = ?, tessera_scaled = ?, tessera_res_after = ?
                            WHERE receipt_code = ?
                            LIMIT 1
                        ");
                        $stmtInv->execute([$tesseraId, $amountScaled, $resAfter, $receiptCode]);

                        $ticketsPrintedId = (int)($ticket['tickets_printed_id'] ?? 0);
                        if ($ticketsPrintedId > 0) {
                            try {
                                $stmtSc = $db->prepare("UPDATE tickets_printed SET scal=?, scal_amount=? WHERE id=? LIMIT 1");
                                $stmtSc->execute([$tesseraId, $amountScaled, $ticketsPrintedId]);
                            } catch (Throwable $eSc) {
                                $stmtSc = $db->prepare("UPDATE tickets_printed SET scal=? WHERE id=? LIMIT 1");
                                $stmtSc->execute([$tesseraId, $ticketsPrintedId]);
                            }
                        }

                        $thr = function_exists('getTesseraScalareAlertThresholdFromCostanti')
                            ? getTesseraScalareAlertThresholdFromCostanti()
                            : null;

                        if (!is_array($response['data'])) $response['data'] = [];
                        $response['data']['tessera_scalare'] = [
                            'tessera_id' => $tesseraId,
                            'scaled' => $amountScaled,
                            'remaining' => $amountRemaining,
                            'res_after' => $resAfter,
                            'threshold' => $thr
                        ];

                        if ($thr !== null && $resAfter <= (float)$thr) {
                            $response['data']['tessera_alert'] =
                                "⚠️ Credito tessera in esaurimento (residuo €" . number_format($resAfter, 2, '.', '') . ")";
                        }
                    }
                }
            }
        }
    } catch (Throwable $eTess) {
        error_log('[emit_receipt_plate] Tessera scalare warning: ' . $eTess->getMessage());
    }

    $fileCreato = writeReceiptTxt($receiptCode, $invoiceDir . DIRECTORY_SEPARATOR, false, $db);
    if (!$fileCreato || !file_exists($fileCreato)) {
        throw new Exception('Errore fisico nella scrittura della ricevuta TXT (rigenerazione dopo tessera)!');
    }

    $barcodeValue = trim((string)$ticket_code);
    if ($barcodeValue !== '') {
        $printResult = escpos_print_txt_with_barcode_from_file($fileCreato, $barcodeValue);
        if (!$printResult['success']) {
            error_log('[emit_receipt_plate] PRINT WARNING: ' . $printResult['message']);
        }
    }

    $response['success'] = true;
    $response['message'] = "Ricevuta emessa e dati aggiornati";
    $response['data'] = array_merge(
        is_array($response['data']) ? $response['data'] : [],
        [
            'receipt_code' => $receiptCode,
            'RECEIPT_FILENAME' => isset($fileCreato) ? basename($fileCreato) : ''
        ]
    );

} catch (Throwable $e) {
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('EMIT_RECEIPT_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;