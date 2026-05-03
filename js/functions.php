<?php
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
 * =========================
 * ✅ NEW: STAMPA ESC/POS (TCP RAW 9100) + BARCODE CODE128
 * Carina A: stampa il TXT e, quando incontra "BARCODE: ...",
 * NON stampa quella riga come testo: stampa il barcode vero con ticket_code.
 * =========================
 */

function escpos_get_printer_config(): array {
    // config.php carica già COSTANTI in $GLOBALS['COSTANTI']
    $c = $GLOBALS['COSTANTI'] ?? [];

    $ip = trim((string)($c['IP'] ?? ''));
    $portRaw = $c['PORT'] ?? $c['Port'] ?? 9100;
    $port = is_numeric($portRaw) ? (int)$portRaw : 9100;

    return [
        'ip' => $ip,
        'port' => $port,
    ];
}

function escpos_connect_tcp(string $ip, int $port) {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($ip, $port, $errno, $errstr, 2.5);
    if (!$fp) return [null, "Connessione stampante fallita {$ip}:{$port} ({$errno}) {$errstr}"];
    stream_set_timeout($fp, 2);
    return [$fp, ''];
}

function escpos_w($fp, string $bytes): void {
    @fwrite($fp, $bytes);
}

function escpos_init($fp): void {
    // ESC @
    escpos_w($fp, "\x1B\x40");
}

function escpos_align($fp, int $n): void {
    // ESC a n (0 left, 1 center, 2 right)
    escpos_w($fp, "\x1B\x61" . chr($n));
}

function escpos_bold($fp, bool $on): void {
    // ESC E n
    escpos_w($fp, "\x1B\x45" . chr($on ? 1 : 0));
}

function escpos_size($fp, int $wMul, int $hMul): void {
    // GS ! n -> n = (w-1)<<4 | (h-1)
    $wMul = max(1, min(8, $wMul));
    $hMul = max(1, min(8, $hMul));
    $n = (($wMul - 1) << 4) | ($hMul - 1);
    escpos_w($fp, "\x1D\x21" . chr($n));
}

function escpos_ln($fp, int $n = 1): void {
    escpos_w($fp, str_repeat("\n", max(1, $n)));
}

function escpos_cut($fp): void {
    // GS V 1
    escpos_w($fp, "\x1D\x56\x01");
}

function escpos_text($fp, string $txt): void {
    // normalizza CRLF e caratteri
    $txt = str_replace("\r\n", "\n", $txt);
    $txt = str_replace("\r", "\n", $txt);
    escpos_w($fp, $txt);
}

function escpos_barcode_code128($fp, string $data): void {
    $data = trim($data);
    if ($data === '') return;

    // center barcode
    escpos_align($fp, 1);

    // HRI (testo sotto barcode): GS H n (0 none, 1 above, 2 below, 3 both)
    escpos_w($fp, "\x1D\x48\x02"); // sotto

    // font HRI: GS f n (0 A, 1 B)
    escpos_w($fp, "\x1D\x66\x00");

    // altezza: GS h n
    escpos_w($fp, "\x1D\x68" . chr(80));

    // larghezza modulo: GS w n (2-6 tipico)
    escpos_w($fp, "\x1D\x77" . chr(3));

    /**
     * CODE128 in ESC/POS (GS k):
     * - Molte stampanti supportano: GS k 73 n d1..dn   (73 = 0x49)
     * - n = length
     *
     * ✅ Nota: per Code Set B spesso si premette "{B"
     * Quasi tutte accettano ASCII con {B.
     */
    $payload = "{B" . $data;
    $len = strlen($payload);

    // GS k m n ...  (m=0x49)
    escpos_w($fp, "\x1D\x6B\x49" . chr($len) . $payload);

    escpos_ln($fp, 2);
    escpos_align($fp, 0);
}

function escpos_print_txt_with_barcode(string $txt, string $barcodeValue): array {
    $cfg = escpos_get_printer_config();
    if (empty($cfg['ip'])) {
        return ['success' => false, 'message' => 'IP stampante non configurato in costanti.txt (chiave IP)'];
    }

    [$fp, $err] = escpos_connect_tcp($cfg['ip'], $cfg['port']);
    if (!$fp) {
        return ['success' => false, 'message' => $err];
    }

    try {
        escpos_init($fp);

        // ✅ header "carino": prima riga in grassetto/centrata se presente
        // (usiamo sempre il TXT come fonte, senza cambiarlo)
        $lines = preg_split("/\n/", str_replace("\r", "", $txt));

        $printedHeader = false;

        foreach ($lines as $i => $line) {
            $line = rtrim($line, "\n");
            $trim = trim($line);

            // intercetta riga barcode
            if (stripos($trim, 'BARCODE:') === 0) {
                // ❌ DISATTIVATO: stampare come testo
                // escpos_text($fp, $line . "\n");

                // ✅ NEW: barcode vero (solo ticket_code come richiesto)
                escpos_barcode_code128($fp, $barcodeValue);
                continue;
            }

            // "carina A" minimale:
            // - se la riga è la ragione sociale (dopo =====), la centriamo e la mettiamo più grande
            if (!$printedHeader && $trim !== '' && strpos($trim, '====') === false && strpos($trim, '----') === false) {
                $printedHeader = true;
                escpos_align($fp, 1);
                escpos_bold($fp, true);
                escpos_size($fp, 2, 2);
                escpos_text($fp, $trim . "\n");
                escpos_size($fp, 1, 1);
                escpos_bold($fp, false);
                escpos_align($fp, 0);
                continue;
            }

            // linee di separazione un po' più lunghe (80mm)
            if (preg_match('/^=+$/', $trim)) {
                escpos_text($fp, str_repeat('=', 42) . "\n");
                continue;
            }
            if (preg_match('/^-+$/', $trim)) {
                escpos_text($fp, str_repeat('-', 42) . "\n");
                continue;
            }

            escpos_text($fp, $line . "\n");
        }

        escpos_ln($fp, 2);
        escpos_cut($fp);

        @fclose($fp);
        return ['success' => true, 'message' => "Stampato su {$cfg['ip']}:{$cfg['port']}"];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['success' => false, 'message' => 'Errore stampa: ' . $e->getMessage()];
    }
}

function escpos_print_txt_with_barcode_from_file(string $filePath, string $barcodeValue): array {
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'File non trovato: ' . $filePath];
    }
    $txt = @file_get_contents($filePath);
    if ($txt === false) {
        return ['success' => false, 'message' => 'Impossibile leggere file: ' . $filePath];
    }
    return escpos_print_txt_with_barcode($txt, $barcodeValue);
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