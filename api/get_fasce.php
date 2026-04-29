<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'data' => [], 'message' => '', 'nore' => 5];

try {
    // ===== CARICA COSTANTI =====
    $costanti = $GLOBALS['COSTANTI'];
    if (!$costanti || !is_array($costanti)) {
        throw new Exception('Costanti non disponibili');
    }

    // ===== INDIVIDUA TUTTE LE FASCE =====
    $fasce_nums = [];
    foreach ($costanti as $k => $v) {
        if (preg_match('/^TestoF(\d+)$/', $k, $m)) {
            $fasce_nums[] = (int)$m[1];
        }
    }
    // Ordina e rimuovi doppioni
    $fasce_nums = array_unique($fasce_nums);
    sort($fasce_nums);

    // ===== COSTRUISCI ARRAY FASCE =====
    $fasce = [];
    foreach ($fasce_nums as $i) {
        $fasce[] = [
            'codice'      => "F$i",
            'testo'       => $costanti["TestoF$i"] ?? "Fascia $i",
            'prezzo'      => isset($costanti["PrezzoF$i"])     ? (float)$costanti["PrezzoF$i"]     : 0.0,
            'prezzo_day'  => isset($costanti["PrezzoDayF$i"])  ? (float)$costanti["PrezzoDayF$i"]  : 0.0,
            'tolleranza'  => isset($costanti["TolleranzaF$i"]) ? (int)$costanti["TolleranzaF$i"]   : 5,
        ];
    }

    // ===== NORE (ORE STANDARD) =====
    $nore = (int)($costanti['Nore'] ?? 5);

    $response['success'] = true;
    $response['data'] = $fasce;
    $response['nore'] = $nore;
    $response['count'] = count($fasce);
    $response['message'] = '✅ Fasce caricate da costanti.txt';

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    error_log('GET_FASCE ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>