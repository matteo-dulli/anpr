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

    // ✅ CORRETTO: Leggi il turno (SENZA colonna 'nome')
    $stmt = $db->prepare("
        SELECT id, operatore_cod, stato, inizio_turno, ore_totali
        FROM operatori_turni
        WHERE operatore_cod = ?
        LIMIT 1
    ");
    $stmt->execute([$operatore_cod]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        throw new Exception('Operatore non trovato');
    }

    $now = date('Y-m-d H:i:s');

    // Calcola ore se il turno era aperto
    $ore_totali = 0;
    if ($turno['inizio_turno']) {
        $inizio = new DateTime($turno['inizio_turno']);
        $fine = new DateTime($now);
        $diff = $fine->diff($inizio);
        $ore_totali = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
    }

    // Aggiorna turno: chiudi
    $stmt = $db->prepare("
        UPDATE operatori_turni
        SET stato = 'offline', fine_turno = ?, ore_totali = ore_totali + ?, updated_at = NOW()
        WHERE operatore_cod = ?
    ");
    $stmt->execute([$now, $ore_totali, $operatore_cod]);

    // Log azione
    if (function_exists('logEvent')) {
        logEvent('turni', "LOGOUT: Operatore $operatore_cod chiude turno - Ore: " . number_format($ore_totali, 2));
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno chiuso - Ore totali: " . number_format($ore_totali, 2);
    $response['data'] = [
        'operatore_cod' => $operatore_cod,
        'stato' => 'offline',
        'ore_totali' => round($ore_totali, 2)
    ];

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_LOGOUT_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
