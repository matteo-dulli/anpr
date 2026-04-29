<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'data' => [], 'message' => ''];

try {
    // Colonne da estrarre con i loro DEFAULT
    $columns = [
        'TestoF1', 'TestoF2', 'TestoF3', 'TestoF4', 'TestoF5',
        'PrezzoF1', 'PrezzoF2', 'PrezzoF3', 'PrezzoF4', 'PrezzoF5',
        'TolleranzaF1', 'TolleranzaF2', 'TolleranzaF3', 'TolleranzaF4', 'TolleranzaF5',
        'PrezzoDayF1', 'PrezzoDayF2', 'PrezzoDayF3', 'PrezzoDayF4', 'PrezzoDayF5',
        'Nore'
    ];

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    
    // Query INFORMATION_SCHEMA per i DEFAULT
    $stmt = $db->prepare("
        SELECT 
            COLUMN_NAME,
            COLUMN_DEFAULT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'cassa'
          AND COLUMN_NAME IN ($placeholders)
    ");
    
    // Prepara parametri: schema + colonne
    $params = array_merge([getenv('DB_NAME') ?: 'anpr_db'], $columns);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$rows) {
        throw new Exception('Nessun valore di default trovato');
    }

    // Organizza i dati
    $defaults = [];
    foreach ($rows as $row) {
        $columnName = $row['COLUMN_NAME'];
        $columnDefault = $row['COLUMN_DEFAULT'];
        
        // Converte i tipi appropriati
        if (in_array($columnName, ['Nore', 'TolleranzaF1', 'TolleranzaF2', 'TolleranzaF3', 'TolleranzaF4', 'TolleranzaF5'])) {
            $defaults[$columnName] = (int)$columnDefault;
        } elseif (in_array($columnName, ['PrezzoF1', 'PrezzoF2', 'PrezzoF3', 'PrezzoF4', 'PrezzoF5', 'PrezzoDayF1', 'PrezzoDayF2', 'PrezzoDayF3', 'PrezzoDayF4', 'PrezzoDayF5'])) {
            $defaults[$columnName] = (float)$columnDefault;
        } else {
            $defaults[$columnName] = $columnDefault; // string
        }
    }

    // Organizza per fascia per facilità d'uso
    $response['success'] = true;
    $response['data'] = [
        'raw_defaults' => $defaults,
        'fasce' => [
            'F1' => [
                'testo' => $defaults['TestoF1'] ?? 'Auto piccola',
                'prezzo' => $defaults['PrezzoF1'] ?? 3.00,
                'prezzo_day' => $defaults['PrezzoDayF1'] ?? 19.00,
                'tolleranza' => $defaults['TolleranzaF1'] ?? 5
            ],
            'F2' => [
                'testo' => $defaults['TestoF2'] ?? 'Auto media',
                'prezzo' => $defaults['PrezzoF2'] ?? 4.00,
                'prezzo_day' => $defaults['PrezzoDayF2'] ?? 20.00,
                'tolleranza' => $defaults['TolleranzaF2'] ?? 5
            ],
            'F3' => [
                'testo' => $defaults['TestoF3'] ?? 'Auto grande',
                'prezzo' => $defaults['PrezzoF3'] ?? 5.00,
                'prezzo_day' => $defaults['PrezzoDayF3'] ?? 25.00,
                'tolleranza' => $defaults['TolleranzaF3'] ?? 5
            ],
            'F4' => [
                'testo' => $defaults['TestoF4'] ?? 'Auto di lusso',
                'prezzo' => $defaults['PrezzoF4'] ?? 6.00,
                'prezzo_day' => $defaults['PrezzoDayF4'] ?? 35.00,
                'tolleranza' => $defaults['TolleranzaF4'] ?? 5
            ],
            'F5' => [
                'testo' => $defaults['TestoF5'] ?? 'Furgone',
                'prezzo' => $defaults['PrezzoF5'] ?? 7.00,
                'prezzo_day' => $defaults['PrezzoDayF5'] ?? 50.00,
                'tolleranza' => $defaults['TolleranzaF5'] ?? 5
            ]
        ],
        'nore' => $defaults['Nore'] ?? 5
    ];

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>