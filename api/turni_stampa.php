<?php
/**
 * turni_stampa.php
 * Serve il file .txt del turno selezionato per stampa o download
 *
 * GET params:
 *   id      → id della sessione in turni_sessioni
 *   action  → 'print'    (apre HTML stampabile)
 *          | 'download' (scarica .txt)
 *          | 'thermal'  (✅ stampa diretta su termica ESC/POS, ritorna JSON)
 */
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/escpos.php';

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? trim($_GET['action']) : 'print';

if (!$id) {
    http_response_code(400);
    echo 'Parametro id mancante';
    exit;
}

try {
    $db = getDatabaseConnection();

    $stmt = $db->prepare("
        SELECT id, numero_turno, operatore_cod, stato, inizio, fine,
               ticket_emessi, ticket_annullati, ricevute,
               importo_contante, importo_online, importo_totale,
               file_path
        FROM turni_sessioni
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $sessione = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sessione) {
        http_response_code(404);
        echo 'Turno non trovato';
        exit;
    }

    $turniDir  = PROJECT_ROOT . '/turni';
    $fileName  = $sessione['file_path'];
    $fileFull  = $fileName ? $turniDir . '/' . $fileName : null;

    // ----------------------------------------------------------------
    // Se il file .txt esiste, usalo direttamente (✅ normalizza UTF-8)
    // ----------------------------------------------------------------
    $contenuto = null;
    if ($fileFull && file_exists($fileFull)) {
        $contenuto = file_get_contents($fileFull);

        // ✅ escpos.php si aspetta UTF-8. Se il file è ANSI/Windows-1252 lo convertiamo.
        if ($contenuto !== false && $contenuto !== null && $contenuto !== '') {
            if (function_exists('mb_check_encoding') && !mb_check_encoding($contenuto, 'UTF-8')) {
                $tmp = @iconv('Windows-1252', 'UTF-8//IGNORE', $contenuto);
                if ($tmp !== false && $tmp !== null && $tmp !== '') {
                    $contenuto = $tmp;
                }
            }
        }
    }

    // ----------------------------------------------------------------
    // Altrimenti, genera il contenuto al volo dal DB (NO separator, € OK)
    // ----------------------------------------------------------------
    if (!$contenuto) {
        $numFormatted = str_pad($sessione['numero_turno'], 3, '0', STR_PAD_LEFT);
        $dataFmt      = $sessione['inizio'] ? date('d-m-Y', strtotime($sessione['inizio'])) : date('d-m-Y');
        $oraInizio    = $sessione['inizio'] ? date('H:i:s', strtotime($sessione['inizio'])) : '--:--:--';
        $oraFine      = $sessione['fine']   ? date('H:i:s', strtotime($sessione['fine']))   : '--:--:--';

        $c  = "RIEPILOGO TURNO N. {$numFormatted}\n";
        $c .= "Data:          {$dataFmt}\n";
        $c .= "Operatore:     {$sessione['operatore_cod']}\n";
        $c .= "Ora inizio:    {$oraInizio}\n";
        $c .= "Ora chiusura:  {$oraFine}\n";
        $c .= "\n";

        $c .= "STATISTICHE EMISSIONI:\n";
        $c .= sprintf("Ticket emessi totale: %d\n", (int)$sessione['ticket_emessi']);
        $c .= sprintf("Ticket annullati:     %d\n", (int)$sessione['ticket_annullati']);
        $c .= sprintf("Ricevute emesse:      %d\n", (int)$sessione['ricevute']);
        $c .= "\n";

        $c .= "PAGAMENTI:\n";
        $c .= sprintf("Contante: € %s\n", number_format((float)$sessione['importo_contante'], 2, ',', '.'));
        $c .= sprintf("Online:   € %s\n", number_format((float)$sessione['importo_online'],   2, ',', '.'));
        $c .= sprintf("TOTALE:   € %s\n", number_format((float)$sessione['importo_totale'],   2, ',', '.'));
        $c .= "\n";

        $contenuto = $c;

        if (!is_dir($turniDir)) {
            @mkdir($turniDir, 0755, true);
        }
        if ($fileName) {
            @file_put_contents($turniDir . '/' . $fileName, $c); // UTF-8
        }
    }

    // ----------------------------------------------------------------
    // ✅ STAMPA TERMICA DIRETTA - ritorna JSON
    // ----------------------------------------------------------------
    if ($action === 'thermal') {
        header('Content-Type: application/json; charset=utf-8');

        $printRes = escpos_print_txt_with_barcode((string)$contenuto, '', true);

        echo json_encode([
            'success' => (bool)($printRes['success'] ?? false),
            'message' => (string)($printRes['message'] ?? ''),
            'data' => [
                'id'           => (int)$id,
                'numero_turno' => (int)($sessione['numero_turno'] ?? 0),
                'file_path'    => (string)($sessione['file_path'] ?? '')
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ----------------------------------------------------------------
    // Download del file .txt
    // ----------------------------------------------------------------
    if ($action === 'download') {
        $dlName = $fileName ?: ('turno_' . str_pad($sessione['numero_turno'], 3, '0', STR_PAD_LEFT) . '.txt');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $dlName . '"');
        echo $contenuto;
        exit;
    }

    // ----------------------------------------------------------------
    // HTML stampabile (browser)
    // ----------------------------------------------------------------
    $numFormatted  = str_pad($sessione['numero_turno'], 3, '0', STR_PAD_LEFT);
    $contenutoHtml = nl2br(htmlspecialchars((string)$contenuto, ENT_QUOTES, 'UTF-8'));

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Turno ' . htmlspecialchars($numFormatted) . '</title>
<style>
  body { font-family: "Courier New", monospace; background:#fff; color:#111; padding:30px; }
  pre  { font-size:14px; line-height:1.6; white-space:pre-wrap; }
  .btn-print { margin-top:20px; padding:10px 20px; font-size:14px;
               background:#3b82f6; color:#fff; border:none; border-radius:6px; cursor:pointer; }
  @media print { .btn-print { display:none; } }
</style>
</head>
<body>
<button class="btn-print" onclick="window.print()">🖨️ Stampa</button>
<pre>' . $contenutoHtml . '</pre>
</body>
</html>';

} catch (Exception $e) {
    http_response_code(500);
    echo 'Errore: ' . htmlspecialchars($e->getMessage());
}
?>
