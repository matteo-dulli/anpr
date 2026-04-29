<?php
// api/test_emit_receipt.php - File di TEST per debug

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== TEST EMIT_RECEIPT DEBUG ===\n\n";

// ===== TEST 1: CONFIG =====
echo "TEST 1: Caricamento config.php\n";
try {
    require_once __DIR__ . '/../config/config.php';
    echo "✅ config.php caricato\n";
    echo "  PROJECT_ROOT: " . PROJECT_ROOT . "\n";
    echo "  INVOICE_DIR: " . INVOICE_DIR . "\n";
    echo "  COSTANTI_FILE: " . COSTANTI_FILE . "\n";
} catch (Exception $e) {
    echo "❌ Errore: " . $e->getMessage() . "\n";
    die();
}

// ===== TEST 2: COSTANTI =====
echo "\nTEST 2: Costanti caricate\n";
$costanti = $GLOBALS['COSTANTI'] ?? [];
if (!empty($costanti)) {
    echo "✅ Costanti caricate (" . count($costanti) . " elementi)\n";
    echo "  TestoF1: " . ($costanti['TestoF1'] ?? 'NOT FOUND') . "\n";
    echo "  PrezzoF1: " . ($costanti['PrezzoF1'] ?? 'NOT FOUND') . "\n";
    echo "  Nore: " . ($costanti['Nore'] ?? 'NOT FOUND') . "\n";
} else {
    echo "❌ Nessuna costante caricata!\n";
}

// ===== TEST 3: DATABASE =====
echo "\nTEST 3: Connessione DB\n";
try {
    $db = getDatabaseConnection();
    echo "✅ DB connesso\n";
    
    // Testa garage_info
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM garage_info");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  garage_info: " . $row['cnt'] . " righe\n";
    
    // Testa passages
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM passages");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  passages: " . $row['cnt'] . " righe\n";
    
} catch (Exception $e) {
    echo "❌ Errore DB: " . $e->getMessage() . "\n";
}

// ===== TEST 4: CARTELLA INVOICE =====
echo "\nTEST 4: Cartella INVOICE_DIR\n";
$invoice_dir = INVOICE_DIR;
echo "  Path: " . $invoice_dir . "\n";
echo "  Esiste: " . (is_dir($invoice_dir) ? "✅ SÌ" : "❌ NO") . "\n";
echo "  Scrivibile: " . (is_writable($invoice_dir) ? "✅ SÌ" : "❌ NO") . "\n";

if (!is_dir($invoice_dir)) {
    echo "  Tentativo creazione...\n";
    if (@mkdir($invoice_dir, 0777, true)) {
        echo "  ✅ Cartella creata\n";
    } else {
        echo "  ❌ Impossibile creare cartella\n";
    }
}

// ===== TEST 5: EMISSIONE TEST =====
echo "\nTEST 5: Emissione ricevuta TEST\n";
try {
    $passageId = 11;
    $price = 3.00;
    
    echo "  passage_id: $passageId\n";
    echo "  price: $price\n";
    
    // Recupera passaggio
    $stmt = $db->prepare("SELECT * FROM passages WHERE id = ?");
    $stmt->execute([$passageId]);
    $passage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$passage) {
        echo "❌ Passaggio non trovato\n";
    } else {
        echo "✅ Passaggio trovato\n";
        echo "  entry_datetime: " . $passage['entry_datetime'] . "\n";
        echo "  exit_datetime: " . $passage['exit_datetime'] . "\n";
        
        // Genera codice
        $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
        $receiptCode = "R" . $now->format('Ymd-His') . "-" . substr(strtoupper(bin2hex(random_bytes(4))), 0, 5);
        echo "  Receipt code generato: $receiptCode\n";
        
        // Test scrittura
        $filename = 'RECEIPT_' . $receiptCode . '.txt';
        $fullPath = rtrim($invoice_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        echo "  Path file: $fullPath\n";
        
        $testContent = "TEST RICEVUTA\nPassaggio: $passageId\nPrezzo: $price\n";
        if (@file_put_contents($fullPath, $testContent)) {
            echo "✅ File scritto correttamente\n";
            // Pulisci file test
            @unlink($fullPath);
        } else {
            echo "❌ Impossibile scrivere file\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Errore: " . $e->getMessage() . "\n";
}

echo "\n=== FINE TEST ===\n";
?>