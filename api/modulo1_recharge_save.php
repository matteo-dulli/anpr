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

    if (empty($data['tipo_ricarica'])) {
        throw new Exception('tipo_ricarica mancante');
    }

    $db = getDatabaseConnection();

    $quantita_ore = floatval($data['quantita_ore'] ?? 0);
    $prezzo_ricarica = floatval($data['prezzo_ricarica'] ?? 0);  // ✅ Prendi dal payload JS
    $totale_ricarica = floatval($data['totale_ricarica'] ?? 0);  // ✅ Prendi dal payload JS

    // ✅ Estrai secondary_barcode dalle ultime 5 cifre del primary_barcode
    $secondary_barcode = substr($data['primary_barcode'], -5);

    // ✅ Verifica se esiste già un record non annullato per questo ticket
    $checkStmt = $db->prepare("
        SELECT id FROM ricariche 
        WHERE primary_barcode = ? AND annullato = 0 AND stop = 0
        LIMIT 1
    ");
    $checkStmt->execute([$data['primary_barcode']]);
    $existingId = $checkStmt->fetchColumn();

    if ($existingId && $existingId !== ($data['id_ricarica'] ?? null)) {
        // Esiste già un'altra ricarica attiva per questo ticket
        throw new Exception('Esiste già una ricarica attiva per questo ticket');
    }

    // ✅ Se esiste un ID, UPDATE; altrimenti INSERT
    if (!empty($data['id_ricarica'])) {
        $stmt = $db->prepare("
            UPDATE ricariche
            SET 
                secondary_barcode = ?,
                tipo_ricarica = ?,
                prezzo_ricarica = ?,
                quantita_ore = ?,
                totale_ricarica = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND stop = 0
            LIMIT 1
        ");
        $stmt->execute([
            $secondary_barcode,
            $data['tipo_ricarica'],
            $prezzo_ricarica,
            $quantita_ore,
            $totale_ricarica,
            $data['id_ricarica']
        ]);
        $idRicarica = $data['id_ricarica'];
    } else {
        $stmt = $db->prepare("
            INSERT INTO ricariche (
                id_turno, 
                primary_barcode,
                secondary_barcode, 
                plate_number,
                tipo_ricarica, 
                prezzo_ricarica,
                quantita_ore,
                totale_ricarica,
                annullato,
                stop
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, 0, 0
            )
        ");
        $stmt->execute([
            $data['id_turno'] ?? null,
            $data['primary_barcode'],
            $secondary_barcode,
            $data['plate_number'] ?? null,
            $data['tipo_ricarica'],
            $prezzo_ricarica,
            $quantita_ore,
            $totale_ricarica
        ]);
        $idRicarica = $db->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Ricarica salvata',
        'data' => [
            'id_ricarica' => $idRicarica,
            'totale' => $totale_ricarica,
            'prezzo' => $prezzo_ricarica
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



