<?php
// simple_test.php - Test database semplificato
header('Content-Type: application/json');

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'step' => 0,
    'messages' => []
];

try {
    // Step 1: Carica config
    $result['step'] = 1;
    $result['messages'][] = 'Caricando config...';
    
    require_once __DIR__ . '/../config/config.php';
    $result['messages'][] = '✅ Config caricato';
    $result['DB_HOST'] = DB_HOST;
    $result['DB_NAME'] = DB_NAME;
    $result['DB_USER'] = DB_USER;
    
    // Step 2: Connessione diretta
    $result['step'] = 2;
    $result['messages'][] = 'Connettendo a MySQL...';
    
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if (!$conn) {
        throw new Exception('Connessione fallita: ' . mysqli_connect_error());
    }
    
    $result['messages'][] = '✅ Connesso a MySQL';
    
    // Step 3: Verifica database
    $result['step'] = 3;
    $result['messages'][] = 'Verificando database...';
    
    $dbResult = mysqli_query($conn, "SELECT DATABASE()");
    $dbName = mysqli_fetch_row($dbResult);
    $result['current_database'] = $dbName[0];
    $result['messages'][] = '✅ Database: ' . $dbName[0];
    
    // Step 4: Elenca tabelle
    $result['step'] = 4;
    $result['messages'][] = 'Elencando tabelle...';
    
    $tablesResult = mysqli_query($conn, "SHOW TABLES");
    $tables = [];
    while ($row = mysqli_fetch_row($tablesResult)) {
        $tables[] = $row[0];
    }
    
    $result['tables'] = $tables;
    $result['table_count'] = count($tables);
    $result['messages'][] = '✅ Tabelle trovate: ' . count($tables);
    
    // Step 5: Verifica tabella plates
    $result['step'] = 5;
    $result['messages'][] = 'Verificando tabella plates...';
    
    if (in_array('plates', $tables)) {
        $result['plates_exists'] = true;
        $result['messages'][] = '✅ Tabella plates esiste';
        
        // Conta righe
        $countResult = mysqli_query($conn, "SELECT COUNT(*) as count FROM plates");
        $count = mysqli_fetch_assoc($countResult);
        $result['plates_count'] = (int)$count['count'];
        $result['messages'][] = '✅ Righe in plates: ' . $result['plates_count'];
    } else {
        $result['plates_exists'] = false;
        $result['messages'][] = '❌ Tabella plates NON ESISTE';
    }
    
    // Step 6: Verifica tabella tickets
    $result['step'] = 6;
    if (in_array('tickets', $tables)) {
        $result['tickets_exists'] = true;
        $result['messages'][] = '✅ Tabella tickets esiste';
    } else {
        $result['tickets_exists'] = false;
        $result['messages'][] = '⚠️ Tabella tickets NON ESISTE';
    }
    
    // Step 7: Verifica tabella scan_logs
    $result['step'] = 7;
    if (in_array('scan_logs', $tables)) {
        $result['scan_logs_exists'] = true;
        $result['messages'][] = '✅ Tabella scan_logs esiste';
    } else {
        $result['scan_logs_exists'] = false;
        $result['messages'][] = '⚠️ Tabella scan_logs NON ESISTE';
    }
    
    $result['success'] = true;
    $result['step'] = 'COMPLETATO';
    
    mysqli_close($conn);
    
} catch (Exception $e) {
    $result['success'] = false;
    $result['error'] = $e->getMessage();
    $result['messages'][] = '❌ ERRORE: ' . $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>