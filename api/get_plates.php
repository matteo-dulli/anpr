<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();

$response = [
    'success' => false,
    'message' => '',
    'count'   => 0,
    'data'    => []
];

try {
    $days = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 5;
    $sort = isset($_GET['sort']) && strtolower($_GET['sort']) === 'asc' ? 'asc' : 'desc';

    $tz = new DateTimeZone('Europe/Rome');
    $fromDate = new DateTime('today', $tz);
    $fromDate->modify('-' . ($days - 1) . ' days');
    $fromStr  = $fromDate->format('Y-m-d H:i:s');

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1000;
    if ($limit < 1) $limit = 1000;
    if ($limit > 2000) $limit = 2000;

    $items = [];

// ================== 1. TARGHE NORMALI DA plates ==================
// PATCH: prende ticket_printed più recente e la riga cassa associata

// ================== MODIFICA (AGGIUNTA) ==================
// Aggiungo join con tabella images per avere image_path e file_name nel JSON.
// Questo evita che la UI chiami get_image.php?id=... (lento) che scandisce il filesystem.
// Con image_path possiamo usare get_image.php?path=... (veloce) o thumbs statiche.
// NOTA: non rimuovo nulla, aggiungo solo colonne + LEFT JOIN.
// =========================================================
$sqlPlates = "
    SELECT
        p.id,
        p.plate_number,
        p.plate_corrected,
        p.date_detected,
        p.is_manual,
        tp.ticket_code,
        tp.entry_datetime,
        tp.exit_datetime,
        COALESCE(c.giorni,  NULL) as giorni,
        COALESCE(c.ore,    NULL) as ore,
        COALESCE(c.minuti, NULL) as minuti,
        COALESCE(c.Tpaid, 0)        as Tpaid,
        COALESCE(c.TpayC, 0)        as TpayC,
        COALESCE(c.TpayE, 0)        as TpayE,
        COALESCE(c.Tannullato, 0)   as Tannullato,
        COALESCE(c.Tannultxt, '')   as Tannultxt,
        COALESCE(c.prezzo, 0)       as prezzo,
        COALESCE(c.fascia, tk.fascia, tp.fascia, '')      as fascia,
        COALESCE(c.invoice_code, '') as invoice_code,

        -- ✅ NEW: abbonamento attivo
        EXISTS (
            SELECT 1
            FROM abbonamenti a
            WHERE a.plate_number = p.plate_number
              AND COALESCE(a.attivo,0) = 1
              AND (a.inabb IS NULL OR a.inabb <= CURDATE())
              AND (a.finabb IS NULL OR a.finabb >= CURDATE())
        ) AS abb_attivo,

        -- ✅ NEW: tessera attiva
        EXISTS (
            SELECT 1
            FROM tesserapre t
            WHERE t.plate_number = p.plate_number
              AND COALESCE(t.attivo,0) = 1
              AND COALESCE(t.canc,0) = 0
        ) AS tessera_attiva,

        -- ✅ NEW (AGGIUNTA): path immagine e nome file per thumbs veloci
        i.image_path AS image_path,
        i.file_name  AS image_file

        FROM plates p
        LEFT JOIN (
            SELECT tp.*
            FROM tickets_printed tp
            INNER JOIN (
                SELECT plate_id, MAX(id) AS maxid
                FROM tickets_printed
                GROUP BY plate_id
            ) tmax ON tp.plate_id = tmax.plate_id AND tp.id = tmax.maxid
        ) tp ON p.id = tp.plate_id
        LEFT JOIN tickets tk ON tk.plate_id = p.id
        LEFT JOIN cassa c ON c.Tticket_code = tp.ticket_code

        -- ✅ NEW (AGGIUNTA): join immagini (se esiste 1 sola immagine per plate_id è ok)
        LEFT JOIN images i ON i.plate_id = p.id

        WHERE p.date_detected >= :fromDate
        ORDER BY p.date_detected DESC
        LIMIT {$limit}
";

$stmt = $db->prepare($sqlPlates);
$stmt->execute([':fromDate' => $fromStr]);
$plates = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plates as $p) {
    $isManual = (int)$p['is_manual'] === 1;

    $items[] = [
        'id'              => (int)$p['id'],
        'plate_number'    => $p['plate_number'],
        'plate_corrected' => $p['plate_corrected'],
        'date_detected'   => $p['date_detected'],
        'is_manual'       => $isManual ? 1 : 0,
        'is_passage'      => 0,
        'passage_id'      => null,
        'ticket_code'     => $p['ticket_code'] ?? null,
        'origin_label'    => $isManual ? '✏️ Manuale' : '📸 Rilevata',
        'entry_datetime'  => $p['entry_datetime'],
        'exit_datetime'   => $p['exit_datetime'],
        'giorni'          => $p['giorni'],
        'ore'             => $p['ore'],
        'minuti'          => $p['minuti'],
        'Tpaid'           => (int)$p['Tpaid'],
        'TpayC'           => (int)$p['TpayC'],
        'TpayE'           => (int)$p['TpayE'],
        'Tannullato'      => (int)$p['Tannullato'],
        'Tannultxt'       => $p['Tannultxt'],
        'prezzo'          => floatval($p['prezzo']),
        'fascia'          => $p['fascia'],
        'invoice_code'    => $p['invoice_code'],

        'abb_attivo'      => (int)($p['abb_attivo'] ?? 0),
        'tessera_attiva'  => (int)($p['tessera_attiva'] ?? 0),

        // ✅ NEW (AGGIUNTA): campi immagine per thumbnails veloci
        // image_path è relativo alla cartella MONITORED_FOLDER (es: 2026/04/.../file-ANPR.jpg)
        'image_path'      => $p['image_path'] ?? null,
        'image_file'      => $p['image_file'] ?? null,

        // OLD (DA NON USARE: sovrascrive i valori veri)
        // 'abb_attivo'     => 0,
        // 'tessera_attiva' => 0,
    ];
}

    // ================== 2. PASSAGGI ANONIMI DA passages ==================
    $sqlPassages = "
        SELECT
            p.id,
            p.entry_datetime,
            p.ticket_code,
            p.note,
            p.info,
            p.paid,
            p.exit_datetime,
            COALESCE(c.Ppaid, 0)          as Ppaid,
            COALESCE(c.PpayC, 0)          as PpayC,
            COALESCE(c.PpayE, 0)          as PpayE,
            COALESCE(c.invoice_price, 0)  as invoice_price,
            COALESCE(c.fascia, '')        as fascia,
            COALESCE(c.invoice_code, '')  as invoice_code,
            COALESCE(c.Pannullato, 0)     as Pannullato,
            COALESCE(c.Pannultxt, '')     as Pannultxt,
            tp.ticket_code as ticket_code_printed
        FROM passages p
        LEFT JOIN tickets_printed tp ON p.ticket_printed_id = tp.id
        LEFT JOIN cassa c ON p.id = c.idpassages
        WHERE p.entry_datetime >= :fromDate
        GROUP BY p.id
        ORDER BY p.entry_datetime DESC
        LIMIT {$limit}
    ";

    $stmtP = $db->prepare($sqlPassages);
    $stmtP->execute([':fromDate' => $fromStr]);
    $passages = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    foreach ($passages as $pg) {
        $passageId = (int)$pg['id'];
        $items[] = [
            'id'                => -$passageId,
            'plate_number'      => '🚶 PASSAGGIO ' . $passageId,
            'plate_corrected'   => null,
            'date_detected'     => $pg['entry_datetime'],
            'is_manual'         => 1,
            'is_passage'        => 1,
            'passage_id'        => $passageId,
            'ticket_code'       => $pg['ticket_code'] ?? $pg['ticket_code_printed'],
            'note'              => $pg['note'],
            'info'              => $pg['info'],
            'paid'              => (int)$pg['paid'],
            'exit_datetime'     => $pg['exit_datetime'],
            'Ppaid'         => (int)$pg['Ppaid'],
            'PpayC'         => (int)$pg['PpayC'],
            'PpayE'         => (int)$pg['PpayE'],
            'invoice_price' => floatval($pg['invoice_price']),
            'fascia'        => $pg['fascia'],
            'invoice_code'  => $pg['invoice_code'],
            'Pannullato'    => (int)$pg['Pannullato'],
            'Pannultxt'     => $pg['Pannultxt'],
            'origin_label'  => '🚶 Passaggio'
        ];
    }

    // ================== 3. ORDINA IN UNICO ARRAY ==================
    if (!empty($items)) {
        usort($items, function ($a, $b) use ($sort) {
            $da = strtotime($a['date_detected']);
            $dbb = strtotime($b['date_detected']);
            if ($da === $dbb) return 0;
            if ($sort === 'asc') return $da <=> $dbb;
            return $dbb <=> $da;
        });
    }

    // ================== 4. LIMIT FINALE (merge) ==================
    if (count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    $response['success'] = true;
    $response['data']    = $items;
    $response['count']   = count($items);

} catch (Exception $e) {
    $response['message'] = '❌ Errore caricamento targhe/passaggi: ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'GET_PLATES_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>