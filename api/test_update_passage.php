<?php
// api/test_update_passage.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== TEST UPDATE_PASSAGE ===\n\n";

// TEST 1: Config
echo "TEST 1: Config\n";
try {
    require_once __DIR__ . '/../config/config.php';
    echo "✅ Config loaded\n";
} catch (Exception $e) {
    echo "❌ " . $e->getMessage() . "\n";
    die();
}

// TEST 2: DB
echo "\nTEST 2: Database\n";
try {
    $db = getDatabaseConnection();
    echo "✅ DB connected\n";
    
    $stmt = $db->query("DESCRIBE cassa");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "  Colonne cassa: " . implode(", ", $columns) . "\n";
} catch (Exception $e) {
    echo "❌ " . $e->getMessage() . "\n";
    die();
}

// TEST 3: Update test
echo "\nTEST 3: Test UPDATE\n";
try {
    $passage_id = 12;
    $paid = 1;
    $pag_cash = 1;
    $pag_electronic = 0;
    $fascia = "F2";
    $prezzo = 4.00;
    $annullato = 0;
    $motivo = "";
    
    $stmtCheck = $db->prepare("SELECT id FROM cassa WHERE idpassages = ? LIMIT 1");
    $stmtCheck->execute([$passage_id]);
    $existingCassa = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existingCassa) {
        echo "  Riga cassa trovata: " . $existingCassa['id'] . "\n";
        
        $stmtCassa = $db->prepare("
            UPDATE cassa
            SET 
                Ppaid = ?,
                PpayC = ?,
                PpayE = ?,
                fascia = ?,
                prezzo = ?,
                Pannullato = ?,
                Pannultxt = ?,
                updated_at = NOW()
            WHERE idpassages = ?
        ");
        
        $result = $stmtCassa->execute([$paid, $pag_cash, $pag_electronic, $fascia, $prezzo, $annullato, $motivo, $passage_id]);
        
        if ($result) {
            echo "✅ UPDATE riuscito\n";
        } else {
            echo "❌ UPDATE fallito: " . implode(", ", $stmtCassa->errorInfo()) . "\n";
        }
    } else {
        echo "  Nessuna riga cassa, tentativo INSERT\n";
        
        $stmtGetTicket = $db->prepare("SELECT ticket_code FROM passages WHERE id = ?");
        $stmtGetTicket->execute([$passage_id]);
        $passageData = $stmtGetTicket->fetch(PDO::FETCH_ASSOC);
        
        if (!$passageData) {
            echo "❌ Passaggio non trovato\n";
        } else {
            $ticket_code = $passageData['ticket_code'] ?: '';
            echo "  Ticket code: " . $ticket_code . "\n";
            
            $stmtCassa = $db->prepare("
                INSERT INTO cassa 
                (idpassages, Pticket_code, Ppaid, PpayC, PpayE, fascia, prezzo, Pannullato, Pannultxt, datacassa, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
            ");
            
            $result = $stmtCassa->execute([$passage_id, $ticket_code, $paid, $pag_cash, $pag_electronic, $fascia, $prezzo, $annullato, $motivo]);
            
            if ($result) {
                echo "✅ INSERT riuscito\n";
            } else {
                echo "❌ INSERT fallito: " . implode(", ", $stmtCassa->errorInfo()) . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Errore: " . $e->getMessage() . "\n";
}

echo "\n=== FINE TEST ===\n";
?>