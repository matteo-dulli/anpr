<?php
// modulo1_ticket_get.php - ritorna ultimo ticket_printed per plate_id

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    require_once __DIR__ . '/../config/config.php';

    $plateId = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
    if ($plateId <= 0) throw new Exception('plate_id non valido');

    $db = getDatabaseConnection();

    // ✅ NEW: recupero plate_number dalla tabella plates (per mostrare anche la targa)
    $stmtP = $db->prepare("SELECT plate_corrected, plate_number FROM plates WHERE id=? LIMIT 1");
    $stmtP->execute([$plateId]);
    $p = $stmtP->fetch(PDO::FETCH_ASSOC);
    $plateNumber = '';
    if ($p) {
        $plateNumber = !empty($p['plate_corrected']) ? $p['plate_corrected'] : ($p['plate_number'] ?? '');
    }

    // ✅ NEW: prendi l'ultimo ticket per quella plate_id
    $stmt = $db->prepare("
        SELECT id, ticket_code, plate_id, plate_number, entry_datetime, exit_datetime
        FROM tickets_printed
        WHERE plate_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$plateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Nessun ticket associato a questa targa');

    // PATCH: se plate_number in tickets_printed è vuoto, usa quello da plates
    if (empty($row['plate_number'])) $row['plate_number'] = $plateNumber;

    $response['success'] = true;
    $response['data'] = $row;

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);