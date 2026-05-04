<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    // ✅ usa /anpr/config/config.php per i path
    $configPath = dirname(__DIR__) . '/config/config.php';
    if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) throw new Exception('config.php non trovato');
    require_once $configPath;

    if (!defined('COSTANTI_FILE')) throw new Exception('COSTANTI_FILE non definito');
    if (!file_exists(COSTANTI_FILE)) throw new Exception('costanti.txt non trovato: ' . COSTANTI_FILE);

    $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new Exception('Impossibile leggere costanti.txt');

    $inBlock = false;
    $items = [];

    foreach ($lines as $line) {
        $raw = trim((string)$line);
        if ($raw === '') continue;

        // header blocchi: "# ...."
        if (strpos($raw, '#') === 0) {
            // ✅ NEW: entra nel blocco AUTORIZZATI
            // header blocchi: "# ...."
if (strpos($raw, '#') === 0) {
    // ✅ FIX: niente mb_strtoupper (non sempre disponibile)
    $hdr = trim(strtoupper($raw));

    // entra nel blocco AUTORIZZATI
    // (nota: nel tuo costanti.txt è "# AUTORIZZATI")
    if ($hdr === '# AUTORIZZATI') {
        $inBlock = true;
        continue;
    }

    // se ero nel blocco e trovo un altro header, esco
    if ($inBlock) break;

    // altri header -> ignora
    continue;
}

            // ✅ NEW: se ero nel blocco e trovo un altro header, esco
            if ($inBlock) break;

            // altri header -> ignora
            continue;
        }

        if ($inBlock) {
            // righe valide nel blocco: prendo tutto come valore singolo
            // (se hai righe tipo "A=xxx" le prendiamo intere; dimmi e lo splitto)
            $val = trim($raw);
            if ($val !== '') $items[] = $val;
        }
    }

    $items = array_values(array_unique($items));

    if (empty($items)) {
        // fallback per evitare select vuota
        // (lasciamo comunque message per debug)
        $response['success'] = true;
        $response['message'] = '⚠️ Nessuna voce trovata nel blocco # AUTORIZZATI';
        $response['data'] = [];
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response['success'] = true;
    $response['data'] = $items;

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);