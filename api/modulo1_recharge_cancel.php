<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['id_ricarica'])) {
        throw new Exception('id_ricarica mancante');
    }

    $db = getDatabaseConnection();

    // ✅ AGGIORNA: Marca come annullato INVECE di cancellare
    $stmt = $db->prepare("
        UPDATE ricariche
        SET annullato = 1, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$data['id_ricarica']]);

    echo json_encode([
        'success' => true,
        'message' => 'Ricarica annullata',
        'affected_rows' => $stmt->rowCount()
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
