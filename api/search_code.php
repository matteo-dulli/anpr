<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => [
        'query' => '',
        'results' => []
    ]
];

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'prefix'; // prefix | exact
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    if ($limit <= 0 || $limit > 50) $limit = 10;

    if ($q === '') {
        throw new Exception('Parametro q richiesto');
    }

    $db = getDatabaseConnection();

    $response['data']['query'] = $q;

    // Normalizza
    $qUpper = strtoupper($q);

    // =========================
    // PATCH: se NON inizia con T o R, in prefix usiamo LIKE '%q%' (contiene)
    // =========================
    $startsWithT = (strpos($qUpper, 'T') === 0);
    $startsWithR = (strpos($qUpper, 'R') === 0); // include anche R_
    $isGeneric = (!$startsWithT && !$startsWithR);

    //// VECCHIO: in prefix usava sempre LIKE 'q%'
    //// $like = $qUpper . '%';

    // NUOVO:
    // - prefix + generic => '%q%'
    // - prefix + T/R     => 'q%'
    // - exact            => '=' (come prima)
    $like = $isGeneric ? ('%' . $qUpper . '%') : ($qUpper . '%');

    $results = [];

    // ========== 1) RICEVUTE (R_...) ==========
    if ($mode === 'exact') {
        $stmt = $db->prepare("
            SELECT
                c.invoice_code AS code,
                'receipt' AS code_type,
                c.Tplate_id AS plate_id,
                c.idpassages AS passage_id,
                COALESCE(c.Tticket_code, c.Pticket_code) AS ticket_code,
                c.invoice_entry_datetime AS entry_datetime,
                c.invoice_exit_datetime AS exit_datetime,
                c.prezzo AS prezzo,
                c.invoice_price AS invoice_price
            FROM cassa c
            WHERE UPPER(c.invoice_code) = ?
            LIMIT ?
        ");
        $stmt->execute([$qUpper, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("
            SELECT
                c.invoice_code AS code,
                'receipt' AS code_type,
                c.Tplate_id AS plate_id,
                c.idpassages AS passage_id,
                COALESCE(c.Tticket_code, c.Pticket_code) AS ticket_code,
                c.invoice_entry_datetime AS entry_datetime,
                c.invoice_exit_datetime AS exit_datetime,
                c.prezzo AS prezzo,
                c.invoice_price AS invoice_price
            FROM cassa c
            WHERE UPPER(c.invoice_code) LIKE ?
            ORDER BY c.updated_at DESC
            LIMIT ?
        ");
        $stmt->execute([$like, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $r) {
        $results[] = $r;
    }

    // ========== 2) TICKET (T... o barcode secondario) ==========
    if ($mode === 'exact') {
        $stmt = $db->prepare("
            SELECT
                tp.ticket_code AS code,
                'ticket' AS code_type,
                tp.plate_id AS plate_id,
                tp.passage_id AS passage_id,
                tp.ticket_code AS ticket_code,
                tp.entry_datetime AS entry_datetime,
                tp.exit_datetime AS exit_datetime
            FROM tickets_printed tp
            WHERE UPPER(tp.ticket_code) = ?
               OR UPPER(COALESCE(tp.barcode_secondary, SUBSTRING_INDEX(tp.ticket_code, '-', -1))) = ?
            ORDER BY tp.id DESC
            LIMIT ?
        ");
        $stmt->execute([$qUpper, $qUpper, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("
            SELECT
                tp.ticket_code AS code,
                'ticket' AS code_type,
                tp.plate_id AS plate_id,
                tp.passage_id AS passage_id,
                tp.ticket_code AS ticket_code,
                tp.entry_datetime AS entry_datetime,
                tp.exit_datetime AS exit_datetime
            FROM tickets_printed tp
            WHERE UPPER(tp.ticket_code) LIKE ?
               OR UPPER(COALESCE(tp.barcode_secondary, SUBSTRING_INDEX(tp.ticket_code, '-', -1))) LIKE ?
            ORDER BY tp.id DESC
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $r) {
        $results[] = $r;
    }

    // ========== 3) Se ho trovato un ticket, arricchisco con eventuale ricevuta ==========
    $ticketCodes = [];
    foreach ($results as $r) {
        if (!empty($r['ticket_code'])) {
            $ticketCodes[] = strtoupper($r['ticket_code']);
        }
    }
    $ticketCodes = array_values(array_unique($ticketCodes));

    if (count($ticketCodes) > 0) {
        $placeholders = implode(',', array_fill(0, count($ticketCodes), '?'));

        $stmt = $db->prepare("
            SELECT
                invoice_code,
                COALESCE(Tticket_code, Pticket_code) AS ticket_code,
                Tplate_id,
                idpassages,
                invoice_entry_datetime,
                invoice_exit_datetime,
                prezzo,
                invoice_price,
                updated_at
            FROM cassa
            WHERE UPPER(COALESCE(Tticket_code, Pticket_code)) IN ($placeholders)
            ORDER BY updated_at DESC
        ");
        $stmt->execute($ticketCodes);
        $cassaRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($cassaRows as $cr) {
            $tc = strtoupper((string)$cr['ticket_code']);
            if (!isset($map[$tc])) {
                $map[$tc] = $cr;
            }
        }

        foreach ($results as &$r) {
            $tc = strtoupper((string)($r['ticket_code'] ?? ''));
            if ($tc !== '' && isset($map[$tc])) {
                $r['receipt_code'] = $map[$tc]['invoice_code'];
                $r['receipt_plate_id'] = $map[$tc]['Tplate_id'];
                $r['receipt_passage_id'] = $map[$tc]['idpassages'];
                $r['receipt_entry_datetime'] = $map[$tc]['invoice_entry_datetime'];
                $r['receipt_exit_datetime'] = $map[$tc]['invoice_exit_datetime'];
                $r['receipt_price'] = $map[$tc]['invoice_price'] ?: $map[$tc]['prezzo'];
            }
        }
        unset($r);
    }

    // Dedup: stesso code + type una volta
    $seen = [];
    $dedup = [];
    foreach ($results as $r) {
        $key = ($r['code_type'] ?? '') . ':' . strtoupper((string)($r['code'] ?? ''));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $dedup[] = $r;
        }
    }

    $response['success'] = true;
    $response['data']['results'] = array_slice($dedup, 0, $limit);

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>