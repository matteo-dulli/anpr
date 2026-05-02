<?php
require_once __DIR__ . '/escpos.php';
function cleanDateTimeNoSec($dt) {
    if (!$dt) return '';
    if ($dt instanceof DateTimeInterface) {
        return $dt->format('d/m/Y H:i');
    }
    $s = trim((string)$dt);
    if ($s === '') return '';
    $ts = strtotime($s);
    if ($ts === false) {
        return preg_replace('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}):\d{2}$/', '$1', $s);
    }
    return date('d/m/Y H:i', $ts);
}

/**
 * =====================================================================
 * NOTA IMPORTANTE:
 * In questo file NON dovrebbero esserci funzioni escpos_* duplicate.
 * Se decidi di usare api/escpos.php come unico modulo stampa,
 * sposta/lascia lì tutte le funzioni escpos_* e rimuovile da qui.
 * =====================================================================
 */

/**
 * (Se ti serve ancora EAN13, spostala in escpos.php perché dipende da
 * escpos_align/escpos_w/escpos_feed che stanno in escpos.php.)
 */
function escpos_barcode_ean13($fp, string $digits12): void {
    $digits12 = preg_replace('/\D+/', '', $digits12);
    $digits12 = substr($digits12, 0, 12);
    if (strlen($digits12) !== 12) return;

    escpos_align($fp, 1);
    escpos_w($fp, "\x1D\x48\x02");       // HRI sotto
    escpos_w($fp, "\x1D\x68" . chr(80)); // altezza
    escpos_w($fp, "\x1D\x77" . chr(3));  // larghezza

    // EAN13: m = 67 (0x43) con lunghezza (spesso è la forma più supportata)
    escpos_w($fp, "\x1D\x6B" . chr(67) . chr(12) . $digits12);

    escpos_feed($fp, 2);
    escpos_align($fp, 0);
}

/**
 * =========================
 * ✅ ESISTENTE: writeReceiptTxt
 * (non cambiamo il formato TXT)
 * =========================
 */

function computeDurationParts($start, $end): array {
    $ts1 = strtotime((string)$start);
    $ts2 = strtotime((string)$end);
    if ($ts1 === false || $ts2 === false) {
        return ['giorni' => 0, 'ore' => 0, 'minuti' => 0, 'valid' => false];
    }

    $diff = $ts2 - $ts1;
    if ($diff < 0) $diff = 0;

    $mins = (int) floor($diff / 60);
    $giorni = (int) floor($mins / (60 * 24));
    $mins -= $giorni * 60 * 24;
    $ore = (int) floor($mins / 60);
    $minuti = (int) ($mins - $ore * 60);

    return ['giorni' => $giorni, 'ore' => $ore, 'minuti' => $minuti, 'valid' => true];
}

