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
    $opts[] = ['value' => 0, 'label' => 'Totale'];
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

    // default = Totale
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 0;
    if ($days < 0) $days = 0;
    if ($days > $maxDays) $days = $maxDays;

    // Range "da mezzanotte" (coerente con il resto del progetto)
    $tz = new DateTimeZone('Europe/Rome');

    $fromDT = null;
    if ($days > 0) {
        $from = new DateTime('today', $tz);
        $from->modify('-' . ($days - 1) . ' days');
        $fromDT = $from->format('Y-m-d 00:00:00');
    }

    // datetime entrata ticket_printed robusto (fallback created_at)
    $tpEntryDT = "COALESCE(tp.entry_datetime, tp.created_at)";

    // WHERE dinamico + params coerenti (1 solo parametro)
    $where = "WHERE TRIM(COALESCE(tp.ticket_code,'')) <> ''";
    $params = [];

    if ($days > 0) {
        $where .= " AND $tpEntryDT >= :fromDT";
        $params[':fromDT'] = $fromDT;
    }

    // Presenza = ticket_printed SENZA ricevuta e NON annullato
    // - ricevuta: invoice_code valorizzato
    // - annullato: Tannullato=1 o Pannullato=1
    // Nota: la correlazione avviene per ticket_code (targa o passaggio).
    $sql = "
      SELECT
        COUNT(DISTINCT tp.id) AS tickets_tp_range,

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
      $where
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $presentiT = (int)($row['presenti_t'] ?? 0);
    $ticketsTPRange = (int)($row['tickets_tp_range'] ?? 0);

    $response['success'] = true;
    $response['data'] = [
        'days' => $days,
        'max_days' => $maxDays,
        'options' => buildOptions($maxDays),
        'from_datetime' => $fromDT,
        'presenti' => $presentiT,
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
