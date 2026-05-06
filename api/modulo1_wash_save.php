<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['primary_barcode'])) {
        throw new Exception('primary_barcode mancante');
    }

    if (empty($data['plate_id'])) {
        throw new Exception('plate_id mancante');
    }

    if (empty($data['tipo_lavaggio'])) {
        throw new Exception('tipo_lavaggio mancante');
    }

    $db = getDatabaseConnection();

    // ✅ Calcola totali
    $prezzo_lavaggio = floatval($data['prezzo_lavaggio'] ?? 0);
    $totale_accessori = floatval($data['totale_accessori'] ?? 0);
    $totale_prodotti = floatval($data['totale_prodotti'] ?? 0);
    
    // ✅ Totale = prezzo_lavaggio + accessori + prodotti
    $totale_lavaggio = $prezzo_lavaggio + $totale_accessori + $totale_prodotti;

    // ✅ Pulisci JSON vuoti
    $accessori_json = isset($data['accessori']) && !empty($data['accessori']) 
        ? json_encode($data['accessori'], JSON_UNESCAPED_UNICODE) 
        : '[]';
    
    $prodotti_json = isset($data['prodotti']) && !empty($data['prodotti']) 
        ? json_encode($data['prodotti'], JSON_UNESCAPED_UNICODE) 
        : '[]';

    // ✅ Estrai secondary_barcode dalle ultime 5 cifre del primary_barcode
    $secondary_barcode = substr($data['primary_barcode'], -5);

    // ✅ Verifica se esiste già un lavaggio NON annullato per questo ticket
    $stmtCheck = $db->prepare("
        SELECT id FROM lavaggi 
        WHERE primary_barcode = ? AND annullato = 0 AND stop = 0
        LIMIT 1
    ");
    $stmtCheck->execute([$data['primary_barcode']]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // ✅ UPDATE se esiste
        $id_lavaggio = $existing['id'];
        
        $stmt = $db->prepare("
            UPDATE lavaggi SET
                tipo_lavaggio = ?,
                prezzo_lavaggio = ?,
                accessori_json = ?,
                totale_accessori = ?,
                prodotti_json = ?,
                totale_prodotti = ?,
                totale_lavaggio = ?,
                annullato = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['tipo_lavaggio'],
            $prezzo_lavaggio,
            $accessori_json,
            $totale_accessori,
            $prodotti_json,
            $totale_prodotti,
            $totale_lavaggio,
            $id_lavaggio
        ]);
    } else {
        // ✅ INSERT nuovo lavaggio
        $stmt = $db->prepare("
            INSERT INTO lavaggi (
                id_turno,
                primary_barcode,
                secondary_barcode,
                plate_number,
                tipo_lavaggio,
                prezzo_lavaggio,
                accessori_json,
                totale_accessori,
                prodotti_json,
                totale_prodotti,
                totale_lavaggio,
                annullato,
                stop
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0
            )
        ");

        $stmt->execute([
            $data['id_turno'] ?? null,
            $data['primary_barcode'],
            $secondary_barcode,
            $data['plate_number'] ?? null,
            $data['tipo_lavaggio'],
            $prezzo_lavaggio,
            $accessori_json,
            $totale_accessori,
            $prodotti_json,
            $totale_prodotti,
            $totale_lavaggio
        ]);

        $id_lavaggio = $db->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Lavaggio salvato',
        'data' => [
            'id_lavaggio' => $id_lavaggio,
            'totale' => $totale_lavaggio
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

