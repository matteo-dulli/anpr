<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $configPath = dirname(__DIR__) . '/config/config.php';
    if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) throw new Exception('config.php non trovato');
    require_once $configPath;

    $raw = file_get_contents('php://input');
    $b = $raw ? json_decode($raw, true) : [];

    $plateId = (int)($b['plate_id'] ?? 0);
    $autor = trim((string)($b['autor'] ?? ''));

    if ($plateId <= 0) throw new Exception('plate_id non valido');

    $db = getDatabaseConnection();

    // ✅ prendo plate_number effettivo
    $stmt = $db->prepare("SELECT plate_number, plate_corrected FROM plates WHERE id=? LIMIT 1");
    $stmt->execute([$plateId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception('Targa non trovata');

    $plateNumber = trim((string)($p['plate_corrected'] ?: $p['plate_number']));
    if ($plateNumber === '') throw new Exception('plate_number vuoto');

    // ----------------------------
    // VECCHIO: update solo riga corrente
    // $u = $db->prepare("UPDATE plates SET autor=? WHERE id=? LIMIT 1");
    // $u->execute([$autor, $plateId]);
    // ----------------------------

    // ✅ NEW: update su tutte le righe della targa
    $u = $db->prepare("
        UPDATE plates
        SET autor=?
        WHERE plate_number=? OR plate_corrected=?
    ");
    $u->execute([$autor, $plateNumber, $plateNumber]);

    $response['success'] = true;
    $response['message'] = '✅ Autorizzazione salvata';
    $response['data'] = ['plate_number' => $plateNumber, 'autor' => $autor];

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);