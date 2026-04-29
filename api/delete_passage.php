<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) {
        throw new Exception('ID passaggio non valido');
    }

    // prendo il ticket_printed_id per scollegarlo
    $stmt = $db->prepare("SELECT ticket_printed_id FROM passages WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $pg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pg) {
        throw new Exception('Passaggio non trovato');
    }

    $ticketPrintedId = $pg['ticket_printed_id'] ? (int)$pg['ticket_printed_id'] : 0;

    // cancella il passaggio
    $del = $db->prepare("DELETE FROM passages WHERE id = ? LIMIT 1");
    $del->execute([$id]);

    // scollega eventuale ticket_printed
    if ($ticketPrintedId > 0) {
        $upd = $db->prepare("UPDATE tickets_printed SET passage_id = NULL WHERE id = ? LIMIT 1");
        $upd->execute([$ticketPrintedId]);
    }

    $response['success'] = true;
    $response['message'] = 'Passaggio eliminato correttamente';

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'DELETE_PASSAGE_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);