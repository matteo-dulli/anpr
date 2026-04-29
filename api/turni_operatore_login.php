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

    // Controlla se operatore esiste
    $chk = $db->prepare("SELECT id FROM operatori_turni WHERE operatore_cod = ? LIMIT 1");
    $chk->execute([$operatore_cod]);
    if (!$chk->fetch()) {
        throw new Exception('Operatore non trovato');
    }

    $now = date('Y-m-d H:i:s');

    // Aggiorna turno
    $stmt = $db->prepare("
        UPDATE operatori_turni
        SET stato = 'online', inizio_turno = ?, login_time = ?, updated_at = NOW()
        WHERE operatore_cod = ?
    ");
    $stmt->execute([$now, $now, $operatore_cod]);

    // Log azione
    if (function_exists('logEvent')) {
        logEvent('turni', "LOGIN: Operatore $operatore_cod accesso turno");
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno aperto per operatore $operatore_cod";
    $response['data'] = ['operatore_cod' => $operatore_cod, 'stato' => 'online'];

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_LOGIN_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
