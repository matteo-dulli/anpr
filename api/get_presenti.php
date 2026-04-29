<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function getMaxDaysFromCostanti(): int {
    $maxDays = 6;
    if (isset($GLOBALS['COSTANTI']['LIMITE_GIORNI_PRESENZA']) && is_numeric($GLOBALS['COSTANTI']['LIMITE_GIORNI_PRESENZA'])) {
        return max(1, (int)$GLOBALS['COSTANTI']['LIMITE_GIORNI_PRESENZA']);
    }
    if (defined('COSTANTI_FILE') && file_exists(COSTANTI_FILE)) {
        $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (preg_match('/^Giorni\s+(\d+)\s*=\s*(\d+)/i', $line, $m)) $maxDays = max($maxDays, (int)$m[1], (int)$m[2]);
            elseif (preg_match('/^Giorno\s+(\d+)\s*=\s*(\d+)/i', $line, $m)) $maxDays = max($maxDays, (int)$m[1], (int)$m[2]);
        }
    }
    return max(1, $maxDays);
}

function buildOptions(int $maxDays): array {
    $opts = [];
    for ($d = 1; $d <= $maxDays; $d++) {
        if ($d === 1) $label = 'Oggi';
        elseif ($d === 2) $label = 'Oggi + Ieri';
        elseif ($d === 3) $label = 'Oggi + Ieri + Altroieri';
        else $label = "Ultimi {$d} giorni";
        $opts[] = ['value' => $d, 'label' => $label];
    }
    return $opts;
}

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $maxDays = getMaxDaysFromCostanti();

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
    if ($days < 1) $days = 1;
    if ($days > $maxDays) $days = $maxDays;

    // Range "da mezzanotte" (coerente con il resto del progetto)
    $tz = new DateTimeZone('Europe/Rome');
    $from = new DateTime('today', $tz);
    $from->modify('-' . ($days - 1) . ' days');
    $fromDT = $from->format('Y-m-d 00:00:00');

    // datetime entrata ticket_printed robusto (fallback created_at)
    $tpEntryDT = "COALESCE(tp.entry_datetime, tp.created_at)";

    $sql = "
      SELECT
        -- ticket_printed nel range (debug)
        SUM(CASE
          WHEN TRIM(COALESCE(tp.ticket_code,'')) <> ''
           AND $tpEntryDT >= :fromDT
          THEN 1 ELSE 0 END
        ) AS tickets_tp_range,

        -- presenti: ticket_printed nel range SENZA ricevuta in cassa e non annullati
        SUM(CASE
          WHEN TRIM(COALESCE(tp.ticket_code,'')) <> ''
           AND $tpEntryDT >= :fromDT2
           AND COALESCE(c.Tannullato, 0) = 0
           AND TRIM(COALESCE(c.invoice_code, '')) = ''
          THEN 1 ELSE 0 END
        ) AS presenti_t

      FROM tickets_printed tp
      LEFT JOIN cassa c
        ON c.Tticket_code = tp.ticket_code
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':fromDT'  => $fromDT,
        ':fromDT2' => $fromDT,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $presentiT = (int)($row['presenti_t'] ?? 0);
    $ticketsTPRange = (int)($row['tickets_tp_range'] ?? 0);

    $response['success'] = true;
    $response['data'] = [
        'days' => $days,
        'max_days' => $maxDays,
        'options' => buildOptions($maxDays),
        'from_datetime' => $fromDT,

        // totale presenze (per ora solo T)
        'presenti' => $presentiT,

        // breakdown/debug
        'breakdown' => [
            'presenti_t' => $presentiT,
            'tickets_printed_range' => $ticketsTPRange,
        ],
    ];

} catch (Throwable $e) {
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>