<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

/*
 * DEBUG TEMP (disattivato): riattiva solo se serve.
 *
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () use (&$response) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        $response['success'] = false;
        $response['message'] = "❌ FATAL: {$err['message']} in {$err['file']}:{$err['line']}";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
});
*/

try {
    $plateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($plateId <= 0) throw new Exception('ID targa non valido');

    // ticket_code più recente
    $stmt = $db->prepare("
        SELECT ticket_code
        FROM tickets_printed
        WHERE plate_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$plateId]);
    $tp = $stmt->fetch(PDO::FETCH_ASSOC);
    $ticket_code = $tp ? $tp['ticket_code'] : null;

    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.plate_number,
            p.plate_corrected,
            p.date_detected,
            p.is_manual,

            t.ticket_info,
            t.vehicle_type,
            t.vehicle_brand,
            t.vehicle_color,
            t.vehicle_position,

            /* =========================================================
               PATCH (OPZIONE 1):
               ingresso pre-ticket da manual_plates_log, fallback su tickets.*
               ========================================================= */

            COALESCE(mpl.entry_date, t.entry_date, NULL) as entry_date,
            COALESCE(mpl.entry_time, t.entry_time, NULL) as entry_time,

            t.exit_date,
            t.exit_time,
            t.notes,
            t.paid,
            t.subscription,
            t.subscription_from,
            t.subscription_to,
            t.ticket_prepaid,
            t.ticket_from,
            t.ticket_to,
            t.ticket_balance,
            t.authorized_vehicle,

            tp.ticket_code,
            tp.entry_datetime,
            tp.exit_datetime,

            /* riga cassa */
            COALESCE(c.invoice_code, NULL) as invoice_code,
            COALESCE(c.fascia, NULL)       as fascia,
            COALESCE(c.prezzo, 0)          as prezzo,
            COALESCE(c.invoice_entry_datetime, NULL) as invoice_entry_datetime,
            COALESCE(c.invoice_exit_datetime,  NULL) as invoice_exit_datetime,
            COALESCE(c.Tentry_date, NULL)  as Tentry_date,
            COALESCE(c.Tentry_time, NULL)  as Tentry_time,
            COALESCE(c.Texit_date, NULL)   as Texit_date,
            COALESCE(c.Texit_time, NULL)   as Texit_time,
            COALESCE(c.Tannullato, 0)      as Tannullato,
            COALESCE(c.Tannultxt, '')      as Tannultxt,
            COALESCE(c.TpayC, 0)           as TpayC,
            COALESCE(c.TpayE, 0)           as TpayE,
            COALESCE(c.Tpaid, 0)           as Tpaid,
            COALESCE(c.giorni, NULL)       as giorni,
            COALESCE(c.ore, NULL)          as ore,
            COALESCE(c.minuti, NULL)       as minuti

        FROM plates p
        LEFT JOIN tickets t ON p.id = t.plate_id

        /* ✅ NEW: join log manuale (pre-ticket) */
        LEFT JOIN manual_plates_log mpl ON mpl.plate_id = p.id

        LEFT JOIN tickets_printed tp ON p.id = tp.plate_id AND tp.ticket_code = :ticket_code
        LEFT JOIN cassa c ON c.Tticket_code = tp.ticket_code
        WHERE p.id = :pid
        ORDER BY tp.id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':ticket_code' => $ticket_code,
        ':pid' => $plateId
    ]);

    $plate = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plate) throw new Exception('Targa non trovata');

    // ================== IMMAGINI (da tabella images) ==================
    // - Veloce su Windows (no scan filesystem)
    // - Compatibile Linux (image_path relativo, URL via ANPR_IMAGES_URL)
    try {
        $imgStmt = $db->prepare("
            SELECT image_path, file_name, created_at
            FROM images
            WHERE plate_id = ?
            ORDER BY created_at DESC
            LIMIT 2
        ");
        $imgStmt->execute([$plateId]);
        $imgs = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($imgs as &$img) {
            $rel = ltrim(str_replace('\\', '/', (string)$img['image_path']), '/');
            $img['url'] = rtrim(ANPR_IMAGES_URL, '/') . '/' . $rel;
        }
        unset($img);

        $plate['images'] = $imgs;
    } catch (Exception $e) {
        // non bloccare il caricamento targa se la query immagini fallisce
        $plate['images'] = [];
    }

    $response['success'] = true;
    $response['message'] = '✅ Targa caricata';
    $response['data'] = $plate;

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}
// ✅ NEW: comodo per frontend (prima immagine)
if (!empty($imgs)) {
    $plate['image_path'] = $imgs[0]['image_path'] ?? null;
    $plate['image_url']  = $imgs[0]['url'] ?? null;
}
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>