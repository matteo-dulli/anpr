<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();

$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['plate_number'])) {
        throw new Exception('Numero targa mancante');
    }

    $plateNumber = strtoupper(trim($data['plate_number']));
    if ($plateNumber === '') {
        throw new Exception('Numero targa vuoto');
    }
    if (strlen($plateNumber) > 10) {
        throw new Exception('Numero targa troppo lungo (max 10 caratteri)');
    }

    $now = date('Y-m-d H:i:s');

    // CREA RECORD IN plates CON is_manual = 1
    $stmt = $db->prepare("
        INSERT INTO plates (plate_number, plate_corrected, date_detected, is_manual)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$plateNumber, $plateNumber, $now]);
    $plateId = (int)$db->lastInsertId();

    // (OPZIONALE) LOGGA IN manual_plates_log SE TI SERVE STORICO
    if ($db->query("SHOW TABLES LIKE 'manual_plates_log'")->rowCount() > 0) {
        $logStmt = $db->prepare("
            INSERT INTO manual_plates_log (plate_id, plate_number, created_at)
            VALUES (?, ?, NOW())
        ");
        $logStmt->execute([$plateId, $plateNumber]);
    }

    // Salva ticket_code se fornito
    $ticketCode = isset($data['ticket_code']) ? trim($data['ticket_code']) : '';
    if (!empty($ticketCode)) {
        $ticketStmt = $db->prepare("
            INSERT INTO tickets_printed (ticket_code, plate_id, plate_number, entry_datetime)
            VALUES (?, ?, ?, NOW())
        ");
        $ticketStmt->execute([$ticketCode, $plateId, $plateNumber]);
    }

    // LOG EVENTO
    if (function_exists('logEvent')) {
        logEvent('plate', "Creata targa manuale: ID=$plateId NUMERO=$plateNumber DATA=$now");
    }

    $response['success'] = true;
    $response['plate_id'] = $plateId;
    $response['plate_number'] = $plateNumber;
    $response['date_detected'] = $now;
    $response['message'] = 'Targa manuale creata correttamente';

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'CREATE_MANUAL_PLATE_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>