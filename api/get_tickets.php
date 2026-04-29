<?php
// api/get_tickets.php - Recupera ticket per una targa
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$response = [
    'success' => false,
    'data'    => [],
    'message' => ''
];

try {
    if (!isset($_GET['plate_id'])) {
        throw new Exception('plate_id richiesto');
    }

    $plateId = (int) $_GET['plate_id'];

    // Connessione DB
    $db = getDatabaseConnection();

    // Query ticket per quella targa CON ticket_code da tickets_printed
    $sql = "
        SELECT 
            t.*,
            tp.ticket_code
        FROM tickets t
        LEFT JOIN tickets_printed tp ON t.plate_id = tp.plate_id
        WHERE t.plate_id = ? 
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('prepare() fallita: ' . implode(' | ', $db->errorInfo()));
    }

    if (!$stmt->execute([$plateId])) {
        throw new Exception('execute() fallita: ' . implode(' | ', $stmt->errorInfo()));
    }

    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        $response['data'] = $ticket;
    }

    $response['success'] = true;

} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>