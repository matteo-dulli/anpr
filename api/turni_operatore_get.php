<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';

    if (!$operatore_cod) {
        throw new Exception('operatore_cod obbligatorio');
    }

    $stmt = $db->prepare("
        SELECT id, operatore_cod, nome, stato, inizio_turno, fine_turno, login_time, ore_totali
        FROM operatori_turni
        WHERE operatore_cod = ?
        LIMIT 1
    ");
    $stmt->execute([$operatore_cod]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        throw new Exception('Operatore non trovato');
    }

    $response['success'] = true;
    $response['data'] = $turno;

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_OPERATORE_GET_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
