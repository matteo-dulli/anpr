<?php
/**
 * turni_list.php
 * Ritorna la lista dei turni degli ultimi 30 giorni
 * Response: { success, data: [ { id, numero_turno, operatore_cod, stato, data_inizio, file_name } ] }
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $db = getDatabaseConnection();

    // Controlla se la tabella esiste prima di interrogarla
    $tableCheck = $db->query("SHOW TABLES LIKE 'turni_sessioni'");
    if ($tableCheck->rowCount() === 0) {
        // Tabella non ancora creata (nessun login ancora effettuato)
        $response['success'] = true;
        $response['data']    = [];
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $limite = date('Y-m-d H:i:s', strtotime('-30 days'));

    $stmt = $db->prepare("
        SELECT
            id,
            numero_turno,
            operatore_cod,
            stato,
            inizio  AS data_inizio,
            fine    AS data_fine,
            file_path
        FROM turni_sessioni
        WHERE inizio >= ?
        ORDER BY inizio DESC
        LIMIT 100
    ");
    $stmt->execute([$limite]);

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'id'            => (int)$row['id'],
            'numero_turno'  => (int)$row['numero_turno'],
            'operatore_cod' => $row['operatore_cod'],
            'stato'         => $row['stato'],
            'data_inizio'   => $row['data_inizio'],
            'data_fine'     => $row['data_fine'],
            'file_name'     => $row['file_path'] ? $row['file_path'] : null
        ];
    }

    $response['success'] = true;
    $response['data']    = $data;

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
