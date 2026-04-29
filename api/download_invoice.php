<?php
// Scarica/mostra un file ricevuta dalla cartella INVOICE_DIR senza dipendere da alias webserver.
// URL: /anpr/api/download_invoice.php?file=RECEIPT_R_20260420_160500-15.txt

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../config/config.php';

    if (!defined('INVOICE_DIR')) {
        throw new Exception('INVOICE_DIR non definita');
    }

    $file = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
    if ($file === '') {
        throw new Exception('Parametro file mancante');
    }

    // Sicurezza: blocca path traversal e limita ai .txt delle ricevute
    // Accetta: RECEIPT_....txt
    if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
        throw new Exception('Nome file non valido');
    }
    if (!preg_match('/^RECEIPT_[A-Za-z0-9_:-]+\.txt$/', $file)) {
        throw new Exception('Formato file non consentito');
    }

    $fullPath = rtrim(INVOICE_DIR, '/\\') . DIRECTORY_SEPARATOR . $file;

    if (!file_exists($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "File non trovato";
        exit;
    }

    if (!is_readable($fullPath)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "File non leggibile";
        exit;
    }

    // Forza visualizzazione o download (qui: download; se vuoi inline cambia in inline)
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($fullPath));

    // Niente output JSON: è un file
    readfile($fullPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '❌ ' . $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}