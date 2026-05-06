<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['id_lavaggio'])) {
        throw new Exception('id_lavaggio mancante');
    }

    $db = getDatabaseConnection();

    // ✅ AGGIORNA: Marca come annullato INVECE di cancellare
    $stmt = $db->prepare("
        UPDATE lavaggi
        SET annullato = 1, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$data['id_lavaggio']]);

    echo json_encode([
        'success' => true,
        'message' => 'Lavaggio annullato',
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
