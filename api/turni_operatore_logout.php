<?php
/**
 * turni_operatore_logout.php
  */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';
    $id_sessione   = isset($body['id_sessione']) ? (int)$body['id_sessione'] : 0;

    if ($operatore_cod === '') {
        throw new Exception('operatore_cod obbligatorio');
    }

    $now = date('Y-m-d H:i:s');

    $db->beginTransaction();

    // ============================================================
    // 0) MIGRATION FALLBACK: se manca id_sessione, ricava/crea sessione
    // ============================================================
    if ($id_sessione <= 0) {
        // 0.1) prova a recuperare un turno online in turni_sessioni per questo operatore
        try {
            $stmtFind = $db->prepare("
    SELECT id, stato
    FROM turni_sessioni
    WHERE operatore_cod=?
    ORDER BY
      (stato='online') DESC,
      inizio DESC
    LIMIT 1
");
            $stmtFind->execute([$operatore_cod]);
            $rowFind = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if ($rowFind && !empty($rowFind['id'])) {
                $id_sessione = (int)$rowFind['id'];
            }
        } catch (Throwable $e) {
            // ignore: tabella potrebbe non esistere o altro
        }

        // 0.2) se ancora non esiste, crea sessione usando operatori_turni.inizio_turno
        if ($id_sessione <= 0) {
            $stmtOp = $db->prepare("
                SELECT inizio_turno
                FROM operatori_turni
                WHERE operatore_cod=? AND stato='online'
                LIMIT 1
            ");
            $stmtOp->execute([$operatore_cod]);
            $op = $stmtOp->fetch(PDO::FETCH_ASSOC);

            if (!$op || empty($op['inizio_turno'])) {
                throw new Exception('id_sessione obbligatorio (nessun turno online recuperabile)');
            }

            $inizio = $op['inizio_turno'];

            // numero progressivo unico
            $stmtNum = $db->query("SELECT COALESCE(MAX(numero_turno), 0) + 1 AS next_num FROM turni_sessioni");
            $nextNum = (int)($stmtNum->fetch(PDO::FETCH_ASSOC)['next_num'] ?? 1);
            if ($nextNum <= 0) $nextNum = 1;

            $insSess = $db->prepare("
                INSERT INTO turni_sessioni (numero_turno, operatore_cod, stato, inizio)
                VALUES (?, ?, 'online', ?)
            ");
            $insSess->execute([$nextNum, $operatore_cod, $inizio]);

            $id_sessione = (int)$db->lastInsertId();
        }
    }

    // ============================================================
    // 1) Leggi sessione turno
    // ============================================================
    $stmtSess = $db->prepare("
        SELECT id, numero_turno, operatore_cod, stato, inizio, fine, file_path
        FROM turni_sessioni
        WHERE id = ?
        LIMIT 1
    ");
    $stmtSess->execute([$id_sessione]);
    $sess = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        throw new Exception("Sessione turno non trovata (id_sessione=$id_sessione)");
    }

    $inizio = $sess['inizio'] ?: $now;

    // ============================================================
    // 2) Chiudi turni_sessioni
    // ============================================================
    $updSess = $db->prepare("
        UPDATE turni_sessioni
        SET stato='offline', fine=?, updated_at=NOW()
        WHERE id=?
        LIMIT 1
    ");
    $updSess->execute([$now, $id_sessione]);

    // ============================================================
    // 3) Chiudi legacy operatori_turni (se presente)
    // ============================================================
    try {
        $stmtOp = $db->prepare("
            SELECT id, operatore_cod, stato, inizio_turno, ore_totali
            FROM operatori_turni
            WHERE operatore_cod = ?
            LIMIT 1
        ");
        $stmtOp->execute([$operatore_cod]);
        $turnoOp = $stmtOp->fetch(PDO::FETCH_ASSOC);

        if ($turnoOp) {
            $ore_totali = 0;
            if (!empty($turnoOp['inizio_turno'])) {
                $inizioDT = new DateTime($turnoOp['inizio_turno']);
                $fineDT   = new DateTime($now);
                $diff = $fineDT->diff($inizioDT);
                $ore_totali = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
            }

            $stmtUpdOp = $db->prepare("
                UPDATE operatori_turni
                SET stato='offline', fine_turno=?, logout_time=?, ore_totali=ore_totali + ?, updated_at=NOW()
                WHERE operatore_cod=?
                LIMIT 1
            ");
            $stmtUpdOp->execute([$now, $now, $ore_totali, $operatore_cod]);
        }
    } catch (Throwable $e) {
        // non bloccare
        if (function_exists('logEvent')) {
            logEvent('error', 'TURNI_LOGOUT_WARN(operatori_turni): ' . $e->getMessage());
        }
    }

    // ============================================================
    // 4) Popola idturno (best-effort) nel periodo [inizio, now]
    // ============================================================
    $rangeStart = $inizio;
    $rangeEnd   = $now;

    $bestEffortExec = function(string $sql, array $params) use ($db) {
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            if (function_exists('logEvent')) {
                logEvent('error', 'TURNI_IDTURNO_UPDATE_WARN: ' . $e->getMessage(), ['sql' => $sql]);
            }
        }
    };

    // cassa (created_at)
    $bestEffortExec(
        "UPDATE cassa
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND created_at BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // invoices (created_at)
    $bestEffortExec(
        "UPDATE invoices
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND created_at BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // invoices_printed (created_at)
    $bestEffortExec(
        "UPDATE invoices_printed
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND created_at BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // passages (entry_datetime)
    $bestEffortExec(
        "UPDATE passages
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND entry_datetime BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // tickets (created_at) - se la colonna non esiste, viene ignorato
    $bestEffortExec(
        "UPDATE tickets
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND created_at BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // tickets_printed (created_at)
    $bestEffortExec(
        "UPDATE tickets_printed
         SET idturno = ?
         WHERE (idturno IS NULL OR idturno = 0)
           AND created_at BETWEEN ? AND ?",
        [$id_sessione, $rangeStart, $rangeEnd]
    );

    // ============================================================
    // 5) Statistiche minime da cassa (coerenti con turni_stampa.php)
    // ============================================================
    $stmtStats = $db->prepare("
        SELECT
          SUM(CASE WHEN COALESCE(Pticket_code,'') <> '' THEN 1 ELSE 0 END) AS ticket_emessi,
          SUM(CASE WHEN COALESCE(Pannullato,0) = 1 THEN 1 ELSE 0 END)     AS ticket_annullati_p,
          SUM(CASE WHEN COALESCE(Tticket_code,'') <> '' THEN 1 ELSE 0 END) AS ticket_targa,
          SUM(CASE WHEN COALESCE(Tannullato,0) = 1 THEN 1 ELSE 0 END)      AS ticket_annullati_t,

          SUM(CASE WHEN COALESCE(invoice_code,'') <> '' THEN 1 ELSE 0 END) AS ricevute,

          SUM(CASE WHEN COALESCE(PpayC,0)=1 OR COALESCE(TpayC,0)=1 THEN 1 ELSE 0 END) AS pagamenti_cash,
          SUM(CASE WHEN COALESCE(PpayE,0)=1 OR COALESCE(TpayE,0)=1 THEN 1 ELSE 0 END) AS pagamenti_elett,

          SUM(CASE WHEN COALESCE(PpayC,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END)
            + SUM(CASE WHEN COALESCE(TpayC,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END) AS importo_contante,

          SUM(CASE WHEN COALESCE(PpayE,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END)
            + SUM(CASE WHEN COALESCE(TpayE,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END) AS importo_online

        FROM cassa
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmtStats->execute([$rangeStart, $rangeEnd]);
    $st = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

    $ticket_emessi = (int)($st['ticket_emessi'] ?? 0);
    $ticket_annullati = (int)($st['ticket_annullati_p'] ?? 0) + (int)($st['ticket_annullati_t'] ?? 0);
    $ricevute = (int)($st['ricevute'] ?? 0);

    $importo_contante = (float)($st['importo_contante'] ?? 0);
    $importo_online   = (float)($st['importo_online'] ?? 0);
    $importo_totale   = $importo_contante + $importo_online;

    $pag_cash  = (int)($st['pagamenti_cash'] ?? 0);
    $pag_elett = (int)($st['pagamenti_elett'] ?? 0);
    $pag_num   = $pag_cash + $pag_elett;

    $updStats = $db->prepare("
        UPDATE turni_sessioni
        SET
          ticket_emessi=?, ticket_annullati=?, ricevute=?,
          importo_contante=?, importo_online=?, importo_totale=?,
          pagamenti_cash=?, pagamenti_elett=?, pagamenti_num=?,
          updated_at=NOW()
        WHERE id=?
        LIMIT 1
    ");
    $updStats->execute([
        $ticket_emessi, $ticket_annullati, $ricevute,
        $importo_contante, $importo_online, $importo_totale,
        $pag_cash, $pag_elett, $pag_num,
        $id_sessione
    ]);

    // ============================================================
    // 6) Crea TXT in /turni e salva file_path
    // ============================================================
    $numFormatted = str_pad((int)$sess['numero_turno'], 3, '0', STR_PAD_LEFT);
    $dataFmt      = $inizio ? date('d-m-Y', strtotime($inizio)) : date('d-m-Y');
    $oraInizio    = $inizio ? date('H:i:s', strtotime($inizio)) : '--:--:--';
    $oraFine      = date('H:i:s', strtotime($now));

    $fileName = $numFormatted . '_' . $dataFmt . '.TXT';
    $turniDir = PROJECT_ROOT . '/turni';
    if (!is_dir($turniDir)) @mkdir($turniDir, 0755, true);
// ✅ definisci SEMPRE inizio/fine turno prima di usarli
$fineTurno = date('Y-m-d H:i:s');

// se hai già $sess (sessione turno), usa quello come inizio
$inizioTurno = !empty($sess['inizio']) ? $sess['inizio'] : null;

// fallback: se per qualche motivo manca, prova da operatori_turni
if (!$inizioTurno) {
    $stOp = $db->prepare("SELECT inizio_turno FROM operatori_turni WHERE operatore_cod=? LIMIT 1");
    $stOp->execute([$operatore_cod]);
    $opRow = $stOp->fetch(PDO::FETCH_ASSOC);
    $inizioTurno = !empty($opRow['inizio_turno']) ? $opRow['inizio_turno'] : $fineTurno;
}
    $stats = getTurnoStats($db, $inizioTurno, $fineTurno); // già include presenti se l'hai messo dentro
$mov = getPresentiMovimentiAttuali($db);
$totali = calcTotali($mov);
// ✅ ragione sociale (intestazione)
$garage = getGarageRagioneSociale($db);
if (!is_string($garage) || trim($garage) === '') {
    $garage = 'GARAGE';
}

// ✅ numero turno tipo 002 / 003
$numFormatted = str_pad((string)($sess['numero_turno'] ?? 0), 3, '0', STR_PAD_LEFT);

// ✅ operatore (fallback se manca)
$operatore_cod = $operatore_cod ?? ($sess['operatore_cod'] ?? '');
$txt = buildTurnoTxtCustom(
    $garage,
    $numFormatted,
    $operatore_cod,
    $inizioTurno,
    $fineTurno,
    $stats,
    $mov,
    $totali
);
    
    $fileFull = $turniDir . '/' . $fileName;
    if (@file_put_contents($fileFull, $txt) === false) {
        throw new Exception("Impossibile scrivere file turno: $fileFull");
    }

    $updFile = $db->prepare("UPDATE turni_sessioni SET file_path=? WHERE id=? LIMIT 1");
    $updFile->execute([$fileName, $id_sessione]);

    // ============================================================
    // 7) Stampa ESC/POS (senza barcode)
    // ============================================================
    $printRes = escpos_print_txt_with_barcode($txt, '', true);

    $db->commit();

    if (function_exists('logEvent')) {
        logEvent('turni', "LOGOUT: Operatore $operatore_cod - id_sessione=$id_sessione file=$fileName print=" . (($printRes['success'] ?? false) ? 'OK' : 'KO'));
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno chiuso";
    $response['data'] = [
        'id_sessione'    => $id_sessione,
        'numero_turno'   => (int)$sess['numero_turno'],
        'operatore_cod'  => $operatore_cod,
        'inizio'         => $inizio,
        'fine'           => $now,
        'file_path'      => $fileName,
        'print_success'  => (bool)($printRes['success'] ?? false),
        'print_message'  => (string)($printRes['message'] ?? '')
    ];

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_LOGOUT_ERROR: ' . $e->getMessage());
    }
}

function getGarageRagioneSociale(PDO $db): string {
    try {
        $st = $db->query("SELECT ragione_sociale FROM garage_info LIMIT 1");
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $rs = trim((string)($row['ragione_sociale'] ?? ''));
        return $rs !== '' ? $rs : 'GARAGE';
    } catch (Throwable $e) {
        return 'GARAGE';
    }
}
function fmtDateIt(?string $dt): string {
    if (!$dt) return '--/--/----';
    $ts = strtotime($dt);
    if ($ts === false) return '--/--/----';
    return date('d/m/Y', $ts);
}
function fmtTimeIt(?string $dt): string {
    if (!$dt) return '--:--:--';
    $ts = strtotime($dt);
    if ($ts === false) return '--:--:--';
    return date('H:i:s', $ts);
}
function getPresentiCounter(PDO $db): int {
    $sql = "
      SELECT
        COUNT(DISTINCT CASE
          WHEN NOT EXISTS (
            SELECT 1
            FROM cassa c
            WHERE (c.Tticket_code = tp.ticket_code OR c.Pticket_code = tp.ticket_code)
              AND (
                   TRIM(COALESCE(c.invoice_code,'')) <> ''
                OR COALESCE(c.Tannullato,0) = 1
                OR COALESCE(c.Pannullato,0) = 1
              )
          )
          THEN tp.id
        END) AS presenti_t
      FROM tickets_printed tp
      WHERE TRIM(COALESCE(tp.ticket_code,'')) <> ''
    ";
    $st = $db->query($sql);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int)($row['presenti_t'] ?? 0);
}
function getTurnoStats(PDO $db, string $start, string $end): array {
    $stats = [
        'ticket_emessi' => 0,
        'ticket_annullati' => 0,
        'ricevute_emesse' => 0,
        'um_count' => 0,
        'pagamenti_n' => 0,
        'cash_n' => 0,
        'elett_n' => 0,
        'contante_eur' => 0.0,
        'online_eur' => 0.0,
        'totale_eur' => 0.0,
        'presenti' => 0,
    ];

    // ricevute/pagamenti/annullati da cassa
    $st = $db->prepare("
        SELECT
          SUM(CASE WHEN COALESCE(invoice_code,'')<>'' THEN 1 ELSE 0 END) AS ricevute,

          SUM(CASE WHEN COALESCE(Pannullato,0)=1 THEN 1 ELSE 0 END) AS pann,
          SUM(CASE WHEN COALESCE(Tannullato,0)=1 THEN 1 ELSE 0 END) AS tann,

          SUM(CASE WHEN COALESCE(Ppaid,0)=1 OR COALESCE(Tpaid,0)=1 THEN 1 ELSE 0 END) AS pagamenti_n,

          SUM(CASE WHEN COALESCE(PpayC,0)=1 OR COALESCE(TpayC,0)=1 THEN 1 ELSE 0 END) AS cash_n,
          SUM(CASE WHEN COALESCE(PpayE,0)=1 OR COALESCE(TpayE,0)=1 THEN 1 ELSE 0 END) AS elett_n,

          ( SUM(CASE WHEN COALESCE(PpayC,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END)
          + SUM(CASE WHEN COALESCE(TpayC,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END) ) AS contante_eur,

          ( SUM(CASE WHEN COALESCE(PpayE,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END)
          + SUM(CASE WHEN COALESCE(TpayE,0)=1 THEN COALESCE(invoice_price,0) ELSE 0 END) ) AS online_eur
        FROM cassa
        WHERE created_at BETWEEN ? AND ?
    ");
    $st->execute([$start, $end]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['ricevute_emesse']  = (int)($r['ricevute'] ?? 0);
    $stats['ticket_annullati'] = (int)($r['pann'] ?? 0) + (int)($r['tann'] ?? 0);
    $stats['pagamenti_n']      = (int)($r['pagamenti_n'] ?? 0);
    $stats['cash_n']           = (int)($r['cash_n'] ?? 0);
    $stats['elett_n']          = (int)($r['elett_n'] ?? 0);
    $stats['contante_eur']     = (float)($r['contante_eur'] ?? 0);
    $stats['online_eur']       = (float)($r['online_eur'] ?? 0);
    $stats['totale_eur']       = $stats['contante_eur'] + $stats['online_eur'];

    // ✅ presenti totali (non dipende dal turno)
    try {
        $stats['presenti'] = getPresentiCounter($db);
    } catch (Throwable $e) {
        $stats['presenti'] = 0;
    }

// ✅ Ticket emessi nel turno = tickets_printed.created_at nel range
try {
    $stT = $db->prepare("
        SELECT COUNT(*) AS ticket_emessi
        FROM tickets_printed tp
        WHERE tp.created_at BETWEEN ? AND ?
          AND TRIM(COALESCE(tp.ticket_code,'')) <> ''
    ");
    $stT->execute([$start, $end]);
    $rT = $stT->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['ticket_emessi'] = (int)($rT['ticket_emessi'] ?? 0);
} catch (Throwable $e) {
    // ignore
}

// ✅ UM nel turno = tickets_printed.um=1 con exit_datetime nel range
try {
    $stUM = $db->prepare("
        SELECT COUNT(*) AS um_count
        FROM tickets_printed tp
        WHERE COALESCE(tp.um,0) = 1
          AND tp.exit_datetime IS NOT NULL
          AND TRIM(tp.exit_datetime) <> ''
          AND tp.exit_datetime BETWEEN ? AND ?
          AND TRIM(COALESCE(tp.ticket_code,'')) <> ''
    ");
    $stUM->execute([$start, $end]);
    $rUM = $stUM->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['um_count'] = (int)($rUM['um_count'] ?? 0);
} catch (Throwable $e) {
    // ignore
}

    return $stats;
}
function getRiepilogoMovimenti(PDO $db, string $start, string $end): array {
    $st = $db->prepare("
        SELECT
          ip.receipt_code,
          ip.entry_datetime,
          ip.exit_datetime,
          COALESCE(ip.price,0) AS price,
          COALESCE(ip.tessera_scaled,0) AS tp_scaled,
          c.fascia AS fascia
        FROM invoices_printed ip
        LEFT JOIN cassa c ON c.invoice_code = ip.receipt_code
        WHERE ip.created_at BETWEEN ? AND ?
        ORDER BY ip.created_at ASC, ip.id ASC
    ");
    $st->execute([$start, $end]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function calcTotali(array $mov): array {
    $initFasce = function() {
        return [
            'F1' => ['n'=>0,'eur'=>0.0],
            'F2' => ['n'=>0,'eur'=>0.0],
            'F3' => ['n'=>0,'eur'=>0.0],
            'F4' => ['n'=>0,'eur'=>0.0],
            'F5' => ['n'=>0,'eur'=>0.0],
        ];
    };

    $out = [
        // ricevute NON tessera
        'tot_ric_n' => 0,
        'tot_ric_eur' => 0.0,

        // ricevute tessera prepagata (TP)
        'tot_tp_n' => 0,
        'tot_tp_eur' => 0.0,

        // classi non-tessera
        'fasce' => $initFasce(),

        // classi TP
        'fasce_tp' => $initFasce(),
    ];

    foreach ($mov as $r) {
        $fascia = strtoupper(trim((string)($r['fascia'] ?? '')));
        if (!isset($out['fasce'][$fascia])) $fascia = 'F1';

        $price = (float)($r['price'] ?? 0);
        $tp    = (float)($r['tp_scaled'] ?? 0);

        if ($tp > 0) {
            // TP: importo detratto
            $out['tot_tp_n']++;
            $out['tot_tp_eur'] += $tp;

            $out['fasce_tp'][$fascia]['n']++;
            $out['fasce_tp'][$fascia]['eur'] += $tp;
        } else {
            // NORMALE
            $out['tot_ric_n']++;
            $out['tot_ric_eur'] += $price;

            $out['fasce'][$fascia]['n']++;
            $out['fasce'][$fascia]['eur'] += $price;
        }
    }

    return $out;
}
function buildTurnoTxt(string $garage, array $info, array $stats, array $mov, array $totali): string {
    $line = str_repeat('=', 42) . "\n";
    $sep  = str_repeat('-', 42) . "\n";

    $numFormatted = str_pad((string)$info['numero_turno'], 3, '0', STR_PAD_LEFT);

    $txt  = $line;
    $txt .= $garage . "\n";
    $txt .= $sep;

    // INFO TURNO
    $txt .= "INFO TURNO\n";
    $txt .= "Turno N.      {$numFormatted}\n";
    $txt .= "Operatore:    " . (string)$info['operatore_cod'] . "\n";
    $txt .= "Apertura:     " . fmtDateIt($info['inizio']) . " " . fmtTimeIt($info['inizio']) . "\n";
    $txt .= "Chiusura:     " . fmtDateIt($info['fine'])   . " " . fmtTimeIt($info['fine'])   . "\n";
    $txt .= "\n";

// STATISTICHE
$txt .= "STATISTICHE:\n";
$txt .= $sep;

$txt .= sprintf("%-20s %5d\n", "Ticket emessi:",    (int)($stats['ticket_emessi'] ?? 0));
$txt .= sprintf("%-20s %5d\n", "Ticket annullati:", (int)($stats['ticket_annullati'] ?? 0));
$txt .= sprintf("%-20s %5d\n", "Ricevute emesse:",  (int)($stats['ricevute_emesse'] ?? 0));
$txt .= sprintf("%-20s %5d\n", "Presenti:",         (int)($stats['presenti'] ?? 0));
$txt .= sprintf("%-20s %5d\n", "UM:",               (int)($stats['um_count'] ?? 0));

$txt .= "\n";
    
    // RIEPILOGO CLASSI
    $txt .= "RIEPILOGO CLASSI:\n";
    $txt .= $sep;

    foreach (['F1','F2','F3','F4','F5'] as $f) {
        $n   = (int)($totali['fasce'][$f]['n'] ?? 0);
        $eur = (float)($totali['fasce'][$f]['eur'] ?? 0);

        $nTP   = (int)($totali['fasce_tp'][$f]['n'] ?? 0);
        $eurTP = (float)($totali['fasce_tp'][$f]['eur'] ?? 0);

        $txt .= sprintf("%s = n.%5d  EUR %10.2f\n", $f, $n, $eur);
        $txt .= sprintf("%s TP = n.%2d  EUR %10.2f\n", $f, $nTP, $eurTP);
    }

    $txt .= "\n";

    // RIEPILOGO PAGAMENTI
    $txt .= "RIEPILOGO PAGAMENTI:\n";
    $txt .= $sep;
    $txt .= sprintf("Pagamenti totali:     %5d\n", (int)($stats['pagamenti_n'] ?? 0));
    $txt .= sprintf("Pagamenti Cash:       %5d\n", (int)($stats['cash_n'] ?? 0));
    $txt .= sprintf("Pagamenti Pos.:       %5d\n", (int)($stats['elett_n'] ?? 0));
    $txt .= "\n";

    // TOTALI IMPORTI
    $txt .= sprintf("TOTALE:      EUR %10.2f\n", (float)($stats['totale_eur'] ?? 0));
    $txt .= sprintf("Contante:    EUR %10.2f\n", (float)($stats['contante_eur'] ?? 0));
    $txt .= sprintf("Pos:         EUR %10.2f\n", (float)($stats['online_eur'] ?? 0));

    $txt .= $line;
    return $txt;
}
function buildTurnoTxtCustom(
    string $garage,
    string $numFormatted,
    string $operatore_cod,
    string $inizioTurno,   // "Y-m-d H:i:s"
    string $fineTurno,     // "Y-m-d H:i:s"
    array $stats,          // ticket_emessi, ticket_annullati, ricevute_emesse, presenti, pagamenti_n, cash_n, elett_n, totale_eur, contante_eur, online_eur
    array $mov,            // righe movimenti (receipt_code, entry_datetime, exit_datetime, price, tp_scaled, fascia)
    array $totali          // output calcTotali: tot_ric_n, tot_ric_eur, tot_tp_n, tot_tp_eur, fasce[F1..F5][n,eur,tp]
): string {

    $line = str_repeat('=', 42) . "\n";
    $sep  = str_repeat('-', 42) . "\n";

    $txt  = $line;
    $txt .= $garage . "\n";
    $txt .= $sep;

    // INFO TURNO
    $txt .= "INFO TURNO\n";
    $txt .= "Turno N.      {$numFormatted}\n";
    $txt .= "Operatore:    {$operatore_cod}\n";
    $txt .= "Apertura:     " . fmtDateIt($inizioTurno) . " " . fmtTimeIt($inizioTurno) . "\n";
    $txt .= "Chiusura:     " . fmtDateIt($fineTurno)   . " " . fmtTimeIt($fineTurno)   . "\n";
    $txt .= "\n";

    // STATISTICHE
    $txt .= "STATISTICHE:\n";
    $txt .= $sep;
    $txt .= sprintf("Ticket emessi:        %5d\n", (int)($stats['ticket_emessi'] ?? 0));
    $txt .= sprintf("Ticket annullati:     %5d\n", (int)($stats['ticket_annullati'] ?? 0));
    $txt .= sprintf("Ricevute emesse:      %5d\n", (int)($stats['ricevute_emesse'] ?? 0));
    $txt .= sprintf("Presenti:             %5d\n", (int)($stats['presenti'] ?? 0));
	$txt .= sprintf("UM:                   %5d\n", (int)($stats['um_count'] ?? 0));
    $txt .= "\n";

    // RIEPILOGO MOVIMENTI
    $txt .= "RIEPILOGO MOVIMENTI\n";
    $txt .= $sep;

   if (empty($mov)) {
    $txt .= "(nessun presente)\n";
} else {
    foreach ($mov as $m) {
        // ultime 5 cifre del barcode_secondary (fallback: ticket_code se presente)
        $bc = strtoupper(trim((string)($m['barcode_secondary'] ?? '')));
        if ($bc === '') {
            $bc = strtoupper(trim((string)($m['ticket_code'] ?? ''))); // funziona solo se lo selezioni in query
        }
        $last5 = ($bc !== '') ? substr($bc, -5) : '-----';

        // targa o PASSAG
        if (!empty($m['passage_id'])) {
            $who = 'PASSAGG';
        } else {
            $who = strtoupper(trim((string)($m['plate_number'] ?? '')));
            if ($who === '') $who = '---';
        }

        // data + ora entrata
        $entry = (string)($m['entry_dt'] ?? '');
        $d = $entry ? fmtDateIt($entry) : '--/--/----';
        $t = $entry ? fmtTimeIt($entry) : '--:--:--';

        // fascia
        $fascia = strtoupper(trim((string)($m['fascia'] ?? '')));
        if ($fascia === '') $fascia = '--';

        // output: 4DFR2  ER435TT  04/05/2026 18:42:56 F1
        $txt .= sprintf("%-5s  %-6s  %s %s %s\n", $last5, $who, $d, $t, $fascia);
    }
}

    $txt .= "\n";

    // RIEPILOGO CLASSI (F1..F5)
    $txt .= "RIEPILOGO CLASSI:\n";
    $txt .= $sep;

    foreach (['F1','F2','F3','F4','F5'] as $f) {
        $n   = (int)($totali['fasce'][$f]['n'] ?? 0);
        $eur = (float)($totali['fasce'][$f]['eur'] ?? 0);
        // opzionale: TP per fascia
        $tp  = (float)($totali['fasce'][$f]['tp'] ?? 0);

        // Se vuoi stampare anche TP, lascia questa riga:
        $txt .= sprintf("%s = n.%5d  EUR %10.2f  TP %10.2f\n", $f, $n, $eur, $tp);

        // Se invece vuoi SOLO n e EUR, usa questa e commenta la sopra:
        // $txt .= sprintf("%s = n.%5d  EUR %10.2f\n", $f, $n, $eur);
    }

    $txt .= "\n";

    // RIEPILOGO PAGAMENTI
    $txt .= "RIEPILOGO PAGAMENTI:\n";
    $txt .= $sep;
    $txt .= sprintf("Pagamenti totali:     %5d\n", (int)($stats['pagamenti_n'] ?? 0));
    $txt .= sprintf("Pagamenti Cash:       %5d\n", (int)($stats['cash_n'] ?? 0));
    $txt .= sprintf("Pagamenti Pos.:       %5d\n", (int)($stats['elett_n'] ?? 0));
    $txt .= "\n";

    // TOTALI IMPORTI
    $txt .= "TOTALI IMPORTI:\n";
    $txt .= $sep;
    $txt .= sprintf("TOTALE:      EUR %10.2f\n", (float)($stats['totale_eur'] ?? 0));
    $txt .= sprintf("Contante:    EUR %10.2f\n", (float)($stats['contante_eur'] ?? 0));
    $txt .= sprintf("Pos:         EUR %10.2f\n", (float)($stats['online_eur'] ?? 0));

    $txt .= $line;
    return $txt;
}
function getPresentiMovimentiAttuali(PDO $db): array {
    $tpEntryDT = "COALESCE(tp.entry_datetime, tp.created_at)";

    $sql = "
      SELECT
        tp.id,
        tp.plate_number,
        tp.passage_id,
        tp.fascia,
        tp.barcode_secondary,
        $tpEntryDT AS entry_dt
      FROM tickets_printed tp
      WHERE TRIM(COALESCE(tp.ticket_code,'')) <> ''
        AND NOT EXISTS (
          SELECT 1
          FROM cassa c
          WHERE (c.Tticket_code = tp.ticket_code OR c.Pticket_code = tp.ticket_code)
            AND (
                 TRIM(COALESCE(c.invoice_code,'')) <> ''
              OR COALESCE(c.Tannullato,0) = 1
              OR COALESCE(c.Pannullato,0) = 1
            )
        )
      ORDER BY entry_dt ASC, tp.id ASC
    ";

    $st = $db->prepare($sql);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
