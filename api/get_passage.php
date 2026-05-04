<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'data' => null, 'message' => ''];

try {
    $passage_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($passage_id <= 0) {
        throw new Exception('ID passaggio non valido');
    }

    error_log('🔍 GET_PASSAGE: ID=' . $passage_id);

    // =========================================================
    // ✅ JOIN ULTIMA RICEVUTA EMESSA (robusto, anche se cassa non ha idpassages/invoice_code)
    // - Se esistono più ricevute per lo stesso passaggio, prende l'ultima (MAX(id))
    // =========================================================
    $joinLastInvoicePrinted = "
        LEFT JOIN (
            SELECT ip1.passage_id, ip1.receipt_code
            FROM invoices_printed ip1
            INNER JOIN (
                SELECT passage_id, MAX(id) AS max_id
                FROM invoices_printed
                GROUP BY passage_id
            ) ip2 ON ip2.passage_id = ip1.passage_id AND ip2.max_id = ip1.id
        ) ip ON ip.passage_id = p.id
    ";

    // ============================
    // QUERY ESTESA (con campi uscita da cassa)
    // Nota: alcune installazioni non hanno datacassa/oraincasso/invoice_exit_datetime.
    // Se la query fallisce, usiamo fallback base.
    // ============================
    $sqlExtended = "
    SELECT 
        p.id,
        p.ticket_printed_id,
        p.ticket_code,
        p.entry_datetime,
        p.exit_datetime,
        p.note,
        p.info,
        p.paid,
        p.created_at,
        p.updated_at,

        COALESCE(c.Ppaid, p.paid) as Ppaid,
        COALESCE(c.PpayC, 0) as PpayC,
        COALESCE(c.PpayE, 0) as PpayE,

        COALESCE(c.invoice_price, 0) as invoice_price,
        COALESCE(c.invoice_price, 0) as prezzo,

        COALESCE(c.Pannullato, 0) as annullato,
        COALESCE(c.Pannultxt, '') as motivo,
        COALESCE(tp.fascia, c.fascia, 'F1') as fascia,

        c.invoice_code as invoice_code,

        -- ✅ NEW: codice ricevuta realmente emesso (da invoices_printed)
        ip.receipt_code AS receipt_code,

        c.datacassa as datacassa,
        c.oraincasso as oraincasso,
        c.invoice_exit_datetime as invoice_exit_datetime,

        c.Pexit_datetime as Pexit_datetime

    FROM passages p
    $joinLastInvoicePrinted
    LEFT JOIN cassa c ON p.id = c.idpassages
    LEFT JOIN tickets_printed tp ON p.ticket_printed_id = tp.id
    WHERE p.id = ?
    LIMIT 1
";

    // ============================
    // QUERY BASE (senza campi opzionali cassa)
    // ============================
    $sqlBase = "
        SELECT 
            p.id,
            p.ticket_printed_id,
            p.ticket_code,
            p.entry_datetime,
            p.exit_datetime,
            p.note,
            p.info,
            p.paid,
            p.created_at,
            p.updated_at,

            COALESCE(c.Ppaid, p.paid) as Ppaid,
            COALESCE(c.PpayC, 0) as PpayC,
            COALESCE(c.PpayE, 0) as PpayE,

            COALESCE(c.invoice_price, 0) as invoice_price,
            COALESCE(c.invoice_price, 0) as prezzo,

            COALESCE(c.Pannullato, 0) as annullato,
            COALESCE(c.Pannultxt, '') as motivo,
            COALESCE(tp.fascia, c.fascia, 'F1') as fascia,

            c.invoice_code as invoice_code,

            -- ✅ NEW: codice ricevuta realmente emesso (da invoices_printed)
            ip.receipt_code AS receipt_code

        FROM passages p
        $joinLastInvoicePrinted
        LEFT JOIN cassa c ON p.id = c.idpassages
        LEFT JOIN tickets_printed tp ON p.ticket_printed_id = tp.id
        WHERE p.id = ?
        LIMIT 1
    ";

    $passage = null;

    try {
        $stmt = $db->prepare($sqlExtended);
        $stmt->execute([$passage_id]);
        $passage = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback se colonne non esistono / query non valida
        error_log('⚠️ GET_PASSAGE: query estesa fallita, uso fallback base. Err=' . $e->getMessage());

        $stmt = $db->prepare($sqlBase);
        $stmt->execute([$passage_id]);
        $passage = $stmt->fetch(PDO::FETCH_ASSOC);

        // assicura chiavi (così il JS non esplode)
        if ($passage) {
            $passage['datacassa'] = $passage['datacassa'] ?? null;
            $passage['oraincasso'] = $passage['oraincasso'] ?? null;
            $passage['invoice_exit_datetime'] = $passage['invoice_exit_datetime'] ?? null;
        }
    }

    if (!$passage) {
        throw new Exception('Passaggio non trovato: ' . $passage_id);
    }

    // ✅ assicura chiave receipt_code sempre presente
    $passage['receipt_code'] = $passage['receipt_code'] ?? null;

    error_log(
        '✅ Passaggio caricato: ID=' . $passage_id .
        ' FASCIA=' . ($passage['fascia'] ?? '') .
        ' INVOICE_CODE=' . ($passage['invoice_code'] ?? '') .
        ' RECEIPT_CODE=' . ($passage['receipt_code'] ?? '') .
        ' PAID=' . ($passage['Ppaid'] ?? '') .
        ' CASH=' . ($passage['PpayC'] ?? '') .
        ' ELECTRONIC=' . ($passage['PpayE'] ?? '') .
        ' PREZZO=' . ($passage['prezzo'] ?? '')
    );

    $response['success'] = true;
    $response['data'] = $passage;
    $response['message'] = '✅ Passaggio caricato';

} catch (Throwable $e) {
    error_log('❌ GET_PASSAGE ERROR: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>