function writeReceiptTxt($receiptCode, $dir = null, $isReprint = false, $db = null) {
    if (!$db) {
        require_once __DIR__ . '/../config/config.php';
        $db = getDatabaseConnection();
    }

    if ($dir === null) {
        $dir = defined('INVOICE_DIR') ? rtrim(INVOICE_DIR, "/\\") . DIRECTORY_SEPARATOR : '/tmp/';
    } else {
        // assicura trailing separator
        $dir = rtrim((string)$dir, "/\\") . DIRECTORY_SEPARATOR;
    }

    // ===== INFO GARAGE =====
    $qr = $db->query("SELECT * FROM garage_info WHERE id=1 LIMIT 1");
    $info = $qr ? $qr->fetch(PDO::FETCH_ASSOC) : [];

    $ragione_sociale = $info['ragione_sociale'] ?? 'Garage';
    $indirizzo = trim($info['indirizzo'] ?? '');
    $cap = trim($info['cap'] ?? '');
    $citta = trim($info['citta'] ?? '');
    $provincia = trim($info['provincia'] ?? '');
    $piva = trim($info['piva'] ?? '');
    $cf = trim($info['cf'] ?? '');
    $tel = trim($info['tel'] ?? '');
    $cell = trim($info['cell'] ?? '');
    $email = trim($info['email'] ?? '');

    $data = null;

    // ===== 1) QUERY COMPLETA (JOIN con cassa/plates) =====
    try {
        $stmt = $db->prepare("
            SELECT
                ip.receipt_code,
                ip.price AS ip_price,

                ip.tessera_id,
                ip.tessera_scaled,
                ip.tessera_res_after,

                c.Tplate_id AS plate_id,
                p.plate_number,

                -- ✅ FIX: fallback anche su invoices_printed
                COALESCE(c.Pentry_datetime, c.invoice_entry_datetime, ip.entry_datetime) AS ingresso,
                COALESCE(c.Pexit_datetime,  c.invoice_exit_datetime,  ip.exit_datetime)  AS uscita,

                c.giorni,
                c.ore,
                c.minuti,

                COALESCE(c.invoice_price, c.prezzo, ip.price) AS prezzo,
                COALESCE(c.Pticket_code, c.Tticket_code) AS ticket_code,
                c.idpassages
            FROM invoices_printed ip
            LEFT JOIN cassa c ON c.invoice_code = ip.receipt_code
            LEFT JOIN plates p ON p.id = c.Tplate_id
            WHERE ip.receipt_code = ?
            LIMIT 1
        ");
        $stmt->execute([$receiptCode]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $data = null;
    }

    // ===== 2) FALLBACK: invoices_printed (minimo indispensabile) =====
    if (!$data) {
        try {
            $stmt = $db->prepare("
                SELECT
                    receipt_code,
                    price AS prezzo,
                    entry_datetime AS ingresso,
                    exit_datetime  AS uscita,
                    tessera_id,
                    tessera_scaled,
                    tessera_res_after
                FROM invoices_printed
                WHERE receipt_code = ?
                LIMIT 1
            ");
            $stmt->execute([$receiptCode]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $data['plate_id'] = null;
                $data['plate_number'] = null;
                $data['giorni'] = 0;
                $data['ore'] = 0;
                $data['minuti'] = 0;
                $data['ticket_code'] = '';
                $data['idpassages'] = null;
            }
        } catch (Throwable $e) {
            $data = null;
        }
    }

    if (!$data) return false;

    // ===== TESSERA: leggila sempre da invoices_printed (robusto) =====
    try {
        $stmtTess = $db->prepare("
            SELECT tessera_id, tessera_scaled, tessera_res_after
            FROM invoices_printed
            WHERE receipt_code = ?
            LIMIT 1
        ");
        $stmtTess->execute([$receiptCode]);
        $tRow = $stmtTess->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $data['tessera_id'] = $tRow['tessera_id'] ?? null;
            $data['tessera_scaled'] = $tRow['tessera_scaled'] ?? null;
            $data['tessera_res_after'] = $tRow['tessera_res_after'] ?? null;
        }
    } catch (Throwable $e) {
        // ignore
    }

    // ===== INGRESSO/USCITA =====
    $ingressoRaw = $data['ingresso'] ?? '';
    $uscitaRaw   = $data['uscita'] ?? '';

    $ingresso = cleanDateTimeNoSec($ingressoRaw);
    $uscita   = cleanDateTimeNoSec($uscitaRaw);

    // ===== ✅ DURATA FIX (calcolo da datetime se parti 0) =====
    $giorni = (int)($data['giorni'] ?? 0);
    $ore    = (int)($data['ore'] ?? 0);
    $minuti = (int)($data['minuti'] ?? 0);

    $hasParts = ($giorni > 0 || $ore > 0 || $minuti > 0);
    if (!$hasParts) {
        $computed = computeDurationParts($ingressoRaw, $uscitaRaw);
        if ($computed['valid']) {
            $giorni = $computed['giorni'];
            $ore    = $computed['ore'];
            $minuti = $computed['minuti'];
        }
    }

    $durata = '';
    if ($giorni > 0) $durata .= $giorni . 'g ';
    if ($ore > 0)    $durata .= $ore . 'h ';
    if ($minuti > 0) $durata .= $minuti . 'm';
    $durata = trim($durata);
    if ($durata === '') $durata = '0m';

    // ===== PREZZO =====
    $importo = number_format((float)($data['prezzo'] ?? 0), 2, ',', '.');

    // ===== RIGHE OPZIONALI =====
    $ticketLine = '';
    if (!empty($data['ticket_code'])) {
        $ticketLine = "TICKET: {$data['ticket_code']}\n";
    }

    $passageLine = '';
    if (!empty($data['idpassages'])) {
        $passageLine = "ID PASSAGGIO: {$data['idpassages']}\n";
    }

    $barcodeValue = !empty($data['ticket_code'])
        ? $data['ticket_code']
        : ($data['receipt_code'] ?? $receiptCode);

    $plateNumber = !empty($data['plate_number']) ? $data['plate_number'] : '-';
    $plateId     = isset($data['plate_id']) ? $data['plate_id'] : '-';

    // ===== TESSERA LINE (come tua, robusta) =====
    $tesseraLineFinal = '';
    $rc = trim((string)($data['receipt_code'] ?? $receiptCode ?? ''));
    if ($rc !== '' && $db instanceof PDO) {
        $stmtTess = $db->prepare("
            SELECT tessera_id, tessera_scaled, tessera_res_after
            FROM invoices_printed
            WHERE receipt_code = ?
            LIMIT 1
        ");
        $stmtTess->execute([$rc]);
        $tRow = $stmtTess->fetch(PDO::FETCH_ASSOC) ?: [];

        $tid = (int)($tRow['tessera_id'] ?? 0);
        $tscRaw = $tRow['tessera_scaled'] ?? null;
        $traRaw = $tRow['tessera_res_after'] ?? null;

        $hasScaled = ($tscRaw !== null && $tscRaw !== '' && is_numeric($tscRaw));
        $hasResAfter = ($traRaw !== null && $traRaw !== '' && is_numeric($traRaw));

        if ($tid > 0 && $hasScaled && $hasResAfter) {
            $scaled = number_format((float)$tscRaw, 2, ',', '.');
            $resid  = number_format((float)$traRaw, 2, ',', '.');
            $tesseraLineFinal = "TESSERA N. {$tid}: DETRATTO € {$scaled}    RESIDUO: € {$resid}\n";
        }
    }

    // ===== CORPO RICEVUTA (FORMATO INVARIATO) =====
    $body =
"================================
{$ragione_sociale}
{$indirizzo} {$cap} {$citta} ({$provincia})
P.IVA: {$piva}   CF: {$cf}
Tel: {$tel}   Cell: {$cell}
Email: {$email}
--------------------------------
RICEVUTA: {$data['receipt_code']}
{$ticketLine}{$passageLine}TARGA: {$plateNumber}
ID_TARGA: {$plateId}
INGRESSO: {$ingresso}
USCITA:   {$uscita}
DURATA:   {$durata}
IMPORTO:  € {$importo}
{$tesseraLineFinal}--------------------------------
Presentare questo biglietto al ritiro del veicolo. 
Present this ticket when collecting the vehicle.

BARCODE: {$barcodeValue}
================================
";

    // ===== FILE NAME =====
    $baseFn = "RECEIPT_{$data['receipt_code']}";

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    if ($isReprint) {
        $i = 1;
        $filename = "{$dir}{$baseFn}_{$i}.txt";
        while (file_exists($filename) && $i < 1000) {
            $i++;
            $filename = "{$dir}{$baseFn}_{$i}.txt";
        }
    } else {
        $filename = "{$dir}{$baseFn}.txt";
    }

    $ok = @file_put_contents($filename, $body);
    if ($ok === false) {
        return false;
    }

    return $filename;
}

// ============================================================================
// ✅ PATCH: evita Fatal "Cannot redeclare getTesseraScalareAlertThresholdFromCostanti"
// Se è già definita in un altro file (es. emit_receipt_plate.php), questa viene saltata.
// NON cancelliamo nulla: la funzione resta identica, solo protetta.
// ============================================================================
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

            // supporto "SOGLIA = 5" oppure "5"
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
// ============================================================================
?>