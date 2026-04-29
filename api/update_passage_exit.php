<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    $passage_id = isset($body['passage_id']) ? (int)$body['passage_id'] : 0;
    $exit_datetime = isset($body['exit_datetime']) ? $body['exit_datetime'] : null;

    if ($passage_id <= 0) {
        throw new Exception('ID passaggio non valido');
    }

    if (!$exit_datetime) {
        throw new Exception('exit_datetime richiesto');
    }

    // Converte il formato (2026-04-16 16:29:03) nel formato MySQL
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $exit_datetime);
    
    if (!$dateTime) {
        throw new Exception('Formato datetime non valido: ' . $exit_datetime);
    }

    $formattedDateTime = $dateTime->format('Y-m-d H:i:s');

    // Log per debug
    error_log('UPDATE_PASSAGE_EXIT - ID: ' . $passage_id . ' | DateTime: ' . $formattedDateTime);

    // Aggiorna exit_datetime
    $stmt = $db->prepare("
        UPDATE passages
        SET 
            exit_datetime = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    
    $result = $stmt->execute([$formattedDateTime, $passage_id]);

    if (!$result) {
        throw new Exception('Errore aggiornamento passaggio: ' . implode(', ', $stmt->errorInfo()));
    }

    if ($stmt->rowCount() === 0) {
        throw new Exception('Passaggio non trovato');
    }

    $response['success'] = true;
    $response['message'] = '✅ Exit datetime aggiornato: ' . $formattedDateTime;

    if (function_exists('logEvent')) {
        logEvent('passage', "Exit datetime aggiornato: ID=$passage_id a $formattedDateTime");
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
    
    error_log('UPDATE_PASSAGE_EXIT_ERROR: ' . $e->getMessage());
    
    if (function_exists('logEvent')) {
        logEvent('error', 'UPDATE_PASSAGE_EXIT_ERROR: ' . $e->getMessage());
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>